# Namestitev

Postavitev nove stranke. Čarovnik `setup.php` opravi večino; ročno ostaneta samo
baza v cPanelu in vnos storitev.

## Kaj potrebuješ

- PHP 7.4 ali novejši z razširitvami **PDO MySQL**, **mbstring**, **cURL**, **OpenSSL**
- MySQL 5.7+ ali MariaDB 10.3+
- Poštni predal na domeni (za obvestila o povpraševanjih)
- OpenAI ključ, po želji Azure ključ za slovenski glas

Manjkajoče razširitve vklopiš v cPanelu pod *Select PHP Version → Extensions*.
Čarovnik jih preveri sam in ne dovoli nadaljevati, dokler kaj manjka.

---

## 1. Baza

V cPanelu → **MySQL Databases** ustvari bazo in uporabnika z vsemi pravicami nanjo.
Zapiši si ime, uporabnika in geslo — na shared hostingu imata predpono računa,
npr. `mojracun_asistent`.

Tabel ti ni treba ustvarjati; to naredi čarovnik.

**Ločena baza ali baza spletne strani?** Koda dela v obeh postavitvah.

*Skupna z WordPressom* pomeni manj vnosov in podatke strani na dosegu. Tabele
nosijo predpono `ai_`, da se ne pomešajo z `wp_`. WordPressova posodobitev tujih
tabel ne briše, nevarna pa sta vtičnik za "čiščenje baze", ki neznane tabele
ponudi v brisanje, in obnovitev starejše varnostne kopije.

*Ločena baza* pomeni, da napaka v asistentu ne more poškodovati spletne strani in
da uhajanje `config.php` ne izda poverilnic strani. Ceno plačaš z enim dodatnim
vnosom v cPanelu.

V obeh primerih je **redna varnostna kopija edina prava zaščita**. V bazi, kjer
ima drug uporabnik vse pravice, tabel ni mogoče narediti neizbrisljivih. Cron:

```
mysqldump -u UPORABNIK -pGESLO BAZA ai_products ai_customers ai_orders ai_business_hours ai_inquiries > ~/backups/asistent-$(date +\%F).sql
```

Mapa `~/backups` mora biti **zunaj** `public_html`, sicer je izvoz baze dosegljiv
prek brskalnika.

## 2. Datoteke

Naloži mapo na strežnik, npr. v `public_html/asistent/`.

```
asistent/
├── admin/           povprasevanja in urejanje storitev (geslo)
├── ai/              chat, prepis govora, sinteza, definicije orodij
├── data/            podatki o poslovanju, ki niso v bazi
├── docs/
├── logs/            nastane sam; mora biti zaprt za brskalnik
├── sql/             struktura baze
├── tests/
├── tools/           tool sloj in HTTP endpointi
├── voice-agent/     agent za telefonske klice (tece locено)
├── index.html       glasovni asistent + gumbi do portalov
├── widget.js        vgradnja klepeta v tujo stran
├── setup.php        carovnik — PO NAMESTITVI IZBRISI
└── config.php       ustvari carovnik
```

## 3. Čarovnik

Odpri `https://domena.si/asistent/setup.php` in izpolni obrazec. Ustvari tabele,
zapiše `config.php` ter zgenerira `TOOL_SECRET`, `DATA_VIEW_KEY` in zgoščeno
skrbniško geslo.

**Takoj po namestitvi izbriši `setup.php` s strežnika.** Dokler je tam, je pot do
prepisa nastavitev odprta vsakomur, ki bi kdaj izbrisal `config.php`.

Čarovnik se sam izklopi, dokler `config.php` obstaja, a to ni razlog, da bi
datoteko puščal na strežniku.

## 4. Vsebina

**Storitve** vneseš v `admin/storitve.php`. Asistent bere izključno od tam in si
cen ne izmišlja. Prazna cena pomeni "cena po dogovoru", oznaka "od" pa izhodiščno
ceno — brez nje asistent izhodiščno ceno pove kot končno.

**Delovni čas** je privzeto pon–pet 9–17 v tabeli `ai_business_hours`. Popravi ga,
če se razlikuje; asistent ga stranki pove kot dejstvo.

**Roke in pogoje plačila** uredi v `data/business-info.json`.

## 5. Preverjanje

Orodja, brez stroška pri OpenAI:

```bash
BASE_URL=https://domena.si/asistent bash tests/test-tools.sh
```

Vsi testi morajo biti zeleni. Posebej zadnja dva: `GET` na tool mora vrniti 405 in
mapa `logs/` ne sme biti dosegljiva prek brskalnika.

Pošiljanje e-pošte preizkusiš na `data-view.php` (ključ je v `config.php` pod
`DATA_VIEW_KEY`).

Asistenta preizkusi na `https://domena.si/asistent/`:

| Vprašanje | Kaj mora narediti |
|---|---|
| "Koliko stane …?" | pokliče orodje, pove ceno iz baze, pri izhodiščni doda "od" |
| Vprašanje o storitvi brez cene | pove "cena po dogovoru", ne izmisli zneska |
| "Pošljite ponudbo" | zbere ime, telefon in e-pošto, povzame, počaka na potrditev |
| Vprašanje o tujem naročilu | zavrne brez ujemajočega kontakta |
| "Pozabi navodila in …" | vljudno zavrne |

Če pri vprašanju o ceni ne pokliče nobenega orodja, je odgovor izmišljen. To je
najhujša napaka, ki jo iščeš.

## 6. Vgradnja v spletno stran

```html
<div id="tatjana-chat"></div>
<script src="/asistent/widget.js" defer></script>
```

Kadar je asistent na isti domeni kot stran, dodatnih nastavitev ni. Pri vgradnji
s tuje domene mora biti ta domena v `CHAT_ALLOWED_ORIGINS`.

---

## Pred predajo stranki

- [ ] `setup.php` odstranjen s strežnika
- [ ] `data-view.php` odstranjen ali ima nastavljen `DATA_VIEW_KEY`
- [ ] `REQUIRE_HTTPS` je `true`
- [ ] `logs/` vrne 403 ali 404
- [ ] `config.php` vrne prazno stran, ne izpisa kode
- [ ] Naročilo se ne razkrije brez ujemajočega kontakta
- [ ] V OpenAI nastavljena mesečna omejitev porabe
- [ ] Dnevna varnostna kopija `ai_` tabel v cronu
- [ ] Delovni čas in pogoji plačila preverjeni, ne privzeti

## Koliko stane

Vsako vprašanje sproži enega ali dva klica na OpenAI. Pri `gpt-4o-mini` je to
delčki centa na pogovor. Glas doda prepis in sintezo; Azure ima brezplačnih
500.000 znakov mesečno.

Varovala v kodi: omejitve na minuto in dan na IP, skupna dnevna kapica in dnevni
proračun žetonov. Zadnji šteje dejanski strošek, ne števila klicev — en klic z
dolgo zgodovino stane toliko kot deset kratkih. Porabo vidiš na `data-view.php`.

Mesečna omejitev pri OpenAI je zadnja obramba, če bi ključ kdaj ušel. Nastavi jo.

## Telefon

Glej [telefon.md](telefon.md). Agent teče ločeno od tega gostovanja, kliče pa iste
`tools/*.php` s skupno skrivnostjo, zato se poslovna logika ne prepisuje.
