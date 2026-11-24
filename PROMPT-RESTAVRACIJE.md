# Tatjana Restaurant: plan in prompt za Claude Code

Stanje na 22. 11. 2026. Odločitve (Luka): vanilla PHP (podprta verzija, min. 8.3) + MySQL, ostane cPanel,
nov repo `tatjana_restaurant`, v1 = samo rezervacije + PWA za osebje.

Dokument ima dva dela: **A. plan** (za človeka) in **B. prompt** (prilepi v
Claude Code v prazni mapi novega repa).

---

## A. Plan

### Zakaj ta smer

- Microgramm (glavni konkurent) po javnih straneh nima modula za rezervacije
  gostov in nima telefonije. To je vrzel; blagajna ni.
- Lastna davčna blagajna (FURS, ZOI/EOR, offline, storno) je 8-18+ mesecev in
  visoko tveganje. V v1 je ne gradimo. Računi ostanejo v restavracijinem POS.
- Tatjana telefon že obstaja. Manjka skupni rezervacijski sistem in zaslon za
  osebje.

### Kaj gradimo v v1

1. Rezervacijsko jedro: restavracije (tenanti), mize, kapacitete, termini,
   pravila, atomarna rezervacija brez dvojnih zasedb.
2. HTTP API (isti vzorec kot `tools/*.php` v Tatjani): ločena pot za osebje
   (seja) in ločena za Tatjano (`TOOL_SECRET` na tenanta).
3. PWA za osebje: dnevni pregled, zasedenost miz, ročna rezervacija,
   prihod/no-show/odpoved. Namestljiva na telefon/tablico.
4. Orodja za Tatjano: `preveri_razpolozljivost`, `ustvari_rezervacijo`,
   `spremeni_rezervacijo`, `odpovej_rezervacijo`. Poslovna logika v PHP, ne v
   promptu.
5. SMS potrditev in opomnik (cron), povezava za odpoved.
6. Admin: restavracije, mize, urniki, zaprti dnevi, pravila, uporabniki.

### Kaj NE gradimo v v1

Naročila, kuhinjski zaslon, računi, plačila, FURS, native aplikacije, integracija
z Microgramm, spletni widget (v2).

### Arhitektura

```
Klicatelj -> LiveKit agent (Python, obstoječi voice-agent)
                 | HTTPS + skrivnost na tenanta
                 v
           api/ (PHP 8.3+)  <-- PWA osebja (seja, CSRF)
                 |
           Service sloj (Reservations, Tables, Availability)
                 |
           Repository sloj (PDO, vedno tenant_id)
                 |
               MySQL (InnoDB, transakcije)
```

Načela (prenesena iz Tatjane, ne kršimo):

1. **Kar mora veljati zanesljivo, je v kodi, ne v modelu.** Razpoložljivost,
   kapaciteta, meje in potrditev so v PHP.
2. **Vsak SQL-ni dostop gre skozi repository, ki vsiljuje `tenant_id`.** Test:
   restavracija A nikoli ne vidi podatkov B.
3. **Poslovna logika obstaja enkrat.** PWA in Tatjana kličeta isti service.
4. **Nič ni v kodi o posamezni restavraciji.** Vse v bazi/konfiguraciji.

### Omejitve cPanel (vplivajo na načrt)

- Brez SSH, brez Composerja, brez procesov v ozadju razen cron.
  -> Brez ogrodja. Lasten tanek router, PSR-4 autoload z roko.
  -> Posodabljanje PWA z **pollingom** (npr. 5 s). SSE/WebSocket šele na VPS.
  -> Cron za SMS opomnike in čiščenje.
- Frontend brez build koraka (vanilla JS, ES moduli, `manifest.json`,
  service worker), ker na strežniku ni Nodea. Če Claude Code utemelji build,
  mora biti izhod commitan in mora deploy ostati "Update from Remote".
- Deploy: cPanel Git (Update from Remote, nato Deploy HEAD Commit). `config.php`
  ni v gitu.
- UTF-8 brez BOM. Datoteke z BOM tiho podrejo odgovore.
- Lokalno ni MySQL: sintaksa/unit se preveri lokalno, SQL poizvedbe ne.
  Predlagaj `docker compose` MySQL za lokalni razvoj in integracijske teste.

