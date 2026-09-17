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
        email: str,
        phone: str | None = None,
        product: str | None = None,
        quantity: str | None = None,
        note: str | None = None,
    ) -> dict:
        """Odda povpraševanje, da podjetje stranki pripravi ponudbo. Uporabi
        šele, ko imaš ime IN e-pošto, in ko je stranka potrdila, da naj
        povpraševanje oddaš. Povpraševanje ni naročilo.

        Args:
            name: Ime in priimek stranke ali naziv podjetja.
            email: E-poštni naslov, na katerega gre ponudba.
            phone: Telefonska številka za povratni klic. Pri klicu je ne navajaj —
                pusti prazno in vzame se številka, s katere stranka kliče.
                Izpolni jo samo, če stranka izrecno pove drugo številko.
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

        vsebina = {"name": name, "phone": telefon, "email": email}
        for kljuc, vrednost in (("product", product), ("quantity", quantity), ("note", note)):
            if vrednost:
                vsebina[kljuc] = vrednost
        return await poklici_orodje("submit-inquiry", vsebina)


def izberi_prepis():
    """Azure, kadar je ključ nastavljen; sicer OpenAI.

    Azure prepisuje sproti, med govorom, in strežnik stoji v Italiji. OpenAI
    počaka, da sogovornik neha govoriti, nato pošlje ves posnetek čez Atlantik
    in čaka na odgovor. To je na vsak obrat nekaj sekund tišine v slušalki —
    največji posamezen vir zamika v tem skladu.
    """
    if os.getenv("AZURE_SPEECH_KEY"):
        return azure.STT(
            language="sl-SI",
            # Koliko tišine Azure šteje za konec povedi. Privzetih 500 ms je
            # za telefon dobro izhodišče; nižje pomeni hitrejši odziv, a več
            # prekinjanja sredi stavka.
            segmentation_silence_timeout_ms=int(os.getenv("STT_TISINA_MS", "500")),
        )

    log.warning("AZURE_SPEECH_KEY ni nastavljen — uporabljam OpenAI prepis (pocasnejsi)")
    return openai.STT(model=os.getenv("STT_MODEL", "gpt-4o-transcribe"), language="sl")


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
            # Telefonska linija prenese 8 kHz. Privzetih 24 kHz pomeni trikrat
            # več podatkov in dvojno prevzorčenje, kar se sliši kot praskanje
            # in preskakovanje pri daljših odgovorih.
            sample_rate=int(os.getenv("TTS_SAMPLE_RATE", "16000")),
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


def preberi_nastavitve() -> dict:
    """Prenese sistemski prompt s strežnika, da je enak kot pri besedilnem klepetu."""
    r = httpx.post(
        f"{TOOLS_BASE_URL}/ai/agent-config.php",
        headers={"X-Tool-Secret": TOOL_SECRET},
        timeout=10.0,
    )
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
    # Številke ne pišemo v dnevnik. Dnevniki se berejo, pošiljajo in hranijo
    # dlje, kot kdo pričakuje, za štetje pa zadošča, ali je znana ali ne.
    log.info("klic s %s številke", "znane" if stevilka else "skrite")
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

    await ctx.delete_room()


server = agents.AgentServer()


@server.rtc_session(agent_name="tatjana")
async def vstopna_tocka(ctx: agents.JobContext) -> None:
    nastavitve = preberi_nastavitve()

    zacetek_klica = time.perf_counter()
    klicatelj = await stevilka_klicatelja(ctx)
    vratar = await vprasaj_vratarja("start", klicatelj)

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
        stt=izberi_prepis(),
        # 0,3 je zvenelo kot posnetek: model je vedno izbral najbolj pricakovano
        # besedo. 0,6 da vec raznolikosti v ubeseditvi. Cene to ne ogrozi, ker
        # jih model prepise iz orodja, ne sestavlja sam - a prav to preveri,
        # ce vrednost se dvignes.
        llm=openai.LLM(
            model=os.getenv("LLM_MODEL", "gpt-4o-mini"),
            temperature=float(os.getenv("LLM_TEMPERATURE", "0.6")),
        ),
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
        # Model začne sestavljati odgovor že med tem, ko sogovornik še govori.
        # Če ta konča drugače, kot je model predvidel, se delo zavrže. V večini
        # primerov pa je odgovor pripravljen, preden sogovornik utihne.
        preemptive_generation=True,
        # Koliko tišine pomeni "sogovornik je končal". Prekratko pomeni, da
        # asistentka skoči v besedo, predolgo pa neroden molk.
        min_endpointing_delay=float(os.getenv("KONEC_MIN", "0.4")),
        max_endpointing_delay=float(os.getenv("KONEC_MAX", "3.0")),
        # Telefonska linija šumi. Privzeto pol sekunde zvoka že velja za
        # prekinitev, zato asistentko sredi daljšega odgovora utiša vsak hrup
        # v ozadju — v slušalki se to sliši kot sekanje in preskakovanje.
        # Zato zahtevamo daljši in razumljen govor, preden jo utišamo.
        min_interruption_duration=float(os.getenv("PREKIN_SEK", "0.7")),
        min_interruption_words=int(os.getenv("PREKIN_BESEDE", "2")),
        # Če prekinitev ni bila prava, naj pove stavek do konca.
        resume_false_interruption=True,
        false_interruption_timeout=float(os.getenv("PREKIN_LAZNA", "1.5")),
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
        await ctx.delete_room()
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
