<?php
/**
 * Razvojni pregled baze — vse tabele na enem mestu, da se med testiranjem
 * ne rabi odpirati phpMyAdmina.
 *
 * NAMENOMA ne gre skozi AdapterInterface: to ni del poslovne logike, ampak
 * pripomoček za razvoj. Adapterju zato ne dodajamo metod, ki jih asistent
 * nikoli ne potrebuje.
 *
 * DOSTOP: stran prikazuje imena, telefonske številke in naslove strank, zato
 * zahteva ključ iz config.php (DATA_VIEW_KEY). Če ključ ni nastavljen, je
 * stran izklopljena. Pred predajo stranki jo odstrani s strežnika.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

date_default_timezone_set(defined('TIMEZONE') ? TIMEZONE : 'Europe/Ljubljana');

$configuredKey = defined('DATA_VIEW_KEY') ? (string) DATA_VIEW_KEY : '';

if ($configuredKey === '') {
    http_response_code(404);
    exit('Ni na voljo.');
}

$givenKey = (string) ($_POST['key'] ?? $_GET['key'] ?? '');
$authorised = $givenKey !== '' && hash_equals($configuredKey, $givenKey);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return array{0: array<int, array<string, mixed>>, 1: string|null} */
function fetchTable(PDO $pdo, string $sql): array
{
    try {
        return [$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC), null];
    } catch (PDOException $e) {
        return [[], $e->getMessage()];
    }
}

$tables = [];
$connectionError = null;

// Ista predpona kot v adapterju. Preverjena, ker se ime tabele zlepi v SQL.
$prefix = defined('DB_PREFIX') ? (string) DB_PREFIX : '';
if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_]{1,32}$/', $prefix)) {
    exit('Neveljaven DB_PREFIX.');
}

