# Stanje projekta

AI asistent za spletno stran in kasneje telefon. Besedilni klepet odgovarja na
vprašanja o ponudbi, cenah, naročilih in dostavi ter zbira povpraševanja.

**Živi naslov:** https://kreativnisplet.si/asistent/ (noindex, ni povezan iz menija)
**Repozitorij:** https://github.com/kosokroso/tatjana_ai
**Zadnje preverjeno:** 13. 9. 2026 — 26/26 testov zelenih, klepet in povpraševanje preverjena v živo

---

## Narejeno

### Podatkovni sloj
- [x] Testna baza MySQL: 20 izdelkov, 5 strank, 12 naročil, delovni čas, povpraševanja
- [x] `AdapterInterface` — poslovna logika nikoli ne gre neposredno v bazo
- [x] `DirectMySQLAdapter` — vse poizvedbe prek pripravljenih stavkov
- [x] `VascoAdapter` — predloga za prehod na pravi ERP (še ni implementiran)
- [x] Iskanje, ki prenese slovensko sklanjatev ("peletov" najde "Peleti A1")

### Orodja (tool sloj)
- [x] `product-lookup` — cene, zaloga, ponudba
- [x] `order-lookup` — stanje naročila; **zahteva številko naročila IN telefon/e-pošto lastnika**
- [x] `business-info` — delovni čas, območja dostave, načini plačila
- [x] `submit-inquiry` — zbere povpraševanje, zapiše v bazo, obvesti podjetje po e-pošti
- [x] Enotna oblika odgovora s kodami napak (`not_found` ≠ `system_error`)
- [x] Omejitev števila zahtevkov na IP
- [x] Dnevnik klicev z maskiranjem osebnih podatkov in samodejnim brisanjem po 14 dneh

### Chat sloj
- [x] `ai/chat.php` — pogovor z OpenAI in klicanje orodij
- [x] Ponovni poskus ob prehodni napaki (429, 5xx)
- [x] Sistemski prompt: brez izmišljanja podatkov, varovanje podatkov strank, zavrnitev ukazov tipa "pozabi navodila"
- [x] Ime asistentke in podjetja v celoti iz `config.php` — ista koda gre k naslednji stranki brez posega v kodo
- [x] `chat-test.html` — testna stran, usklajena z videzom spletne strani
- [x] `data-view.php` — pregled vseh tabel za lažje testiranje, zaklenjen s ključem

