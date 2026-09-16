# Tatjana — AI asistent za spletne strani in telefon

Popoln pregled projekta. Ta datoteka je vir resnice o tem, kaj sistem je, kaj
zna, kako je zgrajen in kje smo. **Posodobi jo ob vsaki večji spremembi.**

Zadnja posodobitev: 16. 9. 2026

---

## 1. Kaj to je

AI asistent, ki se na spletni strani podjetja pogovarja z obiskovalci — po
besedilu ali z glasom — odgovarja na vprašanja o ponudbi, cenah in rokih ter
zbira povpraševanja. Isti asistent bo sprejemal telefonske klice.

**Poslovni namen:** Luka to prodaja slovenskim malim podjetjem kot storitev z
mesečnim vzdrževanjem. Prva stranka je njegova lastna agencija Kreativni Splet.
Zato ni grajen za eno podjetje: ime, panoga, katalog in kontakti so v
konfiguraciji in bazi, ne v kodi.

| | |
|---|---|
| Živi naslov | `https://kreativnisplet.si/asistent/` |
| Repozitorij | `https://github.com/kosokroso/tatjana_ai` (javen) |
| Gostovanje | cPanel, `public_html/asistent/`, uporabnik `kreati02` |
| Baza | skupna z WordPressom, tabele s predpono `ai_` |
| Model | `gpt-4o-mini` |
| Glas | Azure `sl-SI-PetraNeural`, regija `italynorth` |

---

## 2. Kaj asistent zna

### Odgovarja na vprašanja o ponudbi
Ko obiskovalec vpraša po ceni, obsegu ali kaj paket vključuje, asistent **pokliče
orodje**, ki prebere katalog iz baze. Cen si nikoli ne izmišlja.

- Če cena ni vpisana → pove *"cena po dogovoru"*
- Če je cena izhodiščna → pove *"od 399 €"*, nikoli 399 € kot končno
- Če storitev ni v ponudbi (`active = 0`) → je ne omeni

### Pove podatke o poslovanju
Delovni čas (z izračunom, ali je odprto prav zdaj), roke in potek dela, pogoje
plačila. Delovni čas bere iz baze, ostalo iz `data/business-info.json`.

### Preveri stanje projekta obstoječe stranke
Zahteva **dva podatka**: številko projekta IN telefon ali e-pošto, s katero je
bil naročen. Ob neujemanju vrne enak odgovor kot pri neobstoječem projektu, da
iz razlike ni mogoče ugotoviti, katere številke obstajajo.

### Zbere povpraševanje
Vodi pogovor proti oddaji: vpraša, kaj stranka potrebuje, zbere **ime, telefon
IN e-pošto**, povzame vse skupaj, počaka na potrditev, šele nato odda. Podrobnosti
o projektu zapiše v polje `note`, da lahko ekipa pripravi ponudbo brez klica.

**Ponudbe ne pripravi in cene ne potrdi** — to je namerno. Cena je odvisna od
obsega; asistent, ki bi jo zavezujoče obljubil, bi podjetje lahko drago stal.

### Govori in posluša
Na spletni strani: mikrofon → prepis → odgovor → govor. Za telefon je pripravljen
ločen agent, ki teče zunaj gostovanja.

---

## 3. Kako je zgrajeno

```
Obiskovalec (brskalnik ali telefon)
        │
        ├─ index.html ........... glasovni asistent + gumbi do portalov
        └─ widget.js ............ vgradnja klepeta v tujo stran
                │
                ▼
        ai/chat.php ............. pogovor z OpenAI, zanka klicanja orodij
        ai/transcribe.php ....... govor → besedilo
        ai/speak.php ............ besedilo → govor (Azure ali OpenAI)
        ai/guard.php ............ skupna zaščita vseh treh
                │
                ▼
        ToolRegistry ............ sestavi orodja, lovi napake, piše dnevnik
                │
                ▼
        AdapterInterface ........ edina pot do podatkov
                │
                ├─ DirectMySQLAdapter (v uporabi)
                └─ VascoAdapter (predloga za ERP)
                        │
                        ▼
                    MySQL (ai_* tabele)
```

### Zakaj adapterski sloj
Poslovna logika nikoli ne gre neposredno v bazo. Ko bo stranka imela ERP (Vasco
je prvi kandidat), se napiše nov adapter, orodja pa ostanejo nedotaknjena.
Preslikava stolpcev ERP-ja v naše ključe spada v adapter, nikoli v orodje.

