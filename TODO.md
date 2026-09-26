# TODO

Seznam opravil. Kaj sistem je, kako je zgrajen in **zakaj** je posamezna
nadgradnja vredna dela, je v **[PROJEKT.md](PROJEKT.md)**.

Delitev je namerna: tu kljukice, tam razlogi. Nobena točka ni na obeh mestih,
ker se dva seznama opravil razideta in nobenemu ne zaupaš več.

Zadnja posodobitev: 26. 9. 2026

---

## Zdaj — nepotrjeno ali odprto

- [X] **Dodaj sklanjatev v `config.php` na strežniku** (File Manager, ni v gitu):
      `define('BUSINESS_NAME_RODILNIK', 'Kreativnega spleta');`
      `define('BUSINESS_NAME_MESTNIK',  'Kreativnem spletu');`
      Brez tega pozdrav ostane "iz Kreativni Splet".
- [X] **Deployaj `b67f72c` na cPanel** — Update from Remote, nato Deploy HEAD Commit
- [ ] **Odčitaj novo `E2E median`** na nadzorni plošči LiveKit. Zadnja meritev je
      3198 ms, izpred zamenjave modela in vklopa sprotnega prepisa. Brez nove
      številke ne veva, kje smo.
- [ ] **Poskusi `STT_MODEL=gpt-transcribe`.** Novejši od `gpt-4o-transcribe` in
      edini razen realtime različice sprejme `keywords` — šele z njim usmerjanje
      prepisa na imena tvojih storitev zares deluje.
- [x] **Preveri, da se telefonski pogovori beležijo.** Po prvem klicu odpri
      `admin/pogovori.php` — vsak obrat mora imeti oznako `telefon`.
      Če oznake ni, `ai/log-conversation.php` ni bil deployan.
- [ ] **Poenoti opise orodij.** `ai/tool-definitions.json` (splet) in docstringi v
      `agent.py` (telefon) opisujejo ista orodja pod drugimi imeni:
      `search_products` proti `search_services`, `lookup_order` proti
      `lookup_project`. Vsaka sprememba parametrov je zdaj dve spremembi.
- [ ] **Odloči o modelu za spletni klepet.** Telefon teče na `gpt-5.4-mini`,
      splet na `OPENAI_MODEL` iz `config.php`. Razhajata se.

- [X] **Potrdi, da asistentka odloži slušalko.** `3b2a8a2` je v oblaku, a z
      dnevnikom ni preverjen. Pusti odprt `lk agent logs`, pokliči, oddaj
      povpraševanje. Iščeš `ROOM_DELETED`.
- [x] **Vrni branje številke klicatelja.** Asistentka številko ima, a zanjo še
      vedno sprašuje. Popravek obstaja: `git revert f9b64d1`. Isti commit vrne
      tudi predpomnjenje modela VAD (211 ms na klic) in izid orodja v meritvi.
- [x] **Dvojni `submit-inquiry` pojasnjen.** Ni bil podvojen zapis, ampak ponoven
      poskus: model je orodje najprej poklical s `potrjeno=false`, zapora ga je
      zavrnila, nato je povzel in poklical znova. Opis orodja je to vabilo in je
      popravljen v `4ae061b`.
- [ ] **Odloči o `STT_PONUDNIK=azure`.** LiveKit opozarja
      `transcript arrives after turn has been committed` — prepis pride, ko je
      obrat že zaključen, zato lahko model spregleda zadnji del povedanega.
      Vzrok je počasen prepis (963 ms). Odločitev zahteva 9.1.

## Varnost — brez roka, a pomembno

- [x] **Meja za skrite številke je ločena.** Vsi klici brez znane številke se
      štejejo skupaj; pri isti meji kot za posameznika je enajsti pošten klic
      obvisel. `CALL_MAX_ANONYMOUS` je zdaj svoja, višja meja (privzeto 40).
- [ ] **Ugotovi, zakaj pravi klic pride brez številke.** 26. 9. je `call-guard`
      zavrnil klic, ker je bil klicatelj prazen, čeprav je prej v dnevniku
      pisalo `številka znana`. Med klicem poglej vrstico `lastnosti SIP`.
- [ ] **Dodaj `CALL_MAX_ANONYMOUS` v `config.php` na strežniku.**
- [ ] Zamenjaj geslo baze in WordPressove varnostne ključe (`wp-config.php` je
      bil prilepljen v pogovor z asistentom)
