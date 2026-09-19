# Tatjana — AI asistent za spletne strani in telefon

Popoln pregled projekta. Ta datoteka je vir resnice o tem, kaj sistem je, kaj
zna, kako je zgrajen in kje smo. **Posodobi jo ob vsaki večji spremembi.**

Zadnja posodobitev: 19. 9. 2026 (zvecer)

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
| Model | `gpt-5.4-mini` (telefon), `OPENAI_MODEL` iz `config.php` (splet) |
| Glas | Azure `sl-SI-PetraNeural`, regija `italynorth` |
| Telefon | `+386 5 7774124` (DIDWW, Nova Gorica) — **deluje** |
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

### Govori in posluša
Na spletni strani: mikrofon → prepis → odgovor → govor.

Po telefonu enako, prek ločenega agenta, ki teče v LiveKit Cloud. Ta zna dvoje,
česar klepet ne:

- **Prebere številko, s katere kličejo**, iz same povezave (`sip.phoneNumber`),
  zato je stranki ni treba narekovati. Narekovanje po zvoku je bil najpogostejši
  vir napak — števke se zamenjajo in ponudba odide v prazno.
- **Odloži slušalko**, ko je povpraševanje oddano in stranka nima več vprašanj
  (orodje `koncaj_pogovor`). Brez tega klic obvisi v tišini.

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
| `call-guard.php` | Vratar telefonskih klicev: pove, ali sme klic naprej, in prejme trajanje. Zaščiteno s `TOOL_SECRET`. |
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
| `core/CallLimits.php` | Dnevni števci telefonskih klicev. Številk ne shranjuje — za štetje zadošča zgoščena vrednost. |
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
`ai/chat.php` in prešteje odstopanja.

**Zakaj prvo:** šele s tem se da odgovoriti, ali je večji model vreden denarja,
ali Azure prepis slovenščino res razume slabše, ali je nov prompt boljši. Brez
tega ostaja vse to stvar mnenja.

**Vloženo:** dan dela. `tests/test-tools.sh` je že predloga za obliko.

### 9.2 Predaja človeku

**Težava:** ko asistentka česa ne zna ali je klicatelj nejevoljen, klic konča v
slepi ulici. Za podjetje je to izgubljena stranka, in prav ta klic si bo
zapomnil.

**Kaj:** orodje `predaj_cloveku`, ki klic preveže na pravo številko.
`ctx.transfer_sip_participant(participant, transfer_to, play_dialtone)` je v
SDK že na voljo. Sproži se, ko stranka izrecno zahteva človeka, ko dvakrat
zapored ne dobi odgovora, ali ko gre za pritožbo.

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

### 9.4 Znanje prek kataloga

Zdaj zna odgovoriti samo iz `ai_products` in `business-info.json`. Vprašanja
tipa "ali delate tudi za društva", "kako poteka prevzem strani" nimajo vira.

Rešitev: tabela `ai_knowledge` z vprašanji in odgovori, ki jih stranka ureja
sama v skrbniški strani, plus orodje za iskanje po njej. Polno indeksiranje
spletne strani je naslednji korak, a preprosta tabela pokrije večino primerov.

### 9.5 Naročanje terminov

Za del slovenskih malih podjetij — frizer, zobozdravnik, servis — je rezervacija
termina glavni razlog za klic, ne povpraševanje. Brez tega tem panogam nimaš kaj
prodati.

Potrebuje tabelo prostih terminov, orodje za rezervacijo in potrditev po e-pošti.
Google Calendar naj pride kasneje; najprej lastna tabela.

### 9.6 Opozorila, ko kaj odpove

Zdaj se za izpad izve šele ob naslednjem ročnem klicu. Potrebno:
obvestilo, ko je dnevni proračun dosežen, ko orodje večkrat zapored odpove, ko
se agent v oblaku ustavi, in ko povpraševanje čaka več kot dva dni.

### 9.7 Snemanje klicev in privolitev

LiveKit klice že snema (`enable_recording: true` v zahtevi za posel). Posnetki so
najboljše gradivo za točko 9.1 — pravi klicatelji, prava slovenščina, pravi šum.

**Pred uporabo je treba klicatelja obvestiti.** Snemanje brez obvestila v EU ni
dopustno. Pozdrav mora povedati, da se klic snema, in zakaj.

### 9.8 SMS potrditev povpraševanja

Po oddaji sporočilo s številko povpraševanja. Stranka ima dokaz, podjetje pa
manj klicev tipa "ali ste kaj dobili". DIDWW to zna; strošek je nekaj centov.

### 9.9 Krajši prompt in predpomnjenje

Sistemski prompt meri okrog 6 KB in gre v vsak obrat. Krajši prompt pomeni nižji
LLM TTFT (zdaj 889 ms) in nižji strošek. OpenAI predpomnjenje vhoda zniža ceno
ponovljenega dela. Smiselno šele po 9.1 — brez merjenja je krajšanje prompta
najhitrejši način, da se asistentka začne vesti slabše.

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

Asistenta preizkusi na `https://kreativnisplet.si/asistent/`. Če pri vprašanju o
ceni **ne pokliče orodja**, je odgovor izmišljen — to je najhujša napaka in jo
`admin/pogovori.php` sam označi.
