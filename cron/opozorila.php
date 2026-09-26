<?php
/**
 * Dnevni pregled stanja. Namenjen cronu, ne brskalniku.
 *
 * cPanel -> Cron Jobs, enkrat na dan:
 *     /usr/local/bin/php /home/UPORABNIK/public_html/asistent/cron/opozorila.php
 *
 * Doslej se je za izpad izvedelo šele ob naslednjem ročnem klicu. Povpraševanje,
 * ki teden dni čaka v tabeli, je izgubljena stranka — in tega nihče ne opazi,
 * ker ni nikogar, ki bi gledal.
 *
 * Pošlje samo, kadar je kaj za povedati. Dnevno sporočilo "vse je v redu" se
 * neha brati po tednu dni, nato pa se spregleda tudi tisto, ki nekaj pove.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    // Prek brskalnika bi vsak obiskovalec lahko sprožil pošiljanje pošte.
    http_response_code(404);
    exit('Ni na voljo.');
}

$registry = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../ai/guard.php';

/** @var string[] $opozorila */
$opozorila = [];

// ---------------------------------------------------------------
// 1. Dnevni proračun žetonov
// ---------------------------------------------------------------
try {
    $proracun = aiBudget();
    $meja = $proracun->limit();
    if ($meja > 0) {
        $porabljeno = $proracun->used();
        $delez = (int) round($porabljeno / $meja * 100);
        if ($delez >= 80) {
            $opozorila[] = $delez >= 100
                ? "Dnevni proračun žetonov je porabljen ({$porabljeno} od {$meja}). "
                  . 'Klepet na strani od zdaj do polnoči odgovarja z nadomestnim sporočilom.'
                : "Dnevni proračun žetonov je pri {$delez} odstotkih ({$porabljeno} od {$meja}).";
        }
    }
} catch (Throwable $e) {
    $opozorila[] = 'Porabe žetonov ni bilo mogoče prebrati: ' . $e->getMessage();
}

// ---------------------------------------------------------------
// 2. Telefonske minute
// ---------------------------------------------------------------
try {
    $klici = new CallLimits(
        LOG_DIR . '/klici',
        defined('CALL_MAX_SECONDS')    ? (int) CALL_MAX_SECONDS    : 600,
        defined('CALL_DAILY_MINUTES')  ? (int) CALL_DAILY_MINUTES  : 120,
        defined('CALL_MAX_PER_CALLER') ? (int) CALL_MAX_PER_CALLER : 10
    );
    $stanje = $klici->stanje();
    if ($stanje['meja_sekund_na_dan'] > 0) {
        $delez = (int) round($stanje['sekund_danes'] / $stanje['meja_sekund_na_dan'] * 100);
        if ($delez >= 80) {
            $minute = (int) round($stanje['sekund_danes'] / 60);
            $opozorila[] = "Telefonske minute so pri {$delez} odstotkih dnevne meje "
                . "({$minute} minut, {$stanje['klicev_danes']} klicev). "
                . 'Pri sto odstotkih asistent klicev ne sprejme več.';
        }
    }
} catch (Throwable $e) {
    $opozorila[] = 'Števcev klicev ni bilo mogoče prebrati: ' . $e->getMessage();
}

// ---------------------------------------------------------------
// 3. Povpraševanja, ki predolgo čakajo
// ---------------------------------------------------------------
$dni = defined('INQUIRY_ALERT_DAYS') ? (int) INQUIRY_ALERT_DAYS : 2;
try {
    $cakajo = [];
    $meja = new DateTimeImmutable("-{$dni} days");
    foreach ($registry->adapter()->listInquiries(['status' => 'new']) as $p) {
        $nastalo = isset($p['created_at']) ? new DateTimeImmutable((string) $p['created_at']) : null;
        if ($nastalo !== null && $nastalo < $meja) {
            $cakajo[] = '#' . $p['id'] . ' ' . ($p['name'] ?? '') . ' (' . $nastalo->format('d.m.Y') . ')';
        }
    }
    if ($cakajo) {
        $opozorila[] = 'Povpraševanja, ki čakajo več kot ' . $dni . ' dni, še vedno v stanju "new":' . "\n  "
            . implode("\n  ", $cakajo);
    }
} catch (Throwable $e) {
    $opozorila[] = 'Povpraševanj ni bilo mogoče prebrati: ' . $e->getMessage();
}

