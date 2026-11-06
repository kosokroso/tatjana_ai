# Prompt za sodelavca

Prilepi vse od vrstice `---` naprej v svoj Claude Code (ali drug agent), odprt v
korenu tega repozitorija.

Ta datoteka je namenoma kratka in kaže na vire resnice, namesto da jih prepisuje.
Prejšnja različica je bila sploščena kopija `PROJEKT.md` in se je razšla: navajala
je `gpt-4o-mini`, spletni klepet kot živ in telefon kot nedelujoč, čeprav nič od
tega ni bilo več res. Če hočeš povzetek brez repozitorija (za agenta, ki datotek
ne vidi), prilepi `POVZETEK-ZA-AI.md`.

---

Delaš na projektu **Tatjana** — AI asistentka, ki sprejema telefonske klice za
slovensko malo podjetje. Pogovarja se v slovenščini, odgovarja na vprašanja o
ponudbi, cenah in rokih, zbira povpraševanja, dogovarja termine in po potrebi
preveže na človeka. Spletni klepet in glasovni asistent na strani sta ukinjena
(17. 10. 2026); ostal je telefon.

Luka (lastnik) to prodaja slovenskim malim podjetjem kot storitev z mesečnim
vzdrževanjem. Prva stranka je njegova agencija Kreativni Splet. **To ni projekt
za eno podjetje.** Vsaka odločitev mora prestati vprašanje: *"Kaj je treba
spremeniti, da to jutri teče za mizarja, avtoservis ali računovodkinjo?"*
Pravilen odgovor je skoraj vedno nekaj vrstic v `config.php` in vsebina baze,
nikoli koda. Vzdrževanje opravlja Luka, ne stranke — ne gradi funkcij za
samopostrežno urejanje, gradi tako, da je uvedba nove stranke hitra.

## Če kode še nimaš

```bash
git clone https://github.com/kosokroso/tatjana_ai
```

Repozitorij je javen, žetona ne potrebuješ. Če si dobil zip, je v njem isto
stanje brez zgodovine.

Trije deli niso in ne bodo v gitu. Brez njih se ne zažene nič:

| Kaj manjka | Kaj vsebuje | Od kod |
|---|---|---|
| `config.php` | baza, OpenAI, Azure, SMTP, `TOOL_SECRET`, ime podjetja | predloga je `config.example.php`; prave vrednosti ima Luka |
| `voice-agent/.env` | ključi agenta, model, prepis, glas, meje | predloga je `voice-agent/.env.example` |
| `voice-agent/livekit.toml` | veže mapo na agenta v oblaku | naredi ga `lk agent config --id <ID agenta>` |

Lokalno ni ne MySQL ne projekta LiveKit. Lokalno torej poženeš samo preverbo
sintakse (`php -l <datoteka>`) in enotska testa agenta
(`cd voice-agent; python test_varovalke.py; python test_eposta.py`). Vse ostalo
teče proti živi bazi in živemu agentu, za kar rabiš dostope od Luke.

**Ne poganjaj `lk agent deploy`, dokler se z Luko ne dogovoriš.** V oblaku sta
dve živi telefonski številki; deploy ju zamenja takoj in brez vprašanja.

## Kaj Tatjana ni

- **Ni spletni klepet.** Ukinjen 17. 10. 2026. Predlog, ki se opira na
  `ai/chat.php` ali `widget.js`, se opira na kodo, ki je ni.
- **Ni izdelek za samopostrežno urejanje.** Vzdrževanje opravlja Luka. Funkcije,
  ki jih mora znati končna stranka sama, niso cilj.
- **Ni večnajemniška.** Ena namestitev je ena stranka. Načrt za eno namestitev z
  več strankami je `PROJEKT.md`, razdelek 9.3.
- **Ne pripravlja ponudb in ne potrjuje cen.** Namerno, ker je cena odvisna od
  obsega.
- **Ne teče na ogrodju in brez Composerja.** Shared hosting brez SSH do
  upravitelja odvisnosti.
- **Ne ugiba.** Brez vira v bazi pove, da bo odgovor potrdil sodelavec.

## Kako predlagaš svojo idejo

1. **Prestati mora eno vprašanje:** deluje to tudi za mizarja in avtoservis, ali
   samo za Kreativni Splet? Če velja samo za eno stranko, gre v `config.php` ali
   v bazo, ne v kodo.
2. **Poglej, ali je že na seznamu.** `PROJEKT.md`, razdelek 9, ima nadgradnje z
   razlogi in oceno dela; `TODO.md` ima odprta opravila. Marsikatera ideja je
   tam skupaj s pojasnilom, zakaj je odložena — to pojasnilo je hitrejše od
   ponovnega razmišljanja.
