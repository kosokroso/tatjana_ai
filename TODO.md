# TODO

Seznam opravil. Kaj sistem je, kako je zgrajen in **zakaj** je posamezna
nadgradnja vredna dela, je v **[PROJEKT.md](PROJEKT.md)**.

Delitev je namerna: tu kljukice, tam razlogi. Nobena točka ni na obeh mestih,
ker se dva seznama opravil razideta in nobenemu ne zaupaš več.

Zadnja posodobitev: 19. 9. 2026

---

## Zdaj — nepotrjeno ali odprto

- [ ] **Potrdi, da asistentka odloži slušalko.** `3b2a8a2` je v oblaku, a z
      dnevnikom ni preverjen. Pusti odprt `lk agent logs`, pokliči, oddaj
      povpraševanje. Iščeš `ROOM_DELETED`.
- [ ] **Vrni branje številke klicatelja.** Asistentka številko ima, a zanjo še
      vedno sprašuje. Popravek obstaja: `git revert f9b64d1`. Isti commit vrne
      tudi predpomnjenje modela VAD (211 ms na klic) in izid orodja v meritvi.
- [ ] **Pojasni dvojni `submit-inquiry`.** V klicu 17. 9. sta bila dva zaporedna.
      Preveri v `admin/index.php`, ali sta zabeleženi dve povpraševanji. Če sta,
      je to podvojen zapis in ne ponovni poskus.
- [ ] **Odloči o `STT_PONUDNIK=azure`.** LiveKit opozarja
      `transcript arrives after turn has been committed` — prepis pride, ko je
      obrat že zaključen, zato lahko model spregleda zadnji del povedanega.
      Vzrok je počasen prepis (963 ms). Odločitev zahteva 9.1.

## Varnost — brez roka, a pomembno

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

- [ ] **Povej klicatelju, da se klic snema.** LiveKit snemanje že dela
      (`enable_recording: true`), obvestila pa ni. V EU to ni dopustno. Vrstica
      gre v pozdrav v `ai/agent-config.php`.
- [ ] Odstrani `setup.php` in `data-view.php` s strežnika
- [ ] Popravi delovni čas v `ai_business_hours` — vpisan je privzeti pon–pet 9–17
- [ ] Dopolni pogoje plačila v `data/business-info.json`
- [ ] Pobriši testne stranke: `DELETE FROM ai_orders; DELETE FROM ai_customers;`
- [ ] Vgradi klepet v `landing.html`

## Nadgradnje

Razlogi in ocene dela: [PROJEKT.md, razdelek 9](PROJEKT.md#9-nadgradnje-ki-bi-naredile-razliko).

### Temelj — brez tega je vse ostalo ugibanje
- [ ] **9.1** Nabor 30–50 preizkusnih pogovorov s pričakovanim izidom *(dan dela)*
- [ ] **9.2** Predaja človeku prek `ctx.transfer_sip_participant` *(pol dneva)*
- [ ] **9.3** Več strank na eni namestitvi, po `sip.trunkPhoneNumber` *(teden; pred prvo zunanjo stranko)*

### Nato
- [ ] **9.4** Tabela `ai_knowledge` + orodje za iskanje po njej
- [ ] **9.5** Naročanje terminov
- [ ] **9.6** Opozorila ob izpadu, prekoračenem proračunu, čakajočem povpraševanju
- [ ] **9.7** Uporabi posnetke klicev kot gradivo za 9.1
- [ ] **9.8** SMS potrditev povpraševanja
- [ ] **9.9** Krajši prompt in predpomnjenje *(šele po 9.1)*

### Kasneje
- [ ] `VascoAdapter`, ko bo znan pravi ERP stranke
- [ ] Odhodni klici — potrebujejo odhodni trunk pri DIDWW (*termination*), podpis
      JWT v PHP in odhodni način v agentu

---

## Glas — kako spreminjam

**Po eno stikalo naenkrat.** To pravilo je plačano: 17. 9. je devet hkratnih
sprememb glasovne poti poslabšalo klic in ugotoviti se ni dalo, katera je kriva.
Celotna pot je bila vrnjena na `b777fc8` in znova grajena po eni.

Postopek: odkomentiraj eno vrstico v `voice-agent/.env`, poženi
`lk agent update-secrets --secrets-file .env --overwrite`, opravi nekaj klicev,
primerjaj `E2E median` na nadzorni plošči LiveKit.

| Stikalo | Stanje | Cilja na |
|---|---|---|
| `PREDCASNO=1` | **vklopljeno** | LLM TTFT, 889 ms |
| `STT_PONUDNIK=azure` | izklopljeno | STT delay, 963 ms |
| `KONEC_MIN`, `KONEC_MAX` | izklopljeno | čakanje po koncu govora |
| `PREKIN_SEK`, `PREKIN_BESEDE` | izklopljeno | sekanje od šuma na liniji |
| `TTS_SAMPLE_RATE=16000` | izklopljeno | praskanje pri dolgih odgovorih |

Izmerjeno stanje in razčlenitev po korakih: [PROJEKT.md, razdelek 8](PROJEKT.md#8-kje-smo).
