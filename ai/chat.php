<?php
/**
 * POST /ai/chat.php
 *
 * Vhod:  { "messages": [ { "role": "user", "content": "Koliko stanejo pelete?" } ] }
 * Izhod: { "success": true, "data": { "reply": "...", "tools_used": ["search_products"] } }
 *
 * Pogovor vodi odjemalec (brskalnik) in ga ob vsakem vprašanju pošlje v celoti.
 * Strežnik sam doda sistemski prompt — tega odjemalec ne more spreminjati.
 *
 * Tooli se kličejo NEPOSREDNO prek ToolRegistry, ne prek HTTP. Tako ni
 * dodatnega omrežnega skoka in interni klici ne trošijo rate limita.
 */

$registry = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/OpenAIClient.php';
require_once __DIR__ . '/../tools/core/RateLimiter.php';

/** Imena orodij, kot jih pozna model -> imena toolov v registru. */
const TOOL_NAME_MAP = [
    'search_products'   => 'product-lookup',
    'lookup_order'      => 'order-lookup',
    'get_business_info' => 'business-info',
];

const MAX_HISTORY_MESSAGES = 20;
const MAX_MESSAGE_LENGTH   = 2000;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
applyCors();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    respond(false, null, 'Dovoljena je samo metoda POST.', 405);
}

// Pozor: $_SERVER['HTTPS'] je na nekaterih strežnikih niz 'off' — empty('off')
// je false, zato preverjanje s samim empty() povezavo napačno razglasi za varno.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

if (defined('REQUIRE_HTTPS') && REQUIRE_HTTPS && !$isHttps) {
    respond(false, null, 'Zahtevana je povezava HTTPS.', 403);
}

// Vsak klic tu stane denar, zato je omejitev strožja kot pri toolih.
$limiter = new RateLimiter(
    LOG_DIR . '/ratelimit',
    defined('CHAT_RATE_LIMIT_PER_MINUTE') ? CHAT_RATE_LIMIT_PER_MINUTE : 20
);
if (!$limiter->allow('chat:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
    header('Retry-After: 60');
    respond(false, null, 'Preveč sporočil zapored. Poskusite čez minuto.', 429);
}

$requestId = bin2hex(random_bytes(6));
$history   = readHistory();

if (!$history) {
    respond(false, null, 'Ni sporočil za obdelavo.', 400);
}

$lastMessage = $history[count($history) - 1]['content'];

try {
    $client = new OpenAIClient(
        OPENAI_API_KEY,
        OPENAI_MODEL,
        defined('OPENAI_TIMEOUT_SECONDS') ? OPENAI_TIMEOUT_SECONDS : 20
    );
    $tools = loadToolDefinitions();

    $messages   = array_merge([['role' => 'system', 'content' => buildSystemPrompt()]], $history);
    $toolsUsed  = [];
    $maxRounds  = defined('MAX_TOOL_ROUNDS') ? MAX_TOOL_ROUNDS : 4;
    $reply      = null;

    for ($round = 0; $round < $maxRounds; $round++) {
        $message = $client->chat($messages, $tools);

        // Nazaj pošljemo samo polja, ki jih API pričakuje — odgovor lahko
        // vsebuje tudi dodatke (refusal, annotations), ki bi klic podrli.
        $assistantTurn = ['role' => 'assistant', 'content' => $message['content'] ?? null];
        if (!empty($message['tool_calls'])) {
            $assistantTurn['tool_calls'] = $message['tool_calls'];
        }
        $messages[] = $assistantTurn;

        if (empty($message['tool_calls'])) {
            $reply = trim((string) ($message['content'] ?? ''));
            break;
        }

        foreach ($message['tool_calls'] as $call) {
            $toolName    = $call['function']['name'] ?? '';
            $toolsUsed[] = $toolName;

            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $call['id'] ?? '',
                'content'      => runTool($registry, $toolName, $call['function']['arguments'] ?? '{}', $requestId),
            ];
        }
    }

    if ($reply === null || $reply === '') {
        // Model se je zavrtel v klicanju orodij ali vrnil prazen odgovor.
        $reply = fallbackReply();
    }

    $registry->logger()->logConversation([
        'request_id'        => $requestId,
        'user_message'      => $lastMessage,
        'tool_calls'        => $toolsUsed,
        'assistant_message' => $reply,
        'rounds'            => $round + 1,
    ]);

    respond(true, ['reply' => $reply, 'tools_used' => $toolsUsed], null, 200);
} catch (OpenAIException $e) {
    error_log('chat.php OpenAI: ' . $e->getMessage());
    $registry->logger()->logConversation([
        'request_id'   => $requestId,
        'user_message' => $lastMessage,
        'error'        => 'openai: ' . $e->getMessage(),
    ]);
    // Stranki ne kažemo tehnične napake — dobi uporaben izhod.
    respond(true, ['reply' => fallbackReply(), 'tools_used' => [], 'degraded' => true], null, 200);
} catch (Throwable $e) {
    error_log('chat.php: ' . $e->getMessage());
    respond(false, null, 'Prišlo je do napake pri obdelavi sporočila.', 500);
}