// ---------------------------------------------------------------
// 4. Napake orodij v današnjem dnevniku
// ---------------------------------------------------------------
try {
    $dnevnik = LOG_DIR . '/tool-calls-' . date('Y-m-d') . '.log';
    if (is_readable($dnevnik)) {
        $napake = [];
        foreach (file($dnevnik, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $vrstica) {
            $zapis = json_decode($vrstica, true);
            if (is_array($zapis) && empty($zapis['success'])) {
                $koda = (string) ($zapis['error_code'] ?? 'brez kode');
                $napake[$koda] = ($napake[$koda] ?? 0) + 1;
            }
        }
        // invalid_input in not_found sta pričakovana: stranka se zmoti, projekta
        // ni. Opozorilo zaslužijo samo napake, ki pomenijo pokvarjen sistem.
        unset($napake['invalid_input'], $napake['not_found'], $napake['rate_limited']);
        if ($napake) {
            $deli = [];
            foreach ($napake as $koda => $koliko) {
                $deli[] = $koda . ': ' . $koliko . 'x';
            }
            $opozorila[] = 'Napake orodij danes — ' . implode(', ', $deli);
        }
    }
} catch (Throwable $e) {
    $opozorila[] = 'Dnevnika orodij ni bilo mogoče prebrati: ' . $e->getMessage();
}

// ---------------------------------------------------------------
// 5. Ali baza sploh odgovarja
// ---------------------------------------------------------------
try {
    $registry->adapter()->listProducts();
} catch (Throwable $e) {
    // To je najhujše od vsega: asistent v tem stanju ne zna povedati nobene cene.
    array_unshift($opozorila, 'BAZA NE ODGOVARJA. Asistent ne more povedati cen ne sprejeti povpraševanj. '
        . 'Napaka: ' . $e->getMessage());
}

// ---------------------------------------------------------------
// Pošlji, kadar je kaj za povedati
// ---------------------------------------------------------------
if (!$opozorila) {
    echo "Nic za javiti.\n";
    exit(0);
}

$prejemnik = defined('ALERT_EMAIL') && ALERT_EMAIL !== ''
    ? ALERT_EMAIL
    : (defined('INQUIRY_EMAIL_TO') ? trim((string) INQUIRY_EMAIL_TO) : '');

$telo = "Pregled asistenta " . date('d.m.Y H:i') . "\n\n"
    . implode("\n\n", $opozorila)
    . "\n\n---\nTo sporocilo poslje cron/opozorila.php. Ce ga ne potrebujes vec,\n"
    . "odstrani cron v cPanelu.\n";

echo $telo;

if ($prejemnik === '') {
    echo "\nALERT_EMAIL ni nastavljen, zato sporocilo ni bilo poslano.\n";
    exit(1);
}

$mailer = new Mailer([
    'host'   => defined('SMTP_HOST')   ? SMTP_HOST   : '',
    'port'   => defined('SMTP_PORT')   ? SMTP_PORT   : 465,
    'secure' => defined('SMTP_SECURE') ? SMTP_SECURE : 'ssl',
    'user'   => defined('SMTP_USER')   ? SMTP_USER   : '',
    'pass'   => defined('SMTP_PASS')   ? SMTP_PASS   : '',
]);

if (!$mailer->isConfigured()) {
    echo "\nSMTP ni nastavljen, zato sporocilo ni bilo poslano.\n";
    exit(1);
}

try {
    $mailer->send(
        $prejemnik,
        'Asistent — ' . count($opozorila) . ' opozoril',
        $telo,
        (string) SMTP_USER,
        defined('BUSINESS_NAME') ? BUSINESS_NAME : ''
    );
    echo "\nPoslano na " . $prejemnik . "\n";
} catch (Throwable $e) {
    // Cron nima komu javiti, da javljanje ne dela. Ostane dnevnik streznika.
    error_log('cron/opozorila: posiljanje ni uspelo: ' . $e->getMessage());
    echo "\nPosiljanje ni uspelo: " . $e->getMessage() . "\n";
    exit(1);
}
