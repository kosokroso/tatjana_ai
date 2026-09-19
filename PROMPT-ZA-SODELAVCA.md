# Prompt za sodelavca

Prilepi vse spodaj (od vrstice `---` naprej) v svoj Claude Code, odprt v korenu
tega repozitorija. Vsebuje vse, kar Claude potrebuje, da začne delati brez
predhodnega konteksta.

---

Delaš na projektu **Tatjana** — AI asistent za slovenska mala podjetja. Spodaj je
celoten kontekst. Preberi do konca, preden karkoli spremeniš.

## 1. Kaj gradimo in za koga

Asistent se na spletni strani podjetja pogovarja z obiskovalci — po besedilu in z
glasom — odgovarja na vprašanja o ponudbi, cenah in rokih ter zbira
povpraševanja. Isti asistent bo sprejemal telefonske klice.

**To ni projekt za eno podjetje.** Luka (lastnik) ga prodaja slovenskim malim
podjetjem kot storitev z mesečnim vzdrževanjem. Prva stranka je njegova lastna
agencija Kreativni Splet. Vsaka odločitev mora prestati vprašanje: *"Kaj je treba
spremeniti, da to jutri teče za mizarja, avtoservis ali računovodkinjo?"*
Pravilen odgovor je skoraj vedno: nekaj vrstic v `config.php` in vsebina baze.
Nikoli koda.

Pomembno: **vzdrževanje opravlja Luka, ne stranke.** Ne gradi funkcij za
samopostrežno urejanje. Gradi tako, da je uvedba nove stranke hitra.

| | |
|---|---|
| V živo | `https://kreativnisplet.si/asistent/` |
| Repozitorij | `https://github.com/kosokroso/tatjana_ai` (javen) |
| Gostovanje | cPanel, shared, `public_html/asistent/` |
| Sklad | PHP 8, vanilla, brez Composerja in brez ogrodja |
| Baza | MySQL, skupna z WordPressom, predpona tabel `ai_` |
| Model | `gpt-4o-mini` |
| Glas | Azure `sl-SI-PetraNeural`, regija `italynorth` |

## 2. Način dela, ki ga Luka pričakuje

- **Vsa komunikacija v slovenščini.** Koda, komentarji, sporočila commitov in
  dokumentacija prav tako.
- **Brez marketinškega tona.** Nobenega "odlično!", "popolno!", nobenih
  superlativov. Povej, kaj si naredil in kaj ne deluje.
- **Razloži zakaj, ne samo kaj.** Pri neočitni odločitvi napiši razlog. Luka
  kodo bere in jo mora čez pol leta razumeti.
- **Ne ugibaj o živem sistemu.** Če ne veš, ali nekaj na strežniku deluje,
  vprašaj ali preveri. Ne trdi, da je popravljeno, dokler ni preverjeno.
- **Luka pogosto prilepi gesla in ključe naravnost v pogovor.** Ko se to zgodi,
  ga opozori, naj jih zamenja, in jih nikoli ne zapiši v datoteko v repozitoriju.
- Sporočila commitov končaj z `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Ne dodajaj emojijev, razen če jih zahteva.
- **Ob vsaki večji spremembi posodobi `PROJEKT.md` in `TODO.md`.** Delitev je
  namerna: `PROJEKT.md` pove, kaj sistem je in **zakaj** je nekaj vredno dela;
  `TODO.md` ima kljukice. Iste točke ne piši na obe mesti — dva seznama opravil
  se razideta in nobenemu ne zaupaš več.

## 3. Arhitektura

```
Obiskovalec (brskalnik ali telefon)
        │
        ├─ index.html ........... glasovni asistent + gumbi do portalov
        └─ widget.js ............ vgradnja klepeta v tujo stran
                │
                ▼
        ai/chat.php ............. pogovor z OpenAI, zanka klicanja orodij
        ai/transcribe.php ....... govor v besedilo
        ai/speak.php ............ besedilo v govor
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

### Tri načela, ki jih ne kršimo

**1. Adapter je edina pot do podatkov.** Orodje nikoli ne odpre PDO povezave in
nikoli ne sestavi SQL-a. Ko bo stranka imela ERP (Vasco je prvi kandidat), se
napiše nov adapter in orodja ostanejo nedotaknjena. Preslikava tujih stolpcev v
naše ključe spada v adapter.

