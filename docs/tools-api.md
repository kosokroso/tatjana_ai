# Tool API

Vsi tooli so dosegljivi kot `POST` na `/tools/<ime>.php` in vračajo JSON.

## Skupna oblika odgovora

```json
{ "success": true,  "data": { }, "error": null }
{ "success": false, "data": null, "error": "Sporočilo za človeka", "error_code": "not_found" }
```

| `error_code` | HTTP | Pomen | Kaj naj naredi AI |
|---|---|---|---|
| `invalid_input` | 400 | Manjka ali je napačen parameter | Vpraša stranko za manjkajoči podatek |
| `not_found` | 404 | Ni zadetka | Pove, da tega nima, in ponudi alternativo |
| `unknown_tool` | 404 | Tool ne obstaja ali ni v `ALLOWED_TOOLS` | Napaka v konfiguraciji, ne v pogovoru |
| `rate_limited` | 429 | Preveč zahtevkov z istega IP | Počaka |
| `system_error` | 500 | Baza ali ERP ne odgovori | Preusmeri stranko na telefon |
| `method_not_allowed` | 405 | Ni bil uporabljen POST | — |
| `https_required` | 403 | `REQUIRE_HTTPS` je vklopljen, zahtevek pa ni šifriran | — |

Razlika med `not_found` in `system_error` je namerna: pri prvem asistent pove, da izdelka ni,
pri drugem stranke ne sme zavajati in jo mora poslati na telefon.

---

## POST /tools/product-lookup.php

**Zahtevek**

| Polje | Obvezno | Vrednosti |
|---|---|---|
| `query` | da | prosto besedilo, do 120 znakov |
| `action` | ne | `search` (privzeto), `get_price`, `check_stock` |
| `category` | ne | `drva`, `peleti`, `briketi` |

```bash
curl -X POST https://domena.si/voice-ai/tools/product-lookup.php \
  -H "Content-Type: application/json" \
  -d '{"query":"bukova drva","action":"get_price"}'
```

**Odgovor**

```json
{
  "success": true,
  "data": [
    {
      "name": "Bukova drva, suha, 33 cm",
      "unit": "kubik",
      "price": 105,
      "price_display": "105,00 € za kubični meter",
      "availability": "na zalogi"
    }
  ],
  "error": null
}
```

Polji `price_display` in `availability` sta pripravljena za branje na glas — model
jih samo prebere, namesto da bi sam oblikoval ceno ali sklanjal enoto.

Nabor polj je namenoma ozek. Vsak odgovor orodja gre v pogovor z modelom in se
plača po žetonih, zato opisa izdelka in številčne zaloge ne vračamo — asistent
stranki pove ime, ceno in ali je izdelek na voljo. Iskanje vrne največ 5 zadetkov
(3 pri `get_price` in `check_stock`).

**Iskanje in slovenska sklanjatev.** Iskalni niz se razbije na besede, daljše od petih
znakov pa se skrajšajo na koren (`peletov` → `pelet`, `bukovih` → `bukov`). Zato zadene
tudi sklanjane oblike. Najprej velja pogoj AND (vse besede), in če ni zadetka, se poizvedba
ponovi z OR — to pokrije primere, ko stranka doda odvečne besede.

---

## POST /tools/order-lookup.php

**Zahtevek**

| Polje | Obvezno | Opis |
|---|---|---|
| `order_id` | da | Številka naročila. Sprejme tudi `#10005` ali `10 005`. |
| `verify` | **da** | Telefon ali e-pošta, s katero je bilo naročilo oddano. |

`verify` je obvezen zato, ker so naročilne številke zaporedne in jih je mogoče uganiti.
Brez preverjanja bi kdorkoli, ki pokliče in navede tujo številko, izvedel ime, termin
dostave in vsebino tujega naročila.

Telefon se primerja po **zadnjih osmih števkah**, da so vse te oblike enakovredne:
`+386 41 234 567`, `041 234 567`, `041234567`.

Ob neujemanju vrne tool **enak odgovor kot pri neobstoječem naročilu** — sicer bi iz
razlike med sporočiloma napadalec razbral, katere številke obstajajo. Zavrnitve se
zabeležijo z oznako `verification_failed`.

```bash
curl -X POST https://domena.si/voice-ai/tools/order-lookup.php \
  -H "Content-Type: application/json" \
  -d '{"order_id":"10005","verify":"041 234 567"}'
```

**Odgovor**

```json
{
  "success": true,
  "data": {
    "id": 10005,
    "status": "scheduled",
    "status_display": "potrjeno, dostava je dogovorjena",
    "order_date": "2026-09-02",
    "delivery_date": "2026-09-18",
    "delivery_display": "četrtek, 18. septembra (čez 7 dni)",
    "delivery_is_past": false,
    "customer_name": "Janez Novak",
    "items": [
      { "name": "Bukova drva, suha, 33 cm", "quantity": 8, "unit": "kubik" }
    ]
  },
  "error": null
}
```

Telefon, e-pošta in naslov stranke se **ne vrnejo** — asistent jih ne potrebuje,
zato ne smejo priti v pogovor.

---

## POST /tools/business-info.php

**Zahtevek**

| Polje | Obvezno | Vrednosti |
|---|---|---|
| `info_type` | ne | `hours` (privzeto), `delivery_regions`, `payments` |

```bash
curl -X POST https://domena.si/voice-ai/tools/business-info.php \
  -H "Content-Type: application/json" \
  -d '{"info_type":"hours"}'
```