### Zakaj so orodja HTTP endpointi
`tools/*.php` so dosegljivi prek HTTP s skupno skrivnostjo `TOOL_SECRET`. Telefonski
agent teče drugje (LiveKit, VPS) in kliče iste endpointe — zato se poslovna logika
ne prepisuje v Pythonu.

### Kaj določa identiteto asistenta
Sistemski prompt (`ai/system-prompt.txt`) **nima nikjer vpisanega imena podjetja**.
Vse pride iz `config.php` prek nadomestkov `{ASSISTANT_NAME}`, `{BUSINESS_NAME}`,
`{BUSINESS_DESCRIPTION}`, `{BUSINESS_PHONE}`, `{BUSINESS_EMAIL}`. Za naslednjo
stranko se spremenijo tri vrstice.

---

## 4. Datoteke

### Koren
| Datoteka | Kaj dela |
|---|---|
| `index.html` | Glasovni asistent, gumbi do portalov. Videz po kreativnisplet.si. |
| `widget.js` | Vgradnja klepeta v tujo stran z eno posodo in eno skripto. Naslov končne točke prebere iz lastnega `src`. |
| `setup.php` | Namestitveni čarovnik. **Po namestitvi izbriši.** Sam se izklopi, ko `config.php` obstaja. |
| `data-view.php` | Razvojni pregled baze, stanja strežnika in preizkus pošiljanja. Zaklenjen s `DATA_VIEW_KEY`. |
| `bootstrap.php` | Naloži konfiguracijo in razrede, vrne pripravljen `ToolRegistry`. Zazna BOM. |
| `config.php` | Nastavitve. **Ni v gitu.** Ustvari ga čarovnik. |
| `.htaccess` | Blokira dostop do `.git/` prek `RewriteRule`. |
| `.cpanel.yml` | Deploy: rsync iz git repozitorija v živo mapo, z vsiljenimi pravicami 755/644. |

### `ai/` — pogovorni sloj
| Datoteka | Kaj dela |
|---|---|
| `chat.php` | Pošlje pogovor OpenAI, izvede klicane toole, vrne odgovor. Največ 4 krogi. |
| `transcribe.php` | Posnetek → besedilo prek `gpt-4o-transcribe`, jezik izrecno `sl`. |
| `speak.php` | Besedilo → mp3. Azure (SSML, slovenski glas) ali OpenAI. Normalizira cene in telefonske številke. |
| `guard.php` | Preverjanje izvora, HTTPS, omejitve, dnevni proračun žetonov. Skupno za vse tri. |
| `agent-config.php` | Telefonskemu agentu vrne sistemski prompt. Zaščiteno s `TOOL_SECRET`. |
| `OpenAIClient.php` | Odjemalec za Chat Completions. Ponovi klic ob 429 in 5xx. Beleži porabo žetonov. |
| `system-prompt.txt` | Pravila asistenta. Brez imena podjetja — to pride iz konfiguracije. |
| `tool-definitions.json` | Opisi orodij za model, v slovenščini. |

### `tools/` — poslovna logika
| Datoteka | Kaj dela |
|---|---|
| `core/AdapterInterface.php` | Vmesnik do podatkov. Vse metode, ki jih mora znati vsak vir. |
| `core/Tool.php` | Osnovni razred orodij + pomočniki za validacijo vhoda. |
| `core/ToolRegistry.php` | Sestavi adapter in orodja, lovi napake, meri čase, piše dnevnik. |
| `core/ToolResponse.php` | Enotna oblika odgovora s kodami napak. |
| `core/Endpoint.php` | HTTP ovoj: metoda, HTTPS, omejitve, branje JSON. |
| `core/RateLimiter.php` | Datotečni števec s poljubnim časovnim oknom. |
| `core/Budget.php` | Dnevna poraba žetonov. Strošek so žetoni, ne klici. |
| `core/Logger.php` | Dnevnik klicev in pogovorov z maskiranjem osebnih podatkov. |
| `core/Mailer.php` | Lasten odjemalec SMTP — `mail()` je na gostovanju izklopljen. |
| `core/SlovenianDate.php` | Slovenska imena dni in mesecev, relativni datumi. |
| `adapters/DirectMySQLAdapter.php` | MySQL prek pripravljenih stavkov, s predpono tabel. |
| `adapters/VascoAdapter.php` | Predloga za ERP. Vse metode vržejo `AdapterException`. |
| `implementations/ProductTool.php` | Iskanje storitev, oblikovanje cene. |
| `implementations/OrderTool.php` | Stanje projekta s preverjanjem lastnika. |
| `implementations/BusinessInfoTool.php` | Delovni čas, roki, plačilo. |
| `implementations/InquiryTool.php` | Oddaja povpraševanja + obvestilo po SMTP. |
| `product-lookup.php`, `order-lookup.php`, `business-info.php`, `submit-inquiry.php` | HTTP endpointi, po tri vrstice. |