if ($authorised) {
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        $tables['Povpraševanja'] = fetchTable($pdo,
            'SELECT id, created_at, name, phone, email, product, quantity, note, source, status
             FROM ' . $prefix . 'inquiries ORDER BY id DESC');

        $tables['Naročila'] = fetchTable($pdo,
            'SELECT o.id, c.name AS stranka, p.name AS izdelek, o.quantity AS kolicina,
                    o.order_date, o.delivery_date, o.status, o.note
             FROM ' . $prefix . 'orders o
             JOIN ' . $prefix . 'customers c ON c.id = o.customer_id
             JOIN ' . $prefix . 'products  p ON p.id = o.product_id
             ORDER BY o.id');

        $tables['Izdelki'] = fetchTable($pdo,
            'SELECT id, name, category, unit, price_per_unit, stock_quantity, active, description
             FROM ' . $prefix . 'products ORDER BY category, name');

        $tables['Stranke'] = fetchTable($pdo,
            'SELECT id, name, phone, email, address, created_date FROM ' . $prefix . 'customers ORDER BY id');

        $tables['Delovni čas'] = fetchTable($pdo,
            'SELECT day_of_week, opens_at, closes_at, closed FROM ' . $prefix . 'business_hours ORDER BY day_of_week');
    } catch (PDOException $e) {
        error_log('data-view.php: ' . $e->getMessage());
        // Sporočilo pokažemo, ker je stran zaklenjena s ključem in ker brez njega
        // ni mogoče ločiti napačnega gesla od napačnega imena baze.
        $connectionError = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Pregled baze</title>
<style>
  :root {
    --bg: #ffffff;
    --ink: #111111;
    --muted: #6f6f6f;
    --line: #e6e6e6;
    --accent: #bf6c2c;
    --zebra: #faf9f8;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    background: var(--bg);
    color: var(--ink);
    font: 15px/1.6 -apple-system, "Segoe UI", Roboto, system-ui, sans-serif;
  }
  a { color: var(--accent); text-decoration: none; }
  a:hover { text-decoration: underline; }
  .wrap { max-width: 1200px; margin: 0 auto; padding: 0 24px 64px; }
  header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 26px 0; flex-wrap: wrap; gap: 12px;
  }
  .brand { font-weight: 700; font-size: 17px; }
  h1 { font-size: clamp(28px, 5vw, 42px); font-weight: 300; letter-spacing: -.02em; margin: 32px 0 6px; }
  .sub { color: var(--muted); font-size: 14px; margin: 0 0 36px; }
  h2 { font-size: 18px; font-weight: 600; margin: 40px 0 4px; }
  .count { color: var(--muted); font-size: 13px; margin: 0 0 12px; }
  .scroll { overflow-x: auto; border: 1px solid var(--line); border-radius: 12px; }
  table { border-collapse: collapse; width: 100%; font-size: 13.5px; }
  th, td { text-align: left; padding: 9px 14px; white-space: nowrap; }
  th {
    background: var(--zebra); font-weight: 600; font-size: 11px;
    text-transform: uppercase; letter-spacing: .06em; color: var(--muted);
    border-bottom: 1px solid var(--line);
  }
  tbody tr:nth-child(even) { background: var(--zebra); }
  td.wide { white-space: normal; min-width: 240px; }
  .empty, .error { color: var(--muted); padding: 14px; font-size: 14px; }
  .error { color: #b3261e; }
  form.gate { display: flex; gap: 10px; margin: 32px 0; max-width: 420px; }
  input[type=password] {
    flex: 1; padding: 13px 18px; border: 1px solid var(--line);
    border-radius: 999px; font: inherit; color: var(--ink);
  }
  input[type=password]:focus { outline: none; border-color: var(--ink); }
  button {
    padding: 13px 28px; border: 0; border-radius: 999px;
    background: var(--ink); color: #fff; font: inherit; cursor: pointer;
  }
  .warn {
    border: 1px solid var(--line); border-left: 3px solid var(--accent);
    border-radius: 8px; padding: 12px 16px; font-size: 13px; color: var(--muted);
    margin-bottom: 8px;
  }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="brand">Pregled baze</div>
    <nav><a href="chat-test.html">Nazaj na klepet</a></nav>
  </header>

<?php if (!$authorised): ?>
  <h1>Vpiši ključ</h1>
  <p class="sub">Stran prikazuje osebne podatke strank, zato je zaklenjena.</p>
  <form class="gate" method="post">
    <input type="password" name="key" placeholder="Ključ" autocomplete="off" autofocus required>
    <button type="submit">Odpri</button>
  </form>
<?php elseif ($connectionError !== null): ?>
  <h1>Napaka</h1>
  <p class="error"><?= h($connectionError) ?></p>
<?php else: ?>
  <h1>Pregled baze</h1>
  <p class="sub">Stanje na <?= h(date('d.m.Y H:i')) ?>. Osveži stran za najnovejše podatke.</p>

  <div class="warn">
    Ta stran je razvojni pripomoček in prikazuje osebne podatke strank.
    Pred predajo stranki jo odstrani s strežnika.
  </div>

  <h2>Preizkus pošiljanja</h2>
  <p class="count">&nbsp;</p>
  <div class="scroll">
    <?php
    if (isset($_GET['test-mail'])) {
        require_once __DIR__ . '/tools/core/Mailer.php';
        try {
            $mailer = new Mailer([
                'host'   => defined('SMTP_HOST')   ? SMTP_HOST   : '',
                'port'   => defined('SMTP_PORT')   ? SMTP_PORT   : 465,
                'secure' => defined('SMTP_SECURE') ? SMTP_SECURE : 'ssl',
                'user'   => defined('SMTP_USER')   ? SMTP_USER   : '',
                'pass'   => defined('SMTP_PASS')   ? SMTP_PASS   : '',
            ]);
            $mailer->send(
                INQUIRY_EMAIL_TO,
                'Preizkus pošiljanja iz asistenta',
                "To je preizkusno sporočilo.\n\nČe si ga prejel, obvestila o povpraševanjih delujejo.",
                defined('INQUIRY_EMAIL_FROM') && INQUIRY_EMAIL_FROM !== '' ? INQUIRY_EMAIL_FROM : (string) SMTP_USER,
                defined('BUSINESS_NAME') ? BUSINESS_NAME : ''
            );
            echo '<p class="empty">Poslano na ' . h(INQUIRY_EMAIL_TO) . '. Preveri predal, tudi neželeno pošto.</p>';
        } catch (Throwable $e) {
            echo '<p class="error">' . h($e->getMessage()) . '</p>';
        }
    } else {
        echo '<p class="empty"><a href="?key=' . h($givenKey) . '&amp;test-mail=1">Pošlji preizkusno sporočilo</a> na '
            . h(defined('INQUIRY_EMAIL_TO') ? INQUIRY_EMAIL_TO : '(ni nastavljeno)') . '</p>';
    }
    ?>
  </div>

  <h2>Strežnik</h2>
  <p class="count">&nbsp;</p>
  <div class="scroll">
    <table>
      <thead><tr><th>postavka</th><th>vrednost</th></tr></thead>
      <tbody>
        <tr><td>PHP</td><td><?= h(PHP_VERSION) ?></td></tr>
        <tr><td>mail()</td><td><?= function_exists('mail') ? 'na voljo' : 'NI na voljo — obvestila po e-pošti ne delujejo' ?></td></tr>
        <tr><td>cURL</td><td><?= function_exists('curl_init') ? 'na voljo' : 'NI na voljo' ?></td></tr>
        <tr><td>vtičnice</td><td><?= function_exists('stream_socket_client') ? 'na voljo' : 'NI na voljo — SMTP ne bo delal' ?></td></tr>
        <tr><td>OpenSSL</td><td><?= extension_loaded('openssl') ? 'na voljo' : 'NI na voljo — SMTP prek SSL ne bo delal' ?></td></tr>
        <tr><td>povezava na mail.kreativnisplet.si:465</td><td><?php
            if (!function_exists('stream_socket_client')) {
                echo 'ni mogoče preveriti';
            } else {
                $napaka = '';
                $koda = 0;
                $vtic = @stream_socket_client('ssl://mail.kreativnisplet.si:465', $koda, $napaka, 5);
                if ($vtic) {
                    $pozdrav = trim((string) @fgets($vtic, 512));
                    fclose($vtic);
                    echo h('odprta — ' . $pozdrav);
                } else {
                    echo h('ni mogoče vzpostaviti (' . $napaka . ')');
                }
            }
        ?></td></tr>
        <tr><td>predpona tabel</td><td><?= h($prefix === '' ? '(brez)' : $prefix) ?></td></tr>
        <tr><td>obvestila na</td><td><?= h(defined('INQUIRY_EMAIL_TO') && INQUIRY_EMAIL_TO !== '' ? INQUIRY_EMAIL_TO : '(izklopljeno)') ?></td></tr>
      </tbody>
    </table>
  </div>

  <?php foreach ($tables as $title => [$rows, $error]): ?>
    <h2><?= h($title) ?></h2>
    <?php if ($error !== null): ?>
      <p class="count">&nbsp;</p>
      <div class="scroll"><p class="error">Tabele ni mogoče prebrati: <?= h($error) ?></p></div>
    <?php elseif ($rows === []): ?>
      <p class="count">0 vrstic</p>
      <div class="scroll"><p class="empty">Tabela je prazna.</p></div>
    <?php else: ?>
      <p class="count"><?= count($rows) ?> vrstic</p>
      <div class="scroll">
        <table>
          <thead>
            <tr><?php foreach (array_keys($rows[0]) as $column): ?><th><?= h($column) ?></th><?php endforeach; ?></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <?php foreach ($row as $column => $value): ?>
                  <td class="<?= in_array($column, ['description', 'note'], true) ? 'wide' : '' ?>"><?= h($value === null ? '—' : (string) $value) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
<?php endif; ?>
</div>
</body>
</html>
