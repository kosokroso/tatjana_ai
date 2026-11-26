"""
Razumevanje in izgovorjava jedi po telefonu (restavracijski agent).

Dve težavi, obe z istim izvorom: imena jedi so pogosto tuja.

1. Prepis. Po telefonu (8 kHz) prepis najpogosteje zgreši ravno imena jedi:
   26. 11. 2026 je "eno pico" prepisal kot "eno pito", "pice" kot "pisave".
   Rešitev: imena jedi, izbir in kategorij z menija gredo v ključne besede
   prepisa (OpenAI `keywords` in `prompt`).

2. Izgovorjava. Azure sl-SI bere zapisano besedo po slovenskih pravilih:
   "mozzarella" kot "moc-ca-rel-la", "carbonara" s "c" kot "ts". Rešitev:
   tik pred sintezo zamenjamo zapis z izgovorom ("mocarela", "karbonara").
   Zapis v pogovoru (in v preverjanju povzetka) ostane nespremenjen.

Pravila za italijanska imena veljajo SAMO za besede z menija te restavracije,
ne za ves govor: slovenske besede, kot sta "signal" ali "Ignac", bi z
italijanskimi pravili zvenele narobe. Stalni slovar spodaj velja vedno, ker
vsebuje samo nedvoumno tuje besede.

Modul nima odvisnosti od LiveKit, zato ga testi poženejo povsod.
"""

from __future__ import annotations

import re

# Stalni slovar: začetek besede -> izgovor začetka. Končnica ostane, zato
# deluje tudi sklanjatev ("mozzarello" -> "mocarelo"). Ključi so male črke.
# Daljši ključ ima prednost pred krajšim (glej _SLOVAR_VZOREC).
#
# Sem sodijo besede, ki jih glas bere narobe IN niso slovenske. Preden kaj
# dodaš, preveri, da ključ ni začetek pogoste slovenske besede.
SLOVAR: dict[str, str] = {
    # italijansko
    "pizzeri": "piceri",
    "pizz": "pic",
    "mozzarell": "mocarel",
    "margherit": "margerit",
    "capricciosa": "kapričoza",
    "quattro": "kvatro",
    "formaggi": "formadži",
    "stagioni": "stadžoni",
    "prosciutt": "prošut",
    "bruschett": "brusket",
    "focacci": "fokač",
    "ciabatt": "čabat",
    "gnocchi": "njoki",
    "tagliatell": "taljatel",
    "spaghett": "špaget",
    "lasagn": "lazanj",
    "carbonar": "karbonar",
    "bolognes": "bolonjez",
    "calzon": "kalcon",
    "capres": "kaprez",
    "carpacci": "karpač",
    "cacio": "kačo",
    "pancett": "pančet",
    "salsicci": "salsič",
    "cipoll": "čipol",
    "parmigian": "parmidžan",
    "peperoncin": "peperončin",
    "porcini": "porčini",
    "funghi": "fungi",
    "ricott": "rikot",
    "risott": "rižot",
    "panna": "pana",
    "cotta": "kota",
    "cappuccin": "kapučin",
    "macchiat": "makjat",
    "gelat": "dželat",
    "zucchin": "cukin",
    "aglio": "aljo",
    "frutti": "fruti",
    "vitell": "vitel",
    "tonnat": "tonat",
    "crudo": "krudo",
    "cotto": "koto",
    # angleško in drugo
    "cheeseburger": "čizburger",
    "cheesecake": "čizkejk",
    "cheddar": "čedar",
    "chicken": "čiken",
    "chili": "čili",
    "chips": "čips",
    "nachos": "načos",
    "jalapeñ": "halapenj",
    "jalapen": "halapenj",
    "tortill": "tortilj",
    "caesar": "cezar",
    "coca": "koka",
    "cocktail": "koktejl",
    "bbq": "barbekju",
    "crème": "krem",
    "creme": "krem",
    "brûlée": "brile",
    "brulee": "brile",
    "crêpe": "krep",
    "sushi": "suši",
}

_SLOVAR_KLJUCI = sorted(SLOVAR, key=len, reverse=True)
_SLOVAR_VZOREC = re.compile(
    r"\b(" + "|".join(re.escape(k) for k in _SLOVAR_KLJUCI) + r")(\w*)",
    re.IGNORECASE,
)

