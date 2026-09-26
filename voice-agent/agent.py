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
import random
import time

import httpx
from dotenv import load_dotenv
from livekit import agents
from livekit.agents import Agent, AgentSession, RunContext, function_tool, llm
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


async def zapisi_pogovor(session: AgentSession, klic_id: str) -> None:
    """Pošlje potek pogovora v dnevnik podjetja.

    Telefonski agent teče v oblaku in orodja kliče po HTTP, zato se je v dnevnik
    podjetja zapisalo, katera orodja je klical, ne pa, kaj je povedal.
    admin/pogovori.php je tako pokrival samo klepet na strani. Stranka, ki
    plačuje vzdrževanje, hoče videti, kaj je asistentka povedala njenim
    klicateljem — in "poglej v LiveKit" ni odgovor.

    Zapis gre v isti dnevnik in v isti obliki kot klepet, zato ga obstoječa
    stran prikaže brez spremembe.
    """
    try:
        zgodovina = session.history.items
    except Exception as e:  # noqa: BLE001
        log.warning("zgodovine pogovora ni bilo mogoče prebrati: %s", e)
        return

    obrati: list[dict] = []
    tekoci: dict | None = None

    for vnos in zgodovina:
        vloga = getattr(vnos, "role", None)
        if vloga is None:
            # Klic orodja ni sporočilo; zabeležimo samo ime, da se vidi, ali je
            # asistentka ceno preverila ali si jo izmislila.
            ime = getattr(vnos, "name", None)
            if ime and tekoci is not None:
                tekoci["tool_calls"].append(str(ime))
            continue

        besedilo = besedilo_sporocila(vnos)
        if not besedilo:
            continue

        if vloga == "user":
            if tekoci is not None:
                obrati.append(tekoci)
            tekoci = {"user_message": besedilo, "assistant_message": "", "tool_calls": []}
        elif vloga == "assistant":
            if tekoci is None:
                # Pozdrav pride pred prvim vprašanjem stranke.
                tekoci = {"user_message": "", "assistant_message": "", "tool_calls": []}
            # Daljši odgovor pride v več delih; sestavimo ga nazaj v enega.
            tekoci["assistant_message"] = (tekoci["assistant_message"] + " " + besedilo).strip()

    if tekoci is not None:
        obrati.append(tekoci)

    if not obrati:
        return

    try:
        await odjemalec().post(
            f"{TOOLS_BASE_URL}/ai/log-conversation.php",
            json={"call_id": klic_id, "source": "telefon", "turns": obrati},
        )
        log.info("pogovor zapisan: %d obratov", len(obrati))
    except Exception as e:  # noqa: BLE001
        # Klic je že končan. Neuspel zapis ne sme ničesar podreti.
        log.warning("pogovora ni bilo mogoče zapisati: %s", e)


def besedilo_sporocila(vnos) -> str:
    """Vsebina sporočila je lahko niz ali seznam delov."""
    vsebina = getattr(vnos, "content", None)
    if isinstance(vsebina, str):
        return vsebina.strip()
    if isinstance(vsebina, list):
        deli = [d.strip() for d in vsebina if isinstance(d, str) and d.strip()]
        return " ".join(deli)
    return ""


def meritev(kaj: str, zacetek: float) -> None:
    """Zapiše trajanje koraka.

    Brez tega se optimizira po občutku. Po nekaj pravih klicih se iz dnevnika
    vidi, ali čas požre prepis, model, orodje ali govor — in samo tisto je
    vredno popravljati.
    """
    log.info("MERITEV %s %.0f ms", kaj, (time.perf_counter() - zacetek) * 1000)


# Mašila pred klicem orodja. Povedo nekaj — "preverim", "zapišem" — in
# zapolnijo čas, ko res nekaj teče. Prazni medmeti sem ne sodijo: "mhm" v
# tišini pogovor razseka, namesto da bi tekel.
#
# Ena sama stalna besedna zveza je sama po sebi znak, da govoriš s strojem —
# človek vsakič reče nekaj malo drugače.
MAŠILA = {
    "isce": ["Trenutek, preverim.", "Samo hip, pogledam v ponudbo.", "Moment, pogledam."],
    "projekt": ["Samo trenutek, pogledam.", "Trenutek, poiščem.", "Hip, preverim."],
    "podatki": ["Trenutek.", "Samo hip.", "Moment."],
    "zapis": ["Zabeležim.", "Dobro, zapišem.", "Zapišem."],
}

# Kar je bilo nazadnje izrečeno, tokrat ne pride na vrsto. Dve enaki besedi
# zapored sta bolj opazni kot ena sama ponovljena čez pet stavkov.
_zadnje: dict[str, str] = {}


