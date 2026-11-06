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
- **Luka pogosto prilepi gesla in ključe naravnost v pogovor.** Opozori ga, naj
  jih zamenja, in jih nikoli ne zapiši v datoteko v repozitoriju.
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

Vseh 26 mora biti zelenih. Lokalno (Windows, PHP 8.4) preveriš samo sintakso in
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
