-- ------------------------------------------------------------
-- NeRo Platform — vnosi za bazo znanja (ai_knowledge)
--
-- Dva dela: vnosi v bazo znanja (ai_knowledge) in en vnos v katalog
-- (ai_products, kategorija ai-resitve).
--
-- Kategorija ai-resitve je hkrati dodana v ai/tool-definitions.json,
-- tools/implementations/ProductTool.php in admin/storitve.php. Brez teh treh
-- bi vnos v katalogu obstajal, model pa ga ne bi videl: tool-definitions.json
-- pove modelu, katere kategorije sploh so, ProductTool pa neznano ime zavrne.
-- Ta del torej terja deploy, vnosi v bazo ne.
--
-- Iskanje je LIKE po question, answer in keywords. Besede, krajse od stirih
-- znakov, iskalnik zavrze — "nero" ima natanko stiri in se obdrzi. Zadetek v
-- keywords steje dvojno, zato so tam sopomenke, nepregibane oblike in razlicice
-- brez sumnikov (spletni klepet jih pogosto ne vpise).
--
-- Odgovori so zapisani tako, kot jih Tatjana izgovori: s sumniki in brez
-- crkovanja spletnih naslovov.
--
-- Zagon: phpMyAdmin v cPanelu, baza kreati02_wp_iuikg, zavihek SQL.
-- Ce je predpona tabel drugacna od ai_, jo popravi v spodnjih stavkih.
-- ------------------------------------------------------------

SET NAMES utf8mb4;

INSERT INTO ai_knowledge (question, answer, keywords, active) VALUES

('Kaj je NeRo?',
 'NeRo je AI asistent za vodenje projektov, ki ga razvijamo pri Kreativnem spletu. Iz opisa projekta samodejno pripravi časovnico, proračun in razporeditev virov, nato pa vas vodi skozi projekt. Namenjen je tistim, ki imajo veliko projektov in malo časa za administracijo. Več je na strani neroflow.eu.',
 'nero, neroflow, nero platform, projektni asistent, vodenje projektov, projektno vodenje, orodje za projekte, platforma, umetna inteligenca, casovnica, proracun', 1),

('Kdo je razvil NeRo?',
 'NeRo razvijamo sami pri Kreativnem spletu in ga ponujamo kot svoj izdelek. Za vprašanja in povratne informacije je na voljo naslov info@kreativnisplet.si.',
 'nero, neroflow, razvijalec, kdo stoji za nero, avtor, lastnik, kreativni splet', 1),

('Je NeRo že na voljo in ga lahko preizkusim?',
 'NeRo je še v razvoju, registracija pa je že odprta in brezplačna ter vzame nekaj minut. Platformo razvijamo skupaj z uporabniki, zato njihove povratne informacije oblikujejo, kaj naredimo naprej. Poiščite NeRo Platform ali obiščite neroflow.eu.',
 'nero, neroflow, preizkus, preizkusiti, registracija, prijava, demo, beta, razvoj, na voljo, dostopno, brezplačno, brezplacno', 1),

('Koliko stane NeRo?',
 'Registracija je trenutno brezplačna, končnih cen pa še ne objavljamo. Ko bodo znane, jih sporočimo. Če želite, zabeležim vaše ime in kontakt in vas sodelavec obvesti, lahko pa pišete na info@kreativnisplet.si.',
 'nero, neroflow, cena, cenik, koliko stane, plačilo, placilo, naročnina, narocnina, paketi, brezplačno, brezplacno', 1),

('Za koga je NeRo primeren?',
 'Za samostojne podjetnike in freelancerje, ki vodijo projekte več strank hkrati, za mala in srednja podjetja, kjer ekipa potrebuje en skupen pregled nad proračuni in napredkom, in tudi za osebne projekte, od prenove doma do organizacije dogodka.',
 'nero, neroflow, komu je namenjen, primeren, uporabniki, podjetniki, samostojni podjetnik, freelancer, ekipa, podjetja, osebni projekti', 1),

('Kaj so glavne funkcije NeRo?',
 'Načrt z enim klikom pripravi časovnico, proračun in razporeditev virov brez ročnega vnašanja. AI projektni vodja prevzame administracijo in sproti odpravlja zastoje. Simulacijski način omogoča preizkus sprememb. Nadzorna plošča pokaže vse projekte hkrati. Na voljo so tudi povzetki projektov, pregled tveganj, finance in trezor za dokumente.',
 'nero, neroflow, funkcije, kaj zna, možnosti, moznosti, časovnica, casovnica, proračun, proracun, viri, nadzorna plošča, plosca, povzetki, tveganja, finance, dokumenti', 1),

('Kaj je simulacijski način v NeRo?',
 'Simulacijski način je varno okolje, nekakšen peskovnik, kjer spremenite potek projekta in vidite posledice, še preden spremembo uveljavite v pravem projektu.',
 'nero, neroflow, simulacija, simulacijski način, nacin, peskovnik, scenariji, preizkus sprememb, kaj če, kaj ce', 1),

