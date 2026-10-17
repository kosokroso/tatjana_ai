-- ------------------------------------------------------------
-- AI storitve — katalog in baza znanja
--
-- Pripravljeno za oglasevalsko kampanjo (AI optimizacija in AI storitve).
-- Cene so povsod NULL, ker so po povprasevanju: asistentka tedaj pove
-- "cena po dogovoru" in ponudi pripravo ponudbe, zneska pa ne ugiba.
--
-- Kategorija ai-resitve mora biti ze dodana v ai/tool-definitions.json,
-- ProductTool.php in admin/storitve.php — sicer vrstice obstajajo, model pa
-- jih po kategoriji ne najde.
--
-- Zagon: phpMyAdmin, baza kreati02_wp_iuikg, zavihek SQL.
-- ------------------------------------------------------------

SET NAMES utf8mb4;

-- ---------- katalog ----------

INSERT INTO ai_products (name, category, unit, price_per_unit, price_from, stock_quantity, description, active) VALUES

('AI optimizacija spletne strani', 'ai-resitve', 'projekt', NULL, 0, 1,
 'Priprava strani in vsebine tako, da podjetje najdejo in omenijo AI pomočniki, kot so ChatGPT, Gemini, Copilot in Perplexity. Zajema strukturirane podatke, jasno vsebino, lokalno vidnost za storitev in kraj, omembe na virih, ki jih AI upošteva, ter spremljanje, kje se omemba pojavi. Cena je odvisna od obsega in stanja strani.', 1),

('Glasovni AI agent za telefon', 'ai-resitve', 'mesec', NULL, 0, 1,
 'Agent, ki sprejme klic, odgovarja na pogosta vprašanja o ponudbi in cenah iz vaše baze, zabeleži povpraševanje s kontaktom in po potrebi preveže na sodelavca. Dela tudi takrat, ko v pisarni ni nikogar. Cena je odvisna od obsega in števila klicev.', 1),

('AI asistent za spletno stran', 'ai-resitve', 'mesec', NULL, 0, 1,
 'Klepetalni asistent na vaši strani, ki odgovarja obiskovalcem iz vašega kataloga in baze znanja ter zbira povpraševanja. Uporablja isto znanje kot telefonski agent, zato sta odgovora enaka. Cena je odvisna od obsega.', 1),

('Avtomatizacija procesov in integracije', 'ai-resitve', 'projekt', NULL, 0, 1,
 'Povezovanje orodij, ki jih že uporabljate, prek Make, da se podatki prenašajo sami in se ponavljajoče se delo ne dela ročno. Na primer povpraševanje s strani naravnost v vaš sistem ali poročilo, ki nastane samo. Cena je odvisna od števila korakov in orodij.', 1);

-- ---------- baza znanja ----------

INSERT INTO ai_knowledge (question, answer, keywords, active) VALUES

('Po čem se AI optimizacija razlikuje od SEO?',
 'SEO skrbi, da vas najde Google, AI optimizacija pa, da vas pozna in omeni umetna inteligenca, na primer ChatGPT, Gemini ali Copilot. Prvo deluje na iskalni rezultat, drugo na odgovor, ki ga pomočnik napiše stranki. Smiselno je oboje, ker ljudje uporabljajo oboje.',
 'razlika, seo, ai optimizacija, geo, primerjava, kaj je bolje, oboje, chatgpt, gemini, copilot, perplexity, google', 1),

('Ali mi lahko zagotovite, da nas bo ChatGPT ali kateri drug AI priporočil?',
 'Tega vam ne morem obljubiti in vam tudi ne bi rada. Kaj AI pomočnik navede, se spreminja in ni pod našim nadzorom. Naredimo pa tisto, kar je v naši moči: da so podatki o vas jasni, strukturirani in na virih, ki jih AI upošteva, in spremljamo, kje se omemba pojavi.',
 'garancija, zagotovilo, obljuba, priporoči, priporoca, chatgpt, gemini, copilot, perplexity, grok, bomo prvi, rezultat, uspeh', 1),

('Kako merite, ali AI optimizacija deluje?',
 'Spremljamo, kje in kako umetna inteligenca omenja vaše podjetje, in to zberemo v mesečno poročilo skupaj s predlogi za naprej. Tako vidite premik, ne samo opravljeno delo.',
 'merjenje, meritve, rezultati, poročilo, porocilo, spremljanje, kako veste, učinek, ucinek, analitika', 1),

