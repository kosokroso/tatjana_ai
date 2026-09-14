<?php
/**
 * POST /ai/transcribe.php  (multipart/form-data, polje "audio")
 *
 * Pretvori posnetek govora v besedilo.
 * Izhod: { "success": true, "data": { "text": "Koliko stane spletna stran?" } }
 *
 * Jezik je izrecno nastavljen na slovenščino. Brez tega model jezik ugiba in
 * pri kratkih ali šumnih posnetkih pogosto zgreši — najpogosteje v hrvaščino.
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../tools/core/RateLimiter.php';
require_once __DIR__ . '/guard.php';

const NAJVECJA_VELIKOST = 20 * 1024 * 1024;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
aiApplyCors();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    odgovori(false, null, 'Dovoljena je samo metoda POST.', 405);
}

if (defined('REQUIRE_HTTPS') && REQUIRE_HTTPS && !aiIsHttps()) {
    odgovori(false, null, 'Zahtevana je povezava HTTPS.', 403);
}

if (!aiOriginAllowed()) {
    odgovori(false, null, 'Zahtevek ne prihaja z dovoljene strani.', 403);
}

$omejitev = aiRateLimitMessage('stt');
if ($omejitev !== null) {
    odgovori(false, null, $omejitev, 429);
}

if (OPENAI_API_KEY === '') {
    odgovori(false, null, 'Prepis govora ni nastavljen.', 500);
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    odgovori(false, null, 'Manjka posnetek.', 400);
}

if ($_FILES['audio']['size'] > NAJVECJA_VELIKOST) {
    odgovori(false, null, 'Posnetek je predolg.', 400);
}

$model = defined('STT_MODEL') && STT_MODEL !== '' ? STT_MODEL : 'gpt-4o-transcribe';
$jezik = defined('SPEECH_LANGUAGE') && SPEECH_LANGUAGE !== '' ? SPEECH_LANGUAGE : 'sl';

// Ime datoteke mora imeti končnico, ki jo OpenAI prepozna; brskalnik posname webm.
$koncnica = 'webm';
if (preg_match('/\.(webm|mp3|mp4|m4a|wav|ogg|mpeg|mpga)$/i', (string) $_FILES['audio']['name'], $m)) {
    $koncnica = strtolower($m[1]);
}

$curl = curl_init('https://api.openai.com/v1/audio/transcriptions');
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . OPENAI_API_KEY],
    CURLOPT_POSTFIELDS     => [
        'file'     => new CURLFile($_FILES['audio']['tmp_name'], (string) $_FILES['audio']['type'], 'posnetek.' . $koncnica),
        'model'    => $model,
        'language' => $jezik,
    ],
]);

$telo   = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$napaka = curl_error($curl);
curl_close($curl);

if ($telo === false) {
    error_log('transcribe.php: ' . $napaka);
    odgovori(false, null, 'Prepis ni uspel.', 502);
}

$dekodirano = json_decode((string) $telo, true);

if ($status < 200 || $status >= 300 || !is_array($dekodirano)) {
    error_log('transcribe.php: HTTP ' . $status . ' ' . substr((string) $telo, 0, 300));
    odgovori(false, null, 'Prepis ni uspel.', 502);
}

odgovori(true, ['text' => trim((string) ($dekodirano['text'] ?? ''))], null, 200);

/**
 * @param mixed $data
 */
function odgovori(bool $uspeh, $data, ?string $napaka, int $status): void
{
    http_response_code($status);
    echo json_encode(
        ['success' => $uspeh, 'data' => $data, 'error' => $napaka],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