3. **Spremembo vedenja poskusi najprej v `ai/system-prompt.txt`.** Če mora
   veljati zanesljivo, spada v kodo (četrto načelo zgoraj).
4. **Pred in po spremembi poženi oba nabora scenarijev** in primerjaj:

```bash
lk agent simulate --scenarios scenarios.yaml --agent-name tatjana --concurrency 2
```

```bash
lk agent simulate --scenarios scenarios-napadi.yaml --agent-name tatjana --concurrency 2
```

5. **Idejo zapiši v `TODO.md` z razlogom**, ne samo kodo. Luka mora čez pol leta
   razumeti, zakaj je nekaj tam.

## Preberi, preden karkoli spremeniš

V tem vrstnem redu:

1. **`POVZETEK-ZA-AI.md`** — kaj sistem je in kaj zna, na eni strani.
2. **`PROJEKT.md`** — vir resnice. Stanje, arhitektura, zemljevid datotek, baza,
   zaščita, deploy, strošek, odločitve, ki jih ne razveljavljaš, česa ne
   poskušaj znova, pasti, ki so nas že ujele. Vedno novejši od tega prompta.
3. **`TODO.md`** — kaj je odprto. Kljukice so tu, razlogi v `PROJEKT.md`.
4. **`ai/system-prompt.txt`** in **`ai/tool-definitions.json`** — tam živi
   vedenje asistenta. Večina sprememb vedenja spada tja, ne v PHP.
5. **`tools/core/AdapterInterface.php`** — če tvoja funkcija potrebuje nov
   podatek, se najprej odloči, ali gre v vmesnik.
6. Po potrebi: `docs/telefon.md` (DIDWW, LiveKit, ID-ji trunka),
   `docs/setup.md` (uvedba nove stranke), `docs/tools-api.md` (endpointi).

## Štiri načela, ki jih ne kršimo

1. **Adapter je edina pot do podatkov.** Orodje nikoli ne odpre PDO povezave in
   nikoli ne sestavi SQL-a. Preslikava tujih stolpcev v naše ključe spada v
   adapter. Ko bo stranka imela ERP (Vasco je prvi kandidat), se napiše nov
   adapter in orodja ostanejo nedotaknjena.
2. **Asistent nima vgrajene identitete.** V `ai/system-prompt.txt` ni imena
   podjetja; nadomestki `{ASSISTANT_NAME}`, `{BUSINESS_NAME}`,
   `{BUSINESS_DESCRIPTION}`, `{BUSINESS_PHONE}`, `{BUSINESS_EMAIL}` se zamenjajo
   ob nalaganju. Če v prompt napišeš "Kreativni Splet", si projekt pokvaril.
3. **Poslovna logika obstaja enkrat.** Orodja so HTTP endpointi s skupno
   skrivnostjo `TOOL_SECRET`. Telefonski agent je v Pythonu in teče drugje
   (LiveKit Cloud), a kliče iste endpointe. Ne prepisuj logike v Python.
4. **Kar mora veljati zanesljivo, ne sme biti odvisno od modela.** Varovala so v
   kodi, ne v promptu. Seznam je v `PROJEKT.md`, razdelek 8 — vsako je bilo
   najprej v promptu in je vsaj enkrat odpovedalo.

## Skrivnosti — brez izjeme

**Repozitorij je javen.** Kar enkrat commitaš, je javno in ostane v zgodovini,
tudi če datoteko pozneje izbrišeš. Čiščenje zahteva prepis zgodovine
(`git filter-repo` + `push --force`), kar podre cPanel deploy, ki iz tega
repozitorija vleče kodo. Zato je edina zanesljiva pot, da skrivnost tja nikoli
ne pride.

- **Nikoli ne commitaj** `config.php`, `voice-agent/.env`, `voice-agent/.env.v2`
  ali `voice-agent/livekit*.toml`. Vse so v `.gitignore` — ne obidi ga z
  `git add -f` in ne odstranjuj vrstic iz `.gitignore`.
- **Pred vsakim commitom preglej seznam datotek** (`git status`). Če je med njimi
  datoteka, ki je prej ni bilo, preveri, kaj je v njej.
- **Prave vrednosti ne gredo v nobeno datoteko repozitorija** — ne v kodo, ne v
  test, ne v scenarij, ne v dokumentacijo, ne v komentar in ne v sporočilo
  commita. Nova konstanta gre v `config.example.php` z nadomestno vrednostjo,
  nov ključ v `voice-agent/.env.example` z praznim mestom.