### `admin/` — skrbniška stran
| Datoteka | Kaj dela |
|---|---|
| `auth.php` | Prijava, seja, CSRF, omejitev poskusov. |
| `index.php` | Povpraševanja: seznam, iskanje, filter, sprememba stanja. |
| `storitve.php` | Urejanje kataloga prek obrazca namesto SQL. |
| `pogovori.php` | Pregled pogovorov z oznakami težav. |

### Ostalo
- `voice-agent/agent.py` — telefonski agent (LiveKit Agents, Python)
- `sql/schema.sql` — samo struktura, brez podatkov
- `data/business-info.json` — roki in pogoji plačila
- `tests/test-tools.sh` — 26 testov orodij prek HTTP
- `docs/setup.md`, `docs/telefon.md`, `docs/tools-api.md`

---

## 5. Baza

Vse tabele s predpono iz `DB_PREFIX` (privzeto `ai_`).

| Tabela | Kaj hrani |
|---|---|
| `ai_products` | Katalog storitev. `price_per_unit` sme biti `NULL` (po dogovoru), `price_from` označi izhodiščno ceno, `active` umik iz ponudbe. |
| `ai_customers` | Obstoječe stranke za poizvedbe o projektih. |
| `ai_orders` | Projekti. Tuji ključi na stranke in storitve. |
| `ai_inquiries` | Povpraševanja. Ime, telefon in **e-pošta obvezni**. Stanje: `new`/`handled`/`discarded`. |
| `ai_business_hours` | Delovni čas, 1 = ponedeljek … 7 = nedelja. |

---

## 6. Zaščita

| Kontrola | Privzeto | Kaj ustavi |
|---|---|---|
| Na IP / minuto | 20 | naval |
| Na IP / dan | 100 | vztrajnega posameznika |
| Skupaj / dan | 500 | več naslovov hkrati |
| **Žetoni / dan** | 200.000 | dejanski strošek, ne glede na obliko zlorabe |
| Dolžina zgodovine | 8.000 znakov | debele zahtevke; obreže začetek, zadnje vprašanje ostane |
| Izvor | `CHAT_ALLOWED_ORIGINS` | klice s tujih strani |
| IPv6 | blok /64 | menjavo naslova znotraj bloka |

Štetje klicev denarnice ne varuje: en klic z dolgo zgodovino stane toliko kot
deset kratkih. Zato dnevni proračun **žetonov**.

**Osebni podatki:** dnevniki maskirajo telefone, e-pošto, imena in zadnji oktet
IP. Hranijo se 14 dni. Mapa `logs/` je zaprta z `.htaccess`.

**Skrbniška stran:** geslo zgoščeno (`password_hash`), seja 8 ur, piškotek
HttpOnly in SameSite=Strict, CSRF žeton na dejanjih, 10 poskusov na 15 minut.

### Preverjeno proti napadom (16. 9. 2026)
Vseh 14 poskusov zavrnjenih: razkritje sistemskega prompta, prevzem vloge,
navodilo skrito v podatku, zahteva po seznamu strank, vrivanje SQL, lažno
lastništvo, socialni inženiring, izsiljena obljuba popusta in roka, pošiljanje
na tuj naslov, večkorakni napad z grajenjem zaupanja.

Tehnična plast: tuj izvor → 403, `curl` brez izvora → 403, neveljaven kontakt →
`invalid_input`, tuj projekt → `not_found`, `admin/` brez gesla → obrazec.

---

## 7. Deploy

