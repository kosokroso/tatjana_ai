<?php
/**
 * Urejanje baze znanja.
 *
 * Katalog pove, kaj podjetje prodaja in po čem. Vprašanja kot "ali delate tudi
 * za društva" ali "kaj potrebujete od nas za začetek" doslej niso imela vira,
 * zato je asistent odgovoril splošno — kar je pri stranki, ki se odloča, enako
 * slabo kot molk.
 *
 * Vsebino ureja podjetje samo. V kodi ne sme biti nič od tega: pri naslednji
 * stranki so vprašanja druga.
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
    } elseif (($_POST['dejanje'] ?? '') === 'brisi') {
        try {
            $registry->adapter()->deleteKnowledge((int) ($_POST['id'] ?? 0));
            $sporocilo = 'Zapis izbrisan.';
        } catch (Throwable $e) {
            error_log('admin/znanje: ' . $e->getMessage());
            $napaka = 'Brisanje ni uspelo.';
        }
    } else {
        $napaka = preveriVnos($_POST);

        if ($napaka === null) {
            try {
                $id = $registry->adapter()->saveKnowledge([
                    'id'       => (int) ($_POST['id'] ?? 0),
                    'question' => trim((string) ($_POST['question'] ?? '')),
                    'answer'   => trim((string) ($_POST['answer'] ?? '')),
                    'keywords' => trim((string) ($_POST['keywords'] ?? '')),
                    'active'   => isset($_POST['active']),
                ]);
                $sporocilo = 'Zapis #' . $id . ' shranjen.';
            } catch (Throwable $e) {
                error_log('admin/znanje: ' . $e->getMessage());
                $napaka = 'Shranjevanje ni uspelo.';
            }
        }
    }
}

/** Vrne sporočilo o napaki ali null. */
function preveriVnos(array $v): ?string
{
    if (mb_strlen(trim((string) ($v['question'] ?? ''))) < 5) {
        return 'Vprašanje je obvezno in mora biti vsaj pet znakov.';
    }
    if (mb_strlen(trim((string) ($v['answer'] ?? ''))) < 5) {
        return 'Odgovor je obvezen in mora biti vsaj pet znakov.';
    }
    if (mb_strlen((string) ($v['question'] ?? '')) > 300) {
        return 'Vprašanje je predolgo (največ 300 znakov).';
    }
    if (mb_strlen((string) ($v['answer'] ?? '')) > 1200) {
        return 'Odgovor je predolg (največ 1200 znakov). Razdeli ga na dva zapisa.';
    }
    return null;
}

try {
    $zapisi = $registry->adapter()->listKnowledge();
} catch (Throwable $e) {
    error_log('admin/znanje: ' . $e->getMessage());
    $zapisi = [];
    $napaka = $napaka ?? 'Zapisov ni bilo mogoče prebrati. Je tabela ai_knowledge ustvarjena?';
}

$urejam = null;
if (isset($_GET['uredi'])) {
    foreach ($zapisi as $z) {
        if ((int) $z['id'] === (int) $_GET['uredi']) {
            $urejam = $z;
            break;
        }
    }
}

$naslov = defined('BUSINESS_NAME') ? BUSINESS_NAME : 'Asistent';