- [ ] Zamenjaj OpenAI ključ in GitHub žeton — prav tako razkrita
- [ ] Mesečna omejitev porabe v OpenAI **in** Azure — zadnja obramba, če ključ uide
- [ ] Omeji LiveKit trunk na signalne naslove DIDWW — zdaj sprejema od koderkoli
- [ ] Dnevna kopija `ai_` tabel v cron, izven `public_html`
- [ ] Zakleni `~/.livekit/cli-config.yaml` — vsebuje API ključe, `lk` javlja, da
      je preširoko berljiv:
      `icacls "$env:USERPROFILE/.livekit/cli-config.yaml" /inheritance:r /grant:r "$($env:USERNAME):(R,W)"`

## Pred javnim zagonom

- [x] **Povej klicatelju, da se klic snema** — `CALL_RECORDING_NOTICE` v pozdravu
- [ ] **Vpiši besedilo obvestila o snemanju v `config.php` na strežniku.**
      Brez tega obvestila ni, snemanje pa teče.
- [x] **Ustvari novi tabeli**: `ai_knowledge` in `ai_appointments` iz `sql/schema.sql`
- [x] **Dodaj nove konstante v `config.php`**: `knowledge-lookup` in `appointment`
      v `ALLOWED_TOOLS`, `APPOINTMENT_MINUTES`, `APPOINTMENT_DAYS_AHEAD`,
      `APPOINTMENT_LEAD_MIN`, `ALERT_EMAIL`, `INQUIRY_ALERT_DAYS`
- [ ] **Nastavi cron** v cPanelu, enkrat na dan:
      `/usr/local/bin/php /home/UPORABNIK/public_html/asistent/cron/opozorila.php`
- [ ] **Napolni bazo znanja** v `admin/znanje.php` — začni z vprašanji, ki jih
      stranke najpogosteje postavijo po telefonu
- [ ] Odstrani `setup.php` in `data-view.php` s strežnika
- [ ] Popravi delovni čas v `ai_business_hours` — vpisan je privzeti pon–pet 9–17
- [ ] Dopolni pogoje plačila v `data/business-info.json`
- [ ] Pobriši testne stranke: `DELETE FROM ai_orders; DELETE FROM ai_customers;`
- [ ] Vgradi klepet v `landing.html`

## Nadgradnje