1. `git push`
2. cPanel → Git Version Control → repozitorij `tatjana_ai`
3. **Update from Remote** (to je pull) → **Deploy HEAD Commit**

**Sam Deploy brez Update naloži staro kodo.** To naju je zavedlo dvakrat.

`config.php` ni v gitu, zato ga deploy ne povozi. Ob spremembi nastavitev ga
naloži ročno prek File Managerja.

---

## 8. Kje smo

### Deluje
- [x] Besedilni klepet in glasovni asistent na spletni strani
- [x] Vsa štiri orodja, 26/26 testov
- [x] Povpraševanja v bazo + obvestilo na `kreativnisplet2025@gmail.com` prek SMTP
- [x] Azure slovenski glas — cene in telefonske številke izgovori pravilno
- [x] Telefonski agent preverjen prek mikrofona (`python agent.py console`)
- [x] Skrbniška stran: povpraševanja, storitve, pogovori
- [x] Namestitveni čarovnik za nove stranke
- [x] Zaščita preverjena proti 14 vrstam napada

### Čaka na eno odobritev
Telefon je **v celoti nastavljen**. Številka `+386 5 7774124` (DIDWW, Nova Gorica,
~7 €/mesec), LiveKit trunk `ST_NZJF2z65DiLj`, dispatch `SDR_tpMPgUorXQhT` → agent
`tatjana`, DIDWW trunk dodeljen.

Klici ne delujejo, ker je številka v stanju *Awaiting Registration*. Klic se ne
pojavi niti v dnevniku DIDWW — Slovenija zahteva registracijo naročnika.
**Rok 30 dni, sicer se številka izgubi.**

Postopek: DIDWW → Identities & Addresses → nova identiteta tipa **Business**
(naziv iz Poslovnega registra, matična številka, Preserje 16, 5295 Branik, izpis
AJPES, dokazilo o naslovu) → My Numbers → Manage DID → Identity.

Ko bo odobreno: `python agent.py dev` in pokliči. Nič več nastavljanja.
ID-ji in podrobnosti so v [docs/telefon.md](docs/telefon.md).

### Nujno, brez roka a pomembno
- [ ] Zamenjaj geslo baze in WordPressove varnostne ključe — `wp-config.php` je bil prilepljen v pogovor z asistentom
- [ ] Zamenjaj OpenAI ključ in GitHub žeton — prav tako razkrita
- [ ] Mesečna omejitev porabe v OpenAI (Billing → Limits) — zadnja obramba, če ključ uide
- [ ] Dnevna kopija `ai_` tabel v cron — povpraševanja so posel
- [ ] Omeji LiveKit trunk na signalne naslove DIDWW — zdaj sprejema od koderkoli

### Pred javnim zagonom
- [ ] Odstrani `setup.php` in `data-view.php` s strežnika
- [ ] Popravi delovni čas v `ai_business_hours` — vpisan je privzeti pon–pet 9–17
- [ ] Dopolni pogoje plačila v `data/business-info.json`
- [ ] Pobriši testne stranke: `DELETE FROM ai_orders; DELETE FROM ai_customers;`
- [ ] Vgradi klepet v `landing.html`

### Kasneje
- [ ] Objava telefonskega agenta na LiveKit Cloud ali VPS, da teče brez tvojega računalnika
- [ ] `VascoAdapter`, ko bo znan pravi ERP stranke

### Odprta vprašanja
- Javni ali zasebni repozitorij. Zdaj javen, ker cPanel Git zasebnega ni zmogel klonirati. Skrivnosti v njem ni (preverjena celotna zgodovina), a kodo, ki jo nameravaš prodajati, lahko kdorkoli prekopira. Za zasebnega je treba prej urediti deploy: GitHub Actions prek FTP ali SSH ključa.
- Ali je `gpt-4o-mini` dovolj dober za slovenščino, ali je vreden večji model.

---

## 9. Strošek

**Klepet in glas na strani:** delčki centa na pogovor pri `gpt-4o-mini`. Azure
ima 500.000 znakov mesečno brezplačno.

**Telefon**, ob predpostavki triminutnega klica:

| Postavka | Na klic | 100 klicev/mesec |
|---|---|---|
| Dohodne minute | ~0,04 € | ~4,50 € |
| Najem številke | — | ~7 € |
| Prepis govora | ~0,017 € | ~1,70 € |
| Model | ~0,005 € | ~0,50 € |
| Govor (Azure F0) | — | 0 € |
| LiveKit | — | 0 € do 1.000 minut |
| **Skupaj** | **~0,09 €** | **~14 €** |

