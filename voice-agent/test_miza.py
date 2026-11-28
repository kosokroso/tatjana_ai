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
    sestavi_pozdrav,
)
from izgovorjava import (
    NAJVEC_KLJUCNIH,
    besedilo_za_govor,
    meja_za_govor,
    stevilo_besedno,
    italijanski_izgovor,
    izgovori_jedi,
    kategorije_jedi,
    kljucne_besede,
    koreni_menija,
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


# Meni kot ga vrne ai/menu (oblika iz klica 26. 11. 2026, skrajšano).
MENI = [
    {"id": 1, "kategorija": "Predjedi", "jed": "Pršut in sir", "opis": "Kraški pršut, domači sir, olive"},
    {"id": 4, "kategorija": "Glavne jedi", "jed": "Burger Goriška", "opis": "Goveji burger, cheddar",
     "izbire": [{"skupina": "Dodatki", "moznosti": [{"id": 3, "ime": "Slanina"}, {"id": 5, "ime": "Jalapeño"}]}]},
    {"id": 6, "kategorija": "Pice", "jed": "Margerita", "opis": "Paradižnik, mozzarella, bazilika",
     "izbire": [{"skupina": "Dodatki", "moznosti": [{"id": 6, "ime": "Šampinjoni"}]}]},
    {"id": 7, "kategorija": "Pice", "jed": "Pršut", "opis": "Paradižnik, mozzarella, pršut, rukola"},
    {"id": 10, "kategorija": "Testenine", "jed": "Pappardelle al ragù", "opis": "Govedina, pancetta"},
    {"id": 9, "kategorija": "Sladice", "jed": "Gibanica", "opis": "Prekmurska"},
]


def preveri_kljucne_besede() -> None:
    k = kljucne_besede(MENI, "Gostilna Pri Mostu")
    # Ime restavracije in imena jedi najprej: prompt prepisa vzame samo prvih 30.
    assert k[:3] == ["Gostilna Pri Mostu", "Pršut in sir", "Burger Goriška"], k
    for beseda in ("Margerita", "Pršut", "Gibanica", "pica", "pice", "Jalapeño", "Šampinjoni", "Testenine"):
        assert beseda in k, beseda
    assert len(k) == len({b.lower() for b in k}), "podvojene besede"
    assert k.index("Gibanica") < k.index("Šampinjoni"), "jedi pred izbirami"
    # Velik meni: meja, brez napake.
    velik = [{"id": i, "kategorija": "Jedi", "jed": f"Jed številka {i}"} for i in range(200)]
    assert len(kljucne_besede(velik)) == NAJVEC_KLJUCNIH
    # Pokvarjen ali prazen meni: samo osnovne besede, brez napake.
    assert "pica" in kljucne_besede([None, "x", {"jed": None}])
    assert kljucne_besede([])[0] == "rezervacija"


def preveri_izgovorjavo() -> None:
    for zapis, izgovor in (
        ("capricciosa", "kapričoza"), ("quattro", "kvatro"), ("formaggi", "formadži"), ("stagioni", "stadžoni"),
        ("prosciutto", "prošuto"), ("gnocchi", "njoki"), ("bruschetta", "brusketa"), ("focaccia", "fokača"),
        ("tagliatelle", "taljatele"), ("lasagne", "lazanje"), ("mozzarella", "mocarela"), ("pancetta", "pančeta"),
        ("cappuccino", "kapučino"), ("Speck", "Spek"), ("Pappardelle", "Papardele"), ("pizza", "pica"),
    ):
        assert italijanski_izgovor(zapis) == izgovor, (zapis, italijanski_izgovor(zapis))

    koreni = koreni_menija(MENI)
    # Stalni slovar velja vedno, tudi s sklanjatvijo; velika začetnica ostane.
    assert izgovori_jedi("Margerita z mozzarello in Quattro Formaggi.") == "Margerita z mocarelo in Kvatro Formadži."
    assert izgovori_jedi("burger s cheddarjem in jalapeñom") == "burger s čedarjem in halapenjom"
    assert izgovori_jedi("špageti carbonara, pizza") == "špageti karbonara, pica"
    # Italijanska pravila samo za besede z menija.
    assert "papardele" in izgovori_jedi("Imamo pappardelle al ragù.", koreni).lower()
    # Slovenske besede in imena ostanejo nedotaknjena, tudi z italijanskimi črkami.
    for stavek in ("Slab signal, gospod Ignac.", "Oddaja naročila je uspela.", "Na ime Luka Kosovel, Tatjana.",
                   "Gibanica in pica Pršut, 12 evrov in 50 centov, ob 19:00.", "Gospa Anna Rossi, Mattia Zanetti."):
        assert izgovori_jedi(stavek, koreni) == stavek, izgovori_jedi(stavek, koreni)
    # Brez menija ne pade.
    assert izgovori_jedi("", set()) == ""


def preveri_povzetek_s_kategorijo() -> None:
    kategorije = kategorije_jedi(MENI)
    assert kategorije[7] == "Pice"
    linije = [{"item_id": 7, "name": "Pršut", "qty": 1, "options_text": None}]
    p = povzetek_narocila(linije, 1250, DANES, "19:00", "Luka Kosovel", DANES, kategorije)
    assert p.startswith("Naročam: pica Pršut. Skupaj 12 evrov in 50 centov."), p
    # Predjed z istim imenom ostane brez "pica"; pica, ki že ima "pica" v imenu, tudi.
    linije2 = [{"item_id": 1, "name": "Pršut in sir", "qty": 1}, {"item_id": 6, "name": "Pizza Margerita", "qty": 2}]
    p2 = povzetek_narocila(linije2, 2950, DANES, "19:00", "Ana", DANES, {1: "Predjedi", 6: "Pice"})
    assert "Naročam: Pršut in sir; Pizza Margerita, 2 krat." in p2, p2
    # Preverjanje izgovorjenega povzetka dela tudi s predpono in z uvodom ob ponovitvi.
    assert narocilo_izgovorjeno("Oprostite, da preverim: " + p, ["Pršut"], 1250, "19:00")
    # Brez kategorij (stara oblika klica) ostane kot prej.
    assert povzetek_narocila(linije, 1250, DANES, "19:00", "Luka", DANES).startswith("Naročam: Pršut.")


def preveri_pogovorne_potrditve() -> None:
    # Klic 26. 11. 2026: gost je potrdil po domače.
    for fraza in ("Ja, dobro, pridem iskat, ajde.", "Ja, ja, potrdim.", "Ma ja, dobro.", "Jap, zmenjeno.", "Ja, daj."):
        assert je_cista_potrditev(fraza), fraza
    # Nova polnila ne smejo odpreti poti spremembam.
    for fraza in ("Ja, daj še eno pico", "Dej, dodaj kolo", "Ja, pridem ob osmih", "Ja, bomo dva", "ajde", "dobro, ampak brez sira"):
        assert not je_cista_potrditev(fraza), fraza


def preveri_pozdrav() -> None:
    privzeti = "Pozdravljeni, tukaj Tatjana, digitalna asistentka restavracije."
    assert sestavi_pozdrav(privzeti, "Gostilna Pri Mostu") == (
        "Gostilna Pri Mostu, pozdravljeni. Tukaj Tatjana, digitalna asistentka. Kako vam lahko pomagam?"
    )
    assert sestavi_pozdrav("", "") == privzeti + " Kako vam lahko pomagam?"
    # Pozdrav restavracije ostane; vprašanje dodamo samo, če ga nima.
    assert sestavi_pozdrav("Dober dan, Pri Mostu. Izvolite?", "X") == "Dober dan, Pri Mostu. Izvolite?"
    assert sestavi_pozdrav("Dober dan, Pri Mostu.", "X") == "Dober dan, Pri Mostu. Kako vam lahko pomagam?"


def preveri_opombe_in_nejasno_potrditev() -> None:
    from miza_pravila import NEJASNA_POTRDITEV

    # Klic 28. 11. 2026: opomba mora biti v povzetku, da jo gost sliši.
    p = povzetek_rezervacije("Marjana Semenič", DANES, "20:00", 6, DANES, "otroški stol, ena oseba brez glutena.")
    assert p == (
        "Rezerviram mizo za 6 oseb, danes, 24. novembra ob 20:00, na ime Marjana Semenič. "
        "Opomba: otroški stol, ena oseba brez glutena. Ali drži?"
    ), p
    assert povzetek_izgovorjen(p, "Marjana Semenič", 6, "20:00")
    # Brez opombe ostane povzetek kot prej.
    assert "Opomba" not in povzetek_rezervacije("Ana", DANES, "19:00", 2, DANES, "  ")
    n = povzetek_narocila([{"name": "Tiramisu", "qty": 1}], 550, DANES, "19:00", "Ana", DANES, None, "brez oreščkov")
    assert n.endswith("na ime Ana. Opomba: brez oreščkov. Ali drži?"), n
    # Angleška potrditev ("Yes.") velja; navodilo ne sme omenjati sistema kot besedila za gosta.
    for fraza in ("Yes.", "Yes, okay.", "Ja, yes."):
        assert je_cista_potrditev(fraza), fraza
    assert not je_cista_potrditev("Yes, but at eight")
    korak = NEJASNA_POTRDITEV["naslednji_korak"]
    assert "Da preverim" in korak and "ne omenjaj sistema" in korak


def preveri_stevila_za_govor() -> None:
    # Klic 28. 11. 2026: datumi in ure so zveneli slabo. Povzetek iz tega klica:
    p = ("Naročam: pica Margerita, 3 krat; pica Pršut, 2 krat. Skupaj 62 evrov. "
         "Prevzem jutri, 29. novembra ob 12:00, na ime Drejc Kogej. Ali drži?")
    assert besedilo_za_govor(p) == (
        "Naročam: pica Margerita, trikrat; pica Pršut, dvakrat. Skupaj dvainšestdeset evrov. "
        "Prevzem jutri, devetindvajsetega novembra ob dvanajstih, na ime Drejc Kogej. Ali drži?"
    ), besedilo_za_govor(p)
    for zapis, govor in (
        ("za 14,50 €", "za štirinajst evrov in petdeset centov"),
        ("12 evrov in 50 centov", "dvanajst evrov in petdeset centov"),
        ("1 evro, 2 evra, 3 evre, 21 €, 1 cent", "en evro, dva evra, tri evre, enaindvajset evrov, en cent"),
        ("za 1 osebo, za 2 osebi, za 6 oseb", "za eno osebo, za dve osebi, za šest oseb"),
        ("1. decembra ob 19:30", "prvega decembra ob devetnajstih trideset"),
        ("od 12.00 dalje, do 14.30", "od dvanajstih dalje, do štirinajstih trideset"),
        ("ob 7 zvečer", "ob sedmih zvečer"),
        ("za 90 minut", "za devetdeset minut"),
        ("Koda je J – T – R – N – D – 8.", "Koda je je, te, er, en, de, osem."),
        ("osem, X, H, S, Y, F", "osem, iks, ha, es, ipsilon, ef"),
        ("Luka K O S O V E L", "Luka ka, o, es, o, ve, e, el"),
    ):
        assert besedilo_za_govor(zapis) == govor, (zapis, besedilo_za_govor(zapis))
    # Ne sme pokvariti: telefonske številke, leta, posamezne velike črke, 1..4 brez enote.
    for ostane in ("Pokličite 041 555 666.", "Leta 2026.", "V redu. S tem je vse. K vam pridem.",
                   "Imamo 2 pici in 3 tiramisuje."):
        assert besedilo_za_govor(ostane) == ostane, besedilo_za_govor(ostane)
    assert [stevilo_besedno(n) for n in (0, 11, 21, 62, 145, 1999)] == [
        "nič", "enajst", "enaindvajset", "dvainšestdeset", "sto petinštirideset", "tisoč devetsto devetindevetdeset"]

    # Glas dobi del s števko šele, ko je stavek cel; besedilo brez števk gre takoj.
    assert meja_za_govor("Prevzem jutri, 29. ", True) == -1
    b = "Prevzem jutri, 29. novembra ob 12:00, na ime Drejc. Ali "
    assert b[: meja_za_govor(b, True) + 1] == "Prevzem jutri, 29. novembra ob 12:00, na ime Drejc. "
    assert meja_za_govor("Dober dan, kako vam ", True) == len("Dober dan, kako vam ") - 1
    # Agencijska Tatjana (brez lastne izgovorjave) ostane pri zamiku ene besede.
    assert meja_za_govor("Prevzem jutri, 29. ", False) == len("Prevzem jutri, 29.")


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
    for preizkus in (preveri_potrditve, preveri_osebe, preveri_datume, preveri_povzetek, preveri_cene, preveri_narocilo, preveri_napade_na_potrditev, preveri_razloge, preveri_sheme_orodij,
                     preveri_kljucne_besede, preveri_izgovorjavo, preveri_povzetek_s_kategorijo,
                     preveri_pogovorne_potrditve, preveri_pozdrav,
                     preveri_opombe_in_nejasno_potrditev,
                     preveri_stevila_za_govor):
        preizkus()
        print("ok", preizkus.__name__)
    print("vse ok")
