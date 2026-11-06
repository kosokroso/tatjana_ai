# Tatjana — povzetek za AI agenta

Prilepi vse od vrstice `---` naprej v katerikoli AI agent. Pove, kaj Tatjana je,
kaj dela in kje tečejo njeni deli. Podrobnosti so v `PROJEKT.md` (razlogi in
stanje) in `TODO.md` (kljukice).

Stanje na 6. 11. 2026.

---

## Kaj je Tatjana

Tatjana je AI asistentka, ki **sprejema telefonske klice** za slovensko malo
podjetje. Klicatelj jo pokliče kot vsako drugo številko; ona se javi, se
pogovarja v slovenščini, odgovarja na vprašanja o ponudbi, cenah in rokih, zbere
povpraševanje ali dogovori termin in po potrebi prevežè na človeka.

**Spletni klepet in glasovni asistent na strani sta ukinjena (17. 10. 2026).**
Ostal je samo telefon. Načrt za nov klepet je v `PROJEKT.md`, razdelek 16.

**Poslovni namen:** Luka Kosovel to prodaja slovenskim malim podjetjem kot
storitev z mesečnim vzdrževanjem. Prva stranka je njegova lastna agencija
Kreativni Splet. Zato sistem ni grajen za eno podjetje: ime asistentke, ime
podjetja, panoga, katalog storitev, delovni čas in kontakti so v konfiguraciji
in bazi, nikoli v kodi. Merilo vsake odločitve: *"Kaj je treba spremeniti, da to
jutri teče za mizarja, avtoservis ali računovodkinjo?"* Pravilen odgovor je
skoraj vedno nekaj vrstic v `config.php` in vsebina baze.

Vzdrževanje opravlja Luka, ne stranke. Funkcije za samopostrežno urejanje niso
cilj; hitra uvedba nove stranke je.

## Kaj zna

| Zmožnost | Kako |
|---|---|
| Vprašanja o ponudbi | pokliče orodje, ki prebere katalog iz baze; cen si nikoli ne izmisli. Ni cene → "cena po dogovoru". Izhodiščna → "od 399 €". `active = 0` → storitve ne omeni |
| Podatki o poslovanju | delovni čas z izračunom, ali je odprto zdaj; roki, potek dela, pogoji plačila |
| Stanje projekta obstoječe stranke | zahteva dva ujemajoča se podatka (številka projekta IN telefon ali e-pošta); ob neujemanju enak odgovor kot pri neobstoječem projektu |
| Povpraševanje | zbere ime, telefon in e-pošto, povzame, počaka na potrditev, šele nato odda; podrobnosti v polje `note` |
| Splošna vprašanja | iz baze znanja, ki jo podjetje ureja v `admin/znanje.php`; brez odgovora ne ugiba, ampak napove sodelavca |
| Termin | prosti termini se izpeljejo iz delovnega časa in zasedenih vrstic; zasedenost se preveri še enkrat tik pred zapisom |
| Preveza na človeka | ob izrecni želji, pritožbi ali dveh zaporednih neznanih odgovorih, samo znotraj delovnega časa |
| Telefonske posebnosti | prebere številko klicatelja iz SIP povezave (`sip.phoneNumber`), da je ni treba narekovati; sama odloži slušalko (orodje `koncaj_pogovor`) |

**Ponudbe ne pripravi in cene ne potrdi.** Namerno — cena je odvisna od obsega.

## Kako je zgrajeno

```
Klicatelj (telefon, DIDWW)
        │
        └─ LiveKit Cloud agent (voice-agent/agent.py, Python)
                │   HTTP + skupna skrivnost TOOL_SECRET
                ▼
        ai/agent-config.php (prompt), ai/call-guard.php (meje klicev)
        ToolRegistry  →  AdapterInterface  →  MySQL (tabele ai_*)
                             ├─ DirectMySQLAdapter (v uporabi)
                             └─ VascoAdapter (predloga za ERP)
```

| | |
|---|---|
| Sklad strežnika | PHP 8, vanilla, brez Composerja in brez ogrodja (shared cPanel hosting) |
| Gostovanje | cPanel, `public_html/asistent/`, `https://kreativnisplet.si/asistent/` |
| Baza | MySQL, skupna z WordPressom, predpona tabel `ai_` |
| Telefonski agent | Python, LiveKit Cloud, regija `eu-central` |
| Repozitorij | `https://github.com/kosokroso/tatjana_ai` (javen) |
| Model | `gpt-5.6-sol` |
| Prepis | `gpt-transcribe` |
| Številka 1 (Tatjana) | `+386 5 7774124`, glas Azure `sl-SI-PetraNeural` |
| Številka 2 (Tatjana V2 / Maja) | `+386 5 7774102`, glas ElevenLabs Bella, `eleven_v3_conversational` |

