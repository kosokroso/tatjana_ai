<?php
/**
 * Pregled dogovorjenih terminov.
 *
 * Asistent termine sprejema sam, zato mora nekdo videti, kaj je dogovoril.
 * Brez te strani bi bil edini vpogled poizvedba SQL — in termin, za katerega
 * nihče ne ve, je slabši od nobenega termina.
 */

declare(strict_types=1);

$registry = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../tools/core/RateLimiter.php';
require_once __DIR__ . '/auth.php';

if (!adminOmogocen()) {
    http_response_code(404);
    exit('Ni na voljo.');
}

adminZagon();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

if (!adminPrijavljen()) {
    header('Location: ./');
    exit;
}

$napaka    = null;
$sporocilo = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (($_POST['dejanje'] ?? '') === 'odjava') {
        adminOdjava();
        header('Location: ./');
        exit;
    }

    if (!adminCsrfVeljaven($_POST['csrf'] ?? null)) {
        $napaka = 'Seja je potekla. Osveži stran in poskusi znova.';
    } elseif (($_POST['dejanje'] ?? '') === 'odpovej') {
        try {
            $registry->adapter()->cancelAppointment((int) ($_POST['id'] ?? 0));
            $sporocilo = 'Termin odpovedan. Stranko obvesti sam — asistent tega ne stori.';
        } catch (Throwable $e) {
            error_log('admin/termini: ' . $e->getMessage());
            $napaka = 'Odpoved ni uspela.';
        }
    }
}

try {
    $termini = $registry->adapter()->listAppointments(['from' => new DateTimeImmutable('today')]);
} catch (Throwable $e) {
    error_log('admin/termini: ' . $e->getMessage());
    $termini = [];
    $napaka = $napaka ?? 'Terminov ni bilo mogoče prebrati. Je tabela ai_appointments ustvarjena?';
}