def izberi_masilo(sklop: str) -> str:
    moznosti = MAŠILA[sklop]
    izbira = [m for m in moznosti if m != _zadnje.get(sklop)] or moznosti
    _zadnje[sklop] = random.choice(izbira)
    return _zadnje[sklop]


async def mašilo(context: RunContext, sklop: str) -> None:
    """Reče kratko potrdilo, medtem ko v ozadju teče klic orodja.

    Brez tega je v slušalki ena do dve sekundi tišine in klic zveni pokvarjeno.
    Klic s tem ni hitrejši, a sogovornik ve, da se nekaj dogaja — enako kot
    človek reče "trenutek, preverim".
    """
    try:
        context.session.say(izberi_masilo(sklop), add_to_chat_ctx=False)
    except Exception as e:  # noqa: BLE001 — mašilo ne sme nikoli podreti klica
        log.debug("mašila ni bilo mogoče izgovoriti: %s", e)


# Nadomestki, ki jih model vpiše, kadar podatka nima. Zapisano povpraševanje s
# takim poljem je slabše od nobenega: nekdo ga bo poskusil poklicati.
NADOMESTKI = {
    "ime", "ime priimek", "ime in priimek", "neznano", "ni podano", "ni znano",
    "stranka", "n/a", "na", "xxx", "test", "brez", "-", "--", "?", "...",
    "telefon", "telefonska", "telefonska številka", "e-pošta", "email",
    "example@example.com", "test@test.com", "info@example.com",
}


def _je_nadomestek(vrednost: str) -> bool:
    return (vrednost or "").strip().lower() in NADOMESTKI