- **Ključa ne izpisuj** v odgovor v pogovoru, v `echo`, v `var_dump` ali v
  dnevnik. `Logger` osebne podatke maskira; ključev naj ne dobi v roke.
- **Ključa ne pošiljaj tretji storitvi** — ne v pastebin, ne v drugo orodje AI,
  ne v CI. Tudi zip za Drive je tretja storitev: vanj gredo samo sledene
  datoteke (`git ls-files`), nikoli `config.php` ali `.env`.
- **Če ključ vseeno uide, ga zamenjaj.** Brisanje commita ne pomaga: zgodovina
  je razširjena po klonih in predpomnilnikih. Zamenjava je edini popravek.
- **Luka pogosto prilepi gesla in ključe naravnost v pogovor.** Uporabi jih za
  nalogo, opozori ga, naj jih zamenja, in jih ne zapiši nikamor.
- Nastavitve na strežnik ne gredo prek gita: `config.php` je iz deploya izvzet
  in se naloži ročno prek File Managerja, `voice-agent/.env` pa prek
  `lk agent update-secrets`.

## Način dela, ki ga Luka pričakuje

- **Vse v slovenščini:** pogovor, koda, komentarji, sporočila commitov,
  dokumentacija.
- **Brez marketinškega tona.** Nobenega "odlično!", nobenih superlativov. Povej,
  kaj si naredil in kaj ne deluje.
- **Razloži zakaj, ne samo kaj.** Pri neočitni odločitvi napiši razlog. Luka
  kodo bere in jo mora čez pol leta razumeti.
- **Ne ugibaj o živem sistemu.** Če ne veš, ali nekaj na strežniku deluje,
  vprašaj ali preveri. Ne trdi, da je popravljeno, dokler ni preverjeno.
- **Glasovna stikala vklapljaj po eno** in vsako preizkusi s klicem. Devet
  hkratnih sprememb glasovne poti je klic poslabšalo in krivca ni bilo mogoče
  najti; pot je bilo treba vrniti na `b777fc8` in graditi znova.
- **Commit in push opravi Luka sam.** Pripravi spremembe in izpiši ukaze.
  Sporočila commitov končaj z
  `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Brez emojijev, razen če jih zahteva.
- **Ob vsaki večji spremembi posodobi `PROJEKT.md` in `TODO.md`.** Delitev je
  namerna: `PROJEKT.md` pove, kaj sistem je in **zakaj** je nekaj vredno dela;
  `TODO.md` ima kljukice. Iste točke ne piši na obe mesti — dva seznama opravil
  se razideta in nobenemu ne zaupaš več.

## Dvoje, kar te ujame prvi dan

**Deploy je dvojen in `git push` ne zažene nobenega dela.**

| Kaj se je spremenilo | Kje teče | Kako pride v uporabo |
|---|---|---|
| `ai/`, `tools/`, `admin/`, prompt | cPanel | Git Version Control → **Update from Remote**, nato **Deploy HEAD Commit** |
| `voice-agent/agent.py` | LiveKit Cloud | `lk agent deploy` |
| `voice-agent/.env` | LiveKit Cloud | `lk agent update-secrets --secrets-file .env --overwrite` |

Najprej cPanel, nato agent — agent ob zagonu prenese prompt in kliče
`call-guard.php`. Sam Deploy brez Update naloži staro kodo; to naju je zavedlo
dvakrat. Druga številka (Tatjana V2) ima svoje datoteke `.env.v2` in
`livekit-v2.toml`, postopek je v `PROJEKT.md`, razdelek 7.

**Testi tečejo proti živemu naslovu, ker lokalno ni MySQL.**

```bash
BASE_URL=https://kreativnisplet.si/asistent bash tests/test-tools.sh
```

Vseh 27 mora biti zelenih. Lokalno (Windows, PHP 8.4) preveriš samo sintakso in
unit logiko:

```powershell
php -l tools/core/Mailer.php
```

Datoteke nikoli ne shrani kot UTF-8 **z BOM** — Notepad in
`Set-Content -Encoding utf8` to naredita privzeto, `config.php` z BOM pa pomeni,
da vsi odgovori vrnejo 200 namesto prave kode. Celoten seznam pasti je v
`PROJEKT.md`, razdelek 13.

## Ko končaš spremembo

Poženi teste, posodobi `PROJEKT.md` in `TODO.md`, pripravi sporočilo commita v
slovenščini in ga izpiši Luki.
