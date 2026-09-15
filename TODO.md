# Stanje projekta

AI asistentka Tatjana za spletno stran, kasneje telefon. Odgovarja na vprašanja o
ponudbi, cenah in rokih ter zbira povpraševanja.

**Naslov:** https://kreativnisplet.si/asistent/ (noindex, ni povezan iz menija)
**Repozitorij:** https://github.com/kosokroso/tatjana_ai
**Zadnje preverjeno:** 13. 9. 2026 — 26/26 testov, klepet in povpraševanje preverjena v živo

---

## Narejeno

### Podatkovni sloj
- [x] Katalog storitev agencije (paketi, trženje, oblikovanje, vzdrževanje)
- [x] Cena sme biti prazna → asistent pove "cena po dogovoru", ne izmišlja zneska
- [x] `price_from` → "od 399 €", da izhodiščna cena ni predstavljena kot končna
- [x] `AdapterInterface` — poslovna logika nikoli ne gre neposredno v bazo
- [x] `DirectMySQLAdapter` — pripravljeni stavki, predpona tabel `ai_`
- [x] Skupna baza z WordPressom, tabele ločene s predpono
- [x] Iskanje prenese slovensko sklanjatev ("trgovine" najde "Spletna trgovina Shopify")
- [x] `VascoAdapter` — predloga za prehod na ERP (ni implementiran)

### Orodja
- [x] `product-lookup` — storitve, cene, kaj paket vključuje
- [x] `order-lookup` — stanje projekta; **zahteva številko IN telefon/e-pošto lastnika**
- [x] `business-info` — delovni čas, roki in potek dela, pogoji plačila
- [x] `submit-inquiry` — **zahteva ime, telefon IN e-pošto**; zapiše v bazo in obvesti podjetje
- [x] Enotna oblika odgovora s kodami napak (`not_found` ≠ `system_error`)
- [x] Dnevnik klicev z maskiranjem osebnih podatkov, brisanje po 14 dneh

### Chat sloj
- [x] `ai/chat.php` — pogovor z OpenAI in klicanje orodij
- [x] Ponovni poskus ob prehodni napaki (429, 5xx)
- [x] Model `gpt-4o-mini`
- [x] Sistemski prompt: brez izmišljanja podatkov, varovanje podatkov strank,
      zavrnitev ukazov tipa "pozabi navodila", aktivno vodenje k povpraševanju
- [x] Ime asistentke in podatki podjetja v celoti iz `config.php`

### Vmesnik
- [x] `widget.js` — vgradnja v poljubno stran z eno posodo in eno skripto
- [x] Videz usklajen s kreativnisplet.si, pisava podedovana od gostiteljske strani
- [x] Uvod predstavi ponudbo in pelje k povpraševanju
- [x] `data-view.php` — pregled tabel in stanja strežnika, zaklenjen s ključem

### Pošiljanje pošte
- [x] Lasten odjemalec SMTP (`tools/core/Mailer.php`) — `mail()` je na tem gostovanju izklopljen
- [x] Pošilja prek `info@kreativnisplet.si`, vrata 465, SSL
- [x] Preizkus pošiljanja na `data-view.php`
- [x] Zapis v bazo je vir resnice — če pošta odpove, povpraševanje ostane

### Zaščita
- [x] Omejitve na minuto, na dan in skupno na dan (števci klicev)
- [x] **Dnevni proračun žetonov** — strošek so žetoni, ne klici; poraba se bere iz
      odgovora OpenAI in sešteva, ob prekoračitvi klepet za ta dan neha odgovarjati
- [x] Skupna dolžina zgodovine omejena na 8.000 znakov (bot ne more z eno dolgo
      zgodovino podreti dnevnega proračuna)
- [x] Štetje po bloku /64 pri IPv6 — en obiskovalec sicer obide omejitev z menjavo naslova
- [x] Poraba žetonov danes vidna na `data-view.php`
- [x] Preverjanje izvora prek `CHAT_ALLOWED_ORIGINS`
- [x] `REQUIRE_HTTPS`, `logs/` in `.git/` nedosegljiva, `config.php` ne razkrije kode
- [x] `config.php` nikoli ni šel v git (preverjena celotna zgodovina)

