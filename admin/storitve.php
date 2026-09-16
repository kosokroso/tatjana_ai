<?php
/**
 * Urejanje kataloga storitev.
 *
 * Do zdaj je vsaka sprememba cene pomenila ročno pisanje SQL vrstice. Pri eni
 * stranki je to znosno, pri desetih poje ves dobiček — zato ta stran.
 *
 * Kategorije so omejene na tiste, ki jih pozna ai/tool-definitions.json.
 * Nova kategorija v bazi bi bila za asistenta nevidna, ker po njej ne bi
 * znal filtrirati.
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

const KATEGORIJE = [
    'spletne-strani' => 'Spletne strani',
    'trzenje'        => 'Trženje',
    'oblikovanje'    => 'Oblikovanje',
    'vzdrzevanje'    => 'Vzdrževanje',
];

const ENOTE = ['paket', 'mesec', 'projekt', 'ura'];

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
    } else {
        $napaka = preveriVnos($_POST);

        if ($napaka === null) {
            try {
                $id = $registry->adapter()->saveProduct([
                    'id'             => (int) ($_POST['id'] ?? 0),
                    'name'           => $_POST['name'] ?? '',
                    'category'       => $_POST['category'] ?? '',
                    'unit'           => $_POST['unit'] ?? '',
                    'price_per_unit' => trim((string) ($_POST['price'] ?? '')) === ''
                        ? null
                        : str_replace(',', '.', (string) $_POST['price']),
                    'price_from'     => isset($_POST['price_from']),
                    'description'    => trim((string) ($_POST['description'] ?? '')),
                    'active'         => isset($_POST['active']),
                ]);
                $sporocilo = 'Storitev #' . $id . ' shranjena.';
            } catch (Throwable $e) {
                error_log('admin/storitve: ' . $e->getMessage());
                $napaka = 'Shranjevanje ni uspelo.';
            }
        }
    }
}

/** Vrne sporočilo o napaki ali null. */
function preveriVnos(array $v): ?string
{
    if (trim((string) ($v['name'] ?? '')) === '') {
        return 'Ime storitve je obvezno.';
    }
    if (!isset(KATEGORIJE[$v['category'] ?? ''])) {
        return 'Izberi veljavno kategorijo.';
    }
    if (!in_array($v['unit'] ?? '', ENOTE, true)) {
        return 'Izberi veljavno enoto.';
    }

    $cena = trim((string) ($v['price'] ?? ''));
    if ($cena !== '') {
        $cena = str_replace(',', '.', $cena);
        if (!is_numeric($cena) || (float) $cena < 0) {
            return 'Cena mora biti število, ali prazna za "cena po dogovoru".';
        }
    }

    return null;
}

try {
    $storitve = $registry->adapter()->listProducts();
} catch (Throwable $e) {
    error_log('admin/storitve: ' . $e->getMessage());
    $storitve = [];
    $napaka = $napaka ?? 'Storitev ni bilo mogoče prebrati.';
}