**Odgovor za `hours`**

```json
{
  "success": true,
  "data": {
    "today": "petek",
    "today_open": true,
    "opens": "08:00",
    "closes": "18:00",
    "open_now": true,
    "current_time": "14:32",
    "tomorrow": "sobota",
    "tomorrow_open": true,
    "tomorrow_opens": "08:00",
    "tomorrow_closes": "14:00",
    "week": [ { "day": "ponedeljek", "closed": false, "opens": "08:00", "closes": "18:00" } ]
  },
  "error": null
}
```

`hours` bere iz baze, `delivery_regions` in `payments` pa iz `data/business-info.json`.
To datoteko lahko ureja podjetje samo, brez posega v kodo.

---

## POST /tools/submit-inquiry.php

Odda povpraševanje stranke, da jo podjetje pokliče nazaj. Edini tool, ki piše.

**Zahtevek**

| Polje | Obvezno | Opis |
|---|---|---|
| `name` | **da** | Ime in priimek stranke, do 120 znakov |
| `phone` | **da** | Telefon za povratni klic; mora vsebovati vsaj 8 števk |
| `email` | ne | Preveri se s `FILTER_VALIDATE_EMAIL` |
| `product` | ne | Kaj stranka želi, z njenimi besedami |
| `quantity` | ne | Količina, kot jo je povedala: "3 kubike", "2 paleti" |
| `note` | ne | Ozek dovoz, želeni teden ipd., do 500 znakov |

```bash
curl -X POST https://domena.si/voice-ai/tools/submit-inquiry.php \
  -H "Content-Type: application/json" \
  -d '{"name":"Janez Novak","phone":"041 234 567","product":"bukova drva","quantity":"3 kubike"}'
```

**Odgovor**

```json
{
  "success": true,
  "data": {
    "id": 501,
    "message": "Povpraševanje je zabeleženo pod številko 501. Podjetje se javi stranki na navedeno telefonsko številko."
  },
  "error": null
}
```

Zapis gre v tabelo `inquiries` in je vir resnice. E-pošta podjetju (`INQUIRY_EMAIL_TO`)
je samo obvestilo — če pošiljanje ne uspe, povpraševanje ostane shranjeno in stranka
dobi enak odgovor, ker bi sicer zaradi težave s poštnim strežnikom izgubili posel.
Neuspeh se zapiše v dnevnik strežnika.

Vrednosti stranke nikoli ne gredo v glave e-pošte, samo v telo — sicer bi vrednost
z znakom za novo vrstico omogočila vrivanje glav in zlorabo strežnika za neželeno pošto.

---

## Kako dodaš nov tool (5 korakov)

1. **Razred.** Ustvari `tools/implementations/MojTool.php`, ki razširja `Tool`.
   Implementiraj `name()` (npr. `delivery-estimate`) in `handle(array $input): ToolResponse`.
   Za branje vhoda uporabi `requireString()`, `enumValue()`, `requireId()` — te metode
   same zavrnejo neveljaven vhod s čisto napako namesto s 500.
2. **Registracija.** V `tools/core/ToolRegistry.php` dodaj `$this->register(new MojTool($adapter));`
   in v `bootstrap.php` vrstico `require_once` za novo datoteko.
3. **Dovoljenje.** V `config.php` dodaj ime v `ALLOWED_TOOLS`.
4. **Endpoint.** Ustvari `tools/delivery-estimate.php` — tri vrstice, prepiši iz obstoječega.
5. **Definicija za AI.** V `ai/tool-definitions.json` dodaj opis v slovenščini,
   v `ai/chat.php` pa preslikavo v `TOOL_NAME_MAP`.

Če tool potrebuje podatke, ki jih adapter še ne zna prebrati, dodaj metodo v
`AdapterInterface` in jo implementiraj v **vseh** adapterjih.

## Kako zamenjaš vir podatkov (2 koraka)

1. Napiši adapter, npr. `tools/adapters/VascoAdapter.php` (predloga je že tam),
   ki implementira `AdapterInterface`. Preslikava stolpcev ERP-ja v predpisane
   ključe spada v adapter, nikoli v toole.
2. V `config.php` spremeni `define('ADAPTER', 'VascoAdapter');` in dodaj razred
   v seznam `ToolRegistry::ADAPTERS`.

Tooli, sistemski prompt in chat sloj ostanejo nedotaknjeni.

## Logi

`logs/tool-calls-YYYY-MM-DD.log` — ena vrstica JSON na klic:

```json
{"ts":"2026-09-11T14:32:04+02:00","request_id":"a1b2c3d4e5f6","source":"chat","ip":"89.212.44.x",
 "tool":"order-lookup","args":{"order_id":10005,"verify":"[tel:***567]"},"success":true,
 "error_code":null,"result":"zapis #10005","duration_ms":12}
```

`logs/conversations-YYYY-MM-DD.log` — vprašanje stranke, uporabljena orodja, odgovor asistenta.

Telefonske številke, e-pošta, imena in zadnji oktet IP so maskirani (`LOG_MASK_PII`).
Datoteke se same brišejo po `LOG_RETENTION_DAYS` dneh, mapa pa je prek `.htaccess`
zaprta za brskalnik — preveri to takoj po namestitvi, ker je na nekaterih hostingih
`AllowOverride` izklopljen in `.htaccess` ne deluje.
