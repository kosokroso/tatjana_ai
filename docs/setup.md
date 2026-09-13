# Namestitev in testiranje

## Kaj potrebuješ na hostingu

- PHP 7.4 ali novejši, z razširitvama **PDO MySQL** in **cURL**
- MySQL 5.7+ ali MariaDB 10.3+
- Dostop do phpMyAdmin in do datotek (FTP, SFTP ali File Manager v cPanelu)

Če katera od razširitev manjka, to vidiš v cPanelu pod "Select PHP Version" →
"Extensions". Brez cURL chat ne dela, brez PDO MySQL ne dela nič.

## Ločena baza ali baza spletne strani?

Koda deluje v obeh postavitvah — odloča `DB_NAME` in `DB_PREFIX` v `config.php`.

**Skupna baza s spletno stranjo** (trenutna izbira). Manj vnosov v cPanelu, in ko
bo asistent kdaj moral brati prave podatke strani, so na dosegu. Tabele nosijo
predpono `ai_`, da se ne pomešajo z `wp_`.

Kaj to pomeni v praksi:

- WordPressova jedrna posodobitev tujih tabel **ne briše** — upravlja samo svoje.
  Tudi vtičnik ob odstranitvi pobriše samo tabele, ki jih ima zapisane v kodi.
- Nevarna sta dva primera: vtičniki za "čiščenje baze", ki neznane tabele prikažejo
  kot osirotele in ponudijo brisanje, ter obnovitev starejše varnostne kopije ali
  selitev celotne baze.
- Zato je **redna varnostna kopija teh tabel edina prava zaščita**. V bazi, kjer ima
  WordPressov uporabnik vse pravice, tabel ni mogoče narediti neizbrisljivih.

Cron v cPanelu, dnevno:

```
mysqldump -u UPORABNIK -pGESLO BAZA ai_products ai_customers ai_orders ai_business_hours ai_inquiries > ~/backups/asistent-$(date +\%F).sql
```

Mapa `~/backups` mora biti **zunaj** `public_html`, sicer je izvoz baze dosegljiv
prek brskalnika.

**Ločena baza.** Napaka v asistentu ne more poškodovati spletne strani, uhajanje
`config.php` ne izda poverilnic strani, kopiji se obnavljata neodvisno. Ceno plačaš
z enim dodatnim vnosom v cPanelu. Preklopiš tako, da v `config.php` vpišeš drugo
bazo in `DB_PREFIX` pustiš prazen.

## 1. Baza

1. V cPanelu ustvari bazo (npr. `asistent_test`) in uporabnika z vsemi pravicami nanjo.
   Zapiši si ime baze, uporabnika in geslo — na shared hostingu imata ime baze in
   uporabnika običajno predpono, npr. `mojracun_asistent`.
2. Odpri phpMyAdmin, levo izberi to bazo.
3. Zavihek **SQL** → prilepi vsebino `sql/schema.sql` → **Izvedi**.
4. Preveri, da so nastale štiri tabele in da ima `products` 20 vrstic.

Skripta je idempotentna — če jo poženeš znova, tabele pobriše in ustvari na novo.
Na testni bazi je to v redu, na produkcijski bi pomenilo izgubo podatkov.

## 2. Datoteke

Naloži celotno mapo na strežnik, npr. v `public_html/voice-ai/`.

```
voice-ai/
├── ai/              chat backend, sistemski prompt, definicije orodij
├── data/            podatki o dostavi in plačilih (ureja podjetje)
├── docs/
├── logs/            nastane sam; mora biti zaprt za brskalnik
├── sql/
├── tests/
├── tools/           tool sloj in HTTP endpointi
├── bootstrap.php
├── chat-test.html   testni klepet
└── config.php       ustvariš ti (glej spodaj)
```

## 3. Konfiguracija

Kopiraj `config.example.php` v `config.php` in izpolni:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mojracun_asistent');
define('DB_USER', 'mojracun_asistent');
define('DB_PASS', 'geslo-iz-cpanela');

