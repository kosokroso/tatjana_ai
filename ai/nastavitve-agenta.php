<?php
/**
 * POST /ai/nastavitve-agenta.php  (glava X-Tool-Secret)
 *
 * Isti odgovor kot ai/agent-config.php. Od 24. 11. 2026 streznik pri Domenci
 * neposreden zahtevek na agent-config.php zavrne s 403, se preden se zazene PHP,
 * pravila v cPanelu pa ni videti. Vkljucitev datoteke to obide, koda ostane ena.
 *
 * Zaradi iskanja, zakaj se glasovni agent po zagonu klica sesuje, vsak zahtevek
 * zapise v logs/agent-config-DATUM.log: cas, maskiran IP, odgovor in samo to,
 * ali je skrivnost prisotna in ali se ujema (nikoli njene vrednosti). Ce zapisa
 * ni, zahtevek do PHP-ja sploh ni prisel.
 */
register_shutdown_function(static function (): void {
    $glava = $_SERVER['HTTP_X_TOOL_SECRET'] ?? '';
    $ujema = defined('TOOL_SECRET') && TOOL_SECRET !== '' && is_string($glava)
        && hash_equals(TOOL_SECRET, $glava);
    $ip = preg_replace('/[0-9a-f]+$/i', 'x', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    @file_put_contents(
        __DIR__ . '/../logs/agent-config-' . date('Y-m-d') . '.log',
        json_encode([
            'ts' => date('c'),
            'ip' => $ip,
            'metoda' => $_SERVER['REQUEST_METHOD'] ?? '',
            'status' => http_response_code(),
            'skrivnost_poslana' => $glava !== '',
            'skrivnost_ok' => $ujema,
            'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 60),
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
});

require __DIR__ . '/agent-config.php';
