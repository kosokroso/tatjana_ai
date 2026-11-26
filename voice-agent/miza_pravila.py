"""
Pravila restavracijskega agenta, ki ne potrebujejo LiveKita.

Ločeno od restavracija.py, da se dajo preizkusiti z navadnim `python
test_miza.py`, brez nameščenega livekit-agents in brez klica.

Kar je tu, odloča koda in ne model: kdaj se rezervacija sme zapisati, kako se
glasi povzetek, ki ga mora gost slišati, in kateri datum pomeni "v petek".
"""

from __future__ import annotations

import re
from datetime import date, timedelta

DNEVI = ["ponedeljek", "torek", "sreda", "četrtek", "petek", "sobota", "nedelja"]
DNEVI_TOZILNIK = ["ponedeljek", "torek", "sredo", "četrtek", "petek", "soboto", "nedeljo"]
MESECI = [
    "januarja", "februarja", "marca", "aprila", "maja", "junija", "julija",
    "avgusta", "septembra", "oktobra", "novembra", "decembra",
]

# Besede, ki potrditev spremenijo v pogojno ali v zavrnitev. "Ja, ampak ob
# osmih" ni potrditev povzetka, ki pravi "ob sedmih".
_PRIDRZKI = {"ne", "nee", "ampak", "vendar", "raje", "pač", "pac", "samo", "spremeni", "počakaj", "pocakaj"}
_POTRDITVE_BESEDE = {
    "ja", "jaa", "da", "drži", "drzi", "potrdim", "potrjujem", "potrjeno",
    "prav", "velja", "seveda", "točno", "tocno", "ok", "okej", "tako", "rezerviraj",
    "odpovej", "lahko",
}
_POTRDITVE_ZVEZE = ("tako je", "v redu", "se strinjam", "kar rezerviraj", "kar odpovej")


def _besede(besedilo: str) -> list[str]:
    cisto = "".join(z if z.isalnum() or z.isspace() else " " for z in (besedilo or "").lower())
    return cisto.split()


def je_cista_potrditev(besedilo: str) -> bool:
    """Ali je gost povzetek potrdil brez pridržka.

    Strožje od _je_potrditev v agent.py: števka ali "ampak" pomenita, da gost
    nekaj spreminja, in takrat zapis ne sme iti skozi. Napačno zavrnjena
    potrditev stane en ponovljen povzetek; napačno sprejeta pa mizo ob napačni uri.
    """
    besede = _besede(besedilo)
    if not besede:
        return False
    if any(b in _PRIDRZKI for b in besede) or any(z.isdigit() for z in besedilo or ""):
        return False
    cisto = " ".join(besede)
    if any(zveza in cisto for zveza in _POTRDITVE_ZVEZE):
        return True
    return any(b in _POTRDITVE_BESEDE for b in besede)


def osebe_besedilo(n: int) -> str:
    """'1 osebo', '2 osebi', '3 osebe', '5 oseb' (tožilnik, kot v 'za 4 osebe')."""
    if n % 100 == 1:
        return f"{n} osebo"
    if n % 100 == 2:
        return f"{n} osebi"
    if n % 100 in (3, 4):
        return f"{n} osebe"
    return f"{n} oseb"


def izgovorjen_dan(dan: date, danes: date) -> str:
    """'danes', 'jutri', 'v petek, 27. novembra' ali 'v petek, 4. decembra'."""
    if dan == danes:
        return f"danes, {dan.day}. {MESECI[dan.month - 1]}"
    if dan == danes + timedelta(days=1):
        return f"jutri, {dan.day}. {MESECI[dan.month - 1]}"
    return f"v {DNEVI_TOZILNIK[dan.weekday()]}, {dan.day}. {MESECI[dan.month - 1]}"


def preberi_datum(vrednost: str) -> date | None:
    try:
        return date.fromisoformat((vrednost or "").strip())
    except ValueError:
        return None


def preberi_uro(vrednost: str) -> str | None:
    """'19:00', '19.00', '19' -> '19:00'. Drugo -> None."""
    m = re.fullmatch(r"\s*(\d{1,2})(?:[:.](\d{2}))?\s*", vrednost or "")
    if not m:
        return None
    ura, minute = int(m.group(1)), int(m.group(2) or 0)
    if ura > 23 or minute > 59:
        return None
    return f"{ura:02d}:{minute:02d}"


def povzetek_rezervacije(ime: str, dan: date, ura: str, osebe: int, danes: date) -> str:
    """Stavek, ki ga mora gost slišati, preden se rezervacija zapiše."""
    return (
        f"Rezerviram mizo za {osebe_besedilo(osebe)}, {izgovorjen_dan(dan, danes)} "
        f"ob {ura}, na ime {ime.strip()}. Ali drži?"
    )


def povzetek_izgovorjen(izgovorjeno: str, ime: str, osebe: int, ura: str) -> bool:
    """Ali je asistentka v zadnjem stavku res prebrala povzetek.

    Model zna "Ja, rezerviram" reči brez povzetka, ali pa povzetek prebere z
    drugo uro, kot jo pošlje orodju. Preverimo tri stvari, ki jih model ne more
    zaobiti z vljudnostjo: del imena, število oseb in uro.
    """
    nizko = (izgovorjeno or "").lower()
    deli = [d for d in (ime or "").lower().split() if len(d) >= 3]
    ime_ok = bool(deli) and any(d in nizko for d in deli)
    osebe_ok = bool(re.search(rf"\b{osebe}\b", nizko))
    ura_h = str(int(ura[:2]))
    ura_ok = ura in nizko or bool(re.search(rf"\b{ura_h}(?:[:.]\d\d)?\b", nizko))
    return ime_ok and osebe_ok and ura_ok


