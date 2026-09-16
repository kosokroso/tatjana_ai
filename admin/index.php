<?php
/**
 * Skrbniški pregled povpraševanj.
 *
 * Kar podjetje vidi: kdo je pisal, kaj potrebuje, in ali je bilo že urejeno.
 * Do zdaj je bila edina pot do tega e-pošta in razvojno orodje, ki prikazuje
 * vse tabele — ne eno ne drugo ni primerno za stranko.
 *
 * Podatki gredo skozi AdapterInterface, ne neposredno v bazo, da stran deluje
 * tudi, ko bo pod njo drug vir podatkov.
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

$napaka   = null;
$sporocilo = null;

// ----------------------------------------------------------------- dejanja

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $dejanje = $_POST['dejanje'] ?? '';

    if ($dejanje === 'prijava') {
        $napaka = adminPrijava((string) ($_POST['geslo'] ?? ''));
        if ($napaka === null) {
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
    } elseif ($dejanje === 'odjava') {
        adminOdjava();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } elseif (adminPrijavljen() && $dejanje === 'stanje') {
        if (!adminCsrfVeljaven($_POST['csrf'] ?? null)) {
            $napaka = 'Seja je potekla. Osveži stran in poskusi znova.';
        } else {
            try {
                $id     = (int) ($_POST['id'] ?? 0);
                $stanje = (string) ($_POST['stanje'] ?? '');
                $sporocilo = $registry->adapter()->updateInquiryStatus($id, $stanje)
                    ? 'Povpraševanje #' . $id . ' posodobljeno.'
                    : 'Povpraševanja #' . $id . ' ni bilo mogoče najti.';
            } catch (Throwable $e) {
                error_log('admin: ' . $e->getMessage());
                $napaka = 'Spremembe ni bilo mogoče shraniti.';
            }
        }
    }
}

// ----------------------------------------------------------------- podatki

$STANJA = [
    'new'       => 'novo',
    'handled'   => 'urejeno',
    'discarded' => 'zavrženo',
];

$seznam = ['items' => [], 'total' => 0];
$filter = [
    'status' => $_GET['status'] ?? '',
    'search' => trim((string) ($_GET['q'] ?? '')),
    'limit'  => 50,
    'offset' => max(0, (int) ($_GET['od'] ?? 0)),
];

if (adminPrijavljen()) {
    try {
        $seznam = $registry->adapter()->listInquiries($filter);
    } catch (Throwable $e) {
        error_log('admin: ' . $e->getMessage());
        $napaka = 'Povpraševanj ni bilo mogoče prebrati.';
    }
}

$naslov = defined('BUSINESS_NAME') ? BUSINESS_NAME : 'Asistent';
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Povpraševanja — <?= h($naslov) ?></title>
<style>
  :root {
    --bg:#fdf9f5; --panel:#fff; --ink:#23201d; --muted:#8a8079;
    --line:#ece3d8; --accent:#e8943a; --teal:#3aaecf; --zebra:#faf7f3;
  }
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
  h1{font-size:clamp(26px,4vw,38px);font-weight:300;letter-spacing:-.02em;margin:34px 0 6px}
  .sub{color:var(--muted);font-size:14px;margin:0 0 28px}

  .obvestilo{border-radius:10px;padding:12px 16px;margin:0 0 20px;font-size:14px}
  .obvestilo.ok{background:#eef7ee;border:1px solid #cfe6cf}
  .obvestilo.err{background:#fdeeec;border:1px solid #f2cdc8;color:#a4302a}

  form.prijava{max-width:380px;margin:40px 0}
  input[type=password],input[type=search]{width:100%;padding:13px 18px;
    border:1px solid var(--line);border-radius:999px;font:inherit;color:var(--ink);background:var(--panel)}
  input:focus{outline:none;border-color:var(--accent)}
  button{padding:12px 26px;border:0;border-radius:999px;background:var(--accent);
    color:#fff;font:inherit;font-weight:600;cursor:pointer}
  button:hover{opacity:.9}
  button.tiho{background:transparent;color:var(--muted);border:1px solid var(--line);font-weight:400;padding:7px 14px;font-size:13px}
  button.tiho:hover{border-color:var(--accent);color:var(--accent);opacity:1}

  .orodja{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 18px}
  .orodja input[type=search]{flex:1;min-width:200px}
  .zavihki{display:flex;gap:6px;flex-wrap:wrap}
  .zavihki a{padding:8px 16px;border:1px solid var(--line);border-radius:999px;
    color:var(--ink);font-size:14px;background:var(--panel)}
  .zavihki a.aktiven{background:var(--ink);color:#fff;border-color:var(--ink)}

  .scroll{overflow-x:auto;border:1px solid var(--line);border-radius:14px;background:var(--panel)}
  table{border-collapse:collapse;width:100%;font-size:14px}
  th,td{text-align:left;padding:12px 14px;vertical-align:top}
  th{background:var(--zebra);font-size:11px;text-transform:uppercase;
    letter-spacing:.07em;color:var(--muted);border-bottom:1px solid var(--line);white-space:nowrap}
  tbody tr+tr{border-top:1px solid var(--line)}
  td.nowrap{white-space:nowrap}
  td.opomba{min-width:260px;color:var(--muted)}
  .znacka{display:inline-block;padding:3px 11px;border-radius:999px;font-size:12px;font-weight:600}
  .znacka.new{background:#fdf0e0;color:#a35f14}
  .znacka.handled{background:#e9f4e9;color:#2f6b30}
  .znacka.discarded{background:#f0eeec;color:#7a716a}
  .prazno{padding:40px;text-align:center;color:var(--muted)}
  .strani{display:flex;gap:10px;align-items:center;margin-top:18px;font-size:14px;color:var(--muted)}
  footer{margin-top:40px;font-size:13px;color:var(--muted)}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="brand"><?= h($naslov) ?> <span>— povpraševanja</span></div>
    <?php if (adminPrijavljen()): ?>
      <form method="post" style="margin:0">
        <input type="hidden" name="dejanje" value="odjava">
        <button type="submit" class="tiho">Odjava</button>
      </form>
    <?php endif; ?>
  </header>

<?php if ($napaka): ?>
  <p class="obvestilo err"><?= h($napaka) ?></p>
<?php endif; ?>
<?php if ($sporocilo): ?>
  <p class="obvestilo ok"><?= h($sporocilo) ?></p>
<?php endif; ?>

<?php if (!adminPrijavljen()): ?>

  <h1>Prijava</h1>
  <p class="sub">Stran vsebuje osebne podatke strank.</p>
  <form class="prijava" method="post">
    <input type="hidden" name="dejanje" value="prijava">
    <input type="password" name="geslo" placeholder="Geslo" autocomplete="current-password" autofocus required>
    <p style="margin:14px 0 0"><button type="submit">Prijava</button></p>
  </form>

<?php else: ?>

  <h1>Povpraševanja</h1>
  <p class="sub"><?= (int) $seznam['total'] ?> zapisov<?= $filter['search'] !== '' ? ' za "' . h($filter['search']) . '"' : '' ?>.</p>

  <form class="orodja" method="get">
    <input type="search" name="q" value="<?= h($filter['search']) ?>" placeholder="Išči po imenu, telefonu, e-pošti ali opombi…">
    <?php if ($filter['status'] !== ''): ?>
      <input type="hidden" name="status" value="<?= h($filter['status']) ?>">
    <?php endif; ?>
    <button type="submit">Išči</button>
  </form>

  <div class="zavihki" style="margin-bottom:18px">
    <?php
    $zavihki = ['' => 'vsa'] + $STANJA;
    foreach ($zavihki as $kljuc => $oznaka):
        $url = '?' . http_build_query(array_filter([
            'status' => $kljuc,
            'q'      => $filter['search'],
        ], static fn($v) => $v !== '' && $v !== null));
    ?>
      <a href="<?= h($url === '?' ? '?' : $url) ?>" class="<?= $filter['status'] === $kljuc ? 'aktiven' : '' ?>"><?= h($oznaka) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="scroll">
    <?php if (!$seznam['items']): ?>
      <p class="prazno">Ni povpraševanj, ki bi ustrezala izbiri.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th><th>prejeto</th><th>stranka</th><th>kontakt</th>
          <th>zanima</th><th>opomba</th><th>stanje</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($seznam['items'] as $v): ?>
        <tr>
          <td class="nowrap"><strong><?= (int) $v['id'] ?></strong></td>
          <td class="nowrap"><?= h(date('j. n. Y H:i', strtotime((string) $v['created_at']))) ?></td>
          <td><?= h($v['name']) ?><br><span style="color:var(--muted);font-size:12px"><?= h($v['source']) ?></span></td>
          <td class="nowrap">
            <a href="tel:<?= h(preg_replace('/\s+/', '', (string) $v['phone'])) ?>"><?= h($v['phone']) ?></a><br>
            <a href="mailto:<?= h($v['email']) ?>"><?= h($v['email']) ?></a>
          </td>
          <td><?= h($v['product'] ?: '—') ?><?= $v['quantity'] ? '<br><span style="color:var(--muted);font-size:12px">' . h($v['quantity']) . '</span>' : '' ?></td>
          <td class="opomba"><?= h($v['note'] ?: '—') ?></td>
          <td class="nowrap"><span class="znacka <?= h($v['status']) ?>"><?= h($STANJA[$v['status']] ?? $v['status']) ?></span></td>
          <td class="nowrap">
            <form method="post" style="display:flex;gap:6px">
              <input type="hidden" name="dejanje" value="stanje">
              <input type="hidden" name="csrf" value="<?= h(adminCsrf()) ?>">
              <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
              <?php foreach ($STANJA as $kljuc => $oznaka): ?>
                <?php if ($kljuc !== $v['status']): ?>
                  <button type="submit" name="stanje" value="<?= h($kljuc) ?>" class="tiho"><?= h($oznaka) ?></button>
                <?php endif; ?>
              <?php endforeach; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php
  $od    = $filter['offset'];
  $limit = $filter['limit'];
  $zadnji = min($od + $limit, (int) $seznam['total']);
  if ((int) $seznam['total'] > $limit):
  ?>
  <div class="strani">
    <?php if ($od > 0): ?>
      <a href="?<?= h(http_build_query(array_filter(['status' => $filter['status'], 'q' => $filter['search'], 'od' => max(0, $od - $limit)]))) ?>">← novejša</a>
    <?php endif; ?>
    <span><?= $od + 1 ?>–<?= $zadnji ?> od <?= (int) $seznam['total'] ?></span>
    <?php if ($zadnji < (int) $seznam['total']): ?>
      <a href="?<?= h(http_build_query(array_filter(['status' => $filter['status'], 'q' => $filter['search'], 'od' => $od + $limit]))) ?>">starejša →</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <footer>Povpraševanja zbira asistent na spletni strani in po telefonu.</footer>

<?php endif; ?>
</div>
</body>
</html>