('Koliko stane AI optimizacija?',
 'Cena je po povpraševanju, ker je odvisna od obsega in od stanja vaše strani. Če mi poveste nekaj o podjetju in strani, zabeležim povpraševanje in sodelavec vam pripravi ponudbo.',
 'cena, koliko stane, cenik, ai optimizacija, geo, ponudba, strošek, strosek, plačilo, placilo', 1),

('Koliko stane glasovni agent ali AI asistent?',
 'Tudi to je po povpraševanju, ker je odvisno od obsega in od tega, koliko klicev ali pogovorov pričakujete. Zabeležim vaše podatke in sodelavec pripravi ponudbo.',
 'cena agenta, koliko stane agent, asistent cena, glasovni agent, klepetalni, cenik, naročnina, narocnina', 1),

('Kako dolgo traja AI optimizacija?',
 'Roka vam ne bi rada povedala na pamet, ker je odvisen od obsega in od tega, v kakšnem stanju je stran. Konkretno oceno dobite v ponudbi, skupaj z opisom korakov.',
 'kako dolgo, trajanje, rok, čas, cas, kdaj bo končano, koncano, koliko časa', 1),

('Ali delate AI optimizacijo tudi za stran, ki je niste naredili vi?',
 'Da, delamo tudi na obstoječih straneh, ne glede na to, kdo jih je izdelal. Najprej pogledamo, v kakšnem stanju je stran, nato povemo, kaj je smiselno narediti.',
 'obstoječa stran, obstojeca, tuja stran, nismo vaša stranka, druga agencija, prenova, optimizacija obstoječe', 1),

('Kaj potrebujete od nas za začetek?',
 'Za prvi pogovor zadošča, da vem, s čim se ukvarjate, kje delate in kaj bi radi dosegli. Za samo delo potem potrebujemo dostop do strani in podatke o storitvah; kaj točno, vam sodelavec napiše v ponudbi.',
 'kaj potrebujete, kaj rabite od nas, dostop, podatki, začetek, zacetek, priprava, kaj moram pripraviti', 1),

('Kaj je glasovni AI agent?',
 'To je asistent, ki sprejme telefonski klic namesto vas, takšen, kot sem jaz. Odgovarja na pogosta vprašanja o ponudbi in cenah, zabeleži povpraševanje s kontaktom in po potrebi preveže na sodelavca. Koristi vam tako, da noben klic ne ostane neodgovorjen, tudi ko ste na terenu.',
 'glasovni agent, telefonski agent, ai agent, asistent na telefonu, sprejema klice, tajnica, odzivnik, kaj si ti', 1),

('Ali AI agent zna klic prevezati na človeka?',
 'Da. Kadar vprašanje presega to, kar ima zapisano, ali kadar sogovornik želi človeka, klic preveže na sodelavca. Če ta ni dosegljiv, zabeleži kontakt in sodelavec pokliče nazaj.',
 'preveza, prevezati, človek, clovek, sodelavec, predaja klica, prenos klica, živa oseba, ziva', 1),

('V katerih jezikih deluje vaš glasovni agent?',
 'Ta, s katerim govorite, govori slovensko. Druge jezike je mogoče dodati, kaj je smiselno za vas, pa vam potrdi sodelavec.',
 'jeziki, jezik, slovensko, angleško, anglesko, večjezično, vecjezicno, tuji jezik, nemško', 1),

('Ali AI agent nadomesti zaposlenega?',
 'Ne. Prevzame ponavljajoča se vprašanja in klice, ki bi sicer ostali neodgovorjeni, zahtevnejše pa preda človeku. Smisel je, da se ljudje ukvarjajo s tistim, česar stroj ne zmore.',
 'nadomesti, zamenja zaposlenega, odpuščanje, odpuscanje, namesto človeka, namesto cloveka, zakaj agent', 1),

('Kaj je avtomatizacija procesov?',
 'Pomeni, da orodja, ki jih že uporabljate, povežemo med seboj, da se podatki prenašajo sami. Na primer povpraševanje s spletne strani samo pride v vaš sistem in sodelavec dobi obvestilo. Koristi vam povsod, kjer nekdo isti podatek prepisuje dvakrat.',
 'avtomatizacija, procesi, make, integracije, povezovanje orodij, prenos podatkov, ročno delo, rocno', 1),

('Kako je pri vaših AI storitvah z varstvom osebnih podatkov?',
 'Pogoje obdelave podatkov uredimo pisno, preden začnemo, in vam povemo, kaj se obdeluje in kje se hrani. Podrobnosti za vaš primer pripravi sodelavec. Zabeležim lahko vaš kontakt, da vas pokliče.',
 'gdpr, osebni podatki, varstvo podatkov, zasebnost, hramba, skladnost, pogodba, obdelava podatkov', 1);