class TelefonskiAsistent(Agent):
    def __init__(
        self,
        navodila: str,
        telefon_klicatelja: str = "",
        identiteta: str = "",
        prevezi_na: str = "",
    ) -> None:
        super().__init__(instructions=navodila)
        self.telefon_klicatelja = telefon_klicatelja
        self.identiteta = identiteta
        self.prevezi_na = prevezi_na

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
        await mašilo(context, "isce")
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
        await mašilo(context, "projekt")
        return await poklici_orodje("order-lookup", {"order_id": order_id, "verify": verify})

    @function_tool()
    async def get_business_info(self, context: RunContext, info_type: str = "hours") -> dict:
        """Podatki o poslovanju: delovni čas, roki izdelave in potek dela, pogoji plačila.

        Args:
            info_type: 'hours' za delovni čas, 'delivery' za roke in potek dela, 'payments' za plačilo.
        """
        await mašilo(context, "podatki")
        return await poklici_orodje("business-info", {"info_type": info_type})

    @function_tool()
    async def knowledge_lookup(self, context: RunContext, query: str) -> dict:
        """Poišče odgovor na vprašanje o podjetju, ki ni o ceni ali ponudbi:
        pogoji, potek dela, kaj potrebujemo od stranke, omejitve, pogosta
        vprašanja. Uporabi vedno, kadar stranka vpraša kaj o načinu dela ali
        sodelovanju in odgovora ne najdeš v ponudbi. Nikoli ne ugibaj.

        Args:
            query: Vprašanje stranke z njenimi besedami, na primer
                'ali delate tudi za društva'.
        """
        await mašilo(context, "podatki")
        return await poklici_orodje("knowledge-lookup", {"query": query})

    @function_tool()
    async def prosti_termini(self, context: RunContext) -> dict:
        """Vrne proste termine za sestanek. Uporabi, ko stranka želi sestanek,
        ogled ali posvet. Stranki povej termine iz polja 'spoken' in ne več kot
        dva ali tri naenkrat — po telefonu si četrtega nihče ne zapomni.
        Termina si nikoli ne izmisli.
        """
        await mašilo(context, "isce")
        return await poklici_orodje("appointment", {"action": "find"})

    @function_tool()
    async def rezerviraj_termin(
        self,
        context: RunContext,
        starts_at: str,
        name: str,
        phone: str = "",
        email: str | None = None,
        note: str | None = None,
    ) -> dict:
        """Rezervira izbrani termin. Uporabi šele, ko je stranka termin potrdila
        in si zbrala ime. Pred rezervacijo na kratko ponovi, kdaj je termin.

        Potrebuješ SAMO ime in telefonsko številko. E-pošta je neobvezna — če je
        stranka nima ali je noče dati, termin vseeno rezerviraj. Zahteva po vseh
        treh podatkih velja za povpraševanje, ne za termin.

        Args:
            starts_at: Termin natanko tako, kot ga je vrnilo orodje za proste termine.
            name: Ime in priimek stranke.
            phone: Telefonska številka stranke. Če si jo v pogovoru izvedela,
                jo VPIŠI. Prazno pusti samo takrat, kadar je stranka ni povedala
                in kliče po telefonu — takrat se vzame številka, s katere kliče.
            email: Neobvezno, za potrditev po e-pošti.
            note: Kaj želi stranka na sestanku.
        """
        await mašilo(context, "zapis")

        telefon = (phone or self.telefon_klicatelja or "").strip()
        if not telefon:
            return {
                "success": False,
                "data": None,
                "error": "Manjka telefonska številka. Vprašaj stranko zanjo.",
            }

        vsebina = {
            "action": "book",
            "starts_at": starts_at,
            "name": name,
            "phone": telefon,
        }
        for kljuc, vrednost in (("email", email), ("note", note)):
            if vrednost:
                vsebina[kljuc] = vrednost

        return await poklici_orodje("appointment", vsebina)

    @function_tool()
    async def submit_inquiry(
        self,
        context: RunContext,
        name: str,
        phone: str,
        email: str,
        potrjeno: bool,
        product: str | None = None,
        quantity: str | None = None,
        note: str | None = None,
    ) -> dict:
        """Odda povpraševanje, da podjetje stranki pripravi ponudbo. Uporabi
        šele, ko imaš ime, telefonsko številko in e-pošto, in ko je stranka
        potrdila, da naj povpraševanje oddaš. Povpraševanje ni naročilo.

        Args:
            name: Ime in priimek stranke ali naziv podjetja.
            phone: Telefonska številka za povratni klic. Če si jo v pogovoru
                izvedela, jo VPIŠI. Prazno pusti samo takrat, kadar je stranka ni
                povedala in kliče po telefonu.
            email: E-poštni naslov, na katerega gre ponudba. Nikoli sem ne vpiši
                telefonske številke; brez veljavnega naslova povpraševanja ni.
            potrjeno: True samo takrat, ko si stranki prebrala nazaj ime, telefon,
                e-pošto in kaj potrebuje, IN je ona to izrecno potrdila. Če tega
                povzetka še ni bilo, vpiši False — orodje te bo opomnilo.
            product: Kaj stranka potrebuje, z njenimi besedami.
            quantity: Obseg, če ga je navedla.
            note: Vse, kar je povedala o projektu — rok, obstoječa stran, panoga, proračun.
        """
        # Povzetek in potrditev sta edino, kar loči zapisano povpraševanje od
        # napačno slišanega. Pravilo je bilo doslej samo v promptu in ga je model
        # kdaj preskočil — oddal je takoj, ko je izvedel ime. Zato zapora tu.
        if not potrjeno:
            return {
                "success": False,
                "data": None,
                "error": "Povpraševanje še ni potrjeno.",
                "naslednji_korak": (
                    "Preberi stranki nazaj ime, telefonsko številko, e-pošto in kaj "
                    "potrebuje, ter počakaj, da to izrecno potrdi. Šele nato pokliči "
                    "to orodje znova s potrjeno=True."
                ),
            }

        # Model si je v enem pogovoru izmislil nadomestke, da je orodje sploh
        # poklical. Kar ni slišal, mora vprašati, ne zapolniti.
        manjka = [
            ime_polja
            for ime_polja, vrednost in (("ime", name), ("telefonsko številko", phone), ("e-pošto", email))
            if not (vrednost or "").strip() or _je_nadomestek(vrednost)
        ]
        if manjka:
            return {
                "success": False,
                "data": None,
                "error": "Manjka: " + ", ".join(manjka) + ".",
                "naslednji_korak": "Vprašaj stranko za to in ne vpisuj nadomestkov.",
            }

        await mašilo(context, "zapis")

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

        vsebina = {"name": name, "phone": telefon, "email": naslov, "confirmed": True}
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
    async def predaj_cloveku(self, context: RunContext, razlog: str) -> dict:
        """Preveže klic sodelavcu. Uporabi, ko stranka izrecno želi govoriti s
        človekom, ko se pritožuje, ali ko ji dvakrat zapored nisi znala
        odgovoriti. Preden orodje pokličeš, stranki povej, da jo prevezuješ.

        Args:
            razlog: Zakaj prevezuješ, z nekaj besedami. Gre v dnevnik, ne stranki.
        """
        if not self.prevezi_na:
            # Zunaj delovnega časa ali brez nastavljene številke. Zvonjenje v
            # prazno je slabše od zabeležke, zato model dobi navodilo namesto
            # napake — sicer se opraviči in pogovor obvisi.
            return {
                "success": False,
                "data": None,
                "error": "Prevezovanje zdaj ni mogoče.",
                "naslednji_korak": (
                    "Povej, da sodelavca zdaj ni na voljo, in ponudi, da zabeležiš "
                    "povpraševanje ter da vas pokličejo nazaj."
                ),
            }

        log.info("preveza na sodelavca, razlog: %s", razlog)
        try:
            await agents.get_job_context().transfer_sip_participant(
                self.identiteta,
                self.prevezi_na,
                play_dialtone=True,
            )
        except Exception as e:  # noqa: BLE001
            # Preveza lahko odpove pri ponudniku SIP. Klicatelj je takrat še
            # vedno na liniji in mora nekaj slišati.
            log.warning("preveza ni uspela: %s", e)
            return {
                "success": False,
                "data": None,
                "error": "Prevezovanje ni uspelo.",
                "naslednji_korak": (
                    "Povej, da te prevezovanje ni uspelo, in ponudi, da zabeležiš "
                    "povpraševanje ter da vas pokličejo nazaj."
                ),
            }

        return {"success": True, "data": {"transferred": True}, "error": None}

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