# Znaki, ki besedo izdajo za italijansko. Dvojni "dd" namenoma manjka
# (slovensko "oddaja"), prav tako samostojni "ch" (angleško "chicken").
_ITALIJANSKO = re.compile(r"cch|cc[ei]|gg[ei]|gli|gn|sc[ei]|sch|qu|zz|gh|tt|ll|pp|nn|rr|ss|ff|mm|bb")
_SAMOGLASNIKI = set("aeiou")


def _je_italijansko(beseda: str) -> bool:
    return bool(_ITALIJANSKO.search(beseda.lower()))


def italijanski_izgovor(beseda: str) -> str:
    """Prebere italijansko besedo s slovenskimi črkami: 'capricciosa' -> 'kapričoza'.

    Pravila so poenostavljena (dovolj za glas po telefonu), ne slovnica:
    c/g pred e, i -> č/dž (i pred samoglasnikom izgine), sc -> š, gn -> nj,
    gli -> lj, qu -> kv, zz -> c, h je nem, dvojni soglasniki postanejo enojni,
    s med samoglasnikoma -> z.
    """
    w = beseda.lower()
    izid: list[str] = []
    i = 0
    n = len(w)

    def zrcali_i(j: int) -> int:
        # "cia", "gio", "sciu": i samo mehča soglasnik in se ne izgovori.
        if j < n and w[j] == "i" and j + 1 < n and w[j + 1] in _SAMOGLASNIKI:
            return j + 1
        return j

    while i < n:
        z = w[i]
        nasl = w[i + 1] if i + 1 < n else ""
        if w.startswith("sch", i):
            izid.append("sk")
            i += 3
        elif z == "s" and nasl == "c" and i + 2 < n and w[i + 2] in "ei":
            izid.append("š")
            i = zrcali_i(i + 2)
        elif w.startswith("cch", i):
            izid.append("k")
            i += 3
        elif z == "c" and nasl == "c" and i + 2 < n and w[i + 2] in "ei":
            izid.append("č")
            i = zrcali_i(i + 2)
        elif z == "c" and nasl in ("e", "i"):
            izid.append("č")
            i = zrcali_i(i + 1)
        elif z == "c" and nasl == "h":
            izid.append("k")
            i += 2
        elif z == "c":
            # "speck", "bocca": en k
            izid.append("k")
            i += 2 if nasl in ("c", "k") else 1
        elif w.startswith("gli", i):
            # "taglia" -> "talja", "gli" na koncu -> "lji"
            izid.append("lj" if i + 3 < n and w[i + 3] in _SAMOGLASNIKI else "lji")
            i += 3
        elif z == "g" and nasl == "n":
            izid.append("nj")
            i += 2
        elif z == "g" and nasl == "g" and i + 2 < n and w[i + 2] in "ei":
            izid.append("dž")
            i = zrcali_i(i + 2)
        elif z == "g" and nasl in ("e", "i"):
            izid.append("dž")
            i = zrcali_i(i + 1)
        elif z == "g" and nasl == "h":
            izid.append("g")
            i += 2
        elif z == "q" and nasl == "u":
            izid.append("kv")
            i += 2
        elif z == "z" and nasl == "z":
            # "pizza" -> "pica"; enojni z ostane ("gorgonzola" se pri nas reče z z)
            izid.append("c")
            i += 2
        elif z == "h":
            i += 1
        elif z == "s" and izid and izid[-1][-1:] in _SAMOGLASNIKI and nasl in _SAMOGLASNIKI:
            izid.append("z")
            i += 1
        elif z == nasl and z not in _SAMOGLASNIKI:
            izid.append(z)
            i += 2
        else:
            izid.append({"x": "ks", "y": "i", "w": "v", "ñ": "nj"}.get(z, z))
            i += 1
    rezultat = "".join(izid)
    return rezultat[:1].upper() + rezultat[1:] if beseda[:1].isupper() else rezultat


def _koren(beseda: str) -> str:
    """Začetek besede, ki ujame tudi sklanjatev: 'mozzarella' -> 'mozzarell'."""
    w = beseda.lower()
    if len(w) >= 5 and w[-1] in _SAMOGLASNIKI:
        return w[:-1]
    return w