### Podatkovni model (izhodišče, Claude Code naj predlaga končno shemo)

`tenants`, `locations`, `tables` (kapaciteta min/max, združljivost), `opening_hours`,
`closures`, `reservations` (status, source: phone/staff/web, party_size, start,
end, table assignment, kontakt, opombe, idempotency_key), `reservation_events`
(revizijska sled), `staff_users` (vloge), `sms_log`, `api_keys` (na tenanta).

Ključna zahteva: **rezervacija je atomarna**. Preverjanje prostosti in zapis v isti
transakciji z zaklepanjem (`SELECT ... FOR UPDATE` ali unikatni ključ na
rezerviran slot). Test sočasnosti obvezen.

### Faze

| Faza | Vsebina | Izhod |
|---|---|---|
| 0 | Pregled Tatjane, odločitve, shema, API pogodba, varnostni model | Specifikacija + shema, brez kode aplikacije |
| 1 | Jedro: tenanti, mize, rezervacije, razpoložljivost, testi | API + testi sočasnosti in izolacije |
| 2 | Avtentikacija osebja, PWA zasloni | Delujoča PWA lokalno |
| 3 | Orodja za Tatjano + simulacijski scenariji | Agent rezervira prek API |
| 4 | SMS, cron, admin | Potrditve, opomniki |
| 5 | Namestitev na cPanel, varnostni pregled, pilot | Pilotna restavracija |

Vsaka faza se konča z delujočim in preizkušenim stanjem. Faze se ne preskakuje.

### Varnost (minimum pred pravo stranko)

- Izolacija tenantov, avtomatizirani testi.
- Gesla `password_hash`, seja `HttpOnly`/`Secure`/`SameSite`, CSRF, rate limiting
  prijave, zaklep po neuspešnih poskusih.
- Skrivnosti na tenanta za Tatjano, primerjava `hash_equals`.
- Vsi parametri na strežniku preverjeni; AI nikoli ne dobi surovega SQL.
- Revizijska sled vsake spremembe rezervacije.
- GDPR: kontaktni podatki gostov, rok hrambe, brisanje, pogodba o obdelavi.
- Dnevna varnostna kopija izven `public_html`, test obnove.
- Padec: če API/agent ne dela, klic preusmeriti na telefon restavracije.

### Odprta vprašanja za Luko (Claude Code jih postavi v fazi 0)

1. Pilotna restavracija: ime, št. miz, kako zdaj vodijo rezervacije?
2. Pravila: trajanje obroka, največ oseb, združevanje miz, čakalni seznam?
3. SMS ponudnik in strošek (Twilio, slovenski gateway)?
4. Ločena baza ali skupna z WordPressom? (Priporočilo: ločena.)
5. Domena PWA (npr. `app.tatjana.ai` ali podmapa)?
6. Številka za restavracije: preusmeritev obstoječe ali nova DIDWW?
7. Prijava osebja: e-pošta + geslo ali PIN na napravi?

---

## B. Prompt za Claude Code

Prilepi vse od `=== PROMPT ===` do `=== KONEC ===`.

=== PROMPT ===

Si vodilni inženir v projektu **Tatjana Restaurant**. Delaš za Luko Kosovela
(Kreativni Splet). Vse, kar napišeš (pogovor, koda, komentarji, commit sporočila,
dokumentacija), je v **slovenščini**. Brez marketinškega tona. Pove, kaj si
naredil in kaj ne deluje. Razloži **zakaj**, ne samo kaj. Ne trdi, da je kaj
popravljeno, dokler ni preverjeno. Commit in push opravi Luka sam: pripravi
spremembe in izpiši ukaze.

### Kontekst

Obstoječi produkt **Tatjana** je telefonska AI asistentka za slovenska mala
podjetja: LiveKit Cloud agent v Pythonu kliče PHP HTTP orodja (`tools/*.php`) s
skupno skrivnostjo; podatki v MySQL; gostovanje na shared cPanelu. Repo je
`https://github.com/kosokroso/tatjana_ai`. **Najprej ga preberi** (`POVZETEK-ZA-AI.md`,
`PROJEKT.md`, `TODO.md`, `docs/`, `tools/core/`, `ai/call-guard.php`,
`voice-agent/agent.py`) in povzemi, kaj lahko ponovno uporabimo in česa ne.
Tatjane ne spreminjaj; je v živo.