$naslov = defined('BUSINESS_NAME') ? BUSINESS_NAME : 'Asistent';
$danes  = new DateTimeImmutable('now');
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Termini — <?= h($naslov) ?></title>
<style>
  :root{--bg:#fdf9f4;--bg-2:#fbf4ea;--panel:#fff;--ink:#1f1c19;--muted:#8d8279;
    --line:#ece2d6;--accent:#e8943a;--teal:#3aaecf;--zebra:#fbf6ef}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);
    font:15px/1.6 "Segoe UI",-apple-system,Roboto,system-ui,sans-serif}
  a{color:var(--accent);text-decoration:none}
  a:hover{text-decoration:underline}
  .wrap{max-width:1180px;margin:0 auto;padding:0 24px 64px}
  header{display:flex;align-items:center;justify-content:space-between;
    padding:22px 0;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:12px}
  .brand{font-weight:800;font-size:17px}
  .brand span{color:var(--teal)}
  nav a{margin-right:16px;color:var(--ink);font-size:14px}
  nav a.aktiven{color:var(--accent);font-weight:600}
  h1{font-size:clamp(26px,4vw,38px);font-weight:300;letter-spacing:-.02em;margin:34px 0 6px}
  .sub{color:var(--muted);font-size:14px;margin:0 0 28px}
  h2{font-size:18px;font-weight:600;margin:34px 0 12px}

  .obvestilo{border-radius:10px;padding:12px 16px;margin:0 0 20px;font-size:14px}
  .obvestilo.ok{background:#eef7ee;border:1px solid #cfe6cf}
  .obvestilo.err{background:#fdeeec;border:1px solid #f2cdc8;color:#a4302a}

  .kartica{background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:24px;
    box-shadow:0 6px 24px rgba(31,28,25,.04);animation:vstop .5s ease both}
  @keyframes vstop{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
  .mreza{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px}
  label{display:block;font-size:13px;color:var(--muted);margin-bottom:5px}
  input[type=text],input[type=number],select,textarea{width:100%;padding:11px 14px;
    border:1px solid var(--line);border-radius:9px;font:inherit;color:var(--ink);background:#fff}
  textarea{min-height:88px;resize:vertical}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent)}
  .namig{font-size:12px;color:var(--muted);margin:5px 0 0}
  .potrdi{display:flex;align-items:center;gap:8px;margin-top:26px}
  .potrdi input{width:auto}
  .potrdi label{margin:0;color:var(--ink);font-size:14px}
  button{padding:12px 26px;border:0;border-radius:999px;
    background:linear-gradient(145deg,var(--accent),#d07f28);
    color:#fff;font:inherit;font-weight:600;cursor:pointer;
    box-shadow:0 6px 18px rgba(232,148,58,.3);transition:transform .18s,box-shadow .18s}
  button:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(232,148,58,.38)}
  button:active{transform:none}
  .tiho{background:transparent;color:var(--muted);border:1px solid var(--line);
    font-weight:400;padding:7px 14px;font-size:13px;border-radius:999px;cursor:pointer}
  .tiho:hover{border-color:var(--accent);color:var(--accent);transform:none;box-shadow:none}

  .scroll{overflow-x:auto;border:1px solid var(--line);border-radius:14px;
    background:var(--panel);box-shadow:0 6px 24px rgba(31,28,25,.04);
    animation:vstop .5s ease both}
  tbody tr{transition:background .15s}
  tbody tr:hover{background:var(--zebra)}
  @keyframes vstop{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
  table{border-collapse:collapse;width:100%;font-size:14px}
  th,td{text-align:left;padding:12px 14px;vertical-align:top}
  th{background:var(--zebra);font-size:11px;text-transform:uppercase;letter-spacing:.07em;
    color:var(--muted);border-bottom:1px solid var(--line);white-space:nowrap}
  tbody tr+tr{border-top:1px solid var(--line)}
  tr.neaktivna td{opacity:.5}
  td.nowrap{white-space:nowrap}
  .znacka{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px}
  .znacka.da{background:#e9f4e9;color:#2f6b30}
  .znacka.ne{background:#f0eeec;color:#7a716a}
  @media (prefers-reduced-motion: reduce){
    *,*::before,*::after{animation-duration:.01ms !important;animation-iteration-count:1 !important;
      transition-duration:.01ms !important}
  }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="brand"><?= h($naslov) ?> <span>— termini</span></div>
    <div>
      <nav style="display:inline">
        <a href="./">Povpraševanja</a>
        <a href="storitve.php">Storitve</a>
        <a href="znanje.php">Znanje</a>
        <a href="termini.php" class="aktiven">Termini</a>
        <a href="pogovori.php">Pogovori</a>
      </nav>
      <form method="post" style="display:inline">
        <input type="hidden" name="dejanje" value="odjava">
        <button type="submit" class="tiho">Odjava</button>
      </form>
    </div>
  </header>

  <h1>Termini</h1>
  <p class="sub">Od danes naprej. Termine sprejema asistent sam; odpoved tukaj
     stranke ne obvesti — to stori sam.</p>

  <?php if ($sporocilo !== null): ?>
    <div class="obvestilo ok"><?= h($sporocilo) ?></div>
  <?php endif; ?>
  <?php if ($napaka !== null): ?>
    <div class="obvestilo err"><?= h($napaka) ?></div>
  <?php endif; ?>

  <?php if (!$termini): ?>
    <div class="kartica">
      <p style="margin:0;color:var(--muted)">Dogovorjenih terminov ni.</p>
    </div>
  <?php else: ?>
  <div class="scroll">
    <table>
      <thead>
        <tr><th>Kdaj</th><th>Stranka</th><th>Telefon</th><th>E-pošta</th><th>Opomba</th><th>Stanje</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($termini as $t):
            $zacetek = new DateTimeImmutable((string) $t['starts_at']);
            $odpovedan = ($t['status'] ?? '') === 'cancelled';
            $relativno = SlovenianDate::relativeDay($zacetek, $danes);
      ?>
        <tr class="<?= $odpovedan ? 'neaktivna' : '' ?>">
          <td class="nowrap">
            <strong><?= h($zacetek->format('d.m.Y H:i')) ?></strong>
            <?php if ($relativno !== null): ?>
              <div style="color:var(--muted);font-size:12px"><?= h($relativno) ?></div>
            <?php endif; ?>
          </td>
          <td><?= h((string) $t['name']) ?></td>
          <td class="nowrap"><?= h((string) $t['phone']) ?></td>
          <td><?= h((string) ($t['email'] ?? '')) ?></td>
          <td><?= h((string) ($t['note'] ?? '')) ?></td>
          <td><span class="znacka <?= $odpovedan ? 'ne' : 'da' ?>">
              <?= $odpovedan ? 'odpovedan' : 'velja' ?></span></td>
          <td class="nowrap">
            <?php if (!$odpovedan): ?>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Odpovem ta termin? Stranke to ne obvesti.')">
              <input type="hidden" name="csrf" value="<?= h(adminCsrf()) ?>">
              <input type="hidden" name="dejanje" value="odpovej">
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button type="submit" class="tiho">odpovej</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
