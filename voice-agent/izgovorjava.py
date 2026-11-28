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


# ---------------------------------------------------------------------------
# Števila z besedami (datumi, ure, cene, količine, črkovane kode)
#
# Klic 28. 11. 2026: "29. novembra" in "ob 12:00" sta zvenela slabo, ker glas
# bere števke. Pred sintezo jih zapišemo z besedami, kot jih reče natakar:
# "devetindvajsetega novembra", "ob dvanajstih". Zapis v pogovoru ostane s
# števkami, ker po njem koda preverja, ali je bil povzetek prebran.
# ---------------------------------------------------------------------------

_ENICE = ["nič", "ena", "dva", "tri", "štiri", "pet", "šest", "sedem", "osem", "devet"]
_NAJST = ["deset", "enajst", "dvanajst", "trinajst", "štirinajst", "petnajst", "šestnajst",
          "sedemnajst", "osemnajst", "devetnajst"]
_DESETICE = {2: "dvajset", 3: "trideset", 4: "štirideset", 5: "petdeset", 6: "šestdeset",
             7: "sedemdeset", 8: "osemdeset", 9: "devetdeset"}
_ENICE_V_SESTAVI = ["", "ena", "dva", "tri", "štiri", "pet", "šest", "sedem", "osem", "devet"]


def stevilo_besedno(n: int) -> str:
    """Glavni števnik 0..9999 v imenovalniku: 62 -> 'dvainšestdeset'."""
    if n < 0 or n > 9999:
        return str(n)
    if n < 10:
        return _ENICE[n]
    if n < 20:
        return _NAJST[n - 10]
    if n < 100:
        d, e = divmod(n, 10)
        return (_ENICE_V_SESTAVI[e] + "in" if e else "") + _DESETICE[d]
    if n < 1000:
        s, ostanek = divmod(n, 100)
        stotice = "sto" if s == 1 else ("dvesto" if s == 2 else _ENICE[s] + "sto")
        return stotice + (" " + stevilo_besedno(ostanek) if ostanek else "")
    t, ostanek = divmod(n, 1000)
    tisoc = "tisoč" if t == 1 else ("dva tisoč" if t == 2 else _ENICE[t] + " tisoč")
    return tisoc + (" " + stevilo_besedno(ostanek) if ostanek else "")


_VRSTILNI_RODILNIK = {
    1: "prvega", 2: "drugega", 3: "tretjega", 4: "četrtega", 5: "petega", 6: "šestega",
    7: "sedmega", 8: "osmega", 9: "devetega", 10: "desetega", 11: "enajstega", 12: "dvanajstega",
    13: "trinajstega", 14: "štirinajstega", 15: "petnajstega", 16: "šestnajstega",
    17: "sedemnajstega", 18: "osemnajstega", 19: "devetnajstega", 20: "dvajsetega",
    30: "tridesetega",
}


def _dan_v_rodilniku(n: int) -> str:
    """29 -> 'devetindvajsetega' (29. novembra)."""
    if n in _VRSTILNI_RODILNIK:
        return _VRSTILNI_RODILNIK[n]
    d, e = divmod(n, 10)
    return _ENICE_V_SESTAVI[e] + "in" + _DESETICE[d] + "ega"


# Ura v mestniku: "ob dvanajstih", "do štirinajstih".
_URE_MESTNIK = [
    "polnoči", "enih", "dveh", "treh", "štirih", "petih", "šestih", "sedmih", "osmih", "devetih",
    "desetih", "enajstih", "dvanajstih", "trinajstih", "štirinajstih", "petnajstih", "šestnajstih",
    "sedemnajstih", "osemnajstih", "devetnajstih", "dvajsetih", "enaindvajsetih",
    "dvaindvajsetih", "triindvajsetih",
]

_MESECI_RE = "januarja|februarja|marca|aprila|maja|junija|julija|avgusta|septembra|oktobra|novembra|decembra"
_DATUM = re.compile(rf"\b(3[01]|[12]\d|[1-9])\.\s*({_MESECI_RE})\b")
_URA = re.compile(r"\b(ob|do|od|po|okoli|okrog)\s+(2[0-3]|[01]?\d)[:.]([0-5]\d)\b(?:\s*h\b)?", re.IGNORECASE)
# "ob 7 zvečer" -> "ob sedmih zvečer"; samo za "ob/okoli/okrog", kjer je število vedno ura.
_URA_BREZ_MINUT = re.compile(r"\b(ob|okoli|okrog)\s+(2[0-3]|1\d|[1-9])\b(?![.,:]?\d)", re.IGNORECASE)
_KRAT = re.compile(r"\b(\d{1,3})\s*-?\s*krat\b")
_CENA_CENTI = re.compile(r"\b(\d{1,4}),(\d{2})\s*(?:€|EUR\b|evr(?:o|a|e|ov)\b)")
_CENA = re.compile(r"\b(\d{1,4})\s*(?:€|EUR\b|evr(?:o|a|e|ov)\b)")
_CENTI = re.compile(r"\b(\d{1,2})\s*(?:cent(?:a|e|ov)?)\b")
_OSEBE = re.compile(r"\b(\d{1,2})\s+(oseb[aoie]?)\b")
# Ostala samostojna števila 5..999 ("90 minut"). Števila 1..4 pustimo glasu, ker
# je oblika odvisna od spola ("dve pici"). Skupine števk, ki stojijo ob drugi
# skupini, so telefonska številka ("041 555 666") in ostanejo števke.
_OSTALO_STEVILO = re.compile(r"(?<![\d.,:])(?<!\d\s)\b([5-9]|[1-9]\d{1,2})\b(?![.,:]?\d)(?!\s\d)")

