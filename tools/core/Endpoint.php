<?php
/**
 * HTTP ovoj okoli toolov.
 *
 * Vsaka datoteka v /tools/*.php je samo tri vrstice — vso skupno logiko
 * (metoda, HTTPS, skupna skrivnost, branje JSON, oblika odgovora) opravi ta razred.
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

        // Pred ALLOWED_TOOLS, da klicatelj brez skrivnosti ne izve niti, katera
        // orodja so vklopljena.
        if (!self::hasValidSecret()) {
            self::send(ToolResponse::error('Neveljavna skrivnost.', 'unauthorized', 401));
        }

        $allowed = defined('ALLOWED_TOOLS') ? ALLOWED_TOOLS : [];
        if (!in_array($toolName, $allowed, true)) {
            self::send(ToolResponse::error('Tool ni na voljo.', 'unknown_tool', 404));
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
     * Orodja so bila doslej odprta za vsakogar; skrivnost je le preskočila
     * omejitev na IP. Kdorkoli je tako lahko oddajal povpraševanja (z
     * e-poštnim obvestilom podjetju) in zasedal termine. Po HTTP jih kliče
     * samo telefonski agent, ki skrivnost pošlje pri vsakem zahtevku; ukinjeni
     * spletni klepet jih je klical neposredno v PHP. Zato zdaj brez skrivnosti
     * ni odgovora.
     *
     * Prazna TOOL_SECRET pomeni zaprto, ne odprto — enako kot pri
     * ai/agent-config.php in ai/call-guard.php.
     */
    private static function hasValidSecret(): bool
    {
        $secret = defined('TOOL_SECRET') ? (string) TOOL_SECRET : '';
        $given  = $_SERVER['HTTP_X_TOOL_SECRET'] ?? '';

        return $secret !== '' && is_string($given) && hash_equals($secret, $given);
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