**2. Asistent nima vgrajene identitete.** `ai/system-prompt.txt` nikjer ne
vsebuje imena podjetja. Ob nalaganju se zamenjajo nadomestki
`{ASSISTANT_NAME}`, `{BUSINESS_NAME}`, `{BUSINESS_DESCRIPTION}`,
`{BUSINESS_PHONE}`, `{BUSINESS_EMAIL}`. Če v prompt napišeš "Kreativni Splet",
si projekt pokvaril.

**3. Poslovna logika obstaja enkrat.** Orodja so dosegljiva prek HTTP s skupno
skrivnostjo `TOOL_SECRET`. Telefonski agent je v Pythonu in teče drugje, a kliče
iste endpointe. Ne prepisuj logike v Python.

## 4. Datoteke

### Koren
| Datoteka | Kaj dela |
|---|---|
| `index.html` | Glasovni asistent, gumbi do portalov. Videz po kreativnisplet.si. |
| `widget.js` | Vgradnja klepeta v tujo stran. Naslov končne točke prebere iz lastnega `src`. |
| `bootstrap.php` | Naloži konfiguracijo in razrede, vrne pripravljen `ToolRegistry`. Zazna BOM. |
| `setup.php` | Namestitveni čarovnik za novo stranko. Sam se izklopi, ko `config.php` obstaja. |
| `data-view.php` | Razvojni pregled baze in stanja strežnika. Zaklenjen s `DATA_VIEW_KEY`. |
| `config.php` | Nastavitve. **Ni v gitu in ne sme biti.** |
| `config.example.php` | Predloga z razlago vsake konstante. |
| `.htaccess` | Blokira `.git/` prek `RewriteRule`. |
| `.cpanel.yml` | Deploy prek rsync z vsiljenimi pravicami 755/644. |
| `PROJEKT.md` | Stanje projekta. Posodabljaj. |

### `ai/` — pogovorni sloj
| Datoteka | Kaj dela |
|---|---|
| `chat.php` | Pošlje pogovor OpenAI, izvede klicana orodja, vrne odgovor. Največ `MAX_TOOL_ROUNDS` (4) krogov. Obreže zgodovino na 8.000 znakov z začetka. |
| `transcribe.php` | Posnetek v besedilo prek `gpt-4o-transcribe`, jezik izrecno `sl`. |
| `speak.php` | Besedilo v mp3. Azure s SSML ali OpenAI. Normalizira cene in telefonske številke. |
| `guard.php` | `aiOriginAllowed()`, `aiApplyCors()`, `aiIsHttps()`, `aiRateLimitMessage()`, `aiClientKey()`, `aiBudget()`. |
| `agent-config.php` | Telefonskemu agentu vrne sistemski prompt z že zamenjanimi nadomestki. Zaščiteno s `TOOL_SECRET`. |
| `OpenAIClient.php` | Chat Completions. Ponovi ob 429 in 5xx. Beleži porabo žetonov. |
| `system-prompt.txt` | Pravila asistenta. |
| `tool-definitions.json` | Opisi orodij za model, v slovenščini. |

### `tools/core/`
| Datoteka | Kaj dela |
|---|---|
| `AdapterInterface.php` | `searchProducts`, `getProductById`, `findOrderById`, `getBusinessHours`, `createInquiry`, `listInquiries`, `updateInquiryStatus`, `listProducts`, `saveProduct`. |
| `Tool.php` | Osnovni razred + validacija vhoda. |
| `ToolRegistry.php` | Sestavi adapter in orodja, lovi napake, meri čase, piše dnevnik. |
| `ToolResponse.php` | Enotna oblika odgovora s kodami napak. |
| `Endpoint.php` | HTTP ovoj: metoda, HTTPS, omejitve, branje JSON. |
| `RateLimiter.php` | Datotečni števec s poljubnim oknom. |
| `Budget.php` | Dnevna poraba žetonov, `flock`, samodejno čiščenje po 7 dneh. |
| `Logger.php` | Dnevnik z maskiranjem osebnih podatkov. |
| `Mailer.php` | Lasten odjemalec SMTP. |
| `SlovenianDate.php` | Slovenska imena dni in mesecev. |

### `tools/implementations/`
`ProductTool.php`, `OrderTool.php`, `BusinessInfoTool.php`, `InquiryTool.php`.
Vsakemu pripada trivrstični HTTP endpoint v `tools/` (`product-lookup.php`,
`order-lookup.php`, `business-info.php`, `submit-inquiry.php`).