---

## 10. Odločitve, ki jih ne razveljavljaj brez razloga

**Cene se izpisujejo s številko, nikoli z besedami.** `gpt-4o-mini` je pri
pretvorbi 78 € povedal kot "osemdeset evrov" — napačno ceno stranki. Za glas
pretvorbo opravi TTS.

**Prazna cena je `NULL`, ne 0.** Prazen niz bi se ob pretvorbi v število
spremenil v ničlo in asistent bi povedal, da je storitev zastonj.

**`price_from` obstaja, ker so vse objavljene cene izhodiščne.** Brez oznake bi
model 399 € povedal kot končno ceno.

**Vpogled v projekt zahteva dva podatka.** Številke so zaporedne in ugibljive.

**Zapis v bazo je vir resnice, e-pošta ni.** Če pošiljanje odpove, povpraševanje
ostane shranjeno in stranka dobi enak odgovor.

**Kategorije so omejene na tiste iz `tool-definitions.json`.** Nova kategorija v
bazi bi bila za asistenta nevidna.

**Storitve se ne brišejo, ampak umaknejo iz ponudbe.** Nanje kažejo zapisi v
`ai_orders`.

---

## 11. Česa ne poskušaj znova

**WordPressov `wp_mail()` iz našega procesa.** `config.php` in `wp-config.php`
definirata iste konstante (`DB_NAME`, `DB_USER`, `DB_HOST`), zato bi WordPress
dobil naše vrednosti namesto svojih — tiho spreminjanje delovanja žive strani.
Zato obstaja `Mailer.php`.

**Zasebni repozitorij s cPanel Git.** Klon tiho spodleti, tudi z deploy key in
s PAT v URL-ju.

**Azure v regiji West Europe.** Ne sprejema novih strank. Uporabi `italynorth`.

---

## 12. Pasti, ki so nas že ujele

| Simptom | Vzrok |
|---|---|
| Vsaka datoteka v mapi vrne 404, mapa pa 403 | Mapa ima pravice 0700. `rsync -a` prenese pravice izvorne mape, cPanel git repozitorij pa je 0700. Rešeno z `--chmod=D755,F644`. |
| Enako, a pravice so v redu | `<DirectoryMatch>` v `.htaccess` ni veljaven. Za `.git/` uporabi `RewriteRule`. |
| Vsi odgovori 200 namesto prave kode | `config.php` shranjen kot UTF-8 z BOM. Notepad in PowerShell to naredita privzeto. |
| `SQLSTATE[HY093]: Invalid parameter number` | Isti named placeholder večkrat v enem stavku. Vsaka pojavitev rabi svojega. |
| Chat vedno vrne "sistem ne odgovori" | Omejitev žetonov pri `gpt-4o` na novem računu. Rešeno z `gpt-4o-mini`. |
| Asistent pove napačno ceno | Prompt je zahteval pretvorbo cen v besede. |
| `535 Incorrect authentication data` pri SMTP | Napačno geslo predala ali uporabnik brez domene. |
| Deploy naloži staro kodo | Kliknjen samo Deploy brez Update from Remote. |
| Povpraševanje vrne napako, čeprav je zapis v bazi | Obvestilo po pošti je padlo in podrlo cel klic. Pošiljanje mora biti v `try/catch`. |
| `proto: syntax error` pri `lk` CLI | JSON shranjen z BOM. Uporabi `[System.IO.File]::WriteAllText` z `UTF8Encoding($false)`. |
| Azure: "region not accepting new customers" | West Europe je poln. |

---

## 13. Kako testiram

```bash
BASE_URL=https://kreativnisplet.si/asistent bash tests/test-tools.sh
```

Vseh 26 testov mora biti zelenih. Posebej zadnja dva: `GET` na tool mora vrniti
405, mapa `logs/` ne sme biti dosegljiva.

Asistenta preizkusi na `https://kreativnisplet.si/asistent/`. Če pri vprašanju o
ceni **ne pokliče orodja**, je odgovor izmišljen — to je najhujša napaka in jo
`admin/pogovori.php` sam označi.
