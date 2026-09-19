"""
Glasovni agent za telefonske klice.

Kaj dela: sprejme klic prek LiveKit, posluša, govori, in kadar potrebuje
podatek, pokliče iste HTTP endpointe kot besedilni klepet
(https://.../asistent/tools/*.php). Poslovna logika ostane na PHP strani —
tu ni nobenega pravila o cenah, zalogi ali preverjanju naročil.

Sistemski prompt se ob zagonu prenese s strežnika (ai/agent-config.php), da
sta besedilni in glasovni asistent vedno enaka. Sprememba imena podjetja v
config.php velja za oba, brez posega v to datoteko.

Zagon:
    pip install -r requirements.txt
    python agent.py console     # preizkus prek računalnika, brez telefona
    python agent.py dev         # poveže se na LiveKit, sprejema klice
"""

import asyncio
import logging
import os
import time

import httpx
from dotenv import load_dotenv
from livekit import agents
from livekit.agents import Agent, AgentSession, RunContext, function_tool
from livekit.plugins import azure, openai, silero

load_dotenv()

log = logging.getLogger("tatjana")

TOOLS_BASE_URL = os.environ["TOOLS_BASE_URL"].rstrip("/")
TOOL_SECRET = os.environ["TOOL_SECRET"]

# Telefonski klic ne sme obstati. Če endpoint ne odgovori v nekaj sekundah,
# je bolje povedati, da sistem ne odgovarja, kot pustiti tišino.
TIMEOUT_SEKUND = 6.0


# Povezava se odpre enkrat in ostane odprta. Nova povezava na vsak klic pomeni
# novo rokovanje TLS, kar je po telefonu slišnih 100 do 300 milisekund.
_odjemalec: httpx.AsyncClient | None = None


def odjemalec() -> httpx.AsyncClient:
    global _odjemalec
    if _odjemalec is None:
        _odjemalec = httpx.AsyncClient(
            timeout=TIMEOUT_SEKUND,
            headers={"X-Tool-Secret": TOOL_SECRET},
            limits=httpx.Limits(max_keepalive_connections=4, keepalive_expiry=120.0),
        )
    return _odjemalec


async def poklici_orodje(pot: str, vsebina: dict) -> dict:
    """Pokliče PHP endpoint in vrne dekodiran odgovor. Meri, koliko je trajalo."""
    zacetek = time.perf_counter()
    try:
        r = await odjemalec().post(f"{TOOLS_BASE_URL}/tools/{pot}.php", json=vsebina)
        odgovor = r.json()
        meritev("orodje:" + pot, zacetek)
        return odgovor
    except Exception as e:  # noqa: BLE001 — karkoli gre narobe, klic mora teči naprej
        meritev("orodje:" + pot + ":napaka", zacetek)
        log.warning("orodje %s ni odgovorilo: %s", pot, e)
        return {
            "success": False,
            "data": None,
            "error": "Sistem trenutno ne odgovori.",
        }


async def vprasaj_vratarja(dejanje: str, klicatelj: str, sekund: int = 0) -> dict:
    """Vpraša strežnik, ali sme klic naprej, in mu sporoči, koliko je trajal.

    Števci morajo živeti na gostovanju in ne tukaj. Ta agent teče v LiveKit
    Cloud, kjer se replika lahko kadar koli ustavi in zažene znova s praznim
    diskom — števec v agentu bi se vrnil na nič ravno takrat, ko bi bil najbolj
    potreben, in več replik hkrati bi štelo vsaka zase.
    """
    vsebina = {"action": dejanje, "caller": klicatelj}
    if dejanje == "end":
        vsebina["seconds"] = sekund

    try:
        r = await odjemalec().post(f"{TOOLS_BASE_URL}/ai/call-guard.php", json=vsebina)
        return r.json()
    except Exception as e:  # noqa: BLE001
        # Če vratar ne odgovori, klic spustimo skozi. Nedosegljiv strežnik na
        # gostovanju ne sme pomeniti, da telefon podjetja obmolkne — to bi bila
        # večja škoda od ene prekoračene meje.
        log.warning("vratar ni odgovoril (%s): %s", dejanje, e)
        return {"allow": True}


