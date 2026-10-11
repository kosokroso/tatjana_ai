"""Samotest branja in izgovora e-pošte.

    python test_eposta.py

Brez knjižnic in brez omrežja, zato teče tudi na razvijalčevem računalniku,
kjer ni niti LiveKita niti ključev.
"""

from eposta import izgovori_eposte, je_naslov, popravi_naslov


def preveri(opis: str, dobljeno, pricakovano) -> None:
    assert dobljeno == pricakovano, f"{opis}: dobil {dobljeno!r}, pričakoval {pricakovano!r}"
    print(f"  ok  {opis}")


print("popravi_naslov")

# Zapisan naslov ostane, kakršen je — brez popravka in brez nove potrditve.
preveri("pravilen naslov", popravi_naslov("janez.novak@gmail.com"), ("janez.novak@gmail.com", ""))
preveri("velike črke", popravi_naslov(" Janez.Novak@Gmail.com "), ("janez.novak@gmail.com", ""))

# Narekovanje po črkah: to je tisto, kar je model doslej sestavljal sam.
preveri(
    "po črkah",
    popravi_naslov("ka, o, es, o, ve, e, el, afna, ge, em, a, i, el, pika, ce, o, em"),
    ("kosovel@gmail.com", "sestavljeno iz narekovanja"),
)
preveri(
    "ime in domena kot besedi",
    popravi_naslov("janez pika novak afna siol pika net"),
    ("janez.novak@siol.net", "sestavljeno iz narekovanja"),
)
preveri(
    "at namesto afne",
    popravi_naslov("metka at telemach pika net"),
    ("metka@telemach.net", "sestavljeno iz narekovanja"),
)
preveri(
    "podčrtaj in števke",
    popravi_naslov("brus spodnja črta sedem dve afna outlook pika com"),
    ("brus_72@outlook.com", "sestavljeno iz narekovanja"),
)
preveri(
    "de kot Dani",
    popravi_naslov("de kot Dani, a, en, i, afna amis pika net"),
    ("dani@amis.net", "sestavljeno iz narekovanja"),
)
preveri(
    "strešica v naslovu ne obstaja",
    popravi_naslov("špela afna gmail pika com"),
    ("spela@gmail.com", "sestavljeno iz narekovanja"),
)

# Skoraj ujeta domena. Varno samo zato, ker asistentka naslov po tem prebere
# nazaj in čaka na novo potrditev.
preveri("domena mimo", popravi_naslov("ana@gmial.com"), ("ana@gmail.com", "domena popravljena"))
preveri("tuja domena ostane", popravi_naslov("ana@mojepodjetje.si"), ("ana@mojepodjetje.si", ""))

# Kar ni naslov, mora ostati prazno: polje brez naslova pomeni vprašanje
# stranki, ne ugibanje.
preveri("telefonska številka", popravi_naslov("041 234 567"), ("", ""))
preveri("aaa", popravi_naslov("aaa"), ("", ""))
preveri("prazno", popravi_naslov(""), ("", ""))
preveri("brez afne", popravi_naslov("janez novak gmail com"), ("", ""))

print("je_naslov")
preveri("pravi", je_naslov("a@b.si"), True)
preveri("brez pike", je_naslov("a@b"), False)

print("izgovori_eposte")
preveri(
    "znana domena ostane beseda",
    izgovori_eposte("Ponudbo pošljemo na janez@gmail.com, prav?"),
    "Ponudbo pošljemo na je, a, en, e, zet, afna, gmail pika com, prav?",
)
preveri(
    "neznana domena ostane beseda",
    izgovori_eposte("ana@mojafirma.si"),
    "a, en, a, afna, mojafirma pika si",
)
preveri(
    "naslov podjetja brez črkovanja",
    izgovori_eposte(
        "Pišite na luka@kreativnisplet.si.", ("luka@kreativnisplet.si",)
    ),
    "Pišite na luka afna kreativnisplet pika si.",
)
preveri(
    "izvzetje velja samo za ta naslov",
    izgovori_eposte("ana@gmail.com", ("luka@kreativnisplet.si",)),
    "a, en, a, afna, gmail pika com",
)
preveri(
    "pika v imenu",
    izgovori_eposte("a.b@gov.si"),
    "a, pika, be, afna, gov pika si",
)
preveri("besedilo brez naslova", izgovori_eposte("Pokličite na 05 300 10 10."), "Pokličite na 05 300 10 10.")

print("\nvse v redu")
