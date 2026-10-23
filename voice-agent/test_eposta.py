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
# Del pred afno po golih črkah, domena z besedami.
preveri(
    "ime po črkah, domena z besedami",
    izgovori_eposte("Ponudbo pošljemo na janez@gmail.com, prav?"),
    "Ponudbo pošljemo na J, A, N, E, Z afna gmail pika com, prav?",
)
preveri(
    "podčrtaj, vezaj in števke",
    izgovori_eposte("brus_72@t-2.net"),
    "B, R, U, S, podčrtaj, 7, 2 afna t vezaj 2 pika net",
)
preveri(
    "naslov podjetja ostane z besedami",
    izgovori_eposte("Pišite na luka@kreativnisplet.si.", ("luka@kreativnisplet.si",)),
    "Pišite na luka afna kreativnisplet pika si.",
)
preveri("besedilo brez naslova", izgovori_eposte("Pokličite na 05 300 10 10."), "Pokličite na 05 300 10 10.")

# Zakaj črkovanje sploh obstaja (klic 22. 10. 2026, povpraševanje 559): "mavri"
# in "mauri" z besedami zvenita enako in stranka je napačen naslov potrdila.
preveri(
    "homofon se sliši različno",
    (", V, " in izgovori_eposte("stina.mavri@gmail.com")
     and ", U, " in izgovori_eposte("stina.mauri@gmail.com")),
    True,
)
# Sidrne besede ("es kot Slovenija") so bile zavrnjene (Luka, 22. 10. 2026): predolgo.
preveri("brez sidrnih besed", " kot " in izgovori_eposte("marko.zupan@gmail.com"), False)

# Model naslova pogosto ne zapiše z afno, ampak tako, kot ga bo izgovoril.
# Brez te poti črkovanje ne steče (klic 22. 10. 2026, RM_HLZUiN6RXGtp).
preveri(
    "govorjena oblika se vseeno črkuje",
    izgovori_eposte("Slišala sem »stinaikmavri afna gmail pika com«. Je to pravilno?"),
    "Slišala sem »S, T, I, N, A, I, K, M, A, V, R, I afna gmail pika com«. Je to pravilno?",
)
preveri(
    "govorjena oblika s piko",
    "A, pika, M" in izgovori_eposte("stina pika mavri afna gmail pika com"),
    True,
)
preveri(
    "govorjen naslov podjetja ostane z besedami",
    izgovori_eposte("Pišite na info afna kreativnisplet pika si.", ("info@kreativnisplet.si",)),
    "Pišite na info afna kreativnisplet pika si.",
)
# Zaporedja navadnih besed ne ujamemo: raje brez črkovanja kot napačen naslov.
preveri(
    "uvodne besede ne pridejo v naslov",
    izgovori_eposte("Slišala sem janez afna gmail pika com.").startswith("Slišala sem "),
    True,
)

# "pika", kot jo zapiše prepis po telefonu (klici 22. 10. 2026).
for _slisano in ("stina tika mavri", "stina ilka mavri", "stina ika mavri"):
    preveri(
        f"'{_slisano}' je pika",
        popravi_naslov(f"{_slisano} afna gmail pika com")[0],
        "stina.mavri@gmail.com",
    )
preveri(
    "govorjena oblika z 'ilka' se črka cela",
    izgovori_eposte("stina ilka mavri afna gmail pika com").startswith("S, T, I, N, A, pika"),
    True,
)

# Kar asistentka prebere, mora znati sestaviti nazaj.
preveri(
    "prebrano nazaj da isti naslov",
    popravi_naslov(izgovori_eposte("stina.mavri@gmail.com"))[0],
    "stina.mavri@gmail.com",
)

print("\nvse v redu")
