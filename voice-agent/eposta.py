"""Branje in izgovor e-poštnih naslovov po telefonu.

Dva opravila, oba zato, ker po zvoku naslov ne pride cel:

1. `popravi_naslov` sestavi naslov iz tega, kar je stranka narekovala —
   "ka, o, es, afna, ge em a i el, pika, ce o em". Doslej je to pretvarjal model
   sam in se je motil: izpustil je "afna", črko "es" je zapisal kot "se", enkrat
   pa je v polje vpisal telefonsko številko.

2. `izgovori_eposte` naslov pred govorom prebere tako, da ga stranka lahko
   preveri: del pred afno po črkah ("S, T, I"), domeno
   pa z besedami ("afna gmail pika com").

Brez odvisnosti in brez stika s omrežjem, da se da pognati samostojno:
    python test_eposta.py
"""

import re
import unicodedata

# Domene, ki jih narekujejo slovenske stranke. Seznam je kratek namenoma: rabi
# samo za popravek skoraj ujetega zapisa in za to, da jih glas prebere kot
# besedo in ne po črkah.
RAZPOZNANE_DOMENE = (
    "gmail.com",
    "siol.net",
    "telemach.net",
    "t-2.net",
    "amis.net",
    "volja.net",
    "hotmail.com",
    "outlook.com",
    "yahoo.com",
    "icloud.com",
    "gov.si",
    "guest.arnes.si",
    "kreativnisplet.si",
)

# Imena črk, kakor jih stranka izgovori. Kjer prepis izgubi strešico, ostane
# nestrešična oblika pri navadni črki: "ce" je c, ne č. Napačna črka se pokaže
# pri branju nazaj, izpuščena pa ne.
CRKE = {
    "a": "a", "be": "b", "ce": "c", "če": "č", "de": "d", "e": "e",
    "ef": "f", "fe": "f", "ge": "g", "ha": "h", "i": "i", "je": "j",
    "ka": "k", "el": "l", "em": "m", "en": "n", "o": "o", "pe": "p",
    "ku": "q", "kju": "q", "er": "r", "es": "s", "eš": "š", "te": "t",
    "u": "u", "ve": "v", "dvojnive": "w", "iks": "x", "ipsilon": "y",
    "zet": "z", "že": "ž",
}

# "ika", "tika" in "ilka" so "pika", kot jo zapiše prepis po telefonu: začetni
# "p" je šibak kot "s" (22. 10. 2026: "stina tika mavri", "tina ika mauri",
# "stina ilka mavri"). Napačna razlaga je poceni, ker asistentka naslov prebere
# nazaj po črkah; izpuščena pika pa naslov tiho pokvari.
ZNAKI = {
    "pika": ".", "ika": ".", "tika": ".", "ilka": ".",
    "afna": "@", "at": "@", "pri": "@",
    "podčrtaj": "_", "podcrtaj": "_",
    "vezaj": "-", "minus": "-", "pomišljaj": "-", "pomisljaj": "-",
    "plus": "+",
}

STEVKE = {
    "nič": "0", "nic": "0", "ena": "1", "dva": "2", "dve": "2", "tri": "3",
    "štiri": "4", "stiri": "4", "pet": "5", "šest": "6", "sest": "6",
    "sedem": "7", "osem": "8", "devet": "9",
}

# Besede, ki opisujejo zapis in v naslov ne sodijo. Velike in male črke v
# e-pošti ne ločijo, zato "velika" ni podatek.
IZPUSTI = {"velika", "mala", "velik", "črka", "crka", "znak", "vse", "skupaj"}

NASLOV = re.compile(r"^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$")
NASLOV_V_BESEDILU = re.compile(r"[A-Za-zČŠŽčšž0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}")

