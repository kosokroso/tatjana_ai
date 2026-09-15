# Telefonska številka — postopek po korakih

Cilj: stranka pokliče slovensko številko, oglasi se Tatjana, odgovarja na vprašanja
o ponudbi in zbere povpraševanje. Isti podatki, ista baza, ista e-pošta kot v klepetu.

## Kaj se doda in kaj ostane

```
Stranka pokliče
   ↓
SIP ponudnik — proda slovensko številko, klic preda naprej
   ↓
LiveKit Cloud — sprejme klic, vodi zvok, zažene agenta
   ↓
agent.py — posluša, govori, odloča
   ↓ (kadar rabi podatek, prek HTTPS)
kreativnisplet.si/asistent/tools/*.php   ← to že imaš in se ne spremeni
   ↓
Povpraševanje v bazo, obvestilo na e-pošto — kot doslej
```

Nič poslovne logike se ne prepisuje. Agent je samo ušesa in usta.

---

## 1. Skupna skrivnost

Agent teče drugje kot PHP, zato se mora predstaviti. Na strežniku v
`public_html/asistent/config.php` vpiši:

```php
define('TOOL_SECRET', 'dolg-nakljucen-niz');
```

Isti niz pozneje vpišeš v `.env` agenta. Brez njega agent ne dobi sistemskega
prompta in ga rate limit obravnava kot navadnega obiskovalca.

Nov niz zgeneriraš z:

```bash
php -r "echo bin2hex(random_bytes(24));"
```

## 2. Račun na LiveKit

1. Registracija na https://cloud.livekit.io
2. Ustvari projekt, izberi regijo v Evropi
3. **Settings → Keys** → zapiši `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET`

Brezplačni paket zajema okoli 1.000 minut agenta na mesec, kar je pri triminutnih
klicih približno 330 klicev.

## 3. Slovenska telefonska številka

LiveKit svoje številke prodaja zaenkrat samo v ZDA, zato potrebuješ SIP ponudnika.

**Priporočilo: DIDLogic.** LiveKit ima zanj objavljen uraden vodič
(*"Create and configure a didlogic SIP trunk"*), zato nastavitev ni ugibanje.
Samopostrežno, aktivacija 8–48 ur, en račun pokriva dohodne in odhodne klice,
novi računi prvih 30 dni nimajo mesečnega minimuma.

| Ponudnik | Aktivacija | Opomba |
|---|---|---|
| **DIDLogic** | 8–48 h | uraden vodič za LiveKit |
| Telnyx | samopostrežno, ~3 $/mes | uveljavljeno, več preverjanja |
| Zadarma | samopostrežno | najceneje, brez vodiča za LiveKit |
| Twilio | nekaj delovnih dni | največ birokracije za SI številke |

**Slovenija zahteva lokalni naslov** — poštni predal ne zadošča. Sedež v Braniku
to izpolnjuje, potreben pa bo dokaz (izpis iz registra, račun za storitev).
Preverjanje je najdaljši korak celotnega postopka, zato ga sproži prvega in
medtem delaj korake 4 in 5.

Postopek:

1. DIDLogic portal → **Numbers → Buy a number → Slovenia**
2. Številki nastavi cilj na **SIP endpoint tvojega LiveKit projekta**
3. V LiveKitu ustvari **inbound trunk z dispatch pravilom**, ki klic preda
   agentu `tatjana`

## 4. Azure za slovenski glas

Brez tega bo Tatjana govorila slovensko s tujim naglasom in narobe brala številke.

1. https://portal.azure.com → **Speech services** → Create
2. Region **West Europe**, Pricing tier **F0** (brezplačno, 500.000 znakov mesečno)
3. **Keys and Endpoint** → `AZURE_SPEECH_KEY` in regija

## 5. Zaženi agenta

```bash
cd voice-agent
python -m venv venv && venv\Scripts\activate     # Windows
pip install -r requirements.txt
copy .env.example .env
```

Izpolni `.env` z vrednostmi iz korakov 1–4, nato:

```bash
python agent.py console
```

Ta način te poveže z mikrofonom računalnika, brez telefona in brez številke.
Tu preveriš, ali Tatjana razume slovenščino, ali kliče orodja in kako zveni.
**Šele ko to deluje, ima smisel priklapljati telefonijo.**

Ko je v redu:

```bash
python agent.py dev
```

Agent se poveže na LiveKit in sprejema prave klice.

## 6. Objava

Agent mora teči neprekinjeno — tvoj računalnik za to ni primeren. Dve poti:

- **LiveKit Cloud** ga gostuje sam (`lk agent deploy`), kar je najmanj dela
- **VPS** (Hetzner od 4 € na mesec) s `systemd` enoto, če želiš nadzor

---

## Kaj testirati, preden greš k stranki

| Preizkus | Kaj mora narediti |
|---|---|
| "Koliko stane spletna stran?" | pokliče `search_services`, pove **od** 399 € |
| "Kaj pa Meta oglasi?" | pove "cena po dogovoru", ne izmisli zneska |
| Narekovanje e-pošte | ponovi nazaj po delih in počaka na potrditev |
| Povpraševanje do konca | v bazi nastane zapis, e-pošta prispe |
| Hrup v ozadju, narečje | razume ali pošteno prosi za ponovitev |
| Prekinjanje med govorom | agent utihne in posluša |

## Strošek

Predpostavka: povprečen klic 3 minute.

| Postavka | Na klic | 100 klicev/mesec |
|---|---|---|
| Dohodne minute | ~0,04 € | ~4,50 € |
| Najem številke | — | ~2 € |
| Prepis govora | ~0,017 € | ~1,70 € |
| Model | ~0,005 € | ~0,50 € |
| Govor (Azure F0) | — | 0 € do 500.000 znakov |
| LiveKit | — | 0 € do 1.000 minut |
| **Skupaj** | **~0,09 €** | **~9 €** |

Ocene, ne ponudba — cene se spreminjajo, največja neznanka so slovenske minute
pri konkretnem SIP ponudniku. Red velikosti drži.

## Opozorilo glede kode agenta

`agent.py` je napisan po dokumentaciji LiveKit, a ni bil pognan. API se med
različicami spreminja (novejše različice uvajajo `AgentServer` in `inference.*`
namesto neposrednih vtičnikov). Če se ob zagonu zalomi pri uvozih ali imenih
razredov, primerjaj z uradnim primerom za nameščeno različico:
https://docs.livekit.io/agents/start/voice-ai/

Logika orodij in klici na PHP so neodvisni od tega in ostanejo enaki.
