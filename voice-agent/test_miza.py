"""Preveri pravila restavracijskega agenta (miza_pravila.py).

Zagon iz mape voice-agent, brez nameščenega LiveKita:
    python test_miza.py
"""

from datetime import date

from miza_pravila import (
    izgovorjen_dan,
    je_cista_potrditev,
    koledar,
    osebe_besedilo,
    povzetek_izgovorjen,
    povzetek_rezervacije,
    preberi_datum,
    preberi_uro,
)

DANES = date(2026, 11, 24)  # torek


def preveri_potrditve() -> None:
    for b in ("Ja.", "Ja, drži.", "Tako je", "V redu, hvala", "Da", "Seveda", "Ja lahko"):
        assert je_cista_potrditev(b), b
    for b in (
        "",
        "Ne",
        "Ja, ampak ob osmih",
        "Ja, za 5 oseb",
        "Raje v soboto",
        "Mhm, počakaj malo",
        "Koliko stane",
    ):
        assert not je_cista_potrditev(b), b


def preveri_osebe() -> None:
    assert osebe_besedilo(1) == "1 osebo"
    assert osebe_besedilo(2) == "2 osebi"
    assert osebe_besedilo(3) == "3 osebe"
    assert osebe_besedilo(4) == "4 osebe"
    assert osebe_besedilo(5) == "5 oseb"
    assert osebe_besedilo(12) == "12 oseb"


def preveri_datume() -> None:
    assert izgovorjen_dan(DANES, DANES) == "danes, 24. novembra"
    assert izgovorjen_dan(date(2026, 11, 25), DANES) == "jutri, 25. novembra"
    assert izgovorjen_dan(date(2026, 11, 27), DANES) == "v petek, 27. novembra"
    assert izgovorjen_dan(date(2026, 11, 28), DANES) == "v soboto, 28. novembra"
    assert preberi_datum("2026-11-27") == date(2026, 11, 27)
    assert preberi_datum("27. 11.") is None
    assert preberi_uro("19:00") == "19:00"
    assert preberi_uro("19.30") == "19:30"
    assert preberi_uro("19") == "19:00"
    assert preberi_uro("7 zvečer") is None
    assert preberi_uro("25:00") is None
    k = koledar(DANES, 3)
    assert "torek 2026-11-24 (danes)" in k and "sreda 2026-11-25 (jutri)" in k


def preveri_povzetek() -> None:
    p = povzetek_rezervacije("Ana Novak", date(2026, 11, 27), "19:00", 4, DANES)
    assert p == "Rezerviram mizo za 4 osebe, v petek, 27. novembra ob 19:00, na ime Ana Novak. Ali drži?", p
    assert povzetek_izgovorjen(p, "Ana Novak", 4, "19:00")
    # Model je povzetek prebral z drugo uro ali brez imena: zapis ne sme iti.
    assert not povzetek_izgovorjen(p.replace("19:00", "20:00"), "Ana Novak", 4, "19:00")
    assert not povzetek_izgovorjen("Rezerviram za 4 osebe ob 19:00.", "Ana Novak", 4, "19:00")
    assert not povzetek_izgovorjen(p, "Ana Novak", 5, "19:00")
    # "ob 19h" ali "ob 19.00" je ista ura.
    assert povzetek_izgovorjen("Za 2 osebi ob 19.00, na ime Kos Bojan?", "Bojan Kos", 2, "19:00")
    # Število oseb ne sme ujeti dela datuma (27) ali ure.
    assert not povzetek_izgovorjen("Na ime Ana, 27. novembra ob 19:00", "Ana", 2, "19:00")


if __name__ == "__main__":
    for preizkus in (preveri_potrditve, preveri_osebe, preveri_datume, preveri_povzetek):
        preizkus()
        print("ok", preizkus.__name__)
    print("vse ok")