('Ali lahko v NeRo vodim več projektov hkrati?',
 'Da, to je ena glavnih zamisli. Nadzorna plošča prikaže proračune, prodajo in napredek vseh projektov na enem zaslonu in v realnem času.',
 'nero, neroflow, več projektov, vec projektov, hkrati, nadzorna plošča, plosca, pregled projektov, portfelj', 1),

('Ali lahko NeRo pokažem svoji stranki?',
 'Da. Vsaka organizacija dobi svojo kodo in API ključ, ki ju lahko deli s svojo stranko in ji tako omogoči dostop.',
 'nero, neroflow, dostop za stranke, deliti, pokazati stranki, koda, ključ, kljuc, povabilo', 1),

('Kje najdem NeRo in kako se registriram?',
 'Poiščite NeRo Platform ali obiščite neroflow.eu, kjer je povezava do brezplačne registracije. Stran je v slovenščini, angleščini in španščini, na njej pa je tudi video vodič, ki platformo prikaže. Če želite, vam povezavo pošlje sodelavec po elektronski pošti.',
 'nero, neroflow, kje najdem, spletna stran, naslov strani, registracija, prijava, video, vodič, vodic, jeziki, slovenščina, angleščina', 1),

('Kdaj izide končna različica NeRo?',
 'Datuma uradne izdaje še nimamo določenega, zato vam ga ne bi rada napačno povedala. Zabeležim lahko vaše ime in kontakt, da vas sodelavec obvesti, ali pa pišete na info@kreativnisplet.si.',
 'nero, neroflow, kdaj izide, izdaja, datum, končna različica, koncna razlicica, rok, objava', 1),

('Kako je pri NeRo poskrbljeno za varnost in hrambo podatkov?',
 'Tega nimam potrjenega in si ne bi izmišljala. Odgovor vam pripravi sodelavec. Zabeležim lahko vaše ime in kontakt ali pa pišete na info@kreativnisplet.si.',
 'nero, neroflow, varnost, podatki, hramba, zasebnost, gdpr, skladnost, zaupnost', 1),

('S katerimi orodji se NeRo povezuje?',
 'Natančnega seznama integracij še ne objavljamo, zato vam ne bi rada navedla napačnih. Sodelavec vam odgovori, če mi pustite ime in kontakt, lahko pa pišete na info@kreativnisplet.si.',
 'nero, neroflow, integracije, povezave, orodja, uvoz, izvoz, api, povezljivost', 1),

('Kakšna je podpora in kakšni so pogoji uporabe pri NeRo?',
 'Pogojev uporabe in obsega podpore še nimamo objavljenih, zato vam jih ne bi rada opisovala po občutku. Sodelavec vam to pojasni. Pišete lahko tudi na info@kreativnisplet.si.',
 'nero, neroflow, podpora, pomoč, pomoc, pogoji uporabe, sla, garancija, odzivni čas', 1),

('Kako lahko podam povratno informacijo o NeRo?',
 'Povratne informacije sprejemamo na info@kreativnisplet.si ali prek povezave na strani neroflow.eu. Prav te informacije oblikujejo, kaj bomo razvili naprej.',
 'nero, neroflow, povratna informacija, mnenje, predlog, pripomba, feedback, želja, zelja', 1),

('Katere storitve ponujate poleg izdelave spletnih strani?',
 'Poleg oblikovanja in prenove spletnih strani, optimizacije za AI iskalnike, SEO optimizacije, marketinga na družbenih omrežjih in fotografiranja ponujamo tudi svojo platformo NeRo za vodenje projektov. Še je v razvoju, registracija pa je brezplačna in odprta.',
 'ponudba, storitve, kaj ponujate, kaj še delate, izdelki, poleg spletnih strani, nero, neroflow, novosti', 1);


-- ------------------------------------------------------------
-- NeRo v katalogu (ai_products)
--
-- price_per_unit je NULL, ker koncne cene ni. Posledica: asistentka pove
-- "cena po dogovoru". Resnico o brezplacni registraciji nosi description, ki
-- gre modelu skupaj s ceno, in vnos "Koliko stane NeRo?" v bazi znanja.
-- ------------------------------------------------------------

INSERT INTO ai_products (name, category, unit, price_per_unit, price_from, stock_quantity, description, active) VALUES
('NeRo Platform', 'ai-resitve', 'mesec', NULL, 0, 1,
 'AI asistent za vodenje projektov, ki ga razvijamo sami. Iz opisa projekta pripravi časovnico, proračun in razporeditev virov ter vodi projekt. Je še v razvoju. Registracija na neroflow.eu je brezplačna, končne cene še niso objavljene.', 1);