def meritev(kaj: str, zacetek: float) -> None:
    """Zapiše trajanje koraka.

    Brez tega se optimizira po občutku. Po nekaj pravih klicih se iz dnevnika
    vidi, ali čas požre prepis, model, orodje ali govor — in samo tisto je
    vredno popravljati.
    """
    log.info("MERITEV %s %.0f ms", kaj, (time.perf_counter() - zacetek) * 1000)


async def mašilo(context: RunContext, besedilo: str) -> None:
    """Reče kratko potrdilo, medtem ko v ozadju teče klic orodja.

    Brez tega je v slušalki ena do dve sekundi tišine in klic zveni pokvarjeno.
    Klic s tem ni hitrejši, a sogovornik ve, da se nekaj dogaja — enako kot
    človek reče "trenutek, preverim".
    """
    try:
        context.session.say(besedilo, add_to_chat_ctx=False)
    except Exception as e:  # noqa: BLE001 — mašilo ne sme nikoli podreti klica
        log.debug("mašila ni bilo mogoče izgovoriti: %s", e)


class TelefonskiAsistent(Agent):
    def __init__(self, navodila: str, telefon_klicatelja: str = "") -> None:
        super().__init__(instructions=navodila)
        self.telefon_klicatelja = telefon_klicatelja

    @function_tool()
    async def search_services(
        self,
        context: RunContext,
        query: str,
        action: str = "search",
        category: str | None = None,
    ) -> dict:
        """Poišče storitve v ponudbi po imenu ali področju. Vrne ceno, enoto in
        opis, kaj storitev vključuje. Uporabi vedno, kadar stranka sprašuje o
        ponudbi, ceni ali o tem, kaj je v paketu — nikoli ne navajaj cen po spominu.

        Args:
            query: Kaj stranka išče, z njenimi besedami: 'spletna stran', 'trgovina', 'logotip'.
            action: 'search' za splošno ponudbo, 'get_price' za ceno, 'check_stock' za razpoložljivost.
            category: Neobvezno: 'spletne-strani', 'trzenje', 'oblikovanje', 'vzdrzevanje'.
        """
        await mašilo(context, "Trenutek, preverim.")
        vsebina = {"query": query, "action": action}
        if category:
            vsebina["category"] = category
        return await poklici_orodje("product-lookup", vsebina)

    @function_tool()
    async def lookup_project(self, context: RunContext, order_id: str, verify: str) -> dict:
        """Pogleda stanje projekta obstoječe stranke. Zahteva DVA podatka:
        številko projekta in telefonsko številko ali e-pošto, s katero je bil
        naročen. Če stranka pove samo številko, jo najprej vprašaj za telefonsko.

        Args:
            order_id: Številka projekta, na primer '10001'.
            verify: Telefonska številka ali e-pošta stranke, s katero je bil projekt naročen.
        """
        await mašilo(context, "Samo trenutek, pogledam.")
        return await poklici_orodje("order-lookup", {"order_id": order_id, "verify": verify})

    @function_tool()
    async def get_business_info(self, context: RunContext, info_type: str = "hours") -> dict:
        """Podatki o poslovanju: delovni čas, roki izdelave in potek dela, pogoji plačila.

        Args:
            info_type: 'hours' za delovni čas, 'delivery' za roke in potek dela, 'payments' za plačilo.
        """
        await mašilo(context, "Trenutek.")
        return await poklici_orodje("business-info", {"info_type": info_type})

    @function_tool()
    async def submit_inquiry(
        self,
        context: RunContext,
        name: str,
        phone: str,
        email: str,
        product: str | None = None,
        quantity: str | None = None,
        note: str | None = None,
    ) -> dict:
        """Odda povpraševanje, da podjetje stranki pripravi ponudbo. Uporabi
        šele, ko imaš ime, telefonsko številko in e-pošto, in ko je stranka
        potrdila, da naj povpraševanje oddaš. Povpraševanje ni naročilo.

        Args:
            name: Ime in priimek stranke ali naziv podjetja.
            phone: Telefonska številka za povratni klic. Med telefonskim klicem
                vpiši prazen niz, če stranka ni izrecno povedala druge številke —
                vzame se številka, s katere kliče.
            email: E-poštni naslov, na katerega gre ponudba. Nikoli sem ne vpiši
                telefonske številke; brez veljavnega naslova povpraševanja ni.
            product: Kaj stranka potrebuje, z njenimi besedami.
            quantity: Obseg, če ga je navedla.
            note: Vse, kar je povedala o projektu — rok, obstoječa stran, panoga, proračun.
        """
        await mašilo(context, "Zabeležim.")

        # Pri telefonskem klicu je številka že znana iz same povezave. Narekovanje
        # po zvoku je najpogostejši vir napak — števke se zamenjajo in ponudba gre
        # v prazno. Kar stranka izrecno pove, ima vseeno prednost: klicati zna s
        # centrale in želeti povratni klic na mobilni.
        telefon = (phone or self.telefon_klicatelja or "").strip()
        if not telefon:
            return {
                "success": False,
                "data": None,
                "error": "Manjka telefonska številka. Vprašaj stranko zanjo.",
            }

        # Po zvoku se "at" in "pika" pogosto izgubita, model pa je v to polje že
        # vpisal telefonsko številko. Napako ujamemo tu, da dobi jasen popravek
        # namesto splošne zavrnitve s strežnika.
        naslov = (email or "").strip()
        if "@" not in naslov or "." not in naslov.rsplit("@", 1)[-1]:
            return {
                "success": False,
                "data": None,
                "error": (
                    "To ni e-poštni naslov. Vprašaj stranko za e-pošto in jo prosi, "
                    "naj jo pove po črkah."
                ),
            }

        vsebina = {"name": name, "phone": telefon, "email": naslov}
        for kljuc, vrednost in (("product", product), ("quantity", quantity), ("note", note)):
            if vrednost:
                vsebina[kljuc] = vrednost
        odgovor = await poklici_orodje("submit-inquiry", vsebina)

        # Navodilo v odgovoru orodja model upošteva bolj zanesljivo kot pravilo,
        # zakopano sredi sistemskega prompta. Brez tega je klic po oddanem
        # povpraševanju obvisel v tišini, dokler ni odložila stranka.
        if odgovor.get("success"):
            odgovor["naslednji_korak"] = (
                "Povej številko povpraševanja in da ponudbo pošljemo po e-pošti. "
                "Če stranka nima več vprašanj, se poslovi in pokliči orodje koncaj_pogovor."
            )
        return odgovor

    @function_tool()
    async def koncaj_pogovor(self, context: RunContext, pozdrav: str) -> str:
        """Poslovi se in odloži slušalko. Uporabi, ko je pogovor končan: ko si
        oddala povpraševanje in stranka nima več vprašanj, ali ko se stranka
        sama poslovi. Ne uporabi je sredi pogovora ali kadar stranka še kaj
        sprašuje.

        Args:
            pozdrav: Kratek poslovilni stavek, ki ga poveš, preden se klic konča.
        """
        try:
            await context.session.say(pozdrav)
        except Exception as e:  # noqa: BLE001
            log.debug("poslovilnega stavka ni bilo mogoče izgovoriti: %s", e)

        # Zvok do slušalke potuje z zamikom. Brez tega premora se zadnja beseda
        # odreže in klic se konča sredi pozdrava.
        await asyncio.sleep(ODLOZI_PO_SEKUNDAH)
        await odlozi(agents.get_job_context())
        return "Klic je končan."


