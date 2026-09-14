<?php
/**
 * POST /ai/speak.php   { "text": "Enostavna spletna stran je od 399 €." }
 *
 * Vrne posnetek govora (audio/mpeg). Ob napaki vrne JSON, zato odjemalec
 * preveri Content-Type odgovora.
 *
 * Besedilo pride iz odgovora asistenta, torej z našega strežnika — a ga vseeno
 * omejimo po dolžini, ker je končna točka javna in bi sicer kdo prek nje
 * pretvarjal poljubno dolga besedila na naš račun.
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../tools/core/RateLimiter.php';
require_once __DIR__ . '/guard.php';

const NAJVEC_ZNAKOV = 1500;

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
aiApplyCors();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    napaka('Dovoljena je samo metoda POST.', 405);
}

if (defined('REQUIRE_HTTPS') && REQUIRE_HTTPS && !aiIsHttps()) {
    napaka('Zahtevana je povezava HTTPS.', 403);
}

if (!aiOriginAllowed()) {
    napaka('Zahtevek ne prihaja z dovoljene strani.', 403);
}

$omejitev = aiRateLimitMessage('tts');
if ($omejitev !== null) {
    napaka($omejitev, 429);
}

if (OPENAI_API_KEY === '') {
    napaka('Sinteza govora ni nastavljena.', 500);
}

$vhod = json_decode((string) file_get_contents('php://input'), true);
$text = is_array($vhod) ? trim((string) ($vhod['text'] ?? '')) : '';

if ($text === '') {
    napaka('Manjka besedilo.', 400);
}

$text = mb_substr($text, 0, NAJVEC_ZNAKOV);

$model = defined('TTS_MODEL') && TTS_MODEL !== '' ? TTS_MODEL : 'gpt-4o-mini-tts';
$glas  = defined('TTS_VOICE') && TTS_VOICE !== '' ? TTS_VOICE : 'alloy';

$curl = curl_init('https://api.openai.com/v1/audio/speech');
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY,
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model'           => $model,
        'voice'           => $glas,
        'input'           => $text,
        'response_format' => 'mp3',
        // Navodilo glasu: brez tega model slovenščino bere z angleškim naglasom.
        'instructions'    => 'Govori v slovenščini, naravno in prijazno, z zmernim tempom. '
            . 'Si asistentka slovenskega podjetja, ki se pogovarja s stranko.',
    ], JSON_UNESCAPED_UNICODE),
]);

$telo   = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$cnapaka = curl_error($curl);
curl_close($curl);

if ($telo === false) {
    error_log('speak.php: ' . $cnapaka);
    napaka('Sinteza govora ni uspela.', 502);
}

if ($status < 200 || $status >= 300) {
    error_log('speak.php: HTTP ' . $status . ' ' . substr((string) $telo, 0, 300));
    napaka('Sinteza govora ni uspela.', 502);
}

header('Content-Type: audio/mpeg');
header('Content-Length: ' . strlen($telo));
echo $telo;
exit;

function napaka(string $sporocilo, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['success' => false, 'data' => null, 'error' => $sporocilo],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}
