<?php
/**
 * Prijava v skrbniški pregled.
 *
 * Stran prikazuje imena, telefonske številke in e-pošte strank, zato zaščita
 * ni formalnost. Geslo je shranjeno kot zgoščena vrednost (password_hash),
 * nikoli v čisti obliki — če kdaj uide config.php, iz njega ni mogoče
 * prebrati gesla.
 *
 * Brez nastavljenega ADMIN_PASSWORD_HASH je stran izklopljena in vrne 404.
 */

declare(strict_types=1);

const SEJA_IME     = 'asistent_admin';
const SEJA_TRAJANJE = 8 * 3600;

function adminZagon(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Piškotek samo prek HTTPS, nedosegljiv JavaScriptu in brez pošiljanja
    // ob zahtevkih s tujih strani — to pokrije večino ugrabitev seje.
    session_name(SEJA_IME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => adminJeHttps(),
        'samesite' => 'Strict',
    ]);
    session_start();

    // Seja ne sme trajati v nedogled na tujem računalniku.
    if (isset($_SESSION['prijavljen_ob']) && time() - (int) $_SESSION['prijavljen_ob'] > SEJA_TRAJANJE) {
        adminOdjava();
    }
}

function adminJeHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function adminOmogocen(): bool
{
    return defined('ADMIN_PASSWORD_HASH') && ADMIN_PASSWORD_HASH !== '';
}

function adminPrijavljen(): bool
{
    return !empty($_SESSION['prijavljen']);
}

/**
 * Preveri geslo. Vrne sporočilo o napaki ali null ob uspehu.
 *
 * Napačno geslo in preveč poskusov sta ločena primera: prvi je lahko
 * tipkarska napaka, drugi pomeni ugibanje.
 */
function adminPrijava(string $geslo): ?string
{
    $limiter = new RateLimiter(LOG_DIR . '/ratelimit', 10, 900);
    if (!$limiter->allow('admin:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
        error_log('admin: prevec poskusov prijave z ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        return 'Preveč poskusov. Poskusi čez petnajst minut.';
    }

    if (!password_verify($geslo, ADMIN_PASSWORD_HASH)) {
        // Zakasnitev upočasni ugibanje in prikrije razliko v času odgovora.
        usleep(400000);
        return 'Napačno geslo.';
    }

    // Nova identiteta seje ob prijavi prepreči, da bi napadalec vnaprej
    // podtaknil znan ID seje (session fixation).
    session_regenerate_id(true);
    $_SESSION['prijavljen']    = true;
    $_SESSION['prijavljen_ob'] = time();
    $_SESSION['csrf']          = bin2hex(random_bytes(16));

    return null;
}

function adminOdjava(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
}

function adminCsrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/** Brez tega bi tuja stran lahko v imenu prijavljenega spremenila stanje. */
function adminCsrfVeljaven(?string $podan): bool
{
    return is_string($podan) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $podan);
}

function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
