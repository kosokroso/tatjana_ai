"""Preveri varovalki, ki končata klic: ponovitve in obrati brez orodja.

Zagon iz mape voice-agent:
    python test_varovalke.py

Zakaj obstaja: 11. 10. 2026 je varovalka obratov brez orodja odložila zdrav
klic sredi pogovora. Meja je bila dosežena tudi zato, ker je vsak "ja" štel
kot obrat. Oboje se brez preizkusa ne opazi, ker se pokaže šele po telefonu.
"""

from agent import (
    OBRATI_BREZ_ORODJA_PRIVZETO,
    _je_potrditev,
    _je_vprasanje,
    _povzetek_vsebuje_ime,
    je_podatek_stranke,
    je_samo_pritrditev,
    kljuc_vprasanja,
)


def preveri_pritrditve() -> None:
    for besedilo in ("Ja", "ja ja", "Mhm.", "Aha!", "ok", "Hvala.", "Ja, prosim"):
        assert je_samo_pritrditev(besedilo), besedilo

    for besedilo in (
        "Koliko stane spletna stran",
        "Ja, zanima me spletna trgovina",
        "Fotografiranje na domu",
        "",
    ):
        assert not je_samo_pritrditev(besedilo), besedilo


def preveri_kljuc() -> None:
    # Isto vprašanje z drugimi besedami mora dati isti ključ.
    assert kljuc_vprasanja("Koliko stane spletna stran") == kljuc_vprasanja(
        "In koliko bi stala ena spletna stran"
    )
    # Dve različni vprašanji se ne smeta zliti v isto ponovitev.
    assert kljuc_vprasanja("Koliko stane spletna stran") != kljuc_vprasanja(
        "Kako poteka delo pri vas"
    )
    assert kljuc_vprasanja("") == ""


def preveri_podatke_stranke() -> None:
    # Narekovan kontakt ni ponovljeno vprašanje in ne sme končati klica.
    for besedilo in (
        "marko.zupan@gmail.com",
        "Marko pika Zupan at gmail.com",
        "041 234 567",
        "+386 41 234 567",
    ):
        assert je_podatek_stranke(besedilo), besedilo

    for besedilo in (
        "Koliko stane spletna stran",
        "Imamo 5 zaposlenih",
        "",
    ):
        assert not je_podatek_stranke(besedilo), besedilo


def preveri_vprasanja() -> None:
    # Klic se ne sme končati, dokler stranka sprašuje.
    for besedilo in (
        "Koliko stane spletna stran?",
        "kako poteka delo pri vas",
        "In kdaj bi lahko začeli",
        "Kje imate pisarno",
    ):
        assert _je_vprasanje(besedilo), besedilo

    # "ali" in "a" sta tudi veznika — slovo se ne sme zavrniti po nepotrebnem.
    for besedilo in (
        "Hvala, to mi pomaga",
        "Ja, oddajte povpraševanje",
        "Lep pozdrav in nasvidenje",
        "Spletno stran ali trgovino, obojno bi rad",
        "",
    ):
        assert not _je_vprasanje(besedilo), besedilo


def preveri_potrditve() -> None:
    for besedilo in ("Ja", "Tako je", "V redu", "Se strinjam", "Kar oddaj", "Potrjujem"):
        assert _je_potrditev(besedilo), besedilo

    # Sprememba podatka ni potrditev povzetka.
    for besedilo in (
        "Ne, e-pošta je marko pri gmail",
        "Raje osem kubikov",
        "Koliko stane",
    ):
        assert not _je_potrditev(besedilo), besedilo

    # Povzetek mora vsebovati ime, drugače potrditev ne pove, kaj je potrjeno.
    assert _povzetek_vsebuje_ime("Torej Marko Zupan, 041 234 567, prav?", "Marko Zupan")
    assert not _povzetek_vsebuje_ime("Torej 041 234 567, prav?", "Marko Zupan")
    assert not _povzetek_vsebuje_ime("", "Marko Zupan")


def preveri_privzeto_mejo() -> None:
    # Meja 10 je odložila zdrav klic. Nižja vrednost je regresija.
    assert OBRATI_BREZ_ORODJA_PRIVZETO >= 20, OBRATI_BREZ_ORODJA_PRIVZETO


if __name__ == "__main__":
    preveri_pritrditve()
    preveri_podatke_stranke()
    preveri_vprasanja()
    preveri_potrditve()
    preveri_kljuc()
    preveri_privzeto_mejo()
    print("vse v redu")