def _besede_menija(meni: list[dict]) -> list[str]:
    """Imena jedi, izbir in opisi z menija, v vrstnem redu menija."""
    besedila: list[str] = []
    for jed in meni or []:
        if not isinstance(jed, dict):
            continue
        besedila.append(str(jed.get("jed") or ""))
        besedila.append(str(jed.get("opis") or ""))
        for skupina in jed.get("izbire") or []:
            for m in (skupina or {}).get("moznosti") or []:
                besedila.append(str((m or {}).get("ime") or ""))
    return besedila


def koreni_menija(meni: list[dict]) -> set[str]:
    """Koreni italijanskih besed z menija, ki jih stalni slovar ne pokrije.

    Samo te besede gredo med govorom skozi italijanska pravila.
    """
    koreni: set[str] = set()
    for besedilo in _besede_menija(meni):
        for beseda in re.findall(r"[^\W\d_]+", besedilo):
            if len(beseda) < 4 or not _je_italijansko(beseda):
                continue
            if _SLOVAR_VZOREC.match(beseda):
                continue
            koreni.add(_koren(beseda))
    return koreni


def izgovori_jedi(besedilo: str, koreni: set[str] | frozenset[str] = frozenset()) -> str:
    """Zamenja zapis tujih imen jedi z izgovorom, tik pred sintezo govora."""
    def po_slovarju(m: re.Match) -> str:
        zacetek, konec = m.group(1), m.group(2)
        izgovor = SLOVAR[zacetek.lower()]
        if zacetek[:1].isupper():
            izgovor = izgovor[:1].upper() + izgovor[1:]
        return izgovor + konec

    besedilo = _SLOVAR_VZOREC.sub(po_slovarju, besedilo)
    if not koreni:
        return besedilo

    def po_pravilih(m: re.Match) -> str:
        beseda = m.group(0)
        nizko = beseda.lower()
        if any(nizko.startswith(k) for k in koreni):
            return italijanski_izgovor(beseda)
        return beseda

    return re.sub(r"[^\W\d_]+", po_pravilih, besedilo)


# Osnovne besede restavracije, ki jih prepis mora poznati ne glede na meni.
# "pica" in "pice" sta tu zaradi klica 26. 11. 2026 ("eno pito").
OSNOVNE_KLJUCNE = [
    "rezervacija", "miza", "terasa", "oseb", "alergija",
    "naročilo", "prevzem", "pica", "pice", "meni",
]
NAJVEC_KLJUCNIH = 40


def kljucne_besede(meni: list[dict], restavracija: str = "") -> list[str]:
    """Ključne besede za prepis: imena jedi najprej, nato izbire in kategorije.

    Vrstni red je pomemben: prepis dobi v `prompt` samo prvih 30. Imena jedi so
    besede, od katerih je odvisno naročilo, zato gredo pred izbirami.
    """
    jedi: list[str] = []
    izbire: list[str] = []
    kategorije: list[str] = []
    for jed in meni or []:
        if not isinstance(jed, dict):
            continue
        jedi.append(str(jed.get("jed") or ""))
        kategorije.append(str(jed.get("kategorija") or ""))
        for skupina in jed.get("izbire") or []:
            for m in (skupina or {}).get("moznosti") or []:
                izbire.append(str((m or {}).get("ime") or ""))

    izid: list[str] = []
    videno: set[str] = set()
    for beseda in [restavracija, *jedi, *OSNOVNE_KLJUCNE, *izbire, *kategorije]:
        beseda = " ".join(beseda.replace("(", " ").replace(")", " ").split())
        if len(beseda) < 3 or len(beseda) > 40:
            continue
        kljuc = beseda.lower()
        if kljuc in videno:
            continue
        videno.add(kljuc)
        izid.append(beseda)
        if len(izid) >= NAJVEC_KLJUCNIH:
            break
    return izid


def kategorije_jedi(meni: list[dict]) -> dict[int, str]:
    """id jedi -> kategorija; povzetek naročila z njo pove 'pica Pršut'."""
    izid: dict[int, str] = {}
    for jed in meni or []:
        if not isinstance(jed, dict):
            continue
        try:
            izid[int(jed.get("id"))] = str(jed.get("kategorija") or "")
        except (TypeError, ValueError):
            continue
    return izid