### Preverjeno v živo
- [x] 24 avtomatskih testov orodij
- [x] Cel pogovor s povpraševanjem od začetka do konca (zapis #503 v bazi)
- [x] Robni primeri: prompt injection, vprašanje o tujem naročilu, dostava izven območja, prošnja za popust, vprašanje izven teme — vsi pravilno zavrnjeni

### Infrastruktura
- [x] Deploy: GitHub → cPanel Git → `.cpanel.yml` (rsync s pravilnimi pravicami)
- [x] `config.php` z gesli nikoli ni šel v git (preverjena celotna zgodovina)
- [x] `logs/` in `.git/` nedosegljiva prek brskalnika, `config.php` ne razkrije kode
- [x] `REQUIRE_HTTPS` vklopljen

---

## Odprto

### Nujno — preden gre stran pred pravo stranko

- [x] **Omejiti strošek zlorabe `ai/chat.php`.** Dodani dnevna omejitev na IP
      (`CHAT_RATE_LIMIT_PER_DAY`, privzeto 100) in skupna dnevna kapica
      (`CHAT_MAX_PER_DAY_TOTAL`, privzeto 500). Skupna kapica je trda zgornja meja
      dnevnega stroška ne glede na to, od kod klici prihajajo. Številki prilagodi
      pričakovanemu prometu.
- [x] **Preverjanje izvora.** Ko je `CHAT_ALLOWED_ORIGINS` prazen, preverjanja ni
      (razvojni način). Ko vpišeš domeno stranke, vsi drugi izvori dobijo 403.
- [ ] **Vpisati domeno stranke v `CHAT_ALLOWED_ORIGINS`**, ko bo klepet vgrajen v
      njihovo stran. Dokler je polje prazno, lahko endpoint kliče kdorkoli.
      Preverjanje izvora ni nepremagljivo (glavo `Origin` je s curl mogoče nastaviti) —
      trdo mejo stroška postavljata dnevni kapici, ne to.
- [ ] **Zamenjati razkrite skrivnosti.** OpenAI ključ, geslo baze in GitHub žeton so
      bili med razvojem prilepljeni v pogovor. Ustvari nove in stare prekliči.
- [ ] **Odstraniti `data-view.php` in `chat-test.html`** s strežnika ali ju zaščititi.
      `data-view.php` prikazuje imena, telefone in naslove strank.
- [ ] Preveriti, da je v OpenAI nastavljena mesečna omejitev porabe
- [ ] Nastaviti `INQUIRY_EMAIL_TO` in preveriti, da obvestilo o povpraševanju res pride

### Odprto takoj

- [ ] **Obvestila po e-pošti ne delujejo.** `mail()` je na tem strežniku izklopljen
      (vidno na `data-view.php`). Povpraševanja se shranijo v bazo in so vidna na
      strani za pregled, obvestilo pa ne odide. Odločitev: SMTP prek lastnega
      poštnega predala ali storitev za transakcijsko pošto prek cURL.
- [ ] **Zamenjaj geslo baze in WordPressove varnostne ključe** — `wp-config.php`
      je bil prilepljen v pogovor, zato je geslo žive baze razkrito.
- [ ] Dnevna varnostna kopija `ai_` tabel v cron (glej docs/setup.md)

### Odločitve, ki čakajo

- [ ] **Javni ali zasebni repozitorij.** Zdaj je javen, ker cPanel Git zasebnega ni
      zmogel klonirati. Skrivnosti v njem ni, a kodo, ki jo nameravaš prodajati, lahko
      kdorkoli prekopira. Za zasebnega je treba prej urediti deploy:
      GitHub Actions → FTP (samodejno ob pushu) ali SSH deploy key.
- [ ] Pravi podatki stranke namesto testnih (izdelki, cene, območja dostave, kontakt)

### Naslednja faza — glas

- [ ] **Preveriti slovenske telefonske številke pri LiveKit** ali potrebo po lokalnem
      SIP operaterju. To vprašanje lahko podre celoten pristop, zato gre prvo.
- [ ] **Preizkusiti kakovost slovenskega STT/TTS** z vzorčnimi posnetki, brez
      naročnine in brez telefonske številke. Prepoznava slovenskega govora po telefonu
      je največje tveganje projekta — ne prenos zvoka.
- [ ] Šele nato: agent na LiveKit, ki kliče iste `POST /tools/*.php` s `TOOL_SECRET`.
      Poslovna logika se ne prepisuje.

### Kasneje

- [ ] `VascoAdapter` implementirati, ko bo znan pravi ERP
- [ ] Pregled povpraševanj za podjetje (kdo je poklical, kaj želi, kaj je že urejeno)
- [ ] Presoja, ali je `gpt-4o-mini` dovolj dober za slovenščino, ali je vreden večji model

---

## Kako deployam

1. Spremembe grejo na GitHub (`git push`)
2. cPanel → Git Version Control → repozitorij `tatjana_ai`
3. **Update from Remote** (to je pull) → **Deploy HEAD Commit**

Sam Deploy brez Update naloži staro kodo — to naju je enkrat zavedlo.

`config.php` ni v gitu, zato ga deploy ne povozi. Ob spremembi nastavitev ga
naloži ročno prek File Managerja.

## Kako testiram

```bash
BASE_URL=https://tatjana.kreativnisplet.si/AiAssistant_v1 bash tests/test-tools.sh
```

Vseh 24 testov mora biti zelenih. Za klepet odpri `chat-test.html`, za pregled
podatkov `data-view.php`.

---

## Pasti, ki so nas že ujele

Zapisane zato, da jih ne lovimo dvakrat.

| Simptom | Vzrok |
|---|---|
| Vsaka datoteka v mapi vrne 404, mapa pa 403 | Mapa ima pravice 0700 — spletni strežnik ne more vstopiti. `rsync -a` prenese pravice izvorne mape, cPanel git repozitorij pa je 0700. Rešeno z `--chmod=D755,F644`. |
| Enako, a pravice so v redu | `<DirectoryMatch>` v `.htaccess` ni veljaven (samo v glavni konfiguraciji strežnika). Za blokiranje `.git/` uporabi `RewriteRule`. |
| Vsi odgovori 200 namesto prave kode, v JSON prilepljena opozorila | `config.php` shranjen kot "UTF-8 z BOM". Notepad in PowerShell to naredita privzeto. |
| `SQLSTATE[HY093]: Invalid parameter number` | Isti named placeholder večkrat v enem stavku. Pri pravih pripravljenih stavkih to ni dovoljeno — vsaka pojavitev rabi svojega. |
| Chat skoraj vedno vrne "sistem mi trenutno ne odgovori" | Omejitev porabe žetonov na minuto pri `gpt-4o` na novem OpenAI računu. Rešeno s prehodom na `gpt-4o-mini`. |
| Asistent pove napačno ceno | Sistemski prompt je zahteval pretvorbo cen v besede; model je 78 € povedal kot "osemdeset evrov". Cene se zdaj izpisujejo s številko. |
