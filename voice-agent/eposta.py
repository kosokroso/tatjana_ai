"""Branje in izgovor e-poštnih naslovov po telefonu.

Dva opravila, oba zato, ker po zvoku naslov ne pride cel:

1. `popravi_naslov` sestavi naslov iz tega, kar je stranka narekovala —
   "ka, o, es, afna, ge em a i el, pika, ce o em". Doslej je to pretvarjal model
   sam in se je motil: izpustil je "afna", črko "es" je zapisal kot "se", enkrat
   pa je v polje vpisal telefonsko številko.

2. `izgovori_eposte` naslov pred govorom razpiše po črkah. Azurin glas prebere
   "janez.novak@gmail.com" kot besedo in stranka ne more preveriti, ali smo
   slišali prav — to je bil drugi vir izgubljenih ponudb.

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

ZNAKI = {
    "pika": ".", "afna": "@", "at": "@", "pri": "@",
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

# Črka -> ime črke, za branje nazaj. Obratna smer CRKE ne zadošča, ker ima ta
# po dva vnosa za isto črko.
IMENA_CRK = {
    "a": "a", "b": "be", "c": "ce", "č": "če", "d": "de", "e": "e",
    "f": "ef", "g": "ge", "h": "ha", "i": "i", "j": "je", "k": "ka",
    "l": "el", "m": "em", "n": "en", "o": "o", "p": "pe", "q": "ku",
    "r": "er", "s": "es", "š": "eš", "t": "te", "u": "u", "v": "ve",
    "w": "dvojni ve", "x": "iks", "y": "ipsilon", "z": "zet", "ž": "že",
    ".": "pika", "-": "vezaj", "_": "podčrtaj", "+": "plus", "@": "afna",
}


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


def izgovori_eposte(besedilo: str, izvzeti: "tuple[str, ...] | set[str] | list[str]" = ()) -> str:
    """Naslove v besedilu pripravi za glas: del pred afno gre po črkah.

    Domena ostane beseda ("afna gmail pika com"). Črkovanje domene je zvenelo
    kot tuja abeceda in je trajalo dlje, kot je kdo pripravljen poslušati,
    domene pa so izgovorljive besede. Napake nastajajo pred afno.

    `izvzeti` so naslovi, ki se preberejo naravno, brez črkovanja — predvsem
    naslov podjetja. Tega klicatelj ne zapisuje po nareku, ampak ga sliši kot
    podatek, komu naj piše.
    """
    brez_crkovanja = {naslov.strip().lower() for naslov in izvzeti if naslov}
    return NASLOV_V_BESEDILU.sub(lambda u: _izgovor(u.group(0), brez_crkovanja), besedilo)


def _izgovor(naslov: str, brez_crkovanja: "set[str]" = frozenset()) -> str:
    lokalno, _, domena = naslov.lower().rpartition("@")
    domena_po_besedah = domena.replace(".", " pika ")

    if naslov.lower() in brez_crkovanja:
        return f"{lokalno} afna {domena_po_besedah}"

    return ", ".join([
        ", ".join(IMENA_CRK.get(z, z) for z in lokalno),
        "afna",
        domena_po_besedah,
    ])


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