ODLOZI_PO_SEKUNDAH = 0.6


def izberi_prepis(kljucne: list[str] | None = None):
    """Azure, kadar je ključ nastavljen; sicer OpenAI.

    Azure prepisuje sproti, med govorom, in strežnik stoji v Italiji. OpenAI
    počaka, da sogovornik neha govoriti, nato pošlje ves posnetek čez Atlantik
    in čaka na odgovor. To je na vsak obrat nekaj sekund tišine v slušalki —
    največji posamezen vir zamika v tem skladu.
    """
    if os.getenv("STT_PONUDNIK", "openai").lower() == "azure" and os.getenv("AZURE_SPEECH_KEY"):
        log.info("prepis: Azure sl-SI")
        return azure.STT(
            language="sl-SI",
            phrase_list=kljucne or None,
            # Ta čas se sešteje z VAD_TISINA in KONEC_MIN — vsi trije čakajo na
            # isto tišino, eden za drugim. Popravljaj jih skupaj, ne enega samega.
            segmentation_silence_timeout_ms=int(os.getenv("STT_TISINA_MS", "500")),
        )

    model = os.getenv("STT_MODEL", "gpt-4o-transcribe")

    dodatno: dict = {}
    if kljucne:
        # Po telefonu je zvok 8 kHz. Imena storitev in blagovne znamke so prav
        # tiste besede, ki jih prepis najpogosteje zgreši — in hkrati edine, od
        # katerih je odvisen odgovor. Seznam pride iz kataloga na strežniku.
        dodatno["prompt"] = (
            "Pogovor v slovenščini s podjetjem. Pogoste besede: "
            + ", ".join(kljucne[:30])
            + "."
        )

        # keywords sprejmeta samo gpt-transcribe in gpt-live-transcribe. Drugim
        # modelom jih vtakniti pomeni ValueError ob vsakem klicu in nem telefon,
        # zato jih raje izpustimo — prompt zgoraj usmerja prepis tudi brez njih.
        if model.startswith(("gpt-transcribe", "gpt-live-transcribe")):
            dodatno["keywords"] = kljucne

    # Telefonska linija šumi. near_field je za slušalko ob ušesu; far_field bi
    # bil za mikrofon v prostoru.
    zmanjsanje_suma = os.getenv("STT_SUM")
    if zmanjsanje_suma:
        dodatno["noise_reduction_type"] = zmanjsanje_suma

    # Sproten prepis prek websocketa namesto čakanja na konec povedi. Obdrži
    # isti model, a odreže velik del tistih 963 ms — brez menjave na Azure.
    if os.getenv("STT_SPROTNO") == "1":
        dodatno["use_realtime"] = True

    log.info(
        "prepis: OpenAI %s, %d ključnih besed, %s",
        model,
        len(kljucne or []),
        "keywords vklopljen" if "keywords" in dodatno else "keywords ni podprt",
    )
    return openai.STT(model=model, language="sl", **dodatno)


