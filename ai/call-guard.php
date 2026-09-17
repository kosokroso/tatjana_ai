<?php
/**
 * POST /ai/call-guard.php   (glava X-Tool-Secret)
 *
 * Vratar za telefonske klice. Agent vpraša ob začetku klica, ali sme naprej,
 * in ob koncu sporoči, koliko je klic trajal.
 *
 * Zakaj tu in ne v agentu: agent teče v LiveKit Cloud, kjer se replika lahko
 * kadar koli ustavi in zažene znova s praznim diskom. Števec v agentu bi se
 * vrnil na nič ravno takrat, ko bi bil najbolj potreben. Tu, na gostovanju,
 * datoteka preživi in jo vidijo vse replike hkrati.
 *
 * Dve dejanji:
 *   { "action": "start", "caller": "+38641234567" }
 *       -> { "allow": true,  "max_seconds": 600 }
 *       -> { "allow": false, "reason": "dnevna_meja_minut" }
 *   { "action": "end", "caller": "+38641234567", "seconds": 143 }
 *       -> { "ok": true }
 */

require __DIR__ . '/../bootstrap.php';

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

$telo = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($telo)) {
    odgovori(400, ['error' => 'Telo zahtevka ni veljaven JSON objekt.']);
}

$dejanje = is_string($telo['action'] ?? null) ? $telo['action'] : '';
$klicatelj = is_string($telo['caller'] ?? null) ? trim($telo['caller']) : '';

// Klicatelj lahko skrije številko. Takšni klici se štejejo skupaj pod eno
// oznako: brez tega bi bila skrita številka luknja, skozi katero bi šlo
// poljubno število klicev mimo meje na klicatelja.
if ($klicatelj === '') {
    $klicatelj = 'skrita-stevilka';
}

$meje = new CallLimits(
    LOG_DIR . '/klici',
    defined('CALL_MAX_SECONDS')     ? (int) CALL_MAX_SECONDS     : 600,
    defined('CALL_DAILY_MINUTES')   ? (int) CALL_DAILY_MINUTES   : 120,
    defined('CALL_MAX_PER_CALLER')  ? (int) CALL_MAX_PER_CALLER  : 10
);

$oznaka = CallLimits::oznaka($klicatelj);

if ($dejanje === 'start') {
    $razlog = $meje->zavrnitev($oznaka);
    if ($razlog !== null) {
        error_log('call-guard: klic zavrnjen (' . $razlog . ')');
        odgovori(200, ['allow' => false, 'reason' => $razlog]);
    }
    odgovori(200, ['allow' => true, 'max_seconds' => $meje->maxSecondsPerCall()]);
}

if ($dejanje === 'end') {
    $sekund = (int) ($telo['seconds'] ?? 0);
    // Zgornja meja pri zapisu je nujna: brez nje bi napaka ali podtaknjena
    // vrednost v enem zahtevku porabila celo dnevno kapico.
    $sekund = max(0, min($sekund, 4 * 3600));
    $meje->zabelezi($oznaka, $sekund);
    odgovori(200, ['ok' => true]);
}

odgovori(400, ['error' => 'Neznano dejanje. Pricakujem start ali end.']);

function odgovori(int $status, array $podatki): void
{
    http_response_code($status);
    echo json_encode($podatki, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