define('OPENAI_API_KEY', 'sk-...');
define('REQUIRE_HTTPS', true);   // na hostingu vedno true
```

Ključ OpenAI dobiš na platform.openai.com pod API keys. **Takoj nastavi mesečno
omejitev porabe** (Billing → Limits) — brez nje lahko napaka v zanki ali zloraba
endpointa povzroči visok račun.

`config.php` ne sme v git (že je v `.gitignore`) in ga ne objavljaj nikjer.

> **Shrani kot UTF-8 brez BOM.** Notepad in PowerShell privzeto dodata na začetek
> datoteke nevidne bajte (BOM). Ti gredo ven pred glavami HTTP, zato vsi odgovori
> vrnejo 200 namesto prave kode, v JSON pa se prilepijo opozorila PHP. Enako velja
> za prazno vrstico pred `<?php`. V VS Code to nastaviš spodaj desno v vrstici
> stanja ("UTF-8 with BOM" → "Save with Encoding" → "UTF-8"). Če se to vseeno
> zgodi, ti `bootstrap.php` javi vzrok z imenom datoteke in vrstico.

## 4. Test

**Tooli brez AI** (ne stane nič, preveri bazo in varnost):

```bash
BASE_URL=https://tvoja-domena.si/voice-ai bash tests/test-tools.sh
```

Vseh 18 testov mora biti zelenih. Posebej pomembna sta zadnja dva: `GET` mora biti
zavrnjen in mapa `logs/` ne sme biti dosegljiva prek brskalnika.

**Chat** (stane, vsako sporočilo je klic na OpenAI):

Odpri `https://tvoja-domena.si/voice-ai/chat-test.html` in preizkusi:

| Vprašanje | Kaj mora narediti |
|---|---|
| Koliko stanejo bukova drva? | uporabi `search_products`, pove ceno iz baze |
| Imate pelete na zalogi? | uporabi `search_products`, pove razpoložljivost |
| Do kdaj ste danes odprti? | uporabi `get_business_info`, upošteva današnji dan |
| Kam dostavljate in koliko stane? | uporabi `get_business_info` |
| Zanima me naročilo 10005 | **vpraša za telefonsko številko**, šele nato pogleda |
| (nato) 041 234 567 | pove status in termin dostave |
| Naročilo 10005, telefon 031 876 543 | pove, da naročila ne najde — tuja številka |
| Koliko stane dostava na Dunaj? | pove, da tja ne dostavljajo, ne izmišlja cene |

Pod vsakim odgovorom v testni strani piše, katera orodja je model uporabil. Če pri
vprašanju o ceni ni klical nobenega orodja, je odgovor izmišljen — to je najhujša
napaka, ki jo iščeš.

## 5. Varnostni pregled pred predajo stranki

- [ ] `REQUIRE_HTTPS` je `true` in stran teče na https
- [ ] `https://domena.si/voice-ai/logs/` vrne 403 ali 404
- [ ] `https://domena.si/voice-ai/config.php` vrne prazno stran, ne izpisa kode
- [ ] Naročilo se ne razkrije brez pravilnega telefona
- [ ] V OpenAI je nastavljena mesečna omejitev porabe
- [ ] `chat-test.html` je po testiranju odstranjen ali zaščiten z geslom —
      brez tega lahko kdorkoli troši tvoj OpenAI kredit

Zadnja točka je pomembna: chat endpoint nima prijave. Rate limit (20 sporočil na
IP na minuto) ustavi grobo zlorabo, ne pa nekoga, ki bi stran uporabljal ves dan.
Dokler je to interni test, je v redu; pred javno objavo je treba dodati prijavo
ali omejitev na domeno.

## Koliko stane

Vsako sporočilo sproži enega ali dva klica na OpenAI (drugega, ko model najprej
pokliče orodje). Pri modelu `gpt-4o` je to nekaj centov na pogovor; z `gpt-4o-mini`
je bistveno ceneje in za ta nabor vprašanj pogosto dovolj dobro. Model zamenjaš z
eno vrstico v `config.php` — preizkusi oba in primerjaj kakovost slovenščine.

Ker se imena in cene modelov spreminjajo, pred zagonom preveri, kateri modeli so
na tvojem računu na voljo.

## Naslednji korak: glas

Ta koda pokriva besedilni del. Za telefonske klice bo potreben **Node strežnik z
odprtim WebSocketom** (Twilio Media Streams + OpenAI Realtime API), česar navadni
PHP hosting ne omogoča — potrebuješ VPS ali platformo kot Railway/Render.

Zato so tooli narejeni kot HTTP endpointi: glasovni strežnik jih bo klical prek
`POST /tools/...` s skupno skrivnostjo `TOOL_SECRET` v glavi `X-Tool-Secret`,
brez prepisovanja poslovne logike. Sistemski prompt in definicije orodij se
uporabijo enaki.
