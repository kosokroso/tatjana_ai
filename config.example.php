<?php
/**
 * Predloga konfiguracije.
 *
 * Kopiraj v config.php in vpiši prave vrednosti:
 *     cp config.example.php config.php
 *
 * config.php NIKOLI ne gre v git (glej .gitignore) — vsebuje gesla in API ključ.
 */

// ---------------------------------------------------------------
// Časovni pas — shared hosting pogosto teče v UTC, kar bi pokvarilo
// izračun "ali je zdaj odprto" in datume dostave.
// ---------------------------------------------------------------
define('TIMEZONE', 'Europe/Ljubljana');

// ---------------------------------------------------------------
// Baza
// ---------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'asistent_test');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------
// Adapter — določa, od kod tooli berejo podatke.
// Zamenjaš samo to vrstico, ko preideš na drug ERP.
//   'DirectMySQLAdapter' — testna/produkcijska MySQL baza
//   'VascoAdapter'       — Vasco ERP (še ni implementiran)
// ---------------------------------------------------------------
define('ADAPTER', 'DirectMySQLAdapter');

// ---------------------------------------------------------------
// Tooli, ki so dovoljeni prek HTTP. Karkoli drugega vrne 404.
// ---------------------------------------------------------------
define('ALLOWED_TOOLS', ['product-lookup', 'order-lookup', 'business-info']);

// ---------------------------------------------------------------
// Logging
// ---------------------------------------------------------------
define('LOG_DIR', __DIR__ . '/logs');
// Maskiraj telefonske številke in e-pošto v logih (GDPR). Izklopi samo
// začasno pri razhroščevanju, nikoli v produkciji.
define('LOG_MASK_PII', true);
// Koliko dni hraniti loge. Starejši se samodejno brišejo.
define('LOG_RETENTION_DAYS', 14);

// ---------------------------------------------------------------
// Rate limiting (na IP, na minuto). Velja samo za HTTP klice od zunaj.
// Chat backend kliče toole neposredno v PHP, zato ga to ne omejuje.
// ---------------------------------------------------------------
define('RATE_LIMIT_PER_MINUTE', 60);

// Chat endpoint je strožji, ker vsak klic stane pri OpenAI.
define('CHAT_RATE_LIMIT_PER_MINUTE', 20);

// ---------------------------------------------------------------
// Skupna skrivnost za strežnik-na-strežnik klice (kasnejši glasovni sloj).
// Klic z glavo "X-Tool-Secret: <vrednost>" preskoči rate limit.
// Generiraj naključen niz, npr.: bin2hex(random_bytes(24))
// Pusti prazno, da je funkcija izklopljena.
// ---------------------------------------------------------------
define('TOOL_SECRET', '');

// ---------------------------------------------------------------
// Zahtevaj HTTPS. Na lokalnem razvoju false, na hostingu true.
// ---------------------------------------------------------------
define('REQUIRE_HTTPS', false);

// ---------------------------------------------------------------
// OpenAI — za chat sloj (/ai)
// ---------------------------------------------------------------
define('OPENAI_API_KEY', '');
define('OPENAI_MODEL', 'gpt-4o');
define('OPENAI_TIMEOUT_SECONDS', 20);

define('SYSTEM_PROMPT_FILE',    __DIR__ . '/ai/system-prompt.txt');
define('TOOL_DEFINITIONS_FILE', __DIR__ . '/ai/tool-definitions.json');

// Koliko krogov tool callinga dovolimo v enem odgovoru, preden prekinemo.
define('MAX_TOOL_ROUNDS', 4);

// Dovoljeni izvori za chat endpoint (CORS). Prazno = samo isti izvor.
define('CHAT_ALLOWED_ORIGINS', []);

// ---------------------------------------------------------------
// Podatki o poslovanju, ki niso v bazi (območja dostave, načini plačila).
// Ureja jih lahko podjetje samo, brez posega v kodo.
// ---------------------------------------------------------------
define('BUSINESS_INFO_FILE', __DIR__ . '/data/business-info.json');

// ---------------------------------------------------------------
// Podatki podjetja — vstavijo se v sistemski prompt in v odgovore.
// Sistemski prompt (ai/system-prompt.txt) nima nikjer vpisanega imena
// podjetja; vse pride od tu, zato se ista koda uporabi za drugo stranko
// s spremembo teh vrstic.
// ---------------------------------------------------------------
define('BUSINESS_NAME',  'Tatjana');
define('BUSINESS_PHONE', '+386 5 123 45 67');
define('BUSINESS_EMAIL', 'info@example.si');

// Ena do dve povedi: s čim se podjetje ukvarja in kje posluje.
define('BUSINESS_DESCRIPTION', 'Podjetje prodaja drva, pelete in brikete.');