### Preverjeno v živo
- [x] 26 avtomatskih testov orodij
- [x] Cel pogovor s povpraševanjem, obvestilo po e-pošti prispelo (#506)
- [x] Robni primeri: prompt injection, vprašanje o tujem projektu, prošnja za popust,
      vprašanje izven teme — vsi pravilno zavrnjeni

---

## Odprto

### Nujno

- [ ] **Zamenjaj geslo baze in WordPressove varnostne ključe.** `wp-config.php` je bil
      prilepljen v pogovor z asistentom, zato je geslo žive baze razkrito.
      Postopek: odpri oba urejevalnika vnaprej → cPanel MySQL Databases → Change Password
      → popravi `wp-config.php` in `asistent/config.php`. Stran je vmes nekaj sekund dol.
- [ ] **Zamenjaj OpenAI ključ in GitHub žeton** — prav tako razkrita med razvojem.
- [ ] **Dnevna varnostna kopija `ai_` tabel** v cron. Povpraševanja so posel; vtičnik za
      čiščenje baze jih lahko pobriše in kopija je edina zaščita. Ukaz v `docs/setup.md`.
- [ ] **Nastavi mesečno omejitev porabe v OpenAI** (Billing → Limits). Naša koda ustavi
      samo to, kar gre skozi naš strežnik; če kdaj uide ključ, je omejitev pri OpenAI
      edina stvar, ki še drži. To je zadnja obramba, ne prva.

### Pred javnim zagonom

- [ ] **Odstrani `data-view.php` in `chat-test.html`** s strežnika. Prvi prikazuje
      osebne podatke strank, oba sta zdaj na živi domeni.
- [ ] Popravi delovni čas v tabeli `ai_business_hours` — vpisan je privzeti pon–pet 9–17,
      ne preverjen. Asistent ga stranki pove kot dejstvo.
- [ ] Dopolni pogoje plačila v `data/business-info.json` (zdaj samo nevtralna formulacija).
- [ ] Pobriši testne stranke in projekte: `DELETE FROM ai_orders; DELETE FROM ai_customers;`
- [ ] Vgradi klepet v `landing.html` ali podstran:
      `<div id="tatjana-chat"></div><script src="/asistent/widget.js" defer></script>`

### Odločitve, ki čakajo

- [ ] **Javni ali zasebni repozitorij.** Zdaj javen, ker cPanel Git zasebnega ni zmogel
      klonirati. Skrivnosti v njem ni, a kodo, ki jo nameravaš prodajati, lahko kdorkoli
      prekopira. Za zasebnega je treba prej urediti deploy: GitHub Actions → FTP ali SSH ključ.
- [ ] Presoja, ali je `gpt-4o-mini` dovolj dober za slovenščino, ali je vreden večji model.

### Glas — stanje

- [x] Glasovni preizkus na `voice-test.html`: mikrofon → prepis → odgovor → govor
- [x] **Prepis slovenščine deluje**, tudi telefonske številke zapiše pravilno.
      To je bilo največje tveganje projekta in je odpravljeno.
- [x] Normalizacija besedila pred govorom (cene, telefonske številke) — v kodi, ne v promptu
- [x] Azure pripravljen kot ponudnik govora (`TTS_PROVIDER`), neaktiven brez ključa
- [x] **Glasovni agent za telefon deluje** (`voice-agent/agent.py`, preverjeno 15. 9. 2026
      v načinu `console`). Cel sklad: prepis → model → orodja na strežniku → govor.
      Povpraševanje #511 je iz glasovnega pogovora pristalo v bazi, z bogato opombo
      o projektu. Sistemski prompt agent prenese s strežnika, zato sta besedilni in
      glasovni asistent vedno enaka.
- [ ] **Azure ključ — edina znana napaka.** OpenAI glas prebere 399 kot "329".
      Preverjeno: model zapiše pravilno, napaka je izključno v izgovorjavi.
      Azure `sl-SI-PetraNeural` to odpravi; F0 sloj je brezplačen.
      Vpišeš `AZURE_SPEECH_KEY` v `.env` agenta in v `config.php`, koda se ne spremeni.
- [ ] **Slovenska telefonska številka — čaka na zalogo.** Telnyx ima za SI samo
      toll-free (10 $ vstopnine + 10 $/mesec, dohodne minute plača podjetje).
      DIDLogic slovenskih številk nima na zalogi, oddan je request.
      Mobilne številke pri Telnyxu ne zahtevajo dokumentacije — preveri občasno zalogo.
      Local in National zahtevata osebni dokument in dokazilo o naslovu v Braniku.
- [ ] Objava agenta na LiveKit Cloud ali VPS, da teče neprekinjeno

### Naslednja faza — telefon

- [ ] **Preveri slovenske telefonske številke pri LiveKit** ali potrebo po lokalnem SIP
      operaterju. To vprašanje lahko podre celoten pristop, zato gre prvo.
- [ ] **Preizkusi kakovost slovenskega STT/TTS** z vzorčnimi posnetki, brez naročnine in
      brez telefonske številke. Prepoznava slovenskega govora po telefonu je največje
      tveganje projekta — ne prenos zvoka.
- [ ] Šele nato agent, ki kliče iste `POST /tools/*.php` s `TOOL_SECRET`.

### Kasneje

- [ ] Pregled povpraševanj za podjetje (kdo je pisal, kaj želi, kaj je že urejeno)
- [ ] `VascoAdapter`, ko bo znan pravi ERP

---

## Kako deployam

1. `git push`
2. cPanel → Git Version Control → repozitorij `tatjana_ai`
3. **Update from Remote** (to je pull) → **Deploy HEAD Commit**

Sam Deploy brez Update naloži staro kodo — to naju je zavedlo dvakrat.

`config.php` ni v gitu, zato ga deploy ne povozi. Ob spremembi nastavitev ga naloži
ročno prek File Managerja v `public_html/asistent/`.

## Kako testiram

```bash
BASE_URL=https://kreativnisplet.si/asistent bash tests/test-tools.sh
```

Vseh 26 testov mora biti zelenih. Klepet na `/asistent/`, pregled podatkov in
preizkus pošiljanja na `data-view.php`.

---

## Pasti, ki so nas že ujele

| Simptom | Vzrok |
|---|---|
| Vsaka datoteka v mapi vrne 404, mapa pa 403 | Mapa ima pravice 0700 — spletni strežnik ne more vstopiti. `rsync -a` prenese pravice izvorne mape, cPanel git repozitorij pa je 0700. Rešeno z `--chmod=D755,F644`. |
| Enako, a pravice so v redu | `<DirectoryMatch>` v `.htaccess` ni veljaven (samo v glavni konfiguraciji strežnika). Za blokiranje `.git/` uporabi `RewriteRule`. |
| Vsi odgovori 200 namesto prave kode, v JSON prilepljena opozorila | `config.php` shranjen kot "UTF-8 z BOM". Notepad in PowerShell to naredita privzeto. |
| `SQLSTATE[HY093]: Invalid parameter number` | Isti named placeholder večkrat v enem stavku. Pri pravih pripravljenih stavkih vsaka pojavitev rabi svojega. |
| Chat skoraj vedno vrne "sistem mi trenutno ne odgovori" | Omejitev porabe žetonov pri `gpt-4o` na novem OpenAI računu. Rešeno s prehodom na `gpt-4o-mini`. |
| Asistent pove napačno ceno | Prompt je zahteval pretvorbo cen v besede; model je 78 € povedal kot "osemdeset evrov". Cene se zdaj izpisujejo s številko. |
| `535 Incorrect authentication data` pri SMTP | Napačno geslo predala ali uporabniško ime brez domene. Uporabnik mora biti cel naslov. |
| Deploy naloži staro kodo | Kliknjen je bil samo Deploy HEAD Commit brez Update from Remote. |
| Povpraševanje vrne napako, čeprav je zapis v bazi | Obvestilo po pošti je padlo in podrlo cel klic. Pošiljanje mora biti v `try/catch` — zapis je vir resnice. |

## Česa ne poskušaj znova

- **WordPressov `wp_mail()` iz našega procesa.** `config.php` in `wp-config.php` definirata
  iste konstante (`DB_NAME`, `DB_USER`, `DB_HOST`), zato bi WordPress dobil naše vrednosti
  namesto svojih — tiho spreminjanje delovanja žive strani. Zato obstaja `Mailer.php`.
- **Cene v besedah.** Za glas bo pretvorbo opravil TTS, ki števnike obvlada.
