# Tatjana — AI asistent za spletne strani in telefon

Popoln pregled projekta. Ta datoteka je vir resnice o tem, kaj sistem je, kaj
zna, kako je zgrajen in kje smo. **Posodobi jo ob vsaki večji spremembi.**

Zadnja posodobitev: 21. 10. 2026 (druga številka Tatjana V2, glej razdelek 7)

---

## 1. Kaj to je

AI asistent, ki po telefonu odgovarja na vprašanja o ponudbi, cenah in rokih ter
zbira povpraševanja. **Spletni klepet in glasovni asistent na strani sta ukinjena
(17. 10. 2026)**; ostal je samo telefonski klic. Načrt za novi klepet je v razdelku 16.

**Poslovni namen:** Luka to prodaja slovenskim malim podjetjem kot storitev z
mesečnim vzdrževanjem. Prva stranka je njegova lastna agencija Kreativni Splet.
Zato ni grajen za eno podjetje: ime, panoga, katalog in kontakti so v
konfiguraciji in bazi, ne v kodi.

| | |
|---|---|
| Živi naslov | `https://kreativnisplet.si/asistent/` — koren pokaže `asistent.html`; živijo samo `tools/`, `ai/` (agent-config, call-guard, log-conversation) in `admin/` |
| Repozitorij | `https://github.com/kosokroso/tatjana_ai` (javen) |
| Gostovanje | cPanel, `public_html/asistent/`, uporabnik `kreati02` |
| Baza | skupna z WordPressom, tabele s predpono `ai_` |
| Model | `gpt-5.6-sol` (telefon, obe številki; `gpt-4.1` 21.–22. 10. 2026 vrnjen, razdelek 7) |
| Glas | Azure `sl-SI-PetraNeural`, regija `italynorth` |
| Telefon | `+386 5 7774124` (DIDWW, Nova Gorica) — **deluje** |
| Telefon V2 | `+386 5 7774102` (DIDWW, projekt LiveKit `maja`) — ista koda, drug glas |
| Telefonski agent | LiveKit Cloud, `CA_PiXjcGNWErxB`, regija `eu-central` |

ID-ji trunka, dispatch pravila in postopek pri DIDWW so v
[docs/telefon.md](docs/telefon.md).

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

### Odgovarja na vprašanja, ki niso o ceni
Pogoji, potek dela, kaj potrebuje od stranke, ali dela za določeno vrsto strank.
Vsebino ureja podjetje v `admin/znanje.php`; v kodi je ni. Če odgovora ni, tega
**ne zapolni s splošnim znanjem** — pove, da bo potrdil sodelavec.

### Dogovori termin
Prosti termini se izpeljejo iz delovnega časa in že zasedenih vrstic, ne iz
vnaprej pripravljene tabele. Zasedenost se preveri **še enkrat tik pred
zapisom**: med tem, ko stranka pove ime in telefon, lahko termin vzame nekdo drug.

### Preveže na človeka
Ko stranka izrecno želi sodelavca, ko se pritožuje, ali ko ji dvakrat zapored ni
znala odgovoriti. Samo znotraj delovnega časa — zunaj njega bi klicatelj pristal
na zvonjenju v prazni pisarni.

### Govori in posluša
Samo po telefonu, prek ločenega agenta, ki teče v LiveKit Cloud. Ta zna dvoje,
česar klepet ne:

- **Prebere številko, s katere kličejo**, iz same povezave (`sip.phoneNumber`),
  zato je stranki ni treba narekovati. Narekovanje po zvoku je bil najpogostejši
  vir napak — števke se zamenjajo in ponudba odide v prazno.
- **Odloži slušalko**, ko je povpraševanje oddano in stranka nima več vprašanj
  (orodje `koncaj_pogovor`). Brez tega klic obvisi v tišini.

---

## 3. Kako je zgrajeno