// --------------------------------------------------------------------
// Pomožne funkcije
// --------------------------------------------------------------------

/**
 * Prebere zgodovino pogovora iz zahtevka.
 * Sprejmemo samo vloge user in assistant — sistemskega sporočila odjemalec
 * ne sme podtakniti, sicer bi lahko obšel pravila asistenta.
 */
function readHistory(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 64000) {
        respond(false, null, 'Zahtevek je prevelik ali prazen.', 400);
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded) || !isset($decoded['messages']) || !is_array($decoded['messages'])) {
        respond(false, null, 'Pričakovano polje "messages".', 400);
    }

    $history = [];
    foreach ($decoded['messages'] as $message) {
        if (!is_array($message) || !isset($message['role'], $message['content'])) {
            continue;
        }
        if (!in_array($message['role'], ['user', 'assistant'], true) || !is_string($message['content'])) {
            continue;
        }
        $content = trim($message['content']);
        if ($content === '') {
            continue;
        }
        $history[] = [
            'role'    => $message['role'],
            'content' => mb_substr($content, 0, MAX_MESSAGE_LENGTH),
        ];
    }

    return array_slice($history, -MAX_HISTORY_MESSAGES);
}

function buildSystemPrompt(): string
{
    $prompt = @file_get_contents(SYSTEM_PROMPT_FILE);
    if ($prompt === false) {
        throw new RuntimeException('Ni mogoče prebrati datoteke s sistemskim promptom.');
    }

    $prompt = strtr($prompt, [
        '{BUSINESS_PHONE}' => defined('BUSINESS_PHONE') ? BUSINESS_PHONE : '',
        '{BUSINESS_EMAIL}' => defined('BUSINESS_EMAIL') ? BUSINESS_EMAIL : '',
        '{BUSINESS_NAME}'  => defined('BUSINESS_NAME')  ? BUSINESS_NAME  : '',
    ]);

    // Brez tega bi model računal termine glede na datum svojega učenja.
    $now = new DateTimeImmutable('now');
    $prompt .= "\n\n## Trenutni čas\n"
        . 'Danes je ' . SlovenianDate::longDate($now) . ' ' . $now->format('Y')
        . ', ura je ' . $now->format('H:i') . '.';

    return $prompt;
}

function loadToolDefinitions(): array
{
    $raw = @file_get_contents(TOOL_DEFINITIONS_FILE);
    if ($raw === false) {
        throw new RuntimeException('Ni mogoče prebrati definicij orodij.');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Definicije orodij niso veljaven JSON.');
    }
    return $decoded;
}

/**
 * Izvede klic orodja in vrne JSON niz, ki ga model prebere kot rezultat.
 */
function runTool(ToolRegistry $registry, string $modelToolName, string $argumentsJson, string $requestId): string
{
    if (!array_key_exists($modelToolName, TOOL_NAME_MAP)) {
        return json_encode([
            'success' => false,
            'data'    => null,
            'error'   => 'Neznano orodje.',
        ], JSON_UNESCAPED_UNICODE);
    }

    $arguments = json_decode($argumentsJson, true);
    if (!is_array($arguments)) {
        $arguments = [];
    }

    return $registry->call(TOOL_NAME_MAP[$modelToolName], $arguments, 'chat', $requestId)->toJson();
}

function fallbackReply(): string
{
    $phone = defined('BUSINESS_PHONE') ? BUSINESS_PHONE : '';
    return 'Oprostite, sistem mi trenutno ne odgovori. Pokličite nas prosim na ' . $phone
        . ' in vam takoj pomagamo.';
}

function applyCors(): void
{
    $allowed = defined('CHAT_ALLOWED_ORIGINS') ? CHAT_ALLOWED_ORIGINS : [];
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Vary: Origin');
    }
}

/**
 * @param mixed $data
 */
function respond(bool $success, $data, ?string $error, int $status): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'data'    => $data,
        'error'   => $error,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
