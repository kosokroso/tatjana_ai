<?php
/**
 * POST /ai/speak.php   { "text": "Enostavna spletna stran je od 399 €." }
 *
 * Vrne posnetek govora (audio/mpeg). Ob napaki vrne JSON, zato odjemalec
 * preveri Content-Type odgovora.
 *
 * Podprta sta dva ponudnika (TTS_PROVIDER):
 *   'azure'  — prava slovenska glasova (sl-SI-PetraNeural, sl-SI-RokNeural).
 *              Ker je glas slovenski, pravilno prebere števila in cene, prek
 *              SSML pa zna telefonsko številko prebrati po števkah.
 *   'openai' — večjezični model, ki slovenščino bere s tujim naglasom in
 *              telefonskih številk ne izgovori pravilno. Nima SSML.
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

$vhod = json_decode((string) file_get_contents('php://input'), true);
$text = is_array($vhod) ? trim((string) ($vhod['text'] ?? '')) : '';

if ($text === '') {
    napaka('Manjka besedilo.', 400);
}

$text = mb_substr($text, 0, NAJVEC_ZNAKOV);
$ponudnik = defined('TTS_PROVIDER') && TTS_PROVIDER !== '' ? TTS_PROVIDER : 'openai';

[$zvok, $status, $cnapaka] = $ponudnik === 'azure'
    ? azureGovor(normalizirajZaGovor($text))
    : openaiGovor(normalizirajZaGovor($text));

if ($zvok === false) {
    error_log('speak.php (' . $ponudnik . '): ' . $cnapaka);
    napaka('Sinteza govora ni uspela.', 502);
}

if ($status < 200 || $status >= 300) {
    error_log('speak.php (' . $ponudnik . '): HTTP ' . $status . ' ' . substr((string) $zvok, 0, 300));
    napaka('Sinteza govora ni uspela.', 502);
}

header('Content-Type: audio/mpeg');
header('Content-Length: ' . strlen($zvok));
echo $zvok;
exit;

// --------------------------------------------------------------------

/**
 * Popravki, ki jih potrebuje vsak ponudnik.
 *
 * To je namenoma koda in ne navodilo modelu: pretvorba je vedno enaka in
 * nikoli ne sme biti odvisna od tega, ali se je model ta hip domislil pravilne
 * oblike. Prav pri cenah nas je to že enkrat stalo napačnega zneska.
 */
function normalizirajZaGovor(string $text): string
{
    // "399,00 €" -> "399 €": stotini brez vrednosti bi se prebrali kot "cela nič nič".
    $text = preg_replace('/(\d),00(?=\s*€)/u', '$1', $text);
    // Tisočiška pika moti branje: "1.100" -> "1100".
    $text = preg_replace('/(\d)\.(\d{3})\b/u', '$1$2', $text);
    // Simbol valute glasovi berejo različno, beseda je zanesljiva.
    $text = str_replace('€', 'evrov', $text);

    return $text;
}

/**
 * Azure: besedilo v SSML. Telefonske številke označimo, da jih glas prebere
 * po števkah — sicer jih prebere kot en velik znesek.
 */
function vSsml(string $text, string $glas, string $jezik): string
{
    $deli = preg_split(
        '/((?:\+386[\s\-]?|0)\d{1,2}[\s\-\/]?\d{3}[\s\-\/]?\d{3})/u',
        $text,
        -1,
        PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
    ) ?: [$text];

    $vsebina = '';
    foreach ($deli as $del) {
        $ubezen = htmlspecialchars($del, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $vsebina .= preg_match('/^(?:\+386[\s\-]?|0)\d/u', $del)
            ? '<say-as interpret-as="telephone">' . $ubezen . '</say-as>'
            : $ubezen;
    }

    return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="' . $jezik . '">'
        . '<voice name="' . htmlspecialchars($glas, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '">'
        . '<prosody rate="+5%">' . $vsebina . '</prosody>'
        . '</voice></speak>';
}

/** @return array{0: string|false, 1: int, 2: string} */
function azureGovor(string $text): array
{
    $kljuc  = defined('AZURE_SPEECH_KEY')    ? AZURE_SPEECH_KEY    : '';
    $regija = defined('AZURE_SPEECH_REGION') ? AZURE_SPEECH_REGION : '';
    $glas   = defined('AZURE_TTS_VOICE') && AZURE_TTS_VOICE !== '' ? AZURE_TTS_VOICE : 'sl-SI-PetraNeural';
    $jezik  = defined('SPEECH_LANGUAGE') && SPEECH_LANGUAGE !== '' ? SPEECH_LANGUAGE : 'sl';

    if ($kljuc === '' || $regija === '') {
        napaka('Azure za sintezo govora ni nastavljen.', 500);
    }

    $curl = curl_init('https://' . $regija . '.tts.speech.microsoft.com/cognitiveservices/v1');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Ocp-Apim-Subscription-Key: ' . $kljuc,
            'Content-Type: application/ssml+xml',
            'X-Microsoft-OutputFormat: audio-24khz-48kbitrate-mono-mp3',
            'User-Agent: kreativnisplet-asistent',
        ],
        CURLOPT_POSTFIELDS => vSsml($text, $glas, $jezik === 'sl' ? 'sl-SI' : $jezik),
    ]);

    $telo   = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err    = curl_error($curl);
    curl_close($curl);

    return [$telo, $status, $err];
}

/** @return array{0: string|false, 1: int, 2: string} */
function openaiGovor(string $text): array
{
    if (OPENAI_API_KEY === '') {
        napaka('Sinteza govora ni nastavljena.', 500);
    }

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
            'instructions'    => 'Govori v slovenščini, naravno in prijazno, z zmernim tempom. '
                . 'Telefonske številke beri po števkah. '
                . 'Si asistentka slovenskega podjetja, ki se pogovarja s stranko.',
        ], JSON_UNESCAPED_UNICODE),
    ]);

    $telo   = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err    = curl_error($curl);
    curl_close($curl);

    return [$telo, $status, $err];
}

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