Novi produkt: **sistem rezervacij za restavracije** z aplikacijo za osebje (PWA),
ki ga Tatjana uporablja prek istega API kot osebje. Računov, blagajne in FURS v
tem projektu **ne** gradimo; restavracija obdrži svoj POS (običajno Microgramm).

Odločitve, ki so že sprejete (ne odpiraj jih znova):

- Backend: **vanilla PHP + MySQL (InnoDB)**, brez ogrodja in Composerja.
  **PHP verzija:** najnovejša stabilna veja, ki jo cPanel ponuja in ki je še v
  podpori z varnostnimi popravki (minimum 8.3, ciljno 8.4 ali novejša). Nikoli
  7.x, 8.0, 8.1 ali verzija izven podpore. Pred začetkom preveri
  `https://www.php.net/supported-versions.php` in v cPanlu *Select PHP Version*,
  kaj je na voljo, in mi to poroči. Kodo piši za izbrano verzijo (strict_types,
  tipizirane lastnosti, `readonly`), ne za 7.4. V `docs/VARNOST.md` navedi, kdaj
  verzija izgubi podporo in kdo jo nadgradi. MySQL/MariaDB enako: samo podprte
  verzije. Brez tretjih knjižnic, kjer ni nujno; vsaka odvisnost je nova
  napadalna površina.
- Gostovanje: **shared cPanel**, brez SSH, procesi samo prek cron, deploy prek
  cPanel Git.
- Nov repo `tatjana_restaurant`. Delaš v prazni mapi.
- Obseg v1: rezervacije + PWA za osebje + orodja za Tatjano + SMS potrditve.
  Brez naročil, računov, plačil, FURS, native aplikacij.
- Frontend: vanilla JS (ES moduli), PWA (manifest + service worker), **brez
  build koraka**. Polling za osveževanje.

### Načela (ne kršiš jih)

1. Kar mora veljati zanesljivo (razpoložljivost, kapaciteta, dvojne rezervacije,
   potrditve, meje), je v kodi, nikoli samo v promptu modela.
2. Vsak dostop do podatkov gre skozi repository sloj, ki vsiljuje `tenant_id`.
   Nikoli surov SQL iz endpointa ali orodja.
3. Poslovna logika obstaja enkrat (service sloj). PWA in Tatjana kličeta isto.
4. V kodi ni imen ali nastavitev posamezne restavracije; vse v bazi/konfiguraciji.
5. Rezervacija je atomarna: preverjanje prostosti in zapis v isti transakciji z
   zaklepanjem, z idempotency key. Dokaži s testom sočasnosti.
6. Skrivnosti nikoli v repozitoriju. `config.php` ni v gitu; daj `config.example.php`.
7. Datoteke UTF-8 **brez BOM**. Preveri.
8. Spremembe po eno, vsaka preizkušena. Ne nakopičim devetih hkrati.

### Način dela

Delaj po fazah. **Po vsaki fazi se ustavi**, povzemi, kaj je narejeno in preverjeno,
in počakaj na Lukovo potrditev. Za načrtovanje uporabi plan mode. Večje faze
razdeli na manjše korake z jasnim "končano, ko".

**Faza 0 (začni tukaj, brez kode aplikacije):**

1. Preberi obstoječi repo in povzemi ponovno uporabne vzorce (adapter, ToolRegistry,
   RateLimiter, call-guard, Logger, deploy postopek).
2. **Postavi Luki vprašanja** (uporabi AskUserQuestion, največ 4 naenkrat), vsaj:
   pilotna restavracija in njena pravila (št. miz, trajanje obroka, združevanje
   miz, čakalni seznam); SMS ponudnik; ločena ali skupna baza; domena PWA; številka
   za restavracije; način prijave osebja (e-pošta+geslo ali PIN); pričakovano
   št. restavracij v prvem letu. Ne ugibaj odgovorov.
3. Napiši `docs/ARHITEKTURA.md`: slojna struktura, mape, router, avtentikacija,
   obravnava napak, logiranje z maskiranjem osebnih podatkov, konfiguracija.
