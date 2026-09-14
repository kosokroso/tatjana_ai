<?php
/**
 * Skupna vratarska logika za končne točke v /ai.
 *
 * Vsaka od njih (klepet, prepis govora, sinteza govora) stane denar pri OpenAI,
 * zato morajo imeti enako zaščito. Če bi bila prepisana v vsaki datoteki posebej,
 * bi prej ali slej ena ostala brez nje — in prav ta bi bila tista, ki jo kdo najde.
 */

/**
 * Ali zahtevek prihaja s strani, ki ji zaupamo?
 *
 * Dokler je CHAT_ALLOWED_ORIGINS prazen, preverjanja ni — razvojni način.
 * Ko vpišeš domeno stranke, vsi drugi izvori dobijo 403.
 *
 * Ni nepremagljivo: glavo Origin je z orodji, kot je curl, mogoče poljubno
 * nastaviti. Ustavi pa zlorabo iz brskalnika s tuje strani. Trdo mejo stroška
 * postavljajo dnevne kapice, ne to.
 */
function aiOriginAllowed(): bool
{
    $allowed = defined('CHAT_ALLOWED_ORIGINS') ? CHAT_ALLOWED_ORIGINS : [];
    if (!$allowed) {
        return true;
    }

    $source = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($source === '') {
        return false;
    }

    $host = parse_url($source, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return false;
    }

    // Stran na istem gostitelju kot končna točka je vedno v redu — to je primer,
    // ko klepet teče na domeni podjetja.
    if (strcasecmp($host, (string) ($_SERVER['HTTP_HOST'] ?? '')) === 0) {
        return true;
    }

    foreach ($allowed as $origin) {
        $allowedHost = parse_url((string) $origin, PHP_URL_HOST) ?: $origin;
        if (strcasecmp($host, (string) $allowedHost) === 0) {
            return true;
        }
    }

    return false;
}

function aiApplyCors(): void
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

function aiIsHttps(): bool
{
    // Pozor: $_SERVER['HTTPS'] je na nekaterih strežnikih niz 'off' — empty('off')
    // je false, zato bi preverjanje s samim empty() povezavo razglasilo za varno.
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/**
 * Minutna in dnevna omejitev na IP ter skupna dnevna kapica.
 *
 * Minuta ustavi naval, dan pa nekoga, ki bi počasi in ves dan trošil kredit.
 * Skupna kapica je trda zgornja meja dnevnega stroška, ne glede na to, s koliko
 * različnih naslovov klici prihajajo.
 *
 * @param string $namespace Ločen števec za vsako vrsto klica ('chat', 'stt', 'tts').
 * @return string|null Sporočilo za stranko, ali null, kadar je klic dovoljen.
 */
function aiRateLimitMessage(string $namespace): ?string
{
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stateDir = LOG_DIR . '/ratelimit';

    $perMinute = defined('CHAT_RATE_LIMIT_PER_MINUTE') ? CHAT_RATE_LIMIT_PER_MINUTE : 20;
    $perDay    = defined('CHAT_RATE_LIMIT_PER_DAY')    ? CHAT_RATE_LIMIT_PER_DAY    : 100;
    $perDayAll = defined('CHAT_MAX_PER_DAY_TOTAL')     ? CHAT_MAX_PER_DAY_TOTAL     : 500;

    if (!(new RateLimiter($stateDir, $perMinute, 60))->allow($namespace . ':' . $ip)) {
        header('Retry-After: 60');
        return 'Preveč zahtevkov zapored. Poskusite čez minuto.';
    }

    if (!(new RateLimiter($stateDir, $perDay, 86400))->allow($namespace . '-dan:' . $ip)) {
        error_log($namespace . ': dnevna omejitev dosezena za ' . $ip);
        return 'Dnevna omejitev je dosežena. Pišite nam prosim po e-pošti.';
    }

    if (!(new RateLimiter($stateDir, $perDayAll, 86400))->allow($namespace . '-dan-skupaj')) {
        error_log($namespace . ': SKUPNA dnevna omejitev dosezena - preveri, ali gre za zlorabo');
        return 'Storitev trenutno ni na voljo. Pišite nam prosim po e-pošti.';
    }

    return null;
}