// Katero storitev urejamo? Brez parametra je obrazec prazen (nova storitev).
$urejam = null;
if (isset($_GET['uredi'])) {
    foreach ($storitve as $s) {
        if ((int) $s['id'] === (int) $_GET['uredi']) {
            $urejam = $s;
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
<title>Storitve — <?= h($naslov) ?></title>
<style>
  :root{--bg:#fdf9f5;--panel:#fff;--ink:#23201d;--muted:#8a8079;
    --line:#ece3d8;--accent:#e8943a;--teal:#3aaecf;--zebra:#faf7f3}
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

  .kartica{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:22px}
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
  button{padding:12px 26px;border:0;border-radius:999px;background:var(--accent);
    color:#fff;font:inherit;font-weight:600;cursor:pointer}
  button:hover{opacity:.9}
  .tiho{background:transparent;color:var(--muted);border:1px solid var(--line);
    font-weight:400;padding:7px 14px;font-size:13px;border-radius:999px;cursor:pointer}
  .tiho:hover{border-color:var(--accent);color:var(--accent)}

  .scroll{overflow-x:auto;border:1px solid var(--line);border-radius:14px;background:var(--panel)}
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
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="brand"><?= h($naslov) ?> <span>— storitve</span></div>
    <div>
      <nav style="display:inline">
        <a href="./">Povpraševanja</a>
        <a href="storitve.php" class="aktiven">Storitve</a>
      </nav>
      <form method="post" style="display:inline">
        <input type="hidden" name="dejanje" value="odjava">
        <button type="submit" class="tiho">Odjava</button>
      </form>
    </div>
  </header>

<?php if ($napaka): ?><p class="obvestilo err"><?= h($napaka) ?></p><?php endif; ?>
<?php if ($sporocilo): ?><p class="obvestilo ok"><?= h($sporocilo) ?></p><?php endif; ?>

  <h1><?= $urejam ? 'Uredi storitev' : 'Nova storitev' ?></h1>
  <p class="sub">Kar vpišeš tu, asistent pove strankam. Cene ne ugiba — bere jih od tod.</p>

  <form method="post" class="kartica">
    <input type="hidden" name="csrf" value="<?= h(adminCsrf()) ?>">
    <input type="hidden" name="id" value="<?= (int) ($urejam['id'] ?? 0) ?>">

    <div class="mreza">
      <div style="grid-column:1/-1">
        <label for="name">Ime storitve</label>
        <input type="text" id="name" name="name" value="<?= polje($urejam, 'name') ?>"
               maxlength="160" required>
      </div>

      <div>
        <label for="category">Kategorija</label>
        <select id="category" name="category">
          <?php foreach (KATEGORIJE as $k => $oznaka): ?>
            <option value="<?= h($k) ?>" <?= ($urejam['category'] ?? '') === $k ? 'selected' : '' ?>>
              <?= h($oznaka) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="unit">Enota</label>
        <select id="unit" name="unit">
          <?php foreach (ENOTE as $e): ?>
            <option value="<?= h($e) ?>" <?= ($urejam['unit'] ?? '') === $e ? 'selected' : '' ?>><?= h($e) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="price">Cena v evrih</label>
        <input type="text" id="price" name="price" inputmode="decimal"
               value="<?= $urejam && $urejam['price_per_unit'] !== null ? h(rtrim(rtrim(number_format((float) $urejam['price_per_unit'], 2, '.', ''), '0'), '.')) : '' ?>">
        <p class="namig">Pusti prazno za "cena po dogovoru".</p>
      </div>
    </div>

    <div class="potrdi">
      <input type="checkbox" id="price_from" name="price_from" <?= !empty($urejam['price_from']) ? 'checked' : '' ?>>
      <label for="price_from">Izhodiščna cena — asistent pove "od 399 €"</label>
    </div>

    <div class="potrdi">
      <input type="checkbox" id="active" name="active" <?= ($urejam === null || !empty($urejam['active'])) ? 'checked' : '' ?>>
      <label for="active">V ponudbi — neaktivnih asistent ne omenja</label>
    </div>

    <div style="margin-top:20px">
      <label for="description">Opis</label>
      <textarea id="description" name="description" maxlength="400"><?= polje($urejam, 'description') ?></textarea>
      <p class="namig">Kaj storitev vključuje. Asistent to prebere stranki, zato piši v celih stavkih.</p>
    </div>

    <p style="margin:22px 0 0">
      <button type="submit"><?= $urejam ? 'Shrani spremembe' : 'Dodaj storitev' ?></button>
      <?php if ($urejam): ?>
        <a href="storitve.php" style="margin-left:14px">Prekliči</a>
      <?php endif; ?>
    </p>
  </form>

  <h2>Katalog (<?= count($storitve) ?>)</h2>
  <div class="scroll">
    <table>
      <thead>
        <tr><th>#</th><th>storitev</th><th>kategorija</th><th>cena</th><th>v ponudbi</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($storitve as $s): ?>
        <tr class="<?= empty($s['active']) ? 'neaktivna' : '' ?>">
          <td class="nowrap"><?= (int) $s['id'] ?></td>
          <td>
            <strong><?= h($s['name']) ?></strong>
            <?php if ($s['description']): ?>
              <br><span style="color:var(--muted);font-size:12.5px"><?= h(mb_strimwidth((string) $s['description'], 0, 110, '…')) ?></span>
            <?php endif; ?>
          </td>
          <td class="nowrap"><?= h(KATEGORIJE[$s['category']] ?? $s['category']) ?></td>
          <td class="nowrap">
            <?php if ($s['price_per_unit'] === null): ?>
              <span style="color:var(--muted)">po dogovoru</span>
            <?php else: ?>
              <?= !empty($s['price_from']) ? 'od ' : '' ?><?= h(number_format((float) $s['price_per_unit'], 2, ',', '.')) ?> €
              <span style="color:var(--muted)">/ <?= h($s['unit']) ?></span>
            <?php endif; ?>
          </td>
          <td class="nowrap"><span class="znacka <?= !empty($s['active']) ? 'da' : 'ne' ?>"><?= !empty($s['active']) ? 'da' : 'ne' ?></span></td>
          <td class="nowrap"><a href="?uredi=<?= (int) $s['id'] ?>">uredi</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