# Naslov, kakor ga model zapiše, kadar ga namerava izgovoriti: "janez pika
# novak afna gmail pika com", brez afne kot znaka.
#
# Brez tega črkovanje nikoli ne steče. Klic 22. 10. 2026 (`RM_HLZUiN6RXGtp`):
# model je napisal "stinaikmavri afna gmail pika com", `NASLOV_V_BESEDILU` se
# ni ujel in stranka je slišala staro branje z besedami.
#
# Del pred afno sme biti ena beseda ali besede, povezane z ločili. Zaporedja
# navadnih besed nalašč ne ujamemo: "Slišala sem janez afna ..." bi vzelo
# "slišala sem janez" in prebrali bi napačen naslov — to je slabše od tega, da
# črkovanja ni.
IZGOVORJEN_NASLOV = re.compile(
    r"(?<![\w@.])"
    r"([a-zčšž0-9]+(?:\s+(?:pika|ika|tika|ilka|vezaj|podčrtaj|podcrtaj|plus)\s+[a-zčšž0-9]+)*)"
    r"\s+afna\s+"
    r"([a-zčšž0-9]+(?:\s+(?:pika|vezaj)\s+[a-zčšž0-9]+)+)"
    r"(?![\w@])",
    re.IGNORECASE,
)

# Ločila v naslovu, kot se izgovorijo. "@" ni tu: ta loči dva dela in se
# izgovori posebej.
LOCILA_IZGOVOR = {".": "pika", "-": "vezaj", "_": "podčrtaj", "+": "plus"}

# Branje nazaj gre po golih črkah: "S, T, I, N, A".
#
# Brez črkovanja se "mavri" in "mauri" po telefonu slišita enako: 22. 10. 2026
# je prepis zapisal "mauri", asistentka je naslov prebrala z besedami in stranka
# je napačen naslov potrdila. "V" in "U" pa sta različna.
#
# Isti dan zavrnjeno (Luka): sidrne besede ("es kot Slovenija") so predolge,
# imena črk ("es, te") pa niso, kar Slovenec reče. Gola črka je tveganje pri
# glasu, ki jo bere angleško ("ti", "aj") — 11. 10. 2026 je Azure tako bral.


def je_naslov(naslov: str) -> bool:
    """Ali je to sploh e-poštni naslov in ne telefonska številka ali "aaa"."""
    return bool(NASLOV.match(naslov.strip().lower()))


def popravi_naslov(besedilo: str) -> tuple[str, str]:
    """Vrne (naslov, razlog). Razlog je prazen, kadar popravka ni bilo.

    Neprazen razlog pomeni, da naslov ni tak, kot ga je stranka potrdila, zato
    ga mora asistentka prebrati nazaj in počakati na novo potrditev.
    """
    izvirnik = (besedilo or "").strip().lower()
    if not izvirnik:
        return "", ""

    if je_naslov(izvirnik):
        naslov, zamenjana = _popravi_domeno(izvirnik)
        return naslov, "domena popravljena" if zamenjana else ""

    sestavljen = _iz_narekovanja(izvirnik)
    if not je_naslov(sestavljen):
        # Nič uporabnega — naslov vrnemo prazen, da ga klicatelj zavrne z
        # vprašanjem, in ne ugibamo naprej.
        return "", ""

    naslov, _ = _popravi_domeno(sestavljen)
    return naslov, "sestavljeno iz narekovanja"


def izgovori_eposte(
    besedilo: str, brez_crkovanja: tuple[str, ...] = (), male_crke: bool = False
) -> str:
    """Naslove v besedilu pripravi za glas.

    Brez tega Azurin glas "janez.novak@gmail.com" prebere kot eno besedo in
    afne ne izgovori.

    Del pred afno gre po črkah, ker je edini prosto izbran in ker samo
    črkovanje loči naslova, ki zvenita enako (glej `_po_crkah`). Domena gre
    z besedami: izbrana je iz kratkega seznama in jo `_popravi_domeno` tudi
    popravi, zato je črkovanje pri njej samo dolgo.

    `brez_crkovanja` so naslovi podjetja. Te asistentka samo navede ("pišite na
    info afna ..."), stranki jih ni treba preverjati, črkovanje pa bi bilo dolgo.

    `male_crke`: "s, t, i" namesto "S, T, I". Velike črke ElevenLabs glas Bella bere
    po angleško ("ess", "ej", "aj"); male jih izgovori slovensko (Luka, 24. 10. 2026,
    naslov sasa.perkon). Azure Petra bere velike prav, zato je to nastavitev po glasu.
    """
    neckovani = {n.strip().lower() for n in brez_crkovanja if n and n.strip()}
    besedilo = IZGOVORJEN_NASLOV.sub(_v_zapisano, besedilo)
    return NASLOV_V_BESEDILU.sub(
        lambda u: _izgovor(u.group(0), u.group(0).lower() not in neckovani, male_crke), besedilo
    )


