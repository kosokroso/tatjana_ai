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

import logging
import os

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


async def poklici_orodje(pot: str, vsebina: dict) -> dict:
    """Pokliče PHP endpoint in vrne dekodiran odgovor."""
    url = f"{TOOLS_BASE_URL}/tools/{pot}.php"
    try:
        async with httpx.AsyncClient(timeout=TIMEOUT_SEKUND) as client:
            r = await client.post(
                url,
                json=vsebina,
                headers={"X-Tool-Secret": TOOL_SECRET},
            )
            return r.json()
    except Exception as e:  # noqa: BLE001 — karkoli gre narobe, klic mora teči naprej
        log.warning("orodje %s ni odgovorilo: %s", pot, e)
        return {
            "success": False,
            "data": None,
            "error": "Sistem trenutno ne odgovori.",
        }


class TelefonskiAsistent(Agent):
    def __init__(self, navodila: str) -> None:
        super().__init__(instructions=navodila)

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
        return await poklici_orodje("order-lookup", {"order_id": order_id, "verify": verify})

    @function_tool()
    async def get_business_info(self, context: RunContext, info_type: str = "hours") -> dict:
        """Podatki o poslovanju: delovni čas, roki izdelave in potek dela, pogoji plačila.

        Args:
            info_type: 'hours' za delovni čas, 'delivery' za roke in potek dela, 'payments' za plačilo.
        """
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
        šele, ko imaš ime, telefonsko številko IN e-pošto, in ko je stranka
        potrdila, da naj povpraševanje oddaš. Povpraševanje ni naročilo.

        Args:
            name: Ime in priimek stranke ali naziv podjetja.
            phone: Telefonska številka za povratni klic.
            email: E-poštni naslov, na katerega gre ponudba.
            product: Kaj stranka potrebuje, z njenimi besedami.
            quantity: Obseg, če ga je navedla.
            note: Vse, kar je povedala o projektu — rok, obstoječa stran, panoga, proračun.
        """
        vsebina = {"name": name, "phone": phone, "email": email}
        for kljuc, vrednost in (("product", product), ("quantity", quantity), ("note", note)):
            if vrednost:
                vsebina[kljuc] = vrednost
        return await poklici_orodje("submit-inquiry", vsebina)


def preberi_nastavitve() -> dict:
    """Prenese sistemski prompt s strežnika, da je enak kot pri besedilnem klepetu."""
    r = httpx.post(
        f"{TOOLS_BASE_URL}/ai/agent-config.php",
        headers={"X-Tool-Secret": TOOL_SECRET},
        timeout=10.0,
    )
    r.raise_for_status()
    return r.json()


server = agents.AgentServer()


@server.rtc_session(agent_name="tatjana")
async def vstopna_tocka(ctx: agents.JobContext) -> None:
    nastavitve = preberi_nastavitve()

    session = AgentSession(
        # Jezik je izrecno slovenščina. Brez tega model jezik ugiba in po
        # telefonu, kjer je zvok slabši, pogosto zgreši v hrvaščino.
        stt=openai.STT(model=os.getenv("STT_MODEL", "gpt-4o-transcribe"), language="sl"),
        llm=openai.LLM(model=os.getenv("LLM_MODEL", "gpt-4o-mini"), temperature=0.3),
        # Pravi slovenski glas, ne večjezični model. Pravilno prebere števila.
        tts=azure.TTS(
            voice=os.getenv("AZURE_TTS_VOICE", "sl-SI-PetraNeural"),
            language="sl-SI",
        ),
        # Zazna, kdaj je sogovornik nehal govoriti. Brez tega agent skače v besedo.
        vad=silero.VAD.load(),
    )

    await session.start(room=ctx.room, agent=TelefonskiAsistent(nastavitve["system_prompt"]))
    await session.say(nastavitve["greeting"])


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO)
    agents.cli.run_app(server)