def izberi_glas():
    """Azure, kadar je ključ nastavljen; sicer OpenAI.

    Azure ima prava slovenska glasova in pravilno prebere števila, zato je za
    produkcijo edina smiselna izbira. OpenAI je tu zato, da se da sklad
    preizkusiti takoj, brez čakanja na še en račun — slovenščino bere s tujim
    naglasom in telefonskih številk ne izgovori pravilno.
    """
    if os.getenv("AZURE_SPEECH_KEY"):
        return azure.TTS(
            voice=os.getenv("AZURE_TTS_VOICE", "sl-SI-PetraNeural"),
            language="sl-SI",
            # 24 kHz je privzetek pri Azure in zveni dobro. 16000 je vredno
            # poskusiti, če se pri daljših odgovorih pojavi praskanje.
            sample_rate=int(os.getenv("TTS_SAMPLE_RATE", "24000")),
        )

    log.warning("AZURE_SPEECH_KEY ni nastavljen — uporabljam OpenAI glas (slabsa slovenscina)")
    return openai.TTS(
        model=os.getenv("TTS_MODEL", "gpt-4o-mini-tts"),
        voice=os.getenv("TTS_VOICE", "shimmer"),
        instructions=(
            "Govori v slovenščini, naravno in prijazno, z zmernim tempom. "
            "Telefonske številke beri po števkah."
        ),
    )