Obe številki tečeta na isti kodi in isti bazi; razlikujeta se samo po
nastavitvah (`voice-agent/.env` in `.env.v2`). Druga številka obstaja zato, da
se na sestanku zaigrata dva glasova.

### Tri načela, ki jih ne kršimo

1. **Adapter je edina pot do podatkov.** Orodje nikoli ne odpre PDO povezave in
   nikoli ne sestavi SQL-a. Preslikava tujih stolpcev v naše ključe spada v
   adapter.
2. **Asistent nima vgrajene identitete.** V `ai/system-prompt.txt` ni imena
   podjetja; ob nalaganju se zamenjajo nadomestki `{ASSISTANT_NAME}`,
   `{BUSINESS_NAME}`, `{BUSINESS_DESCRIPTION}`, `{BUSINESS_PHONE}`,
   `{BUSINESS_EMAIL}`.
3. **Poslovna logika obstaja enkrat.** Orodja so HTTP endpointi; telefonski
   agent v Pythonu kliče iste endpointe. Logike se ne prepisuje v Python.

### Četrto načelo, plačano z izkušnjo

**Kar mora veljati zanesljivo, ne sme biti odvisno od modela.** Varovala (nujni
primer ne gre skozi prevezo, povpraševanje zahteva potrditev, `verify` mora biti
kontakt, štetje ponovitev, meje klicev) so v kodi, ne v promptu. Vsako je bilo
najprej v promptu in je vsaj enkrat odpovedalo.

## Omejitve okolja

- Shared hosting brez SSH do upravitelja odvisnosti → brez Composerja, brez
  ogrodja, vse teče iz prekopiranih datotek.
- Deploy je dvojen in `git push` ne zažene nobenega dela:
  `ai/`, `tools/`, `admin/`, prompt → cPanel Git (**Update from Remote**, nato
  **Deploy HEAD Commit**; sam Deploy naloži staro kodo);
  `voice-agent/agent.py` → `lk agent deploy`; `.env` → `lk agent update-secrets`.
  Vedno najprej cPanel, nato agent.
- `config.php` ni in ne sme biti v gitu; deploy ga ne povozi.
- Lokalno (Windows, PHP 8.4) ni MySQL — sintaksa in unit logika se dajo
  preveriti, poizvedbe ne.
- Datoteke, shranjene kot UTF-8 **z BOM**, tiho podrejo delovanje (vsi odgovori
  200 namesto prave kode). Notepad in `Set-Content -Encoding utf8` to naredita
  privzeto.

## Zaščita in strošek

Omejitve na IP (20/min, 100/dan), skupaj 500 klicev/dan, dnevni proračun žetonov
200.000 — štetje klicev denarnice ne varuje, ker en klic z dolgo zgodovino stane
toliko kot deset kratkih. Meje telefonskih klicev (trajanje, dnevne minute,
klici na klicatelja) drži `ai/call-guard.php`, da števci preživijo ponovni zagon
replike. Dnevniki maskirajo osebne podatke, hranijo se 14 dni. Zaščita je bila
preizkušena s 14 vrstami napada (razkritje prompta, prevzem vloge, navodilo
skrito v podatku, vrivanje SQL, izsiljena obljuba popusta, večkorakni napad) —
vsi zavrnjeni.

Strošek triminutnega klica je približno 0,09 €; pri 100 klicih na mesec približno
14 € skupaj (DIDWW minute in najem številke, prepis, model; Azure F0 in LiveKit
do 1.000 minut sta brezplačna). Ob klicu tečejo štirje števci hkrati: LiveKit,
DIDWW, OpenAI in Azure.

## Način dela, ki ga Luka pričakuje

- Vse v slovenščini: pogovor, koda, komentarji, sporočila commitov, dokumentacija.
- Brez marketinškega tona in superlativov. Povej, kaj si naredil in kaj ne deluje.
- Razloži **zakaj**, ne samo kaj. Kodo bere in jo mora čez pol leta razumeti.
- Ne ugibaj o živem sistemu. Ne trdi, da je popravljeno, dokler ni preverjeno.
- Glasovna stikala vklapljaj **po eno** in vsako preizkusi s klicem. Devet
  hkratnih sprememb je klic poslabšalo in krivca ni bilo mogoče najti.
- Ob večji spremembi posodobi `PROJEKT.md` (razlogi) **in** `TODO.md`
  (kljukice). Iste točke ne piši na obe mesti.
- Luka pogosto prilepi gesla in ključe naravnost v pogovor. Opozori ga, naj jih
  zamenja, in jih nikoli ne zapiši v datoteko v repozitoriju.
- Commit in push opravi Luka sam. Pripravi spremembe in izpiši ukaze.
