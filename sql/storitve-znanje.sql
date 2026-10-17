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
 'Oblikujemo in prenavljamo spletne strani, delamo optimizacijo za AI iskalnike, da podjetje omenijo ChatGPT, Gemini in podobni pomočniki, SEO optimizacijo za Google, marketing in oglaševanje na družbenih omrežjih, ponujamo svojo platformo NeRo za vodenje projektov v manjših in velikih podjetjih, ter fotografiramo in pripravljamo oglasno gradivo. Delamo tudi druge AI rešitve, na primer glasovne agente, kakršna sem jaz.',
 'storitve, kaj ponujate, kaj delate, ponudba, področja, podrocja, dejavnost, s čim se ukvarjate, kaj vse delate, spletne strani, seo, ai optimizacija, marketing, fotografiranje', 1),

('Kaj je spletna stran oziroma spletna trgovina in kaj izdelava zajema?',
 'Spletna stran je vaša predstavitev na internetu, spletna trgovina pa stran, prek katere stranke tudi kupijo. Delamo ju na WordPressu in WooCommerce. Vključeni so hitrost in tehnična SEO optimizacija, prilagoditev mobilnim napravam, varnostno spremljanje in vzdrževanje, povezave s poslovnimi orodji ter optimizacija za AI iskalnike. Obseg in ceno potrdimo v ponudbi. Stranki koristi tako, da obiskovalec hitro najde, kar išče, in se laže odloči za stik.',
 'spletna stran, izdelava strani, spletna trgovina, wordpress, woocommerce, kaj vključuje, vkljuceno, mobilna, hitrost, vzdrževanje, vzdrzevanje, integracije, crm', 1),

('Kaj je SEO optimizacija?',
 'SEO je optimizacija strani za iskalnike, se pravi delo, da vas ljudje najdejo, ko v Googlu iščejo to, kar ponujate. Pri nas to pomeni tehnično plat strani, torej hitrost, strukturo in dostopnost, urejeno vsebino ter lokalno vidnost, na primer storitev in kraj, kot je Goriška. Delo spremljamo in poročamo, kaj se je spremenilo. Koristi vam tako, da vas najdejo ljudje, ki vašo storitev že iščejo, brez plačanih oglasov.',
 'seo, optimizacija, google, iskalnik, vidnost, uvrstitev, organski obisk, lokalna vidnost, tehnična optimizacija, tehnicna', 1),

('Kaj je AI optimizacija spletne strani?',
 'AI optimizacija je isto za umetno inteligenco, kar je SEO za Google: poskrbimo, da vas ChatGPT, Gemini, Copilot in podobni pomočniki poznajo in omenijo, ko jih kdo vpraša za vašo storitev. Pravimo ji tudi GEO. Zajema strukturirane podatke in jasno vsebino, ki jo AI razume, lokalno vidnost za storitev in kraj, omembe in mnenja na virih, ki jih AI upošteva, ter spremljanje, kje in kako AI omenja podjetje. Koristi vam zato, ker vse več ljudi vpraša AI namesto Googla in tam vas mora imeti kje najti.',
 'ai optimizacija, umetna inteligenca, geo, generative engine optimization, chatgpt, gemini, copilot, perplexity, grok, ai iskalnik, da nas priporoči, priporoca, strukturirani podatki, omembe', 1),

('Ali optimizirate stran tudi za ChatGPT, Gemini ali druge AI pomočnike?',
 'Da, to je ena naših storitev. Poskrbimo, da je vsebina strani zapisana tako, da jo AI pomočniki razumejo in podjetje navedejo med odgovori, in spremljamo, kje se ta omemba pojavi. Tako vas AI navede tudi takrat, ko stranka sploh ne odpre iskalnika.',
 'chatgpt, gemini, copilot, perplexity, grok, ai pomočnik, pomocnik, ai iskalnik, umetna inteligenca, optimizacija za ai, navaja, priporoča, priporoca, geo', 1),

('Kaj je marketing in oglaševanje na družbenih omrežjih?',
 'Marketing pri nas pomeni plačano oglaševanje, torej da vaš oglas vidijo prav tisti ljudje, ki jih storitev zanima. Vodimo kampanje na Facebooku in Instagramu z natančnim ciljanjem, pa tudi Google kampanje Search, Display in Performance Max. Pripravimo vizuale, videe in besedila, vsak mesec pa dobite poročilo z rezultati in predlogi za naprej. Koristi vam, kadar morate do ljudi, ki vas še ne poznajo, priti hitro in načrtno.',
 'marketing, oglaševanje, oglasevanje, družbena omrežja, druzbena omrezja, facebook, instagram, meta, google ads, kampanje, oglasi, targetiranje, poročila, porocila', 1),

('Kaj ponujate pri fotografiranju in videu?',
 'Gre za fotografsko in video produkcijo, torej posnamemo gradivo, ki ga potem uporabite na strani in v oglasih. Pripravimo fotografije in videe za oglaševanje, za spletno stran in za družbena omrežja. Doslej smo največ delali fotografije dogodkov in notranjosti. Koristi vam tako, da imate svoje gradivo namesto splošnih fotografij iz baze, kar se na strani in v oglasih takoj pozna.',
 'fotografiranje, fotografija, foto, video, snemanje, produkcija, dogodki, notranjost, posnetki', 1),

('Kakšne AI rešitve ponujate?',
 'AI rešitve so programi, ki prevzamejo ponavljajoče se delo. Ponujamo avtomatizacijo procesov, glasovne agente, takšne, kot sem jaz, ki sprejmejo klic namesto vas, AI asistente, analizo podatkov in integracije prek Make. Razvijamo tudi lasten izdelek NeRo Platform za vodenje projektov. Koristijo vam povsod, kjer se delo ponavlja, in vzamejo tisti del, ki požre čas.',
 'ai rešitve, ai resitve, umetna inteligenca, avtomatizacija, glasovni agent, asistent, analiza podatkov, make, integracije, nero, chatbot', 1),

('Kako poteka delo z vami?',
 'V štirih korakih. Najprej spoznavanje, kjer pogledamo cilje, stranke in konkurenco. Nato strategija s konkretnim načrtom in časovnico. Sledi izvedba, kjer sproti testiramo in optimiziramo. Na koncu poročanje, torej mesečno poročilo z rezultati in predlogi za naprej.',
 'potek dela, kako poteka, proces, koraki, faze, kako delate, sodelovanje, začetek, zacetek, strategija, izvedba, poročanje, porocanje', 1),

('V kakšnem času odgovorite na povpraševanje?',
 'Odgovorimo v enem delovnem dnevu. Prvi pogovor in ponudba sta brez obveznosti in brez skritih stroškov.',
 'odziv, odgovor, kdaj odgovorite, čakanje, cakanje, povpraševanje, povprasevanje, obveznost, skriti stroški, stroski', 1);