async def preberi_nastavitve() -> dict:
    """Prenese sistemski prompt s strežnika, da je enak kot pri besedilnem klepetu.

    Mora biti asinhrono. Prej je bil tu navaden httpx.post, ki ustavi celotno
    zanko dogodkov, dokler gostovanje ne odgovori — in ustavi jo za vse klice
    hkrati, ne le za tega.
    """
    r = await odjemalec().post(f"{TOOLS_BASE_URL}/ai/agent-config.php")
    r.raise_for_status()
    return r.json()


async def stevilka_klicatelja(ctx: agents.JobContext) -> str:
    """Prebere številko, s katere kdo kliče, iz same telefonske povezave.

    LiveKit jo zapiše kot lastnost udeleženca SIP, zato je znana, še preden
    kdo spregovori. Stranki je tako ni treba narekovati — narekovanje po zvoku
    je najpogostejši vir napak v povpraševanju, ker se števke zamenjajo in
    ponudba odide v prazno.

    Klicatelj sme številko skriti. Takrat je vrnjen prazen niz in stranko za
    številko vseeno vprašamo.
    """
    try:
        await ctx.connect()
        udelezenec = await asyncio.wait_for(ctx.wait_for_participant(), timeout=10.0)
    except Exception as e:  # noqa: BLE001
        log.warning("udeleženca ni bilo mogoče prebrati: %s", e)
        return ""

    stevilka = (udelezenec.attributes.get("sip.phoneNumber") or "").strip()

    # Zasilna pot: LiveKit identiteto udeleženca SIP sestavi iz številke
    # ("sip_+38641234567"). Kadar lastnosti ni, je številka pogosto še vedno tu.
    if not stevilka:
        identiteta = (udelezenec.identity or "").strip()
        if identiteta.startswith("sip_"):
            stevilka = identiteta[4:].strip()

    # Imena lastnosti gredo v dnevnik, vrednosti ne. Iz imen se vidi, kaj je
    # LiveKit sploh poslal, brez tega pa se manjkajoča številka išče na slepo.
    # Vrednosti so telefonske številke in v dnevnik ne sodijo.
    log.info(
        "udeleženec %s, lastnosti SIP: %s, številka %s",
        udelezenec.kind,
        sorted(k for k in udelezenec.attributes if k.startswith("sip.")),
        "znana" if stevilka else "SKRITA ALI NEZNANA",
    )
    return stevilka


async def straza(ctx: agents.JobContext, session: AgentSession, sekund_max: int, zakljucek: str) -> None:
    """Zaključi klic, ki traja predolgo.

    Brez tega lahko ena sama odprta linija teče ure in vleče minute pri štirih
    ponudnikih hkrati — namerno ali pa zato, ker je kdo odložil slušalko poleg
    telefona. Pol minute prej pride opozorilo, da zaključek ne pride sredi
    stavka nekoga, ki resno povprašuje.
    """
    if sekund_max <= 0:
        return

    opozori_ob = max(1, sekund_max - 30)
    await asyncio.sleep(opozori_ob)
    try:
        await session.say("Oprostite, tale klic bom morala kmalu zaključiti.")
    except Exception as e:  # noqa: BLE001
        log.debug("opozorila ni bilo mogoče izgovoriti: %s", e)

    await asyncio.sleep(sekund_max - opozori_ob)
    try:
        await session.say(zakljucek)
    except Exception as e:  # noqa: BLE001
        log.debug("zaključka ni bilo mogoče izgovoriti: %s", e)

    await odlozi(ctx)