### `admin/`
`auth.php` (prijava, seja, CSRF, omejitev poskusov), `index.php`
(povpraševanja), `storitve.php` (urejanje kataloga), `pogovori.php` (pregled
pogovorov z oznakami težav).

### Ostalo
`voice-agent/agent.py` (LiveKit, Python), `sql/schema.sql`,
`data/business-info.json`, `tests/test-tools.sh` (26 testov),
`docs/setup.md`, `docs/telefon.md`, `docs/tools-api.md`.

## 5. Kaj asistent dela

**Vprašanja o ponudbi** — pokliče orodje, ki prebere katalog iz baze. Cen si
nikoli ne izmišlja. Če cena ni vpisana, pove "cena po dogovoru". Če je
izhodiščna, pove "od 399 €".

**Podatki o poslovanju** — delovni čas z izračunom, ali je odprto zdaj; roki in
pogoji plačila.

**Stanje projekta obstoječe stranke** — zahteva številko projekta IN telefon ali
e-pošto. Ob neujemanju vrne enak `not_found` kot pri neobstoječem projektu, da
razlika ne izda, katere številke obstajajo.

**Povpraševanje** — zbere ime, telefon IN e-pošto, povzame, počaka na potrditev,
odda. Podrobnosti gredo v polje `note`.

**Ponudbe ne pripravi in cene ne potrdi.** Namerno. Cena je odvisna od obsega.

## 6. Baza

| Tabela | Ključno |
|---|---|
| `ai_products` | `price_per_unit` sme biti `NULL` (po dogovoru). `price_from` = izhodiščna cena. `active` = umik iz ponudbe. |
| `ai_customers` | Obstoječe stranke za poizvedbe o projektih. |
| `ai_orders` | Projekti, tuji ključi na stranke in storitve. |
| `ai_inquiries` | Ime, telefon in e-pošta obvezni. Stanje `new`/`handled`/`discarded`. |
| `ai_business_hours` | 1 = ponedeljek … 7 = nedelja. |

Predpona pride iz `DB_PREFIX` in gre skozi `preg_match('/^[A-Za-z0-9_]{1,32}$/')`,
ker se imena tabel ne dajo vezati kot parametri.

Baza je **skupna z WordPressom**. Zato predpona `ai_`: posodobitve WordPressa se
ne dotaknejo naših tabel, vtičniki za varnostne kopije zajamejo tudi nas.

## 7. Zaščita

| Kontrola | Privzeto |
|---|---|
| Na IP / minuto | 20 |
| Na IP / dan | 100 |
| Skupaj / dan | 500 |
| Žetoni / dan | 200.000 |
| Zgodovina | 8.000 znakov |
| Izvor | `CHAT_ALLOWED_ORIGINS`, zahteva brez `Origin` je zavrnjena |
| IPv6 | šteje se blok /64 |

Štetje klicev denarnice ne varuje: en klic z dolgo zgodovino stane toliko kot
deset kratkih. Zato dnevni proračun **žetonov**.

Dnevniki maskirajo telefone, e-pošto, imena in zadnji oktet IP. Hranijo se 14
dni. Mapa `logs/` je zaprta z `.htaccess`.

Skrbniška stran: `password_hash`, `session_regenerate_id(true)`, piškotek
HttpOnly + Secure + SameSite=Strict, CSRF žeton na dejanjih, 10 poskusov na 15
minut.

Zaščita je bila 16. 9. 2026 preizkušena s 14 napadi (razkritje prompta, prevzem
vloge, navodilo skrito v podatku, zahteva po seznamu strank, vrivanje SQL, lažno
lastništvo, izsiljena obljuba popusta, pošiljanje na tuj naslov, večkorakni napad
z grajenjem zaupanja). Vsi zavrnjeni. Če spreminjaš sistemski prompt ali orodja,
te teste ponovi.

## 8. Konfiguracija

`config.php` ni v gitu. Ustvari ga `setup.php` ali se prekopira iz
`config.example.php`. 48 konstant, po skupinah:

**Osnova:** `TIMEZONE`, `ADAPTER`, `ALLOWED_TOOLS`, `REQUIRE_HTTPS`
**Baza:** `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`, `DB_PREFIX`
**Dnevnik:** `LOG_DIR`, `LOG_MASK_PII`, `LOG_RETENTION_DAYS`
**Omejitve:** `RATE_LIMIT_PER_MINUTE`, `CHAT_RATE_LIMIT_PER_MINUTE`, `CHAT_RATE_LIMIT_PER_DAY`, `CHAT_MAX_PER_DAY_TOTAL`, `DAILY_TOKEN_BUDGET`
**OpenAI:** `OPENAI_API_KEY`, `OPENAI_MODEL`, `OPENAI_TIMEOUT_SECONDS`, `STT_MODEL`, `TTS_MODEL`, `TTS_VOICE`, `SPEECH_LANGUAGE`
**Azure:** `TTS_PROVIDER`, `AZURE_SPEECH_KEY`, `AZURE_SPEECH_REGION`, `AZURE_TTS_VOICE`
**Asistent:** `SYSTEM_PROMPT_FILE`, `TOOL_DEFINITIONS_FILE`, `MAX_TOOL_ROUNDS`, `CHAT_ALLOWED_ORIGINS`, `BUSINESS_INFO_FILE`
**Znamka:** `ASSISTANT_NAME`, `BUSINESS_NAME`, `BUSINESS_PHONE`, `BUSINESS_EMAIL`, `BUSINESS_DESCRIPTION`
**Pošta:** `INQUIRY_EMAIL_TO`, `INQUIRY_EMAIL_FROM`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS`
**Dostop:** `TOOL_SECRET`, `DATA_VIEW_KEY`, `ADMIN_PASSWORD_HASH`

## 9. Deploy

1. `git push`
2. cPanel → Git Version Control → repozitorij `tatjana_ai`
3. **Update from Remote** (to je pull) → **Deploy HEAD Commit**

**Sam Deploy brez Update naloži staro kodo.** To naju je zavedlo dvakrat.

`config.php` je v `.cpanel.yml` izključen, zato ga deploy ne povozi. Ob
spremembi nastavitev ga naloži ročno prek File Managerja.

## 10. Testiranje

```bash
BASE_URL=https://kreativnisplet.si/asistent bash tests/test-tools.sh
```

26 testov. Vsi morajo biti zeleni. Zadnja dva preverita, da `GET` na orodje vrne
405 in da `logs/` ni dosegljiv.

Lokalno v Windows PowerShell:

```powershell
php -l tools/core/Mailer.php
```

Lokalno ni MySQL, zato adapterja lokalno ne preizkusiš. Sintaksa in unit logika
da, poizvedbe ne.

## 11. Pravila, ki jih ne smeš prekršiti

**Cene se izpisujejo s številko, nikoli z besedami.** `gpt-4o-mini` je pri
pretvorbi 78 € povedal kot "osemdeset evrov" — napačna cena stranki. Za glas
pretvorbo opravi TTS.

**Prazna cena je `NULL`, nikoli 0.** Prazen niz bi se ob `(float)` spremenil v
ničlo in asistent bi povedal, da je storitev zastonj. V
`DirectMySQLAdapter::searchProducts()` je to izrecno:
`$row['price_per_unit'] === null ? null : (float) $row['price_per_unit']`.

**`price_from` obstaja, ker so vse objavljene cene izhodiščne.** Brez oznake bi
model 399 € povedal kot končno ceno.

**Vpogled v projekt zahteva dva ujemajoča se podatka.** Številke so zaporedne in
ugibljive. Primerjava gre prek `hash_equals`.

**Zapis v bazo je vir resnice, e-pošta ni.** V `InquiryTool` je pošiljanje v
`try/catch`. Če pade, povpraševanje ostane shranjeno in stranka dobi enak
odgovor. Prej je izjema podrla cel klic in stranka je videla napako, čeprav je
bil zapis shranjen.

**Kategorije so omejene na tiste iz `tool-definitions.json`.** Nova kategorija v
bazi je za asistenta nevidna, dokler je ne dodaš tudi tja.

**Storitve se ne brišejo, ampak umaknejo (`active = 0`).** Nanje kažejo zapisi v
`ai_orders`.

**Ime podjetja ne gre v kodo.** Samo v `config.php`.

## 12. Česa ne poskušaj

**WordPressov `wp_mail()` iz našega procesa.** `config.php` in `wp-config.php`
definirata iste konstante (`DB_NAME`, `DB_USER`, `DB_HOST`). WordPress bi dobil
naše vrednosti namesto svojih in tiho spremenil delovanje žive strani. Zato
obstaja `Mailer.php` — lasten SMTP, ker je `mail()` na gostovanju izklopljen.

**Zasebni repozitorij s cPanel Git.** Klon tiho spodleti, tudi z deploy key in s
PAT v URL-ju. Zato je repozitorij javen.

**Azure v regiji West Europe.** Ne sprejema novih strank. Uporabi `italynorth`.

**Composer ali ogrodje.** Shared hosting brez SSH pristopa do PHP-jevega
upravitelja odvisnosti. Vse mora teči iz prekopiranih datotek.

## 13. Pasti, ki so nas že ujele

| Simptom | Vzrok |
|---|---|
| Vsaka datoteka v mapi vrne 404, mapa pa 403 | Mapa ima pravice 0700. `rsync -a` prenese pravice izvorne mape, cPanel git repozitorij pa je 0700. Rešeno z `--chmod=D755,F644` in `chmod 755` na cilju. |
| Enako, a pravice so v redu | `<DirectoryMatch>` v `.htaccess` ni veljaven in podre cel strežnik za to mapo. Za `.git/` uporabi `RewriteCond` + `RewriteRule ^ - [F,L]`. |
| Vsi odgovori 200 namesto prave kode | `config.php` shranjen kot UTF-8 z BOM. Notepad in PowerShell `Set-Content -Encoding utf8` to naredita privzeto. |
| `SQLSTATE[HY093]: Invalid parameter number` | Isti named placeholder večkrat v enem stavku, ker je `ATTR_EMULATE_PREPARES => false`. Vsaka pojavitev rabi svoje ime (`:t0n`, `:t0d`, `:t0c`). |
| Chat vedno vrne "sistem ne odgovori" | Omejitev žetonov na minuto pri `gpt-4o` na novem računu. Rešeno z `gpt-4o-mini`. |
| `535 Incorrect authentication data` | Napačno geslo predala ali uporabnik brez domene. |
| Deploy naloži staro kodo | Kliknjen samo Deploy brez Update from Remote. |
| `proto: syntax error (line 1:1)` pri `lk` CLI | JSON shranjen z BOM. Uporabi `[System.IO.File]::WriteAllText($p, $json, (New-Object System.Text.UTF8Encoding($false)))`. |

## 14. Kje smo

**Deluje:** besedilni klepet, glasovni asistent na strani, vsa štiri orodja
(26/26 testov), povpraševanja v bazo in po e-pošti, Azure slovenski glas,
skrbniška stran, namestitveni čarovnik, zaščita proti 14 vrstam napada.

**Čaka:** telefon je v celoti nastavljen — številka `+386 5 7774124` (DIDWW, Nova
Gorica), LiveKit trunk `ST_NZJF2z65DiLj`, dispatch `SDR_tpMPgUorXQhT` → agent
`tatjana`. Klici ne delujejo, ker je številka v stanju *Awaiting Registration*;
Slovenija zahteva registracijo naročnika. Rok 30 dni. Ko bo odobreno, zadostuje
`python agent.py dev`.

**Odprto:** zamenjava razkritih ključev (geslo baze, WordPressove soli, OpenAI
ključ, GitHub žeton), mesečna omejitev porabe v OpenAI, dnevna kopija `ai_`
tabel, omejitev LiveKit trunka na signalne naslove DIDWW, odstranitev
`setup.php` in `data-view.php` pred predajo stranki, pravi delovni čas v
`ai_business_hours`, brisanje testnih strank, vgradnja klepeta v `landing.html`.

Celoten seznam je v `PROJEKT.md`.

## 15. Preden začneš

1. Preberi `PROJEKT.md` — tam je stanje, ki je lahko novejše od tega prompta.
2. Preberi `ai/system-prompt.txt` in `ai/tool-definitions.json`. Tam živi
   vedenje asistenta; večina sprememb vedenja spada tja, ne v PHP.
3. Preberi `tools/core/AdapterInterface.php`. Če tvoja funkcija potrebuje nov
   podatek, se najprej odloči, ali gre v vmesnik.
4. Poženi `bash tests/test-tools.sh`, da vidiš izhodišče.

Ko končaš spremembo: poženi teste, posodobi `PROJEKT.md`, commitaj v
slovenščini.
