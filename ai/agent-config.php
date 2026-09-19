<?php
/**
 * POST /ai/agent-config.php   (glava X-Tool-Secret)
 *
 * Vrne sistemski prompt in podatke podjetja glasovnemu agentu, ki teče drugje
 * (LiveKit Cloud, VPS). Agent si prompta ne nosi s sabo — če bi ga, bi se
 * besedilni in glasovni asistent sčasoma razšla in bi vsako spremembo branda
 * bilo treba narediti na dveh mestih.
 *
 * Zaščiteno s TOOL_SECRET: prompt razkriva, kako je asistent nastavljen, in
 * je zato koristen vsakomur, ki bi ga hotel pretentati.
 */

$registry = require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    odgovori(405, ['error' => 'Dovoljena je samo metoda POST.']);
}

$skrivnost = defined('TOOL_SECRET') ? TOOL_SECRET : '';
$podana    = $_SERVER['HTTP_X_TOOL_SECRET'] ?? '';

if ($skrivnost === '' || !is_string($podana) || !hash_equals($skrivnost, $podana)) {
    odgovori(401, ['error' => 'Neveljavna skrivnost.']);
}

$prompt = @file_get_contents(SYSTEM_PROMPT_FILE);
if ($prompt === false) {
    odgovori(500, ['error' => 'Sistemskega prompta ni mogoče prebrati.']);
}

$ime      = defined('BUSINESS_NAME') ? BUSINESS_NAME : '';
$rodilnik = defined('BUSINESS_NAME_RODILNIK') && BUSINESS_NAME_RODILNIK !== ''
    ? BUSINESS_NAME_RODILNIK
    : $ime;
$mestnik  = defined('BUSINESS_NAME_MESTNIK') && BUSINESS_NAME_MESTNIK !== ''
    ? BUSINESS_NAME_MESTNIK
    : $ime;

$prompt = strtr($prompt, [
    '{BUSINESS_PHONE}'       => defined('BUSINESS_PHONE')       ? BUSINESS_PHONE       : '',
    '{BUSINESS_EMAIL}'       => defined('BUSINESS_EMAIL')       ? BUSINESS_EMAIL       : '',
    '{BUSINESS_NAME}'        => $ime,
    '{BUSINESS_NAME_RODILNIK}' => $rodilnik,
    '{BUSINESS_NAME_MESTNIK}'  => $mestnik,
    '{BUSINESS_DESCRIPTION}' => defined('BUSINESS_DESCRIPTION') ? BUSINESS_DESCRIPTION : '',
    '{ASSISTANT_NAME}'       => defined('ASSISTANT_NAME')       ? ASSISTANT_NAME       : 'asistent',
]);

// Po telefonu veljajo drugačna pravila kot v klepetu: sogovornik ne vidi
// besedila, zato mora asistent ponoviti, kar je slišal, in govoriti krajše.
$prompt .= "\n\n## Posebnosti telefonskega pogovora\n"
    . "- Stranka tvojih odgovorov ne vidi zapisanih, zato govori kratko: en do dva stavka naenkrat.\n"
    . "- Ko ti stranka pove telefonsko številko ali e-pošto, ju ponovi nazaj po delih in počakaj na potrditev. "
    . "Napačno slišan naslov pomeni izgubljeno ponudbo.\n"
    . "- Če česa ne slišiš dobro, prosi, naj ponovi, namesto da ugibaš.\n"
    . "- Ne naštevaj več kot dveh storitev naenkrat. Po telefonu si tretje nihče ne zapomni.\n";

$now = new DateTimeImmutable('now');
$prompt .= "\n## Trenutni čas\nDanes je " . SlovenianDate::longDate($now) . ' ' . $now->format('Y')
    . ', ura je ' . $now->format('H:i') . ".\n";

// Besede, na katere naj prepis pazi.
//
// Po telefonu je zvok 8 kHz in imena storitev ter blagovne znamke so prav tiste
// besede, ki jih prepis najpogosteje zgreši - in hkrati edine, na katerih je
// odvisen odgovor. Seznam pride iz kataloga, ne iz kode, da velja za vsako
// stranko brez posega v agenta.
$kljucne = [];
if (defined('BUSINESS_NAME') && BUSINESS_NAME !== '') {
    $kljucne[] = BUSINESS_NAME;
}
try {
    foreach ($registry->adapter()->listProducts() as $storitev) {
        if (!empty($storitev['active'])) {
            $kljucne[] = (string) $storitev['name'];
            if (!empty($storitev['category'])) {
                $kljucne[] = (string) $storitev['category'];
            }
        }
    }
} catch (Throwable $e) {
    // Prepis brez seznama deluje, le malo slabse. Katalog, ki ne odgovori, ne
    // sme pomeniti, da se agent ne zazene.
    error_log('agent-config: kljucnih besed ni bilo mogoce prebrati: ' . $e->getMessage());
}

// Ponovitve stanejo prostor v pozivu in ne prinesejo nic. Meja je pri 60, ker
// predolg seznam prepis zacne vleci k tem besedam tudi tam, kjer jih ni.
$kljucne = array_slice(array_values(array_unique(array_filter($kljucne))), 0, 60);

odgovori(200, [
    'assistant_name' => defined('ASSISTANT_NAME') ? ASSISTANT_NAME : 'asistent',
    'stt_keywords'   => $kljucne,
    'business_name'  => defined('BUSINESS_NAME')  ? BUSINESS_NAME  : '',
    'business_phone' => defined('BUSINESS_PHONE') ? BUSINESS_PHONE : '',
    'business_email' => defined('BUSINESS_EMAIL') ? BUSINESS_EMAIL : '',
    'system_prompt'  => $prompt,
    'greeting'       => 'Pozdravljeni, tukaj ' . (defined('ASSISTANT_NAME') ? ASSISTANT_NAME : 'asistent')
        . ' iz ' . ($rodilnik !== '' ? $rodilnik : 'podjetja') . '. Kako vam lahko pomagam?',
]);

function odgovori(int $status, array $podatki): void
{
    http_response_code($status);
    echo json_encode($podatki, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
