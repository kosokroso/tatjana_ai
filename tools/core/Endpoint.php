<?php
/**
 * HTTP ovoj okoli toolov.
 *
 * Vsaka datoteka v /tools/*.php je samo tri vrstice — vso skupno logiko
 * (metoda, HTTPS, rate limit, branje JSON, oblika odgovora) opravi ta razred.
 */
final class Endpoint
{
    /**
     * Obdela trenutni zahtevek za dani tool in konča izvajanje.
     */
    public static function handle(ToolRegistry $registry, string $toolName): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header('Allow: POST');
            self::send(ToolResponse::error('Dovoljena je samo metoda POST.', 'method_not_allowed', 405));
        }

        if (defined('REQUIRE_HTTPS') && REQUIRE_HTTPS && !self::isHttps()) {
            self::send(ToolResponse::error('Zahtevana je povezava HTTPS.', 'https_required', 403));
        }

        $allowed = defined('ALLOWED_TOOLS') ? ALLOWED_TOOLS : [];
        if (!in_array($toolName, $allowed, true)) {
            self::send(ToolResponse::error('Tool ni na voljo.', 'unknown_tool', 404));
        }

        if (!self::passesRateLimit()) {
            header('Retry-After: 60');
            self::send(ToolResponse::error('Preveč zahtevkov. Poskusi čez minuto.', 'rate_limited', 429));
        }

        $input = self::readJsonBody();

        self::send($registry->call($toolName, $input, 'http'));
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        // Za hosting z reverse proxyjem pred PHP.
        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * Klici strežnik-na-strežnik (kasnejši glasovni sloj) nosijo skupno
     * skrivnost in gredo mimo omejitve — sicer bi en sam daljši pogovor,
     * ki sproži več toolov, zadel limit, ker prihajajo vsi z istega IP.
     */
    private static function passesRateLimit(): bool
    {
        $secret = defined('TOOL_SECRET') ? TOOL_SECRET : '';
        $given  = $_SERVER['HTTP_X_TOOL_SECRET'] ?? '';
        if ($secret !== '' && is_string($given) && hash_equals($secret, $given)) {
            return true;
        }

        $limiter = new RateLimiter(
            LOG_DIR . '/ratelimit',
            defined('RATE_LIMIT_PER_MINUTE') ? RATE_LIMIT_PER_MINUTE : 60
        );

        return $limiter->allow($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    /**
     * Prebere in dekodira telo zahtevka. Neveljaven JSON je napaka stranke (400),
     * ne strežnika (500).
     */
    private static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        if (strlen($raw) > 8192) {
            self::send(ToolResponse::invalidInput('Zahtevek je prevelik.'));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::send(ToolResponse::invalidInput('Telo zahtevka ni veljaven JSON objekt.'));
        }

        return $decoded;
    }

    private static function send(ToolResponse $response): void
    {
        http_response_code($response->httpStatus());
        echo $response->toJson();
        exit;
    }
}