def _v_zapisano(ujeto: "re.Match[str]") -> str:
    """Govorjeno obliko prevede v zapisano, da jo zna prebrati `_izgovor`."""
    naslov, _ = popravi_naslov(ujeto.group(0))
    return naslov or ujeto.group(0)


def _izgovor(naslov: str, crkuj: bool = True, male_crke: bool = False) -> str:
    lokalno, _, domena = naslov.lower().rpartition("@")
    prvi = _po_crkah(lokalno, male_crke) if crkuj else _z_besedami(lokalno)
    return f"{prvi} afna {_z_besedami(domena)}"


def _po_crkah(del_naslova: str, male_crke: bool = False) -> str:
    """"mavri" -> "M, A, V, R, I".

    Vejice so tu zato, da glas med črkami naredi premor; brez njih je izgovor
    ena dolga beseda in stranka ne more slediti.
    """
    deli = []
    for znak in del_naslova:
        if znak.isalpha():
            deli.append(znak if male_crke else znak.upper())
        elif znak in LOCILA_IZGOVOR:
            deli.append(LOCILA_IZGOVOR[znak])
        elif znak.strip():
            # Števke in vse drugo: glas jih prebere pravilno in se ne slišijo
            # podobno nobeni črki.
            deli.append(znak)
    return ", ".join(deli)


def _z_besedami(del_naslova: str) -> str:
    for znak, beseda in LOCILA_IZGOVOR.items():
        del_naslova = del_naslova.replace(znak, f" {beseda} ")
    return " ".join(del_naslova.split())


def _iz_narekovanja(besedilo: str) -> str:
    # "dvojni ve" je ena črka v dveh besedah; zlepimo jo, preden razdelimo.
    besedilo = besedilo.replace("dvojni ve", "dvojnive")
    besedilo = besedilo.replace("spodnja črta", "podčrtaj").replace("spodnja crta", "podcrtaj")

    sestavljeno: list[str] = []
    preskoci = False

    for zeton in re.split(r"[\s,;]+", besedilo):
        zeton = zeton.strip(".-_")
        if not zeton:
            continue

        # "de kot Dani" — opisno ime za črko. Beseda za "kot" ni podatek.
        if preskoci:
            preskoci = False
            continue
        if zeton == "kot":
            preskoci = True
            continue
        if zeton in IZPUSTI:
            continue

        if zeton in ZNAKI:
            sestavljeno.append(ZNAKI[zeton])
        elif zeton in CRKE:
            sestavljeno.append(CRKE[zeton])
        elif zeton in STEVKE:
            sestavljeno.append(STEVKE[zeton])
        else:
            # Cela beseda: ime, "gmail", "com". Strešice v naslovih ne obstajajo.
            sestavljeno.append(_brez_stresic(zeton))

    return "".join(sestavljeno)


def _brez_stresic(beseda: str) -> str:
    razstavljeno = unicodedata.normalize("NFD", beseda)
    return "".join(z for z in razstavljeno if unicodedata.category(z) != "Mn")


def _popravi_domeno(naslov: str) -> tuple[str, bool]:
    """Skoraj ujeto domeno zamenja z znano: "gmial.com" -> "gmail.com".

    Popravek je varen samo zato, ker asistentka naslov po njem prebere nazaj in
    čaka na potrditev. Brez tega bi tiha zamenjava domene pomenila ponudbo,
    poslano komu drugemu.
    """
    lokalno, _, domena = naslov.rpartition("@")
    if not lokalno or domena in RAZPOZNANE_DOMENE:
        return naslov, False

    najblizja = min(RAZPOZNANE_DOMENE, key=lambda znana: _razdalja(domena, znana))
    if _razdalja(domena, najblizja) <= 2:
        return f"{lokalno}@{najblizja}", True
    return naslov, False


def _razdalja(a: str, b: str) -> int:
    """Levenshtein. Tu je ceneje kot odvisnost zaradi dvajsetih vrstic."""
    prejsnja = list(range(len(b) + 1))
    for i, znak_a in enumerate(a, start=1):
        trenutna = [i]
        for j, znak_b in enumerate(b, start=1):
            trenutna.append(min(
                prejsnja[j] + 1,
                trenutna[j - 1] + 1,
                prejsnja[j - 1] + (znak_a != znak_b),
            ))
        prejsnja = trenutna
    return prejsnja[-1]