```
Klicatelj (telefon)
        │
        └─ telefon (LiveKit agent, voice-agent/agent.py)
                │   HTTP + TOOL_SECRET
                ▼
        ai/agent-config.php ..... prompt; ai/call-guard.php ... vratar klica
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
| `setup.php` | Namestitveni čarovnik. **Po namestitvi izbriši.** Sam se izklopi, ko `config.php` obstaja. |
| `data-view.php` | Razvojni pregled baze, stanja strežnika in preizkus pošiljanja. Zaklenjen s `DATA_VIEW_KEY`. |
| `bootstrap.php` | Naloži konfiguracijo in razrede, vrne pripravljen `ToolRegistry`. Zazna BOM. |
| `config.php` | Nastavitve. **Ni v gitu.** Ustvari ga čarovnik. |
| `.htaccess` | Blokira dostop do `.git/` prek `RewriteRule`. |
| `.cpanel.yml` | Deploy: rsync iz git repozitorija v živo mapo, z vsiljenimi pravicami 755/644. |

### `ai/` — pogovorni sloj
| Datoteka | Kaj dela |
|---|---|
| `guard.php` | Preverjanje izvora, omejitve, dnevni proračun žetonov. Po ukinitvi klepeta ga rabi samo `cron/opozorila.php` (`aiBudget()`); ob novem klepetu se vrne v uporabo. |
| `agent-config.php` | Telefonskemu agentu vrne sistemski prompt. Zaščiteno s `TOOL_SECRET`. |
| `call-guard.php` | Vratar telefonskih klicev: pove, ali sme klic naprej, in prejme trajanje. Zaščiteno s `TOOL_SECRET`. |
| `log-conversation.php` | Telefonski agent sem ob koncu klica pošlje potek pogovora, da ga `admin/pogovori.php` prikaže enako kot klepet. |
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
| `core/CallLimits.php` | Dnevni števci telefonskih klicev. Številk ne shranjuje — za štetje zadošča zgoščena vrednost. |
| `implementations/KnowledgeTool.php` | Odgovori na vprašanja, ki jih ni v katalogu. |
| `implementations/AppointmentTool.php` | Prosti termini in rezervacija. |
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
| `znanje.php` | Urejanje baze znanja. |
| `termini.php` | Pregled in odpoved dogovorjenih terminov. |

### Ostalo
- `cron/opozorila.php` — dnevni pregled; pošlje pošto samo, kadar je kaj narobe
- `voice-agent/agent.py` — telefonski agent (LiveKit Agents, Python)
- `voice-agent/Dockerfile` — slika za LiveKit Cloud; `download-files` teče ob gradnji, da se Silero ne prenaša ob vsakem hladnem zagonu
- `voice-agent/livekit.toml` — veže mapo na oblačnega agenta. **Ni v gitu** (vezan na računalnik, razkriva gostiteljsko ime projekta). Ustvari ga `lk agent config --id CA_PiXjcGNWErxB`
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
| `ai_knowledge` | Odgovori na vprašanja, ki niso o ceni. `keywords` obstaja, ker stranka po telefonu redko uporabi iste besede kot zapisan odgovor. |
| `ai_appointments` | Dogovorjeni termini. Prostih terminov ni v tabeli — izpeljejo se iz delovnega časa. |

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

### Telefon ima svoje meje

Zgornja tabela velja **samo za klepet na strani**. Telefon gre mimo nje: agent
kliče OpenAI in Azure neposredno, `tools/*.php` pa ga spusti skozi že na podlagi
`TOOL_SECRET`. Zato `ai/call-guard.php`:

| Konstanta v `config.php` | Privzeto | Kaj ustavi |
|---|---|---|
| `CALL_MAX_SECONDS` | 600 | posamezen klic; pol minute prej opozori, nato zaključi |
| `CALL_DAILY_MINUTES` | 120 | skupne minute na dan, čez vse klicatelje |
| `CALL_MAX_PER_CALLER` | 10 | klicev iste številke na dan |

Števci živijo na gostovanju, ne v agentu. Agent teče v oblaku, kjer se replika
lahko kadar koli zažene znova s praznim diskom — števec v njem bi se vrnil na
nič ravno takrat, ko bi bil najbolj potreben.

Klici s skrito številko se štejejo skupaj pod eno oznako, sicer bi bila skrita
številka luknja mimo zadnje meje. Če `call-guard.php` ni dosegljiv, klic spustimo
skozi, a časovna meja vseeno velja: nedosegljiv strežnik ne sme pomeniti nemega
telefona niti linije brez konca.

**Osebni podatki:** dnevniki maskirajo telefone, e-pošto, imena in zadnji oktet
IP. Hranijo se 14 dni. Mapa `logs/` je zaprta z `.htaccess`. Celotna pot zapisa
pogovora, kaj se maskira in kaj ne ter poti razkritja: razdelek 15.

**Skrbniška stran:** geslo zgoščeno (`password_hash`), seja 8 ur, piškotek
HttpOnly in SameSite=Strict, CSRF žeton na dejanjih, 10 poskusov na 15 minut.

### Napadalni nabor

`voice-agent/scenarios-napadi.yaml`, 16 scenarijev. Ločen od rednega nabora
namenoma: dražji je in se poganja redkeje. Pokriva vrivanje navodil (neposredno
in skrito v podatku), prevzem vloge, izdajo podatkov o strankah, ugibanje
številk projektov, razkritje sistema, izsiljeno ceno in rok, obrekovanje
konkurence, svetovanje zunaj stroke, zlorabo virov, smeti v bazi, oddajo
povpraševanja za tretjo osebo, menjavo jezikov, žalitve in nujni primer.

Merilo je povsod isto: **napad uspe = padec**. Vljudna zavrnitev ni padec, tudi
če sogovornik ostane nezadovoljen.

### Preverjeno ročno proti napadom (16. 9. 2026)
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

### Telefonski agent je drugi deploy

Kodo poganjata dve različni mesti in `git push` ne zažene nobenega:

| Kaj se je spremenilo | Kje teče | Kako pride v uporabo |
|---|---|---|
| `ai/`, `tools/`, `admin/`, prompt | cPanel | Update from Remote → Deploy HEAD Commit |
| `voice-agent/agent.py` | LiveKit Cloud | `lk agent deploy` |
| `voice-agent/.env` | LiveKit Cloud | `lk agent update-secrets --secrets-file .env --overwrite` |

**Najprej cPanel, nato agent.** Agent ob zagonu prenese prompt in kliče
`call-guard.php`; v obratnem vrstnem redu prvi klic zadene 404.

Sprememba `.env` ne potrebuje `deploy` — agent se po `update-secrets` sam zažene
znova. Sprememba `agent.py` potrebuje `deploy`, ker v oblaku teče posnetek kode
izpred zadnjega deploya, ne tvoja mapa.

### Tatjana V2 — druga številka za predstavitev (21. 10. 2026)

Namen: na sestanku zaigrati dva glasova na dveh številkah brez dveh baz in dveh
promptov. Ista koda, dvoje nastavitev.

| | Tatjana | Tatjana V2 |
|---|---|---|
| Številka | `+386 5 7774124` | `+386 5 7774102` |
| LiveKit projekt | `tatjana` (`p_3c3m91h1rrq`) | `maja` (`p_1bf8zg1pxmb`) |
| Agent | `CA_PiXjcGNWErxB` | `CA_hTBKtHimCXjZ` |
| Dispatch pravilo | `SDR_tpMPgUorXQhT` | `SDR_Ugee48V65ETc` |
| Glas | Azure `sl-SI-PetraNeural` | ElevenLabs Bella (od 24. 10. 2026; prej "Maja", umaknjena iz knjižnice), `eleven_v3_conversational` |
| Ime asistentke | Tatjana (`ASSISTANT_NAME` na strežniku) | Maja (`IME_ASISTENTKE` v `.env.v2`) |
| Model | `gpt-5.6-sol` | `gpt-5.6-sol` (`gpt-4.1` vrnjen 22. 10., glej spodaj) |
| Prepis | `gpt-transcribe` | `gpt-transcribe` (`gpt-live-transcribe` vrnjen, glej spodaj) |
| Nastavitve | `voice-agent/.env` | `voice-agent/.env.v2` |
| Konfiguracija `lk` | `livekit.toml` | `livekit-v2.toml` |

Deploy V2 (iz mape `voice-agent`):

```powershell
lk --project maja agent deploy --config livekit-v2.toml --secrets-file .env.v2 .
```

**Majin agent je prepisan.** Številka 7774102 je bila Maja za Kurivo Gorica.
Projekt LiveKit `maja` na brezplačnem paketu dovoli enega agenta (`maximum number
of agents reached (1/1)`), zato V2 ni mogel dobiti svojega mesta in je Majina
koda v oblaku zamenjana. Majo dobiš nazaj z novim deployem iz repozitorija
KurivoGorica (`Desktop\ClaudeMemory\KurivoGorica\repo\asistent\voice-agent` ima
`.env` in `livekit.toml` z istim agentom):
`lk --project maja agent deploy --secrets-file .env .` iz te mape.

**Klic je zvonil brez odgovora** (21. 10. 2026, 18:13 UTC). Majino pravilo
`SDR_Ugee48V65ETc` je tipa Callee brez `randomize`, zato je vsak klic na
7774102 dobil sobo z istim imenom `maja__38657774102`. Agent se pošlje samo ob
nastanku sobe: če je prejšnja soba še živela, je nov klic pristal v njej brez
agenta. Ta klic je prišel med deployem in posel je pristal pri delavcu, ki se je
ravno zapiral. Popravek: `lk sip dispatch update --id SDR_Ugee48V65ETc --callee
maja_ --randomize` — vsak klic ima zdaj svojo sobo, kot pri Tatjani. Agent
`maja` in trunki so ostali. **Med predstavitvijo ne poganjaj ne `deploy` ne
`update-secrets`:** oba znova zaženeta delavca in klic v tistem trenutku zvoni
v prazno.

**Dispatch pravilo je ostalo nedotaknjeno.** Pravilo `SDR_Ugee48V65ETc` kliče
agenta po imenu `maja`, zato se delavec prijavi pod tem imenom:
`agent_name` v `agent.py` bere `AGENT_IME` in brez te spremenljivke ostane
`tatjana`. V `.env.v2` je `AGENT_IME=maja`. Preimenovanje pravila bi bila druga
sprememba na isti poti brez koristi.

**Preklop glasu na ElevenLabs** (po plačilu paketa), iz mape `voice-agent`:

```powershell
# v .env.v2 nastavi TTS_PONUDNIK=elevenlabs, nato:
lk --project maja agent update-secrets --config livekit-v2.toml --secrets-file .env.v2 --overwrite
```

Vrnitev na Azure je `TTS_PONUDNIK=azure` in isti ukaz. Brez deploya.

**Glas ElevenLabs je vklopljen od 21. 10. 2026**, ko je bil plačan paket Starter
(1 $ prvi mesec, 30k kreditov ≈ 30 minut govora). Model je
`eleven_v3_conversational`; `eleven_v3` je po meritvi petkrat počasnejši,
`eleven_v4_turbo` pa ga vtičnik ne doseže. Meritve so v
[TODO.md](TODO.md#hitrejsega-modela-za-slovenscino-ni--izmerjeno-21-10-2026). Prvi pravi klic
prek te poti je potrjen isti dan; povprečen TTFB iz nadzorne plošče ElevenLabs
je 0,306 s, Azure je imel 529 ms.

**Če ElevenLabs odpove, klicatelj sliši tišino.** `izberi_glas()` na Azure
preklopi samo, kadar `ELEVEN_API_KEY` manjka — napake med klicem ne ujame.
Vrnitev je zato ročna in traja toliko kot `update-secrets`.

**Ime.** Prompt in pozdrav prideta s strežnika z imenom iz `ASSISTANT_NAME`.
`IME_ASISTENTKE` v agentu to ime zamenja v obeh, zato isti strežnik služi obema
številkama. Brez spremenljivke se nič ne spremeni.

### Zamik V2 — izmerjeno 21. 10. 2026

Cilj je bil odziv pod 2 s. Prvi klic z glasom ElevenLabs (nadzorna plošča
LiveKit, seja `RM_EPduApBErwJ4`):

| Korak | Mediana |
|---|---|
| STT delay | 1122 ms |
| LLM TTFT | 1931 ms |
| TTS TTFB | 171 ms |
| **E2E** | **~3225 ms** |

Govor ni bil problem. Model in prepis sta bila 95 % zamika, zato sta se
zamenjala ta dva — vsak izmerjen posebej, z istim promptom in orodji kot v
klicu, preden je šel v deploy.

**Model.** TTFT s pravim promptom (~26.500 znakov, 9 orodij), mediana devetih
vzorcev z razvijalčevega računalnika:

| Model | Privzeto | `service_tier=priority` |
|---|---|---|
| `gpt-5.6-sol` | 1453 ms | 1038 ms |
| `gpt-5.4-mini` | 1046 ms | 824 ms |
| `gpt-4.1-mini` | 969 ms | 716 ms |
| `gpt-4o-mini` | 826 ms | — |
| `gpt-5.4-nano` | 793 ms | — |
| **`gpt-4.1`** | **744 ms** | 822 ms |

`gpt-4.1` je najhitrejši brez doplačila in med hitrimi najmočnejši. Na petih
scenarijih z orodji s pravega strežnika (cena strani, Meta oglasi, "je to
končna cena", delovni čas, storitev, ki je ni) je odgovoril pravilno: "od
399 €", "po dogovoru", "izhodiščna", "tega nimam potrjenega". Odgovori so
krajši in se končajo z vprašanjem, kar po telefonu pomaga. `gpt-4.1` ne
razmišlja in zavrne `reasoning_effort` s 400, zato ga `nastavitve_modela()`
pošlje samo modelom `gpt-5*` in `o*`. Temperatura je 0,6 kot ob uglaševanju.

**Prepis.** Prek istega vtičnika kot v agentu, s ključnimi besedami s strežnika,
na treh slovenskih povedih, zmanjšanih na telefonskih 8 kHz; čas od konca
govora do končnega prepisa, skupaj s 400 ms `VAD_TISINA`:

| Model | Mediana | Napake |
|---|---|---|
| `gpt-transcribe` (sprotno) | ~950 ms | 0 |
| **`gpt-live-transcribe`** | **~560 ms** | 0 |
| `gpt-4o-transcribe` | ~1050 ms | e-pošto zapiše kot naslov |
| Azure `sl-SI` | ~970 ms | e-pošto zlepi v eno besedo |

ElevenLabs Scribe ni bil izmerjen: ključ nima dovoljenja `speech_to_text`.

**Ogrevanje.** `ogrej_proces()` naloži VAD in `anyio` ob zagonu procesa
(`server.setup_fnc`), ne med prvim klicem — 458 + 117 ms manj na prvem klicu.

**Simulacija scenarijev ne teče** v nobenem od obeh projektov:
`quota exceeded: MaxSimulationTextTurns`. Vedenje je bilo zato preverjeno z
neposrednimi klici modela in orodij, ne z `lk agent simulate`.

**Klic po menjavi modela in prepisa** (seja `RM_ccedBr5VZKg8`): E2E 2356 ms,
STT 816 ms, LLM TTFT 1176 ms, TTS 148 ms. Hitreje, a ne pod 2 s. Dnevnik je
pokazal še dva vira:

- **Hladen predpomnilnik prompta.** Prvi obrat klica pošlje ~11.000 žetonov
  brez predpomnilnika. `ogrej_predpomnilnik()` jih pošlje med pozdravom
  (`OGREJ_PROMPT=1`), tok zapre ob prvem kosu. Lokalno izmerjeno: prvi obrat
  2048 → 1156 ms in 1389 → 801 ms, 10.496 žetonov iz predpomnilnika.
  Cena je en polni vhod na klic, pri `gpt-4.1` okrog 2 centa.
- **Uvoz `openai.resources`** ob `models.list()` vtičnika: 430 ms zastoja zanke
  pred pozdravom. Zdaj v `ogrej_proces()`.

**Klic po ogrevanju** (seja `RM_ro52aWSf5W3a`, podjetjev ključ): edini pravi
zahtevek modela je imel 10,6k od 10,7k vhodnih žetonov iz predpomnilnika, čeprav
je bil račun nov in predpomnilnik prazen — ogrevanje torej dela. E2E ni bil
izmerjen: klicatelj je govoril čez pozdrav (prekrivanje 742 ms) in LiveKit
prekinjenih obratov ne meri. `zagon_seje` je padel na 212 ms. Dnevnik je
pokazal še uvoz `livekit.plugins.elevenlabs` (274 ms) — zdaj v `ogrej_proces()`.

**`gpt-live-transcribe` je bil vrnjen na `gpt-transcribe`** (21. 10. 2026
zvečer). V klicih je šum na liniji spreminjal v besede tujih jezikov — "はい",
"什么", "Мне бы чтобы часу…", "นี่อยู่ที่ what the fuck", "Mensch", "Proszę",
"Oh my God". Vsak tak fragment je štel kot govor stranke: Maja je utihnila sredi
stavka, odgovor v pripravi se je zavrgel, in ko so fragmenti prihajali vsakih
1–3 s, ni prišla do besede. Dva od šestih klicev sta tako obmolknila
(`RM_tftbCVPvHqsd` po 56 s, `RM_56eJPEoPEm7b` takoj po pozdravu, tudi na jasno
"Kakšna storitev izvajate?"). V dnevniku agenta ni bilo nobene napake. Klica na
`gpt-transcribe` (`RM_Q4Juv3tRSq96`, `RM_EPduApBErwJ4`) imata čist slovenski
prepis. Moja primerjava prepisa je bila na čistem posnetku brez šuma in tega
ni mogla ujeti. Jezik `sl` gre v seji kot `languages`, a je to namig, ne meja.
Na odgovor je Maja enkrat rekla "Tole je pa japonščina".

**Tatjana dobila isti sklop** (21. 10. 2026 zvečer, brez `gpt-live-transcribe`):
`gpt-4.1`, `LLM_TEMPERATURE=0.6`, `OGREJ_PROMPT=1` in deploy današnje kode
(ogrevanje procesa). Glas ostaja Azure Petra, ime Tatjana. Prejšnji `.env` je v
`voice-agent/.env.varnostna-kopija-2026-10-21`.

**Meritev po vseh spremembah** (21. 10. 2026, 20:35–20:37 po lokalnem času):

| | Maja `RM_eiUpCpDZwHYs` | Tatjana `RM_aUiajKn7W7v3` |
|---|---|---|
| Povprečen odziv | **~2005 ms** | ~2830 ms |
| E2E po obratih | 2318, 2249, 1926 ms | 3084 ms (drugi klic 2810 ms) |
| STT delay | 952 ms | 897 ms |
| LLM TTFT | 886 ms | 915 ms |
| TTS TTFB | 166 ms (ElevenLabs) | **1016 ms** (Azure) |
| Žetoni iz predpomnilnika | 94,2k od 98,1k | 21,4k od 21,7k |

Prepis pri obeh čist slovenski, brez tujih besed, brez tišine; `koncaj_pogovor`
je pri obeh klic pravilno končal. Maja je pred tem imela ~3225 ms, Tatjana
~3200 ms (17. 9.).

Pri Tatjani je zdaj najdaljši korak glas. Azure je imel 17. 9. mediano 529 ms,
zdaj ~1000 ms. Verjetna razlaga (ni preverjena): `gpt-4.1` piše daljše prve
povedi, Azure pa sintetizira celo poved, preden pošlje prvi zvok; ElevenLabs
besedilo sprejema sproti.

Maja je preobčutljiva za šum. V klicu je imela 3 prekinitve, kratki fragmenti
("Chat.", "Hm.", "Imala soft.") so jo ustavili sredi stavka, na "Chat." je celo
odgovorila. Klicatelj je to v klicu rekel sam: "vsak šum, ko ga naredim, se
kar ustaviš".

**`gpt-4.1` je 22. 10. 2026 vrnjen na `gpt-5.6-sol`.** Bil je hitrejši, a je
pokvaril narekovanje e-pošte. V pravem klicu (`RM_bp3hDovVKhfp`, sodelavka Stina)
je izpuščal dele naslova ("tina afna gmail pika com" brez "mavri"), črkoval,
čeprav prompt to prepoveduje, in besedo "pika" zlepil v naslov
("tina.pikamavri"). Na istem pogovoru, trikrat vsakemu modelu: `gpt-5.6-sol` je
naslov po jasnem črkovanju prebral pravilno 3 od 3, `gpt-4.1` 1 od 3. Govoril je
tudi predolgo (~25 s na "Kaj je Kreativni splet?"). Cena vrnitve je ~0,5–0,7 s
na obrat. Pospešitev brez menjave modela: `service_tier=priority` (preizkus:
1453 → 1038 ms), terja spremembo kode.

**"Stina" je postala "Tina" — povpraševanje ni šlo skozi.** Telefonska linija
prenaša do ~3,4 kHz, glas "s" ima večino energije nad 4 kHz, zato se pred "t"
izgubi. Prepis je "Stina" kot besedo in "T I N A" brez premorov zapisal brez
"S"; "S, T in A" s premori in "s kot Slovenija" sta "S" ohranila. `eposta.py`
zna vse te oblike ("es kot Slovenija", "S, T in A", "es te i en a"). Ker je
e-pošta obvezna v agentu in na strežniku, povpraševanje ni bilo oddano. Zdaj
(22. 10. 2026):

- navodilo v `vstopna_tocka`: ob popravku prosi za črkovanje, vsako črko z
  besedo ("es kot Slovenija");
- po dveh popravkih `submit_inquiry(eposta_nepotrjena=True)`: odda s telefonom,
  naslovom, kot je bil slišan, in opombo `E-POŠTA NI POTRJENA … Pokličite
  stranko`. Koda to dovoli šele po treh obratih z narekovanjem
  (`NAREKOVANJ_PRED_NEPOTRJENO`), sicer vrne navodilo za črkovanje. Kadar iz
  slišanega ni mogoče sestaviti naslova, gre `eposta.ni.potrjena@<domena podjetja>`,
  ker strežnik zahteva veljaven naslov. Opomba je odrezana na 500 znakov — daljšo
  strežnik zavrne skupaj s povpraševanjem.

Preverjeno: `test_varovalke.py` (`preveri_nepotrjeno_eposto`), `test_eposta.py`
in trikrat `gpt-5.6-sol` na Stininem pogovoru — vsakič je prosil za črkovanje,
po dveh popravkih ponudil oddajo s telefonom in poklical orodje z
`eposta_nepotrjena: true`. Pravi klic po deployu še ni bil.

**Asistentka naslov prebere nazaj po golih črkah** (22. 10. 2026):
"S, T, I, N, A, pika, M, A, V, R, I afna gmail pika com". To razveljavlja
prejšnje pravilo "nikoli ne črkuje" za branje nazaj. Razlog zanj je bil glas,
ki je 11. 10. črke bral angleško ("ti", "aj"); 22. 10. sta oba glasova (Maja
ElevenLabs, Tatjana Azure) na vzorcu brala slovensko (Luka je poslušal).

Isti dan preizkušeno in zavrnjeno (Luka): sidrne besede ("es kot Slovenija, te
kot Triglav") — predolge; imena črk ("es, te, i") — Slovenec tako ne črkuje.

Zakaj: klic ob 06:13 (`RM_iuqfFNCCzC66`, povpraševanje **559**). Prepis je
zapisal `stina.mauri@gmail.com`, ker se "v" pred "r" izgovori kot [u].
Asistentka je naslov prebrala z besedami, "mauri" in "mavri" pa zvenita enako,
zato je stranka napačen naslov potrdila. **Branje z besedami takega naslova ne
more ujeti nikoli** — ujame ga samo črkovanje.

Zdaj `izgovori_eposte` (`_po_crkah`) del pred afno prebere po črkah, domeno
pa še naprej z besedami ("afna gmail pika com"): domena je
iz kratkega seznama in jo `_popravi_domeno` popravi, zato bi bilo črkovanje pri
njej samo dolgo. Naslov podjetja se ne črkuje — `tts_node` ga poda v
`brez_crkovanja`, ker ga stranka ne preverja.

Prebrano mora `popravi_naslov` znati sestaviti nazaj, sicer bi stranka, ki
ponovi za asistentko, dobila drug naslov. Test `prebrano nazaj da isti naslov`
to drži.

**Asistentka ne skače v besedo, ko klicatelj narekuje podatke** (22. 10. 2026).
Med črkovanjem in pri "Moje ime je … Tone" klicatelj premolkne dlje od
`VAD_TISINA=0.4` in Maja je vskočila ("Kar počasi", "Nadaljujte", "Dobro,").
Globalen dvig `VAD_TISINA` na 0,6 bi upočasnil vsak odgovor za 0,2 s, zato ga ni.
Namesto tega `ob_novem_sporocilu` po vsakem stavku asistentke pokliče
`session.update_options(endpointing_opts=…)`: če stavek prosi za podatek
(`prosi_za_podatek`: glagol prošnje + ime, priimek, e-pošta, telefon, črke,
naslov), je `min_delay` 1,6 s in `max_delay` 4,0 s (`PODATKI_CAKAJ`, 0 izklopi);
po naslednjem stavku, ki ni prošnja, se vrne na običajno. Preverjeno na stavkih
iz klicev 22. 10.: "Mi poveste še ime in e-poštni naslov" da,
"Kar povejte, kaj vas zanima" ne. Pravega klica še ni bilo. Podaljšanje velja
tudi, ko asistentka bere naslov nazaj ("Je naslov pravilen?" ga ne sproži).

Navodilo modelu za črkovanje je usklajeno z branjem: "S, T, I, N, A" in ne več
"es kot Slovenija".

Branje je daljše kot z besedami. Zato je pomembno, da asistentke med branjem ne
prekine šum — Maja ima `PREKIN_BESEDE=2`, Tatjana ga nima.

**Prvi klic s črkovanjem ga ni sprožil** (`RM_HLZUiN6RXGtp`, povpraševanje
**560**, naslov napačen). Model je naslov napisal v govorjeni obliki
("stinaikmavri afna gmail pika com"), `izgovori_eposte` pa je iskal samo obliko
z `@`. Zdaj `IZGOVORJEN_NASLOV` govorjeno obliko najprej prevede v zapisano, v
navodilu pa model piše naslov z afno kot znakom. Zaporedja navadnih besed pred
afno nalašč ne ujame ("Slišala sem janez afna …") — raje brez črkovanja kot
napačen naslov.

**"pika" se po telefonu izgubi kot "ika", "tika", "ilka"** (trije klici 22. 10.).
Začetni "p" je šibak kot "s". `ZNAKI` jih zdaj bere kot piko, model ima isto v
navodilu. Napačna razlaga je poceni, ker gre naslov nazaj po črkah; izpuščena
pika naslov tiho pokvari. Robni primer: naslov, ki se res začne s "tika@", se ne
sestavi in asistentka ga vpraša znova.

**Male črke pri branju naslova** (24. 10. 2026, `CRKE_MALE=1`). Naslov
`sasa.perkon` je bil pravilno razumljen in zapisan, a ga je Bella izgovorila po
angleško: velike črke ("S, A, S, A") glasovi berejo kot angleška imena črk.
Male ("s, a, s, a") zvenijo slovensko (Luka poslušal vzorce A/B/C na Belli). Slovenska
imena črk ("es, a, es") niso potrebna. Vklopljeno na obeh številkah; za Tatjano
(Azure Petra) je bila velika oblika prej prav, malo sem vklopil na Lukovo prošnjo,
ker je razumevanje e-pošte ključno. Če Petra male črke izgovarja slabše,
`CRKE_MALE` iz `.env` in `update-secrets`.

**Zvočnik: Maja sliši sebe in ozadje** (24. 10. 2026). V klicu `RM_txmHGraaQZL6`
je pozdrav ušel nazaj v mikrofon ("Ja. Kako lahko pomagam?" je konec njenega
pozdrava, ne klicatelja) in govor v ozadju je držal zaznavo govora ves čas: 24 s
"govora stranke" v 62 s klica, 7011 ms prekrivanja. Model je odgovor pripravil
(26 žetonov), sistem ga je zavrgel, ker se obrat ni končal. `VAD_PRAG` ozadja ne
loči od klicatelja. Zato Maja: `VAD_PRAG=0.65` in `ODSTRANI_SUM=1`
(`odstranjevanje_suma()`, Krisp BVCTelephony pred zaznavo govora). Napaka pri
nalaganju klic le izpusti. Tatjana ima kodo brez vklopa. Preizkusa na zvočniku še
ni bilo, plačljivosti BVC nismo preverili.

**Maja: `PREKIN_BESEDE=2`** (22. 10. 2026). Prekine jo šele govor z vsaj dvema
besedama; "Chat." ali "Hm." je ne ustavi več, "Ne." prav tako ne. Odgovora na
enobesedne smeti ne prepreči.

**Glas bolj uraden.** Pri `eleven_v3_conversational` gre prek
`text-to-dialogue` samo `stability`, vse drugo vtičnik zavrže. Višja je manj
čustvena. `ELEVEN_STABILNOST=0.75`; na čas do prvega zvoka ne vpliva (izmerjeno
~150 ms pri 0,5 in 1,0).

**Pravilo "po eno stikalo" je tu kršeno namerno.** Trije posegi so šli skupaj,
ker je bil vsak prej izmerjen posebej in ker Tatjana na 7774124 ostaja
nespremenjena kot primerjava. Na Tatjano jih prenašaj po enega.

---

## 8. Kje smo

### Deluje
- [x] Besedilni klepet in glasovni asistent na spletni strani
- [x] Vsa štiri orodja, 26/26 testov
- [x] Povpraševanja v bazo + obvestilo prek SMTP
- [x] Azure slovenski glas — cene in telefonske številke izgovori pravilno
- [x] **Telefon deluje.** Številka registrirana, klic pride skozi, agent se javi
- [x] **Agent teče v LiveKit Cloud** — ni več odvisen od razvijalčevega računalnika
- [x] Agent prebere številko klicatelja iz povezave (`sip.phoneNumber`)
- [x] Meje telefonskih klicev (trajanje, dnevne minute, klici na klicatelja)
- [x] Skrbniška stran: povpraševanja, storitve, pogovori
- [x] Namestitveni čarovnik za nove stranke
- [x] Zaščita preverjena proti 14 vrstam napada

### Glasovna pot — izmerjeno

Iz nadzorne plošče LiveKit, `Response Latency → Tails by stage`.

| Korak | 17. 9. mediana |
|---|---|
| **E2E** | **3198 ms** (prej 6065 ms) |
| STT delay | 963 ms |
| LLM TTFT | 889 ms |
| TTS TTFB | 529 ms |
| EOT | 15 ms |
| Klici orodij | 172 ms |

**Gostovanje ni ozko grlo.** Orodja odgovarjajo v 98–208 ms. Optimiziranje PHP
strani bi bilo zapravljen čas; preostanek je v prepisu, modelu in govoru.

19. 9. je razvijalec po zamenjavi modela in vklopu sprotnega prepisa ocenil
odziv na **eno do dve sekundi** in kakovost na "skoraj odlično". Nova meritev iz
nadzorne plošče še ni odčitana — to je prvo opravilo prihodnjič.

### Stikala za glas

Izboljšave glasovne poti so v `voice-agent/.env`. Privzetki v kodi so stanje
`b777fc8`, za katero je potrjeno, da zveni dobro; vsako stikalo je bilo
vklopljeno posebej in preizkušeno s klici.

| Stikalo | Stanje | Cilja na |
|---|---|---|
| `LLM_MODEL=gpt-5.4-mini` | **vklopljeno** | razumevanje in slovenščina |
| `LLM_TEMPERATURE=auto` | **vklopljeno** | model temperature ne sprejme |
| `LLM_NAPOR=minimal` | **vklopljeno** | razmislek pred odgovorom je slišna tišina |
| `PREDCASNO=1` | **vklopljeno** | LLM TTFT |
| `STT_SPROTNO=1` | **vklopljeno** | STT delay; prepis teče med govorom |
| `STT_MODEL=gpt-transcribe` | izklopljeno | novejši; edini odklene `keywords` iz kataloga |
| `STT_PONUDNIK=azure` | izklopljeno | hitrejši prepis, slabša slovenščina |
| `KONEC_MIN`, `PREKIN_SEK` | izklopljeno | čakanje in prekinjanje |
| `TTS_SAMPLE_RATE=16000` | izklopljeno | praskanje pri dolgih odgovorih |

**Vklapljaj po eno.** To pravilo je plačano: devet hkratnih sprememb glasovne
poti je klic poslabšalo in ugotoviti se ni dalo, katera je kriva. Celotna pot je
bila vrnjena na `b777fc8` in znova grajena po eni — tako je nastala zgornja
tabela.

### Varovala, ki jih drži koda in ne prompt

Ta seznam je nastal iz enega pravila, ki se je potrdilo vsakič: **kar mora
veljati zanesljivo, ne sme biti odvisno od modela.** Vsako od teh je bilo najprej
zapisano v promptu in je vsaj enkrat odpovedalo.

| Varovalo | Kje | Zakaj ni v promptu |
|---|---|---|
| nujni primer ne gre skozi prevezo | `predaj_cloveku` | model se v vznemirjenem pogovoru vedno znova zateka k prevezi |
| prevezo napove orodje | `predaj_cloveku` | model jo je napovedal še enkrat po neuspehu |
| dva poskusa preveze | `predaj_cloveku` | med poskusoma je obljubljal znova |
| napoved preveze čaka največ 4 s | `predaj_cloveku` | ročica govora se ni razrešila in REFER je odšel 11 s prepozno |
| konec klica zahteva pravi pozdrav | `koncaj_pogovor` | klic se je končal z "V redu." |
| povpraševanje zahteva potrditev | `submit_inquiry` + `InquiryTool` | oddal je takoj, ko je izvedel ime |
| nadomestki v poljih se zavrnejo | `submit_inquiry` | vpisal je "ime priimek" |
| `verify` mora biti kontakt | `lookup_project` | klical je z "111" |
| štetje ponovitev | `on_user_turn_completed` | pravilo v promptu je ob četrtem vprašanju spregledal |
| obrati brez orodja | `on_user_turn_completed` | različne ubeseditve razbijejo štetje ponovitev |
| tuje pisave iz govora | `tts_node` | napaka ne nastane iz razumevanja, ampak iz izbire žetona |
| meje klicev | `ai/call-guard.php` | števci morajo preživeti ponovni zagon replike |

### Kaj je odprto

Seznam opravil — odprto, varnost, pred zagonom, nadgradnje — je v
**[TODO.md](TODO.md)**. Tam so kljukice, tu razlogi. Nobena točka ni na obeh
mestih, ker se dva seznama opravil razideta in nobenemu ne zaupaš več.

Trenutno najbolj pereče: prekinitev klica po `3b2a8a2` ni potrjena z dnevnikom,
asistentka pa za telefonsko številko še vedno sprašuje, čeprav jo ima — popravek
je napisan in vrnjen, vrne se z `git revert f9b64d1`.

### Odprta vprašanja
- Javni ali zasebni repozitorij. Zdaj javen, ker cPanel Git zasebnega ni zmogel klonirati. Skrivnosti v njem ni (preverjena celotna zgodovina), a kodo, ki jo nameravaš prodajati, lahko kdorkoli prekopira. V zgodovini je gostiteljsko ime projekta LiveKit (commit `2ef41d6`); skrivnost ni, a olajša iskanje trunka, ki sprejema od koderkoli.
- Ali je `gpt-4o-mini` dovolj dober za slovenščino, ali je vreden večji model.
- Ali je Azure prepis vreden menjave: hitrejši je, a `gpt-4o-transcribe` slovenščino verjetno razume bolje.

---

## 9. Nadgradnje, ki bi naredile razliko

Razvrščeno po tem, koliko vsaka prinese glede na vloženo delo. Prve tri so
temelj: brez njih je vsaka nadaljnja izboljšava ugibanje.

### 9.1 Nabor preizkusnih pogovorov — brez tega ne gre naprej

**Težava:** vsako spremembo prompta, modela ali prepisa zdaj presodi en ročni
telefonski klic in občutek. Zato se je 17. 9. zgodilo, da je devet hkratnih
sprememb poslabšalo klic in ugotoviti se ni dalo, katera je kriva.

**Kaj:** 30 do 50 zapisanih slovenskih pogovorov s pričakovanim izidom —
vprašanje o ceni mora sprožiti orodje, vprašanje o tujem projektu mora biti
zavrnjeno, povpraševanje mora imeti vsa tri polja. Skripta jih požene skozi
`ai/chat.php` (ukinjen, glej razdelek 16; zdaj `lk agent simulate`) in prešteje odstopanja.

**Zakaj prvo:** šele s tem se da odgovoriti, ali je večji model vreden denarja,
ali Azure prepis slovenščino res razume slabše, ali je nov prompt boljši. Brez
tega ostaja vse to stvar mnenja.

**Vloženo:** dan dela. `tests/test-tools.sh` je že predloga za obliko.

### 9.2 Predaja človeku — NAPISANO, ČAKA NA DIDWW

**Težava:** ko asistentka česa ne zna ali je klicatelj nejevoljen, klic konča v
slepi ulici. Za podjetje je to izgubljena stranka, in prav ta klic si bo
zapomnil.

**Kaj:** orodje `predaj_cloveku`, ki klic preveže na pravo številko.
`ctx.transfer_sip_participant(participant, transfer_to, play_dialtone)` je v
SDK že na voljo. Sproži se, ko stranka izrecno zahteva človeka, ko dvakrat
zapored ne dobi odgovora, ali ko gre za pritožbo.

**`transfer_to` mora biti SIP URI, ne tel: URI.** DIDWW prevezov na tel: URI
ne podpira in vrne `603 Unconfigured xfer for tel URIs`; v Refer-To hoce
`sip:<stevilka>@<gostitelj odhodnega trunka>`. Zato je v `TRANSFER_PHONE`
celoten URI, pretvorba v `tel:` v agentu pa velja le za ponudnike, ki ga sprejmejo.

**Zakaj:** to je največji dejavnik zaupanja pri prodaji. "Če te ne razume, te
preveže" odpravi glavni ugovor stranke.

**Vloženo:** pol dneva. Potrebuje delovni čas — zunaj njega se ne preveže, ampak
zabeleži povratni klic.

### 9.3 Več strank na eni namestitvi

**Težava:** vsaka stranka zdaj potrebuje svojo mapo, svoj `config.php` in svojo
bazo. Pri desetih strankah je to deset posodobitev ob vsaki spremembi kode.

**Kaj:** ena namestitev, ki stranko prepozna po klicani številki. Podatek je že
tu — agent bere `sip.trunkPhoneNumber`, klepet pa pozna domeno izvora. Nastavitve
se preselijo iz konstant v tabelo `ai_tenants`.

**Zakaj:** to je razlika med "prodajam projekte" in "prodajam storitev". Brez
tega mesečno vzdrževanje desetih strank pojé več, kot prinese.

**Vloženo:** teden dni. Največji poseg v arhitekturi, zato pred prvo zunanjo
stranko, ne po njej.

---

### 9.4 Znanje prek kataloga — NAREJENO 26. 9. 2026

Zdaj zna odgovoriti samo iz `ai_products` in `business-info.json`. Vprašanja
tipa "ali delate tudi za društva", "kako poteka prevzem strani" nimajo vira.

Rešitev: tabela `ai_knowledge` z vprašanji in odgovori, ki jih stranka ureja
sama v skrbniški strani, plus orodje za iskanje po njej. Polno indeksiranje
spletne strani je naslednji korak, a preprosta tabela pokrije večino primerov.

### 9.5 Naročanje terminov — NAREJENO 26. 9. 2026

Za del slovenskih malih podjetij — frizer, zobozdravnik, servis — je rezervacija
termina glavni razlog za klic, ne povpraševanje. Brez tega tem panogam nimaš kaj
prodati.

Potrebuje tabelo prostih terminov, orodje za rezervacijo in potrditev po e-pošti.
Google Calendar naj pride kasneje; najprej lastna tabela.

### 9.6 Opozorila, ko kaj odpove — NAREJENO 26. 9. 2026

Zdaj se za izpad izve šele ob naslednjem ročnem klicu. Potrebno:
obvestilo, ko je dnevni proračun dosežen, ko orodje večkrat zapored odpove, ko
se agent v oblaku ustavi, in ko povpraševanje čaka več kot dva dni.

### 9.7 Posnetki klicev kot gradivo — delno

Obvestilo o snemanju je **narejeno**: `CALL_RECORDING_NOTICE` pride v pozdrav
takoj za predstavitvijo, torej preden klicatelj kaj pove.

Uporaba posnetkov pa čaka na 9.1, ker brez nabora preizkusnih pogovorov nimajo
kam. Ko bo ta obstajal, je vir nadzorna plošča LiveKit — **Agents → tatjana →
Sessions** ima zapis vsake seje. Zapis je za ta namen boljši od zvoka: iz njega
se takoj vidi, ali je asistentka narobe **slišala** ali narobe **odgovorila**, in
to dvoje ima različna popravka.

`lk egress` tu ne pomaga — ta zna le tekoče snemanje, ne pa dostopa do
posnetkov, ki jih LiveKit Cloud hrani sam.

### 9.8 SMS potrditev povpraševanja

Po oddaji sporočilo s številko povpraševanja. Stranka ima dokaz, podjetje pa
manj klicev tipa "ali ste kaj dobili". DIDWW to zna; strošek je nekaj centov.

### 9.9 Krajši prompt in predpomnjenje — delno

Predpomnjenje je **vklopljeno** (`prompt_cache_key`, od `bc1ed1b`).

Krajšanje ne. Odstranjeno je bilo eno podvojeno pravilo, vsebinskih pa se nisem
dotaknil — in prompt je z znanjem, termini in prevezo medtem zrasel na 10 KB.
Brez 9.1 je krajšanje najhitrejši način, da se asistentka začne vesti slabše, ne
da bi kdo opazil.

---

## 10. Strošek

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
| LiveKit (SIP + minute agenta) | — | 0 € do 1.000 minut |
| **Skupaj** | **~0,09 €** | **~14 €** |

Agent v oblaku med klici miruje (`Replicas 0 / 1 / 1`), zato mirovanje ne stane
skoraj nič. Ob klicu pa tečejo **štirje števci hkrati**: LiveKit, DIDWW, OpenAI
in Azure. Prav zato obstajajo meje iz razdelka 6 — brez njih je strop kartica.

---

## 11. Odločitve, ki jih ne razveljavljaj brez razloga

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

## 12. Česa ne poskušaj znova

**WordPressov `wp_mail()` iz našega procesa.** `config.php` in `wp-config.php`
definirata iste konstante (`DB_NAME`, `DB_USER`, `DB_HOST`), zato bi WordPress
dobil naše vrednosti namesto svojih — tiho spreminjanje delovanja žive strani.
Zato obstaja `Mailer.php`.

**Zasebni repozitorij s cPanel Git.** Klon tiho spodleti, tudi z deploy key in
s PAT v URL-ju.

**Azure v regiji West Europe.** Ne sprejema novih strank. Uporabi `italynorth`.

---

## 13. Pasti, ki so nas že ujele

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
| Glas na vzorcu zveni bolje, po telefonu slabše | Vzorec je 16 kHz naravnost v zvočnike, telefon stisne na 8 kHz. Večjezični glasovi to prenesejo slabše od lastnih slovenskih. `en-US-AvaMultilingualNeural` je bil tako preizkušen in zavrnjen. Primerjaj vedno s pravim klicem. |
| Asistentka se ne javi, telefon samo zvoni | Agent ne teče. V oblaku teče posnetek izpred zadnjega `lk agent deploy`, ne tvoja mapa. |
| Klica ne prekine, čeprav orodje obstaja | `session.aclose()` znotraj orodja `koncaj_pogovor`: seja čaka na orodje, orodje na sejo. Zapri samo sobo. |
| `unable to create agent: maximum number of agents reached (1/1)` | Mesto zaseda Builder agent iz nadzorne plošče. Izbriši ga; `lk agent deploy` nanj ne dela. |
| Klic prevzame star agent | Lokalni `python agent.py dev` in oblačni agent sta oba prijavljena kot `tatjana` in si klice delita. |
| Odziv počasnejši, čeprav si zniževal zamike | `STT_TISINA_MS`, `VAD_TISINA` in `KONEC_MIN` čakajo vsi na isto tišino, eden za drugim. Popravljaj jih skupaj. |
| Agent se javi, nato na vsako poved molči | Povožena metoda `Agent`, ki je `async def`, z navadno. `await None` vrže `TypeError` in obrat umre. `inspect.signature` izpiše `-> None` tudi pri korutinah — preveri z `inspect.iscoroutinefunction`. |
| `ValueError: keywords are only supported by...` | `keywords` sprejmeta samo `gpt-transcribe` in `gpt-live-transcribe`. Drugim modelom jih podati pomeni sesut posel ob vsakem klicu. |
| Asistentka zveni sekano, čeprav je hitra | Samostojen medmet ("mhm") v premoru pogovor razseka na tri kose. Mašilo mora biti prva beseda odgovora, ne ločeno predvajanje pred njim. |
| "iz Kreativni Splet" | Ime podjetja ni sklanjano. Nastavi `BUSINESS_NAME_RODILNIK` in `BUSINESS_NAME_MESTNIK`; samodejno sklanjanje slovenščine ni zanesljivo. |
| Heredoc v Bashu požre `\` in PHP ali Python ne prevede | Pisanje datotek s `cat > f <<'EOF'` odstrani eno poševnico. Izogni se dvojnim poševnicam ali piši po vrsticah. |

---

## 14. Kako testiram

```bash
BASE_URL=https://kreativnisplet.si/asistent bash tests/test-tools.sh
```

Vseh 26 testov mora biti zelenih. Posebej zadnja dva: `GET` na tool mora vrniti
405, mapa `logs/` ne sme biti dosegljiva.

Asistenta preizkusi s klicem na telefonsko številko ali z `lk agent simulate`. Če pri vprašanju o
ceni **ne pokliče orodja**, je odgovor izmišljen — to je najhujša napaka in jo
`admin/pogovori.php` sam označi.

---

## 15. Vsebina pogovorov s Tatjano

### Kako deluje

Tatjana ob koncu telefonskega klica pošlje potek pogovora na
`ai/log-conversation.php` (`POST`, glava `X-Tool-Secret`). Zapis gre skozi isti
`Logger` kot klepet na strani, zato ga `admin/pogovori.php` prikaže brez
posebne obravnave. Razlog, da to obstaja: agent teče v LiveKit Cloud in orodja
kliče po HTTP, zato se je prej zapisalo le, **katera** orodja je klical, ne pa,
**kaj je povedal** — in "poglej v LiveKit" ni odgovor stranki, ki plačuje
vzdrževanje.

Ukinjeni spletni klepet je zapisal isto iz `ai/chat.php`; format ostane. Vsak obrat je ena vrstica JSON:
vprašanje, klicana orodja, odgovor.

Endpoint ima meje, da en zahtevek ne napolni diska: največ 256 KB telesa,
100 obratov, 4.000 znakov na sporočilo, 10 imen orodij.

### Kaj se zapiše in kje

| Zapis | Kje | Hramba |
|---|---|---|
| Potek pogovora | `logs/conversations-YYYY-MM-DD.log` na gostovanju | 14 dni |
| Klici orodij, trajanja, napake | `logs/tool-calls-*.log` | 14 dni |
| Povpraševanja in termini | baza, `ai_inquiries` in `ai_appointments`, **nemaskirano** | do izbrisa |
| Zvok in prepis seje | LiveKit Cloud, če je *Agent observability* vklopljen | 30 dni; na brezplačnem paketu del podatkov dlje |
| Podatki o klicu (številka, trajanje) | DIDWW | po pravilih operaterja |
| Vsebina pogovora | OpenAI (prepis in model) | ob evropskem projektu brez hrambe |
| Besedilo odgovora | Azure (sinteza govora) | brez hrambe; Azure prejme samo besedilo, nikoli posnetek klicatelja |

Nemaskirani podatki v bazi so namen, ne napaka: povpraševanje brez imena,
telefona in e-pošte je neuporabno. Dnevniki so drugo — tam gre za pregled nad
vedenjem asistentke, ne za podatke stranke.

### Kaj se maskira in kaj ne

Maskirano v dnevnikih: e-pošta v običajni obliki (`janez@domena.si` →
`j***@domena.si`), telefonska številka z osmimi ali več ciframi (→
`[tel:***123]`), polja `address` in `note` (→ `[skrito]`), zadnji oktet naslova
IP.

**Ne maskirano, in to je treba vedeti:**

1. **Ime in priimek v prostem besedilu.** Maskiranje imena velja za polja, ki se
   imenujejo `name` ali `ime`, ne za stavek "jaz sem Janez Novak" sredi
   pogovora. Ta ostane zapisan.
2. **E-pošta, narekovana po črkah.** Po telefonu klicatelj naslov pogosto
   izgovori ("janez pika novak afna gmail pika com"). Vzorec za e-pošto tega ne
   prepozna, zato ostane v dnevniku berljiv.
3. Domena e-pošte in zadnje tri cifre telefonske številke ostanejo tudi pri
   maskiranih vnosih.

Dnevniki so torej **psevdonimizirani, ne anonimizirani**. V pogodbi s stranko
jih je treba tako tudi imenovati.

### Ali lahko pride do razkritja podatkov

Po velikosti tveganja:

1. **WordPress na istem gostovanju in v isti bazi.** Tabele `ai_*` stojijo v
   bazi `kreati02_wp_iuikg`. Ranljiv vtičnik na glavni strani je pot do
   povpraševanj strank, mimo celotnega asistenta. Največje tveganje in edino,
   ki ni pod našim nadzorom. Ukrep: ločen uporabnik baze z dostopom samo do
   `ai_*`, dolgoročno ločena baza in strežnik.
2. **LiveKit observability.** Hrani zvok in prepis **brez maskiranja**. Na
   brezplačnem paketu je projekt vključen v program izboljševanja modelov, kar
   pomeni hrambo dela podatkov dlje od 30 dni. Ukrep: izklop v nastavitvah
   projekta (*Data and privacy*) ali *PII redaction* in plačljiv paket; v kodi
   `record=False` v `session.start()`. Opazovanja s tem ne izgubimo, ker potek
   pogovora zapiše `zapisi_pogovor()` v naš dnevnik.
3. **`voice-agent/.env` na strežniku.** Vsebuje ključe, varuje ga samo zavrnitev
   dostopa. Na strežniku ga ne rabimo — skrivnosti so v LiveKitu. Ukrep: izbriši.
4. **Mapa `logs/` leži znotraj web roota.** Zaščitena je z `.htaccess` in
   `Require all denied`, deploy pa `logs/*.log` izključuje, da se zapisi ne
   prepisujejo. Zaščita visi na eni datoteki: brez `.htaccess` ali ob prehodu na
   nginx bi bili dnevniki javni.
5. **`TOOL_SECRET` je eno samo geslo za vsa orodja po HTTP.** Kdor ga ima, lahko
   bere katalog in piše v dnevnik; vpogled v projekt zahteva dva ujemajoča se
   podatka, zato sam ključ ne zadošča. Ukrep: zamenjava ob vsaki menjavi
   sodelavca.
6. **Čiščenje po 14 dneh se sproži naključno**, pri približno vsakem petdesetem
   zapisu. Pri majhnem prometu lahko star dnevnik ostane dlje, kot pravi
   politika hrambe. Ukrep: izbris prek `cron`.

Nižje tveganje, ker je že urejeno: `setup.php` (zna zapisati `config.php`) in
`data-view.php` (prikazuje osebne podatke) sta izključena iz deploya in na živi
strani ne obstajata; `config.php` ni v repozitoriju; skrbniška stran je za
geslom, s CSRF žetonom in omejitvijo poskusov prijave.

---

## 16. Ukinitev spletnega klepeta in načrt za novega

**Odločitev (17. 10. 2026):** `/asistent/` na strani ukinjen. Za zdaj obstaja samo
glasovni klic. Klepet na spletni strani pride pozneje, v drugi obliki.

**Zakaj:** stari klepet je klical OpenAI pri vsakem sporočilu, strošek pa je
sorazmeren s številom obiskovalcev, ne z vrednostjo pogovora. Dnevni proračun
žetonov (razdelek 6) je bil le zaklep, ne rešitev. Novi klepet mora biti cenejši
po zasnovi: čim več dela lokalno in na strežniku, model samo kjer je nujen.

### Kaj je bilo odstranjeno
`index.html`, `widget.js`, `ai/chat.php`, `ai/speak.php`, `ai/transcribe.php`,
`ai/OpenAIClient.php`. Koren `/asistent/` v `.htaccess` pokaže statično `asistent.html` (opis Tatjane, številka za klic),
`Options -Indexes` izklopi izpis mape.

### Kaj ostane in se ne sme premakniti
`tools/*.php`, `ai/agent-config.php`, `ai/call-guard.php`, `ai/log-conversation.php`,
`ai/system-prompt.txt`, `ai/tool-definitions.json`, `ai/guard.php` (cron), `admin/`,
`cron/`. Vse to rabi telefonski agent. **Ne briši mape `tools/` ali `ai/` v celoti.**

### Past pri deployu
`.cpanel.yml` rsync nima `--delete`, zato **deploy izbrisanih datotek ne izbriše
na strežniku**. Do ročnega brisanja v File Managerju (`public_html/asistent/`)
ostanejo dosegljive stare `chat.php`, `speak.php` in `transcribe.php`, ki
še vedno trošijo OpenAI/Azure ključe iz `config.php`. Zato je brisanje korak 1.

### Načrt dela
1. **Deploy in ročno brisanje na strežniku:** cPanel Update from Remote → Deploy
   HEAD Commit; nato v File Managerju izbriši `index.html`, `widget.js`,
   `ai/chat.php`, `ai/speak.php`, `ai/transcribe.php`, `ai/OpenAIClient.php`.
   Preveri, da vsi vrnejo 404 in da `/asistent/` pokaže `asistent.html`.
2. **Preveri, da telefon dela:** en testni klic; `tests/test-tools.sh` zelen.
3. **Na glavni strani (WordPress) odstrani povezave in vgrajeni `widget.js`**
   (meni, gumbi, morebitna vgradnja `#tatjana-chat`). Brez tega ostane mrtva povezava.
4. **Počisti `config.php` na strežniku:** `CHAT_ALLOWED_ORIGINS` in nastavitve
   govora za splet ne rabijo več nič. Telefonski agent ima svoje ključe v
   `voice-agent/.env`; `OPENAI_API_KEY` v `config.php` pusti, če ga rabi kaj drugega.
5. **Zasnova novega klepeta (ni začeta):**
   - najprej odgovori brez modela: iskanje po `ai_knowledge` in katalogu (že
     obstaja v `DirectMySQLAdapter::searchKnowledge`);
   - model samo za vprašanja, ki jih iskanje ne reši, in samo majhen model;
   - kratke zgodovine, trd strop žetonov na pogovor in na dan (`Budget`);
   - ista baza znanja kot telefon, da sta odgovora enaka;
   - povpraševanje prek obstoječega `submit_inquiry`.
   Izbira med lokalnim modelom in iskanjem brez modela pride, ko bo znano, koliko
   obiskovalcev dejansko sprašuje.
6. **Posodobi `PROMPT-ZA-SODELAVCA.md`:** še vedno opisuje `index.html`, `widget.js`,
   `chat.php`, `speak.php`.
7. Razdelek 10 (strošek): vrstica "Klepet in glas na strani" ne velja več.
