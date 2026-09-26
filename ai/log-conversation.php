<?php
/**
 * POST /ai/log-conversation.php   (glava X-Tool-Secret)
 *
 * Telefonski agent ob koncu klica sem pošlje potek pogovora.
 *
 * Zakaj obstaja: telefonski agent teče v LiveKit Cloud in kliče `tools/*.php`
 * neposredno, zato se je v dnevnik podjetja zapisalo, katera orodja je klical,
 * ne pa, kaj je povedal. `admin/pogovori.php` je zato pokrival samo klepet na
 * strani. Stranka, ki plačuje mesečno vzdrževanje, hoče videti, kaj je
 * asistentka povedala njenim klicateljem — in "poglej v LiveKit" ni odgovor.
 *
 * Zapis gre skozi isti Logger kot klepet, v isti dnevnik in v isti obliki, zato
 * `admin/pogovori.php` telefonske pogovore prikaže brez spremembe: vključno z
 * oznako težave, kadar je asistentka o ceni odgovorila brez orodja.
 *
 * Telo:
 *   {
 *     "call_id": "AJ_...",
 *     "source":  "telefon",
 *     "turns": [
 *       { "user_message": "...", "assistant_message": "...", "tool_calls": ["product-lookup"] }
 *     ]
 *   }
 */

declare(strict_types=1);

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

// Cel pogovor je lahko dolg, a ne poljubno dolg. Brez meje bi en sam zahtevek
// lahko napolnil disk gostovanja.
$surovo = (string) file_get_contents('php://input');
if (strlen($surovo) > 262144) {
    odgovori(413, ['error' => 'Zapis pogovora je prevelik.']);
}

$telo = json_decode($surovo, true);
if (!is_array($telo) || !is_array($telo['turns'] ?? null)) {
    odgovori(400, ['error' => 'Pričakujem objekt s poljem turns.']);
}

$vir    = is_string($telo['source'] ?? null) ? substr($telo['source'], 0, 20) : 'telefon';
$klicID = is_string($telo['call_id'] ?? null) ? substr($telo['call_id'], 0, 64) : '-';

$zapisanih = 0;
foreach (array_slice($telo['turns'], 0, 100) as $obrat) {
    if (!is_array($obrat)) {
        continue;
    }

    $vprasanje = trim((string) ($obrat['user_message'] ?? ''));
    $odgovor   = trim((string) ($obrat['assistant_message'] ?? ''));

    // Obrat brez ene in druge strani ni pogovor. Prazne vrstice bi samo
    // razredčile pregled in otežile iskanje pravih težav.
    if ($vprasanje === '' && $odgovor === '') {
        continue;
    }

    $orodja = [];
    if (is_array($obrat['tool_calls'] ?? null)) {
        foreach (array_slice($obrat['tool_calls'], 0, 10) as $ime) {
            if (is_string($ime)) {
                $orodja[] = substr($ime, 0, 40);
            }
        }
    }

    // Logger sam zamaskira telefonske številke in e-pošto, zato se sem sme
    // poslati, kar je stranka res povedala.
    $registry->logger()->logConversation([
        'request_id'        => $klicID,
        'source'            => $vir,
        'user_message'      => mb_substr($vprasanje, 0, 4000),
        'assistant_message' => mb_substr($odgovor, 0, 4000),
        'tool_calls'        => $orodja,
    ]);

    $zapisanih++;
}

odgovori(200, ['ok' => true, 'written' => $zapisanih]);

function odgovori(int $status, array $podatki): void
{
    http_response_code($status);
    echo json_encode($podatki, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