def nastavitve_modela() -> dict:
    """Sestavi nastavitve modela, ki odgovarja.

    Zamenjava modela je ena vrstica v .env, ker je to edina odločitev, ki jo je
    treba preizkusiti s pravimi klici in ne prebrati iz preglednice.

    Novejše družine razmišljajo, preden odgovorijo. Po telefonu to slišiš kot
    tišino, zato je razmislek privzeto na najnižji stopnji — sogovornik čaka v
    živo in nekaj desetink je več vredno od malenkost boljše ubeseditve.
    """
    izbrano: dict = {"model": os.getenv("LLM_MODEL", "gpt-4o-mini")}

    # "auto" ali prazno pomeni, da temperature ne pošljemo. Nekateri modeli je
    # ne sprejmejo in klic pade — takrat vpiši auto namesto številke.
    temperatura = os.getenv("LLM_TEMPERATURE", "0.6").strip().lower()
    if temperatura not in ("", "auto"):
        izbrano["temperature"] = float(temperatura)

    for kljuc, spremenljivka in (
        ("reasoning_effort", "LLM_NAPOR"),
        ("verbosity", "LLM_OBSEZNOST"),
    ):
        vrednost = os.getenv(spremenljivka)
        if vrednost:
            izbrano[kljuc] = vrednost

    # Sistemski prompt meri okrog 8 KB in gre v vsak obrat. S stalnim ključem ga
    # ponudnik hrani predpomnjenega: nižji čas do prve besede in nižja cena.
    izbrano["prompt_cache_key"] = os.getenv("LLM_PREDPOMNILNIK", "tatjana-telefon")

    log.info("model: %s", izbrano["model"])
    return izbrano


def nastavljive_izboljsave() -> dict:
    """Izboljšave, ki jih je treba izmeriti, preden postanejo privzete.

    Vsaka od njih lahko odziv pospeši ali pa poslabša razumevanje — kaj od tega
    se zgodi, je odvisno od linije in od tega, kako govorijo pravi klicatelji.
    Ko jih je bilo več vklopljenih hkrati, se ni dalo ugotoviti, katera je kaj
    naredila. Zato so privzeto izklopljene: vklopi eno, opravi nekaj klicev,
    primerjaj, šele nato naslednjo.

    Vklop v .env, nato lk agent update-secrets.
    """
    izbrano: dict = {}

    # Model začne sestavljati odgovor, še preden sogovornik utihne. Prihrani
    # skoraj sekundo, a odgovori na nedokončano poved, če se ta konča drugače.
    if os.getenv("PREDCASNO") == "1":
        izbrano["preemptive_generation"] = True

    for kljuc, spremenljivka in (
        # Koliko tišine pomeni "sogovornik je končal".
        ("min_endpointing_delay", "KONEC_MIN"),
        ("max_endpointing_delay", "KONEC_MAX"),
        # Koliko govora od sogovornika utiša asistentko sredi stavka. Višje
        # pomeni manj sekanja od šuma na liniji, a počasnejši odziv na pravo
        # prekinitev.
        ("min_interruption_duration", "PREKIN_SEK"),
        ("false_interruption_timeout", "PREKIN_LAZNA"),
    ):
        vrednost = os.getenv(spremenljivka)
        if vrednost:
            izbrano[kljuc] = float(vrednost)

    besede = os.getenv("PREKIN_BESEDE")
    if besede:
        izbrano["min_interruption_words"] = int(besede)

    if izbrano:
        log.info("vklopljene izboljšave: %s", sorted(izbrano))
    return izbrano


async def odlozi(ctx: agents.JobContext) -> None:
    """Konča klic.

    Zapremo samo sobo. Seje tu ni mogoče zapreti: ta funkcija teče tudi znotraj
    orodja koncaj_pogovor, seja pa čaka, da se orodje konča — in orodje bi
    čakalo, da se zapre seja. Klic se zagozdi in nihče ne odloži.

    Cena je pet opozoril "room session transport is closed" v dnevniku, ker seja
    še nekaj trenutkov pošilja dogodke v sobo, ki je ni več. Napake to niso.
    Delujoča prekinitev je vredna več od čistega dnevnika.
    """
    await ctx.delete_room()


server = agents.AgentServer()