function polje(?array $vir, string $kljuc, string $privzeto = ''): string
{
    return h((string) ($vir[$kljuc] ?? $privzeto));
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Znanje — <?= h($naslov) ?></title>
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
    <div class="brand"><?= h($naslov) ?> <span>— znanje</span></div>
    <div>
      <nav style="display:inline">
        <a href="./">Povpraševanja</a>
        <a href="storitve.php">Storitve</a>
        <a href="znanje.php" class="aktiven">Znanje</a>
        <a href="pogovori.php">Pogovori</a>
      </nav>
      <form method="post" style="display:inline">
        <input type="hidden" name="dejanje" value="odjava">
        <button type="submit" class="tiho">Odjava</button>
      </form>
    </div>
  </header>

  <h1><?= $urejam ? 'Uredi zapis' : 'Nov zapis' ?></h1>
  <p class="sub">Odgovori na vprašanja, ki niso o ceni. Asistent jih poišče sam,
     ko stranka vpraša kaj o načinu dela ali sodelovanju.</p>

  <?php if ($sporocilo !== null): ?>
    <div class="obvestilo ok"><?= h($sporocilo) ?></div>
  <?php endif; ?>
  <?php if ($napaka !== null): ?>
    <div class="obvestilo err"><?= h($napaka) ?></div>
  <?php endif; ?>

  <div class="kartica">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(adminCsrf()) ?>">
      <input type="hidden" name="id" value="<?= polje($urejam, 'id', '0') ?>">

      <div>
        <label for="question">Vprašanje, kot ga postavi stranka</label>
        <input type="text" id="question" name="question" maxlength="300"
               value="<?= polje($urejam, 'question') ?>"
               placeholder="Ali delate tudi za društva?">
        <p class="namig">Zapiši ga z besedami stranke, ne s svojimi.</p>
      </div>

      <div style="margin-top:18px">
        <label for="answer">Odgovor</label>
        <textarea id="answer" name="answer" maxlength="1200"><?= polje($urejam, 'answer') ?></textarea>
        <p class="namig">Asistent ga pove s svojimi besedami, zato ne skrbi za slog.
           Skrbi za dejstva — česar tu ni, si ne sme izmisliti.</p>
      </div>

      <div style="margin-top:18px">
        <label for="keywords">Sopomenke, ločene z vejico</label>
        <input type="text" id="keywords" name="keywords" maxlength="300"
               value="<?= polje($urejam, 'keywords') ?>"
               placeholder="neprofitne, zavodi, klubi">
        <p class="namig">Stranka po telefonu redko uporabi iste besede kot zapisan
           odgovor. Tu naštej, kako še lahko vpraša isto stvar.</p>
      </div>

      <div class="potrdi">
        <input type="checkbox" id="active" name="active"
               <?= ($urejam === null || !empty($urejam['active'])) ? 'checked' : '' ?>>
        <label for="active">Asistent sme uporabiti ta odgovor</label>
      </div>

      <div style="margin-top:24px">
        <button type="submit"><?= $urejam ? 'Shrani spremembe' : 'Dodaj zapis' ?></button>
        <?php if ($urejam): ?>
          <a href="znanje.php" style="margin-left:14px;font-size:14px">Prekliči</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <h2>Zapisi (<?= count($zapisi) ?>)</h2>

  <?php if (!$zapisi): ?>
    <div class="kartica">
      <p style="margin:0;color:var(--muted)">Zapisov še ni. Dodaj prvega zgoraj —
         najbolje tistega, ki ga stranke najpogosteje vprašajo po telefonu.</p>
    </div>
  <?php else: ?>
  <div class="scroll">
    <table>
      <thead>
        <tr><th>Vprašanje</th><th>Odgovor</th><th>Sopomenke</th><th>V rabi</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($zapisi as $z): ?>
        <tr class="<?= empty($z['active']) ? 'neaktivna' : '' ?>">
          <td><?= h((string) $z['question']) ?></td>
          <td><?= h(mb_strimwidth((string) $z['answer'], 0, 160, '…')) ?></td>
          <td><?= h((string) ($z['keywords'] ?? '')) ?></td>
          <td><span class="znacka <?= empty($z['active']) ? 'ne' : 'da' ?>">
              <?= empty($z['active']) ? 'ne' : 'da' ?></span></td>
          <td class="nowrap">
            <a href="?uredi=<?= (int) $z['id'] ?>">uredi</a>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Izbrišem ta zapis? Tega ni mogoče razveljaviti.')">
              <input type="hidden" name="csrf" value="<?= h(adminCsrf()) ?>">
              <input type="hidden" name="dejanje" value="brisi">
              <input type="hidden" name="id" value="<?= (int) $z['id'] ?>">
              <button type="submit" class="tiho" style="margin-left:8px">briši</button>
            </form>
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