async def stevilka_klicatelja(ctx: agents.JobContext) -> tuple[str, str]:
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
        return "", ""

    identiteta_udelezenca = (udelezenec.identity or "").strip()
    stevilka = (udelezenec.attributes.get("sip.phoneNumber") or "").strip()

    # Zasilna pot: LiveKit identiteto udeleženca SIP sestavi iz številke
    # ("sip_+38641234567"). Kadar lastnosti ni, je številka pogosto še vedno tu.
    if not stevilka and identiteta_udelezenca.startswith("sip_"):
        stevilka = identiteta_udelezenca[4:].strip()

    # Imena lastnosti gredo v dnevnik, vrednosti ne. Iz imen se vidi, kaj je
    # LiveKit sploh poslal, brez tega pa se manjkajoča številka išče na slepo.
    # Vrednosti so telefonske številke in v dnevnik ne sodijo.
    log.info(
        "udeleženec %s, lastnosti SIP: %s, številka %s",
        udelezenec.kind,
        sorted(k for k in udelezenec.attributes if k.startswith("sip.")),
        "znana" if stevilka else "SKRITA ALI NEZNANA",
    )
    return stevilka, identiteta_udelezenca


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
    nastavitve, podatki_klicatelja = await asyncio.gather(
        preberi_nastavitve(),
        stevilka_klicatelja(ctx),
    )
    klicatelj, identiteta = podatki_klicatelja
    # Meje klicev varujejo telefonsko porabo. Simulacija ne telefonira, poleg
    # tega v njej ni udeleženca SIP — vse seje bi se štele pod isto oznako za
    # skrito številko in po desetih bi vratar zavrnil vse nadaljnje. Prvi tak
    # zagon je zato pokvaril vsakega naslednjega.
    simulacija = ctx.simulation_context() is not None
    if simulacija:
        log.info("simulacija: meje klicev preskočene")
        vratar = {"allow": True}
    else:
        vratar = await vprasaj_vratarja("start", klicatelj)

    meritev("priprava_klica", zacetek_klica)

    # Trajanje se sporoči ob koncu, ne ob začetku: klic, ki se prekine po treh
    # sekundah, ne sme šteti enako kot desetminutni.
    async def ob_koncu() -> None:
        if simulacija:
            return
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
    await session.start(
        room=ctx.room,
        agent=TelefonskiAsistent(
            navodila,
            klicatelj,
            identiteta,
            nastavitve.get("transfer_phone") or "",
        ),
    )
    meritev("zagon_seje", zacetek)

    if not vratar.get("allow", True):
        # Razloga ne povemo. Kdor mejo namerno preizkuša, iz vljudnega stavka
        # ne izve, katera meja je bila dosežena in koliko je do nje.
        # Klicatelj brez e-pošte ali tisti, ki hoče človeka, s samim e-naslovom
        # ni nikamor prišel. Zato tudi telefonska številka.
        telefon = (nastavitve.get("business_phone") or "").strip()
        posta = (nastavitve.get("business_email") or "").strip()
        kam = " ali ".join(x for x in (
            ("pokličete na " + telefon) if telefon else "",
            ("pišete na " + posta) if posta else "",
        ) if x)

        await session.say(
            "Oprostite, tega klica vam danes ne morem sprejeti. "
            + (("Prosim, da " + kam + ". ") if kam else "")
            + "Lep pozdrav."
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

    async def zapisi_ob_koncu() -> None:
        if simulacija:
            return
        await zapisi_pogovor(session, ctx.job.id if ctx.job else "-")

    ctx.add_shutdown_callback(zapisi_ob_koncu)


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO)
    agents.cli.run_app(server)
