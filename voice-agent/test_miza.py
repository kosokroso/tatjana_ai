"""Preveri pravila restavracijskega agenta (miza_pravila.py).

Zagon iz mape voice-agent, brez nameščenega LiveKita:
    python test_miza.py
"""

from datetime import date

from miza_pravila import (
    cena_besedilo,
    izgovorjen_dan,
    je_cista_potrditev,
    koledar,
    narocilo_izgovorjeno,
    osebe_besedilo,
    povzetek_izgovorjen,
    povzetek_narocila,
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


def preveri_cene() -> None:
    assert cena_besedilo(2150) == "21 evrov in 50 centov", cena_besedilo(2150)
    assert cena_besedilo(2000) == "20 evrov"
    assert cena_besedilo(100) == "1 evro"
    assert cena_besedilo(251) == "2 evra in 51 centov"
    assert cena_besedilo(304) == "3 evre in 4 cente"
    assert cena_besedilo(50) == "50 centov"
    assert cena_besedilo(1201) == "12 evrov in 1 cent"


def preveri_narocilo() -> None:
    linije = [
        {"name": "Pizza Margerita", "qty": 2, "options_text": "velika"},
        {"name": "Tiramisu", "qty": 1, "options_text": None},
    ]
    p = povzetek_narocila(linije, 2150, date(2026, 11, 24), "19:35", "Luka", DANES)
    assert p == (
        "Naročam: Pizza Margerita (velika), 2 krat; Tiramisu. Skupaj 21 evrov in 50 centov. "
        "Prevzem danes, 24. novembra ob 19:35, na ime Luka. Ali drži?"
    ), p
    imena = ["Pizza Margerita", "Tiramisu"]
    assert narocilo_izgovorjeno(p, imena, 2150, "19:35")
    # Sklanjatev: 'margerito' prepozna po začetku besede.
    assert narocilo_izgovorjeno("Dve margerito in tiramisu, 21 evrov in 50 centov, ob 19.35", imena, 2150, "19:35")
    # Model je izpustil jed, znesek, cente ali uro, ali je znesek drug: zapis ne sme iti.
    assert not narocilo_izgovorjeno("Naročam Pizza Margerita. Skupaj 21 evrov in 50 centov, ob 19:35.", imena, 2150, "19:35")
    assert not narocilo_izgovorjeno("Naročam margerito in tiramisu ob 19:35. Ali drži?", imena, 2150, "19:35")
    assert not narocilo_izgovorjeno(p.replace("21 evrov", "20 evrov"), imena, 2150, "19:35")
    assert not narocilo_izgovorjeno(p.replace("50 centov", "40 centov"), imena, 2150, "19:35")
    assert not narocilo_izgovorjeno(p.replace("19:35", "20:00"), imena, 2150, "19:35")
    assert not narocilo_izgovorjeno("", imena, 2150, "19:35")
    assert not narocilo_izgovorjeno(p, [], 0, "19:35")
    # Potrditev z drugo količino ("ja, 3 pice") ni čista potrditev.
    assert not je_cista_potrditev("Ja, ampak tri pice")
    assert not je_cista_potrditev("Ja, 3")


def preveri_napade_na_potrditev() -> None:
    # Potrditev z dodatkom ali vprašanjem ni potrditev povzetka.
    for fraza in ("Ja, dodaj še eno pico", "Ja, koliko je to skupaj?", "Ni v redu", "Mogoče ja", "Ja, pa brez sira",
                  "ja, tri", "Ja, ob osmih", "ja, popust", "Okej, a lahko pozneje?", "Ja, kje pa ste?"):
        assert not je_cista_potrditev(fraza), fraza
    for fraza in ("ja", "Ja, v redu.", "Da, potrjujem", "seveda", "Ja, lahko", "Ja, v redu je, hvala", "Točno tako"):
        assert je_cista_potrditev(fraza), fraza


def preveri_razloge() -> None:
    from miza_pravila import NAVODILA_RAZLOGOV

    # Vsaka zavrnitev naročila, ki jo vrne /miza (lib/orders.php), mora imeti navodilo za Tatjano.
    for koda in ("empty_order", "unknown_item", "unavailable_item", "missing_option", "too_many_options",
                 "pickup_closed", "pickup_time_unavailable", "invalid_qty", "invalid_time", "too_many_open_orders", "orders_busy"):
        assert koda in NAVODILA_RAZLOGOV and NAVODILA_RAZLOGOV[koda].strip(), koda


def preveri_sheme_orodij() -> None:
    # OpenAI strict zavrne orodje, katerega parameter je prost objekt (dict / list[dict] / Any):
    # "additionalProperties is required to be supplied and to be false". Takrat Tatjana po pozdravu
    # molči (napaka 400 pri vsakem LLM klicu). Livekit se ne da uvoziti povsod, zato pregledamo kodo.
    import ast
    from pathlib import Path

    for datoteka in ("restavracija.py", "agent.py"):
        drevo = ast.parse((Path(__file__).parent / datoteka).read_text(encoding="utf-8"))
        for fn in ast.walk(drevo):
            if not isinstance(fn, ast.AsyncFunctionDef | ast.FunctionDef):
                continue
            if not any("function_tool" in ast.unparse(d) for d in fn.decorator_list):
                continue
            for arg in fn.args.args + fn.args.kwonlyargs:
                if arg.annotation is None or arg.arg in ("self", "context"):
                    continue
                t = ast.unparse(arg.annotation)
                prosti = any(x in t for x in ("dict", "Dict", "Any", "object"))
                assert not prosti, f"{datoteka}:{fn.name}({arg.arg}: {t}) - uporabi pydantic model, ne dict"


if __name__ == "__main__":
    for preizkus in (preveri_potrditve, preveri_osebe, preveri_datume, preveri_povzetek, preveri_cene, preveri_narocilo, preveri_napade_na_potrditev, preveri_razloge, preveri_sheme_orodij):
        preizkus()
        print("ok", preizkus.__name__)
    print("vse ok")