# Črkovanje: vsaj tri samostojne velike črke ali števke zapored ("J, T, R, N, D, 8",
# "K O S O V L"). Ena sama velika črka na začetku stavka ("V redu") ni črkovanje.
_CRKE = {
    "A": "a", "B": "be", "C": "ce", "Č": "če", "D": "de", "E": "e", "F": "ef", "G": "ge",
    "H": "ha", "I": "i", "J": "je", "K": "ka", "L": "el", "M": "em", "N": "en", "O": "o",
    "P": "pe", "Q": "ku", "R": "er", "S": "es", "Š": "eš", "T": "te", "U": "u", "V": "ve",
    "W": "dvojni ve", "X": "iks", "Y": "ipsilon", "Z": "ze", "Ž": "že",
}
_ZNAK = r"(?<![\w])[A-ZČŠŽ0-9](?![\w])"
_CRKOVANJE = re.compile(rf"{_ZNAK}(?:\s*[,\-–—]?\s*{_ZNAK}){{2,}}")


def _evri(n: int) -> str:
    if n % 100 == 1:
        return "evro"
    if n % 100 == 2:
        return "evra"
    if n % 100 in (3, 4):
        return "evre"
    return "evrov"


def _centi(n: int) -> str:
    if n % 100 == 1:
        return "cent"
    if n % 100 == 2:
        return "centa"
    if n % 100 in (3, 4):
        return "cente"
    return "centov"


def _moski(n: int) -> str:
    """Števnik pred moškim samostalnikom: 'en evro', 'en cent'."""
    return "en" if n == 1 else stevilo_besedno(n)


def _osebe_tozilnik(n: int) -> str:
    """'za 1 osebo' -> 'za eno osebo'; ženski spol, kot ga zahteva 'oseba'."""
    return {1: "eno", 2: "dve"}.get(n, stevilo_besedno(n))


def _crkuj(m: re.Match) -> str:
    znaki = re.findall(r"[A-ZČŠŽ0-9]", m.group(0))
    return ", ".join(_CRKE.get(z) or _ENICE[int(z)] for z in znaki)


def besedilo_za_govor(besedilo: str) -> str:
    """Datume, ure, cene, količine in črkovane kode zapiše z besedami."""
    besedilo = _DATUM.sub(lambda m: f"{_dan_v_rodilniku(int(m.group(1)))} {m.group(2)}", besedilo)

    def ura(m: re.Match) -> str:
        h, mn = int(m.group(2)), int(m.group(3))
        izid = f"{m.group(1)} {_URE_MESTNIK[h]}"
        return izid + (f" {stevilo_besedno(mn)}" if mn else "")

    besedilo = _URA.sub(ura, besedilo)
    besedilo = _URA_BREZ_MINUT.sub(lambda m: f"{m.group(1)} {_URE_MESTNIK[int(m.group(2))]}", besedilo)
    besedilo = _KRAT.sub(
        lambda m: ("en" if m.group(1) == "1" else stevilo_besedno(int(m.group(1)))) + "krat", besedilo
    )

    def cena_s_centi(m: re.Match) -> str:
        e, c = int(m.group(1)), int(m.group(2))
        izid = f"{_moski(e)} {_evri(e)}"
        return izid + (f" in {_moski(c)} {_centi(c)}" if c else "")

    besedilo = _CENA_CENTI.sub(cena_s_centi, besedilo)
    besedilo = _CENA.sub(lambda m: f"{_moski(int(m.group(1)))} {_evri(int(m.group(1)))}", besedilo)
    besedilo = _CENTI.sub(lambda m: f"{_moski(int(m.group(1)))} {_centi(int(m.group(1)))}", besedilo)
    besedilo = _OSEBE.sub(lambda m: f"{_osebe_tozilnik(int(m.group(1)))} {m.group(2)}", besedilo)
    besedilo = _CRKOVANJE.sub(_crkuj, besedilo)
    besedilo = _OSTALO_STEVILO.sub(lambda m: stevilo_besedno(int(m.group(1))), besedilo)
    return besedilo


# Besedilo, ki ga je treba pred pretvorbo videti celega: števka ali velika
# samostojna črka. "29. " brez "novembra" bi sicer šlo v glas samo.
_CAKA_NA_STAVEK = re.compile(r"\d|(?<![\w])[A-ZČŠŽ](?![\w])")
# Konec stavka: ločilo s presledkom za njim, pred njim pa ni števke ("29. novembra").
_KONEC_STAVKA = re.compile(r"(?<!\d)[.!?;:](?=\s)")


def meja_za_govor(besedilo: str, celi_stavki: bool) -> int:
    """Do katerega znaka (vključno) gre besedilo lahko že v glas; -1 pomeni čakaj.

    Brez celih stavkov: do zadnjega presledka (zamik ene besede, kot doslej).
    S celimi stavki: enako, razen kadar del za glas vsebuje števko ali
    črkovanje; takrat do konca zadnjega celega stavka, da gre "ob 12:00" ali
    "29. novembra" v pretvorbo v enem kosu. Konec odgovora pošlje ostanek v
    vsakem primeru.
    """
    meja = besedilo.rfind(" ")
    if meja == -1 or not celi_stavki:
        return meja
    if not _CAKA_NA_STAVEK.search(besedilo[: meja + 1]):
        return meja
    konci = [m.end() for m in _KONEC_STAVKA.finditer(besedilo)]
    return konci[-1] if konci else -1
