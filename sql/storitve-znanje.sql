-- ------------------------------------------------------------
-- Podrocja dela — vnosi za bazo znanja (ai_knowledge)
--
-- Vsebina je povzeta po kreativnisplet.si (17. 10. 2026): storitve, potek
-- dela v stirih korakih in zaveze. Cen tu ni — cene so v katalogu, ker jih
-- sme povedati samo orodje.
--
-- Vrstni red nastevanja ni tukaj, ampak v ai/system-prompt.txt, razdelek
-- "Podrocja dela". Baza znanja pove podrobnosti, prompt pove vrstni red.
--
-- Zagon: phpMyAdmin, baza kreati02_wp_iuikg, zavihek SQL.
-- ------------------------------------------------------------

SET NAMES utf8mb4;

INSERT INTO ai_knowledge (question, answer, keywords, active) VALUES

('Katere storitve ponujate?',
 'Delamo spletne strani in spletne trgovine, SEO optimizacijo, AI optimizacijo strani, da podjetje najdejo in priporočijo tudi ChatGPT in podobni pomočniki, marketing in oglaševanje na družbenih omrežjih, ter fotografiranje in video. Razvijamo tudi lastne AI rešitve, med njimi glasovne agente in NeRo Platform.',
 'storitve, kaj ponujate, kaj delate, ponudba, področja, podrocja, dejavnost, s čim se ukvarjate, kaj vse delate, spletne strani, seo, ai optimizacija, marketing, fotografiranje', 1),

('Kaj zajema izdelava spletne strani?',
 'Strani in trgovine delamo na WordPressu in WooCommerce. Vključeni so hitrost in tehnična SEO optimizacija, prilagoditev mobilnim napravam, varnostno spremljanje in vzdrževanje, povezave s CRM in drugimi poslovnimi orodji ter optimizacija za AI iskalnike. Obseg in ceno potrdimo v ponudbi.',
 'spletna stran, izdelava strani, spletna trgovina, wordpress, woocommerce, kaj vključuje, vkljuceno, mobilna, hitrost, vzdrževanje, vzdrzevanje, integracije, crm', 1),

('Kaj zajema SEO optimizacija?',
 'SEO pri nas pomeni tehnično plat strani, torej hitrost, strukturo in dostopnost, urejeno vsebino ter lokalno vidnost, na primer storitev in kraj, kot je Goriška. Delo spremljamo in poročamo, kaj se je spremenilo.',
 'seo, optimizacija, google, iskalnik, vidnost, uvrstitev, organski obisk, lokalna vidnost, tehnična optimizacija, tehnicna', 1),

('Kaj je AI optimizacija spletne strani?',
 'AI optimizaciji pravimo tudi GEO. Stran pripravimo tako, da jo umetna inteligenca razume in navaja: strukturirani podatki in jasna vsebina, lokalna vidnost za storitev in kraj, omembe in mnenja na virih, ki jih AI upošteva, ter spremljanje, kje in kako AI omenja podjetje.',
 'ai optimizacija, umetna inteligenca, geo, generative engine optimization, chatgpt, gemini, ai iskalnik, da nas priporoči, priporoca, strukturirani podatki, omembe', 1),

('Ali optimizirate stran tudi za ChatGPT in druge AI pomočnike?',
 'Da, to je ena naših storitev. Poskrbimo, da je vsebina strani zapisana tako, da jo AI pomočniki razumejo in podjetje navedejo med odgovori, in spremljamo, kje se ta omemba pojavi.',
 'chatgpt, gemini, ai pomočnik, pomocnik, ai iskalnik, umetna inteligenca, optimizacija za ai, navaja, priporoča, priporoca, geo', 1),

('Kaj zajema marketing in oglaševanje na družbenih omrežjih?',
 'Vodimo kampanje na Facebooku in Instagramu z natančnim targetiranjem, pa tudi Google kampanje Search, Display in Performance Max. Pripravimo vizuale, videe in besedila, vsak mesec pa dobite poročilo z rezultati in predlogi za naprej.',
 'marketing, oglaševanje, oglasevanje, družbena omrežja, druzbena omrezja, facebook, instagram, meta, google ads, kampanje, oglasi, targetiranje, poročila, porocila', 1),

('Kaj ponujate pri fotografiranju in videu?',
 'Pripravimo fotografije in videe za oglaševanje, za spletno stran in za družbena omrežja. Doslej smo največ delali fotografije dogodkov in notranjosti.',
 'fotografiranje, fotografija, foto, video, snemanje, produkcija, dogodki, notranjost, posnetki', 1),

('Kakšne AI rešitve ponujate?',
 'Ponujamo avtomatizacijo procesov, glasovne agente, takšne, kot sem jaz, AI asistente, analizo podatkov in integracije prek Make. Razvijamo tudi lasten izdelek NeRo Platform za vodenje projektov.',
 'ai rešitve, ai resitve, umetna inteligenca, avtomatizacija, glasovni agent, asistent, analiza podatkov, make, integracije, nero, chatbot', 1),

('Kako poteka delo z vami?',
 'V štirih korakih. Najprej spoznavanje, kjer pogledamo cilje, stranke in konkurenco. Nato strategija s konkretnim načrtom in časovnico. Sledi izvedba, kjer sproti testiramo in optimiziramo. Na koncu poročanje, torej mesečno poročilo z rezultati in predlogi za naprej.',
 'potek dela, kako poteka, proces, koraki, faze, kako delate, sodelovanje, začetek, zacetek, strategija, izvedba, poročanje, porocanje', 1),

('V kakšnem času odgovorite na povpraševanje?',
 'Odgovorimo v enem delovnem dnevu. Prvi pogovor in ponudba sta brez obveznosti in brez skritih stroškov.',
 'odziv, odgovor, kdaj odgovorite, čakanje, cakanje, povpraševanje, povprasevanje, obveznost, skriti stroški, stroski', 1);