def _evro(n: int) -> str:
    if n % 100 == 1:
        return f"{n} evro"
    if n % 100 == 2:
        return f"{n} evra"
    if n % 100 in (3, 4):
        return f"{n} evre"
    return f"{n} evrov"


def _cent(n: int) -> str:
    if n % 100 == 1:
        return f"{n} cent"
    if n % 100 == 2:
        return f"{n} centa"
    if n % 100 in (3, 4):
        return f"{n} cente"
    return f"{n} centov"


def cena_besedilo(cent: int) -> str:
    """1550 -> '15 evrov in 50 centov'; 2000 -> '20 evrov'; 50 -> '50 centov'."""
    cent = int(cent)
    e, c = divmod(cent, 100)
    if e and c:
        return f"{_evro(e)} in {_cent(c)}"
    if e:
        return _evro(e)
    return _cent(c)


def povzetek_narocila(linije: list[dict], skupaj_cent: int, dan: date, ura: str, ime: str, danes: date) -> str:
    """Stavek, ki ga mora gost slišati, preden se naročilo zapiše.

    Jedi, količine in znesek vzamemo iz odgovora strežnika (ceno izračuna PHP iz
    baze), ne iz tistega, kar pošlje model.
    """
    deli = []
    for l in linije:
        ime_jedi = str(l.get("name", "")).strip()
        izbire = str(l.get("options_text") or "").strip()
        qty = int(l.get("qty") or 1)
        opis = ime_jedi + (f" ({izbire})" if izbire else "")
        deli.append(f"{opis}, {qty} krat" if qty > 1 else opis)
    return (
        f"Naročam: {'; '.join(deli)}. Skupaj {cena_besedilo(skupaj_cent)}. "
        f"Prevzem {izgovorjen_dan(dan, danes)} ob {ura}, na ime {ime.strip()}. Ali drži?"
    )


def narocilo_izgovorjeno(izgovorjeno: str, imena_jedi: list[str], skupaj_cent: int, ura: str) -> bool:
    """Ali je asistentka res prebrala povzetek naročila: vsako jed, znesek in uro.

    Jed prepoznamo po začetku katere koli besede imena (5 znakov, zaradi sklanjatve:
    'margerita' / 'margerito'). Znesek: evri in, če so, centi.
    """
    nizko = (izgovorjeno or "").lower()
    for ime in imena_jedi:
        besede = [b for b in "".join(z if z.isalnum() else " " for z in ime.lower()).split() if len(b) >= 3]
        if not besede or not any(b[:5] in nizko for b in besede):
            return False
    e, c = divmod(int(skupaj_cent), 100)
    if e and not re.search(rf"\b{e}\b", nizko):
        return False
    if c and not re.search(rf"\b{c}\b", nizko):
        return False
    if not e and not c:
        return False
    ura_h = str(int(ura[:2]))
    return ura in nizko or bool(re.search(rf"\b{ura_h}(?:[:.]\d\d)?\b", nizko))


STANJA_NAROCIL = {
    "new": "sprejeto, še ni v pripravi",
    "preparing": "v pripravi",
    "ready": "pripravljeno, čaka na prevzem",
}


def koledar(danes: date, dni: int = 14) -> str:
    """Tabela datumov za prompt. Model "v petek" sicer izračuna napačno."""
    vrstice = []
    for i in range(dni):
        d = danes + timedelta(days=i)
        oznaka = "danes" if i == 0 else ("jutri" if i == 1 else "")
        vrstice.append(f"- {DNEVI[d.weekday()]} {d.isoformat()}" + (f" ({oznaka})" if oznaka else ""))
    return "\n".join(vrstice)


# Razlogi iz /miza API (lib/booking.php reason_text) in kaj naj model reče.
NAVODILA_RAZLOGOV = {
    "too_soon": "Termin je prekmalu. Ponudi alternativo s seznama.",
    "too_far": "Tako daleč vnaprej rezervacij še ne sprejemamo. Povej to in vprašaj za bližji datum.",
    "group_too_large": (
        "Za tako veliko skupino rezervacije po telefonu ne sprejmemo. Povej, naj "
        "restavracijo pokliče kasneje ali pride osebno; ne obljubljaj mize."
    ),
    "closed": "Restavracija je ta dan zaprta. Povej to in ponudi drug dan.",
    "outside_hours": "Ob tej uri ne sprejemamo rezervacij. Ponudi alternativo s seznama.",
    "slot_full": "Ob tej uri je že veliko prihodov. Ponudi alternativo s seznama.",
    "no_table": "Ob tej uri ni proste mize. Ponudi alternativo s seznama; ne obljubljaj ničesar drugega.",
    # naročila za prevzem
    "empty_order": "Naročilo nima jedi. Vprašaj, kaj želi naročiti.",
    "unknown_item": "Te jedi ni na meniju. Ne izmišljaj jedi: pokliči orodje meni in ponudi, kar obstaja.",
    "unavailable_item": "Te jedi trenutno ni na voljo. Povej to in ponudi drugo jed z menija.",
    "missing_option": "Za to jed je treba izbrati možnost. Vprašaj gosta in naštej možnosti iz sporočila.",
    "too_many_options": "Za to jed je izbir preveč. Povej, koliko jih je največ, in vprašaj znova.",
    "pickup_closed": "Prevzem trenutno ni mogoč. Povej to in povabi, naj pokličejo ob uri prevzema.",
    "pickup_time_unavailable": (
        "Ob želeni uri prevzem ni mogoč ali je termin medtem zaseden. Pokliči znova s potrjeno=False "
        "in brez ure prevzema, da dobiš najzgodnejši možni čas, in ga povej gostu."
    ),
}