4. Napiši `sql/schema.sql` (tenanti, lokacije, mize, urniki, zaprti dnevi,
   rezervacije, dogodki rezervacij, uporabniki osebja, SMS dnevnik, API ključi).
   Utemelji indekse in zaklepanje; opiši, kako preprečuješ dvojno zasedbo.
5. Napiši `docs/API.md`: vsi endpointi (osebje in Tatjana), vhod/izhod, napake,
   avtentikacija, idempotentnost, omejitve hitrosti.
6. Napiši `docs/VARNOST.md`: model groženj (izolacija tenantov, prevzem seje,
   brute force, napad prek govora na AI, uhajanje skrivnosti, GDPR) in kako
   vsako pokrijemo ter testiramo.
7. Napiši `docs/TESTNI-NACRT.md`: unit, integracijski (lokalni MySQL v
   docker compose), sočasnost, izolacija tenantov, simulacijski scenariji za
   Tatjanine nove orodja (`lk agent simulate`).
8. Napiši `PROJEKT.md` (razlogi in stanje) in `TODO.md` (kljukice). Iste točke ne
   piši na obe mesti.
9. Ustavi se in počakaj na potrditev.

**Faza 1: jedro.** Router, repository, service sloj za mize, razpoložljivost in
rezervacije. API za osebje brez UI. Testi: razpoložljivost, kapaciteta, urniki,
zaprti dnevi, sočasne rezervacije (dva procesa, ena zadnja miza), idempotentnost,
izolacija tenantov. Končano, ko so vsi testi zeleni in ko test sočasnosti dokaže,
da dvojne rezervacije ni.

**Faza 2: avtentikacija in PWA.** Prijava, vloge (osebje, vodja), CSRF, rate
limiting. PWA zasloni: dnevni pregled, tloris/seznam miz, nova rezervacija,
prihod, no-show, odpoved, urejanje. Mobilno prvo, velike tipke, deluje na
telefonu in tablici. Offline: pokaži zadnje znano stanje in jasno označi, da je
zastarelo; pisanje brez povezave **ne** sme tiho uspeti.

**Faza 3: Tatjana.** Orodja `preveri_razpolozljivost`, `ustvari_rezervacijo`,
`spremeni_rezervacijo`, `odpovej_rezervacijo` kot HTTP endpointi s skrivnostjo na
tenanta. Potrditev povzetka pred zapisom izvaja koda, ne prompt. Pripravi predlogo
za `voice-agent` (v ločenem repozitoriju Tatjane; ne spreminjaj ga sam, ampak
pripravi sledljiv predlog sprememb) in `scenarios.yaml` scenarije, vključno z
napadi (dvojna rezervacija, preseganje kapacitete, vsiljena obljuba, tuja
restavracija).

**Faza 4: SMS in admin.** SMS potrditev in opomnik prek cron, povezava za
odpoved, pregled `sms_log`, admin za restavracije/mize/urnike/uporabnike.

**Faza 5: namestitev in pilot.** Navodila za cPanel deploy (cPanel Git, ločen
`config.php`), `setup` postopek, kontrolni seznam pred predajo (kot v
`docs/setup.md` Tatjane), varnostna kopija z `mysqldump` izven `public_html`,
monitoring (uptime, napake), načrt ob izpadu (preusmeritev klica na telefon
restavracije). Poročilo o preizkusih in znanih tveganjih.

### Kaj mi poročaj

Po vsaki fazi: kaj je narejeno, kaj je preverjeno (kako), kaj **ni** preverjeno ali
ne deluje, odprta vprašanja, predlog naslednjega koraka. Če nekaj ni mogoče
preveriti lokalno (npr. SQL na produkcijskem MySQL), to napiši izrecno.

### Prepovedano

- Ne spreminjaj živega repa Tatjane ali produkcije.
- Ne zapisuj gesel, ključev ali skrivnosti v datoteke repozitorija. Če jih Luka
  prilepi v pogovor, ga opozori, naj jih zamenja.
- Ne gradi blagajne, računov, plačil ali FURS integracije.
- Ne dodajaj ogrodij ali odvisnosti brez utemeljitve in Lukove potrditve.

=== KONEC ===