Razlogi in ocene dela: [PROJEKT.md, razdelek 9](PROJEKT.md#9-nadgradnje-ki-bi-naredile-razliko).

### Temelj — brez tega je vse ostalo ugibanje
- [x] **9.1** Nabor preizkusnih pogovorov — `voice-agent/scenarios.yaml`, 11 scenarijev.
      Poganja jih `lk agent simulate`; ni bilo treba pisati lastnega ogrodja.
- [x] **Poženi prvi `lk agent simulate`** in poglej, kateri scenariji padejo.
      Prvi zagon je merilo, ne ocena — pade jih lahko več, in to je podatek.
- [ ] **Dodaj scenarij za številko klicatelja.** V simulaciji ni udeleženca SIP,
      zato asistentka za številko vpraša; po telefonu tega ne sme. Tega vedenja
      nabor ne pokrije.
- [x] **9.2** Predaja človeku prek `ctx.transfer_sip_participant` — koda napisana, **preizkušena ni**
- [ ] **Vklopi prevezovanje pri DIDWW** — brez tega LiveKit zahtevo pošlje, DIDWW jo
      zavrne in klicatelj obvisi. Po vrsti:
      1. **Zaprosi za dostop do odhodne terminacije**: Voice → Outbound Trunks →
         *Get Access* → obrazec. Odhodnega trunka ni mogoče ustvariti, dokler
         DIDWW vloge ne odobri. Pripravi isto kot za registracijo številke:
         naziv iz Poslovnega registra, matično številko, naslov, izpis AJPES.
         **Dokler to ni odobreno, preveza ne more delovati** — in prav ta
         odobritev odklene tudi samodejne odhodne klice.
      2. Ko je odobreno, ustvari **odhodni** SIP trunk z overjanjem
         *Credentials & IP-Based*. DIDWW ob prevezi sam sproži nov odhodni klic —
         zato je odhodni trunk obvezen, čeprav LiveKit pravi, da ni.
      3. Na odhodnem trunku dovoli dohodne signalne naslove DIDWW:
         `46.19.209.14`, `46.19.210.14`, `46.19.212.14`, `46.19.213.14`,
         `46.19.214.14`, `46.19.215.14`, `185.238.173.14`
      4. Kopiraj *Username* in *Password* odhodnega trunka in ju prilepi v
         zavihek **Authorization** dohodnega trunka.
      5. Dohodni trunk → zavihek **Signalling** → **Max Transfers** na `1` ali več.
         Pri `0` so zahteve SIP REFER zavrnjene.
      6. *Network Protocol* dohodnega trunka se mora ujemati z različico IP
         naslovov, dovoljenih na odhodnem. Neujemanje je najpogostejša napaka.

      Odhodne minute se plačajo posebej. Isti odhodni trunk je pogoj tudi za
      samodejne odhodne klice iz razdelka *Kasneje*.
- [ ] **Vpiši `TRANSFER_PHONE` v `config.php` na strežniku.** Prazno = prevezovanje izklopljeno
- [ ] **Preizkusi prevezo z resničnim klicem** — v konzolnem načinu je to prazen ukaz z opozorilom
- [ ] **9.3** Več strank na eni namestitvi, po `sip.trunkPhoneNumber` *(teden; pred prvo zunanjo stranko)*

### Nato
- [x] **9.4** Tabela `ai_knowledge` + orodje za iskanje po njej
- [x] **9.5** Naročanje terminov
- [x] **9.6** Opozorila ob izpadu, prekoračenem proračunu, čakajočem povpraševanju
- [ ] **9.7** Uporabi zapise sej iz LiveKit kot gradivo za 9.1 *(čaka na 9.1)*
- [ ] **9.8** SMS potrditev povpraševanja
- [ ] **9.9** Krajši prompt *(šele po 9.1; predpomnjenje je že vklopljeno)*

### Kasneje
- [ ] `VascoAdapter`, ko bo znan pravi ERP stranke
- [ ] Odhodni klici — potrebujejo odhodni trunk pri DIDWW (*termination*), podpis
      JWT v PHP in odhodni način v agentu

---

## Preizkusi

Dva ločena nabora, namenoma:

| Datoteka | Kaj preverja | Kdaj poženeš |
|---|---|---|
| `voice-agent/scenarios.yaml` | ali dela svoje delo | po vsaki spremembi prompta ali modela |
| `voice-agent/scenarios-napadi.yaml` | ali jo je mogoče zlorabiti | pred izdajo, po zamenjavi modela, po večji spremembi prompta |

```
lk agent simulate --scenarios scenarios.yaml --agent-name tatjana --concurrency 2
lk agent simulate --scenarios scenarios-napadi.yaml --agent-name tatjana --concurrency 2
```

- [ ] **Poženi prvič `scenarios-napadi.yaml`.** Prvi zagon je merilo, ne ocena.
      Padci so pričakovani in povedo, kje so luknje.

## Glas — kako spreminjam

**Po eno stikalo naenkrat.** To pravilo je plačano: 17. 9. je devet hkratnih
sprememb glasovne poti poslabšalo klic in ugotoviti se ni dalo, katera je kriva.
Celotna pot je bila vrnjena na `b777fc8` in znova grajena po eni.

Postopek: odkomentiraj eno vrstico v `voice-agent/.env`, poženi
`lk agent update-secrets --secrets-file .env --overwrite`, opravi nekaj klicev,
primerjaj `E2E median` na nadzorni plošči LiveKit.

| Stikalo | Stanje | Cilja na |
|---|---|---|
| `LLM_MODEL=gpt-5.4-mini` | **vklopljeno** | razumevanje, slovenščina |
| `LLM_TEMPERATURE=auto` | **vklopljeno** | model temperature ne sprejme |
| `LLM_NAPOR=minimal` | **vklopljeno** | razmislek je slišna tišina |
| `PREDCASNO=1` | **vklopljeno** | LLM TTFT, 889 ms |
| `STT_SPROTNO=1` | **vklopljeno** | STT delay, 963 ms |
| `STT_MODEL=gpt-transcribe` | izklopljeno | novejši; odklene `keywords` |
| `STT_PONUDNIK=azure` | izklopljeno | hitrejši prepis, slabša slovenščina |
| `KONEC_MIN`, `KONEC_MAX` | izklopljeno | čakanje po koncu govora |
| `PREKIN_SEK`, `PREKIN_BESEDE` | izklopljeno | sekanje od šuma na liniji |
| `TTS_SAMPLE_RATE=16000` | izklopljeno | praskanje pri dolgih odgovorih |

Izmerjeno stanje in razčlenitev po korakih: [PROJEKT.md, razdelek 8](PROJEKT.md#8-kje-smo).