@server.rtc_session(agent_name="tatjana")
async def vstopna_tocka(ctx: agents.JobContext) -> None:
    zacetek_klica = time.perf_counter()

    # Oboje poteka hkrati. Zaporedno sta to dve čakanji na gostovanje, preden
    # asistentka sploh spregovori — sogovornik pa medtem posluša tišino.
    nastavitve, klicatelj = await asyncio.gather(
        preberi_nastavitve(),
        stevilka_klicatelja(ctx),
    )
    vratar = await vprasaj_vratarja("start", klicatelj)
    meritev("priprava_klica", zacetek_klica)

    # Trajanje se sporoči ob koncu, ne ob začetku: klic, ki se prekine po treh
    # sekundah, ne sme šteti enako kot desetminutni.
    async def ob_koncu() -> None:
        await vprasaj_vratarja("end", klicatelj, int(time.perf_counter() - zacetek_klica))

    ctx.add_shutdown_callback(ob_koncu)

    navodila = nastavitve["system_prompt"]
    if klicatelj:
        navodila += f"""

## Številka, s katere kličejo
Stranka kliče s številke {klicatelj}. Za to številko je ne sprašuj — že jo imaš.
Ko zbiraš podatke za povpraševanje, jo samo potrdi, prebrano po skupinah s
premori, na primer: "Za povratni klic uporabim številko, s katere kličete?"
Če stranka pove drugo številko, zapiši tisto, ki jo pove.
"""

    session = AgentSession(
        stt=izberi_prepis(nastavitve.get("stt_keywords") or []),
        # 0,3 je zvenelo kot posnetek: model je vedno izbral najbolj pricakovano
        # besedo. 0,6 da vec raznolikosti v ubeseditvi. Cene to ne ogrozi, ker
        # jih model prepise iz orodja, ne sestavlja sam - a prav to preveri,
        # ce vrednost se dvignes.
        llm=openai.LLM(**nastavitve_modela()),
        tts=izberi_glas(),
        # Zazna, kdaj je sogovornik nehal govoriti. Brez tega agent skače v besedo.
        #
        # Te tri vrednosti so edine, ki jih ni mogoče nastaviti vnaprej — odvisne
        # so od tega, kako hitro govorijo pravi klicatelji. Slovenci sredi stavka
        # pogosto premolknejo; prekratek premor pomeni, da asistentka skoči v
        # besedo, predolg pa neroden molk. Po nekaj klicih popravi v .env.
        vad=silero.VAD.load(
            min_silence_duration=float(os.getenv("VAD_TISINA", "0.55")),
            min_speech_duration=float(os.getenv("VAD_GOVOR", "0.10")),
            activation_threshold=float(os.getenv("VAD_PRAG", "0.5")),
        ),
        **nastavljive_izboljsave(),
    )

    zacetek = time.perf_counter()
    await session.start(room=ctx.room, agent=TelefonskiAsistent(navodila, klicatelj))
    meritev("zagon_seje", zacetek)

    if not vratar.get("allow", True):
        # Razloga ne povemo. Kdor mejo namerno preizkuša, iz vljudnega stavka
        # ne izve, katera meja je bila dosežena in koliko je do nje.
        await session.say(
            "Oprostite, tega klica vam danes ne morem sprejeti. Pišite nam prosim "
            "po elektronski pošti, pa vam odgovorimo. Lep pozdrav."
        )
        await odlozi(ctx)
        return

    await session.say(nastavitve["greeting"])

    # Če vratar ni odgovoril, mejo vseeno postavimo. Nedosegljiv strežnik na
    # gostovanju ne sme pomeniti, da linija ostane odprta brez konca.
    sekund_max = int(vratar.get("max_seconds") or os.getenv("KLIC_MAX_SEK", "600"))
    nadzor = asyncio.create_task(
        straza(ctx, session, sekund_max, "Hvala za klic in lep pozdrav.")
    )

    async def ustavi_strazo() -> None:
        nadzor.cancel()

    ctx.add_shutdown_callback(ustavi_strazo)


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO)
    agents.cli.run_app(server)
