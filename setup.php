<?php
/**
 * Namestitveni čarovnik.
 *
 * Postavitev nove stranke je doslej pomenila ročno: ustvariti bazo, pognati SQL,
 * prepisati config.php in izpolniti petnajst vrednosti, od tega tri naključne
 * skrivnosti. Ura dela in vsaj ena tipkarska napaka. Ta stran to opravi v petih
 * minutah in sproti preveri, ali strežnik sploh zmore, kar potrebujemo.
 *
 * VARNOST: stran zna zapisati config.php, zato se sama izklopi, takoj ko ta
 * obstaja. Brez tega bi lahko kdorkoli prepisal nastavitve delujoče namestitve
 * in preusmeril bazo ter ključe nase.
 */

declare(strict_types=1);

const CONFIG_POT = __DIR__ . '/config.php';
const SHEMA_POT  = __DIR__ . '/sql/schema.sql';

$zeNamescen = is_file(CONFIG_POT);

$napake    = [];
$sporocilo = null;
$vnos      = $_POST;

if (!$zeNamescen && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $napake = preveriVnos($vnos);

    if (!$napake) {
        try {
            $pdo = poveziBazo($vnos);
            $ustvarjenih = ustvariTabele($pdo, $vnos['db_prefix'] ?: 'ai_');
            zapisiConfig($vnos);

            $sporocilo = 'Namestitev končana. Ustvarjenih tabel: ' . $ustvarjenih . '.';
            $zeNamescen = true;
        } catch (Throwable $e) {
            $napake[] = $e->getMessage();
        }
    }
}

// ---------------------------------------------------------------- preverbe

/** @return array[] ime, ali je na voljo, zakaj je pomembno */
function preveriStreznik(): array
{
    return [
        ['PHP 7.4 ali novejši', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION],
        ['PDO MySQL', extension_loaded('pdo_mysql'), 'brez tega ne dela nič'],
        ['mbstring', extension_loaded('mbstring'), 'slovenski znaki v besedilu'],
        ['cURL', function_exists('curl_init'), 'klici na OpenAI'],
        ['OpenSSL', extension_loaded('openssl'), 'SMTP prek SSL'],
        ['vtičnice', function_exists('stream_socket_client'), 'pošiljanje e-pošte'],
        ['pisanje v mapo', is_writable(__DIR__), 'zapis config.php'],
    ];
}

function preveriVnos(array $v): array
{
    $n = [];

    foreach (['db_host' => 'Gostitelj baze', 'db_name' => 'Ime baze', 'db_user' => 'Uporabnik baze',
              'business_name' => 'Ime podjetja', 'admin_password' => 'Skrbniško geslo'] as $k => $oznaka) {
        if (trim((string) ($v[$k] ?? '')) === '') {
            $n[] = $oznaka . ' je obvezen podatek.';
        }
    }

    if (strlen((string) ($v['admin_password'] ?? '')) < 10) {
        $n[] = 'Skrbniško geslo naj ima vsaj deset znakov.';
    }

    $prefix = (string) ($v['db_prefix'] ?? 'ai_');
    if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_]{1,32}$/', $prefix)) {
        $n[] = 'Predpona tabel sme vsebovati samo črke, številke in podčrtaj.';
    }

    foreach (['business_email' => 'E-pošta podjetja', 'inquiry_to' => 'Naslov za obvestila'] as $k => $oznaka) {
        $e = trim((string) ($v[$k] ?? ''));
        if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $n[] = $oznaka . ' ni veljaven e-poštni naslov.';
        }
    }

    return $n;
}

function poveziBazo(array $v): PDO
{
    try {
        return new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $v['db_host'], $v['db_name']),
            (string) $v['db_user'],
            (string) ($v['db_pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        // Sporočilo PDO tu pokažemo namenoma: nameščevalec mora vedeti, ali je
        // napačno geslo ali napačno ime baze, sicer ugiba.
        throw new RuntimeException('Povezava z bazo ni uspela: ' . $e->getMessage());
    }
}

/** Zažene sql/schema.sql in vrne število ustvarjenih tabel. */
function ustvariTabele(PDO $pdo, string $prefix): int
{
    $sql = @file_get_contents(SHEMA_POT);
    if ($sql === false) {
        throw new RuntimeException('Datoteke sql/schema.sql ni mogoče prebrati.');
    }

    if ($prefix !== 'ai_') {
        $sql = preg_replace('/\bai_(products|customers|orders|inquiries|business_hours)\b/', $prefix . '$1', $sql);
    }

    // Komentarji ven, nato razrez po podpičju. Shema ne vsebuje podpičij
    // znotraj nizov, zato preprost razrez zadošča.
    $sql = preg_replace('/^--.*$/m', '', (string) $sql);
    $stavki = array_filter(array_map('trim', explode(';', (string) $sql)));

    $tabel = 0;
    foreach ($stavki as $stavek) {
        $pdo->exec($stavek);
        if (stripos($stavek, 'CREATE TABLE') === 0) {
            $tabel++;
        }
    }

    return $tabel;
}

function zapisiConfig(array $v): void
{
    $skrivnost = bin2hex(random_bytes(24));
    $pregled   = bin2hex(random_bytes(12));
    $hash      = password_hash((string) $v['admin_password'], PASSWORD_DEFAULT);

    $q = static fn($s) => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $s) . "'";

    $config = <<<PHP
<?php
/**
 * Nastavitve te namestitve. Ustvaril setup.php.
 *
 * Ta datoteka je v .gitignore in ne sme iti v git — vsebuje gesla in ključe.
 */

define('TIMEZONE', {$q($v['timezone'] ?: 'Europe/Ljubljana')});

// ---------------------------------------------------------------- baza
define('DB_HOST', {$q($v['db_host'])});
define('DB_NAME', {$q($v['db_name'])});
define('DB_USER', {$q($v['db_user'])});
define('DB_PASS', {$q($v['db_pass'] ?? '')});
define('DB_CHARSET', 'utf8mb4');
define('DB_PREFIX', {$q($v['db_prefix'] ?: 'ai_')});

define('ADAPTER', 'DirectMySQLAdapter');
define('ALLOWED_TOOLS', ['product-lookup', 'order-lookup', 'business-info', 'submit-inquiry']);

// ---------------------------------------------------------------- dnevniki
define('LOG_DIR', __DIR__ . '/logs');
define('LOG_MASK_PII', true);
define('LOG_RETENTION_DAYS', 14);

// ---------------------------------------------------------------- omejitve
// Stetje klicev ustavi naval, dnevni proracun zetonov pa dejanski strosek:
// en klic z dolgo zgodovino stane toliko kot deset kratkih.
define('RATE_LIMIT_PER_MINUTE', 60);
define('CHAT_RATE_LIMIT_PER_MINUTE', 20);
define('CHAT_RATE_LIMIT_PER_DAY', 100);
define('CHAT_MAX_PER_DAY_TOTAL', 500);
define('DAILY_TOKEN_BUDGET', 200000);

// Skupna skrivnost za glasovnega agenta. Zgenerirana ob namestitvi.
define('TOOL_SECRET', {$q($skrivnost)});

define('REQUIRE_HTTPS', true);

// ---------------------------------------------------------------- OpenAI
define('OPENAI_API_KEY', {$q($v['openai_key'] ?? '')});
define('OPENAI_MODEL', 'gpt-4o-mini');
define('OPENAI_TIMEOUT_SECONDS', 20);

define('STT_MODEL', 'gpt-4o-transcribe');
define('TTS_MODEL', 'gpt-4o-mini-tts');
define('TTS_VOICE', 'shimmer');
define('SPEECH_LANGUAGE', 'sl');

// 'azure' ima prava slovenska glasova in pravilno prebere stevila.
// 'openai' slovenscino bere s tujim naglasom.
define('TTS_PROVIDER', {$q(($v['azure_key'] ?? '') !== '' ? 'azure' : 'openai')});
define('AZURE_SPEECH_KEY', {$q($v['azure_key'] ?? '')});
define('AZURE_SPEECH_REGION', {$q($v['azure_region'] ?: 'italynorth')});
define('AZURE_TTS_VOICE', 'sl-SI-PetraNeural');

define('SYSTEM_PROMPT_FILE',    __DIR__ . '/ai/system-prompt.txt');
define('TOOL_DEFINITIONS_FILE', __DIR__ . '/ai/tool-definitions.json');
define('MAX_TOOL_ROUNDS', 4);

// Domene, s katerih je klepet lahko vgrajen. Prazno = samo isti izvor.
define('CHAT_ALLOWED_ORIGINS', {$v['origins_php']});

define('BUSINESS_INFO_FILE', __DIR__ . '/data/business-info.json');

// ---------------------------------------------------------------- podjetje
define('ASSISTANT_NAME', {$q($v['assistant_name'] ?: 'Asistent')});
define('BUSINESS_NAME',  {$q($v['business_name'])});
define('BUSINESS_PHONE', {$q($v['business_phone'] ?? '')});
define('BUSINESS_EMAIL', {$q($v['business_email'] ?? '')});
define('BUSINESS_DESCRIPTION', {$q($v['business_description'] ?? '')});

// ---------------------------------------------------------------- povprasevanja
define('INQUIRY_EMAIL_TO',   {$q($v['inquiry_to'] ?? '')});
define('INQUIRY_EMAIL_FROM', {$q($v['smtp_user'] ?? '')});

// SMTP: marsikateri shared hosting ima mail() izklopljen.
define('SMTP_HOST',   {$q($v['smtp_host'] ?? '')});
define('SMTP_PORT',   {$q($v['smtp_port'] ?: '465')});
define('SMTP_SECURE', {$q($v['smtp_secure'] ?: 'ssl')});
define('SMTP_USER',   {$q($v['smtp_user'] ?? '')});
define('SMTP_PASS',   {$q($v['smtp_pass'] ?? '')});

// ---------------------------------------------------------------- dostopi
define('ADMIN_PASSWORD_HASH', {$q($hash)});
define('DATA_VIEW_KEY', {$q($pregled)});

PHP;

    // Brez BOM: presledek pred <?php bi podrl vse header() klice in vsak
    // odgovor bi vrnil 200 namesto prave kode.
    if (file_put_contents(CONFIG_POT, $config) === false) {
        throw new RuntimeException('config.php ni bilo mogoče zapisati. Preveri pravice mape.');
    }
}

function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function v(array $vnos, string $k, string $privzeto = ''): string
{
    return h((string) ($vnos[$k] ?? $privzeto));
}

$preverbe   = preveriStreznik();
$vseVredu   = !in_array(false, array_column($preverbe, 1), true);
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Namestitev asistenta</title>
<style>
  :root{--bg:#fdf9f5;--panel:#fff;--ink:#23201d;--muted:#8a8079;
    --line:#ece3d8;--accent:#e8943a;--teal:#3aaecf}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);
    font:15px/1.6 "Segoe UI",-apple-system,Roboto,system-ui,sans-serif}
  .wrap{max-width:820px;margin:0 auto;padding:40px 24px 80px}
  h1{font-size:clamp(28px,5vw,42px);font-weight:300;letter-spacing:-.02em;margin:0 0 8px}
  h2{font-size:17px;font-weight:600;margin:32px 0 14px;padding-top:22px;border-top:1px solid var(--line)}
  h2:first-of-type{border-top:0;padding-top:0}
  .sub{color:var(--muted);margin:0 0 30px}
  .kartica{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:26px}
  .mreza{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
  label{display:block;font-size:13px;color:var(--muted);margin-bottom:5px}
  input,select,textarea{width:100%;padding:11px 14px;border:1px solid var(--line);
    border-radius:9px;font:inherit;color:var(--ink);background:#fff}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent)}
  textarea{min-height:76px;resize:vertical}
  .namig{font-size:12px;color:var(--muted);margin:5px 0 0}
  button{padding:14px 30px;border:0;border-radius:999px;background:var(--accent);
    color:#fff;font:inherit;font-weight:600;cursor:pointer;margin-top:26px}
  button[disabled]{opacity:.45;cursor:default}
  ul.preverbe{list-style:none;padding:0;margin:0}
  ul.preverbe li{display:flex;justify-content:space-between;gap:16px;
    padding:9px 0;border-bottom:1px solid var(--line);font-size:14px}
  ul.preverbe li:last-child{border-bottom:0}
  .da{color:#2f6b30;font-weight:600}
  .ne{color:#a4302a;font-weight:600}
  .obvestilo{border-radius:10px;padding:14px 18px;margin:0 0 22px}
  .obvestilo.ok{background:#eef7ee;border:1px solid #cfe6cf}
  .obvestilo.err{background:#fdeeec;border:1px solid #f2cdc8;color:#a4302a}
  .obvestilo ul{margin:8px 0 0;padding-left:20px}
  code{background:#f3ece4;padding:2px 7px;border-radius:5px;font-size:13.5px}
  ol.naprej{padding-left:20px}
  ol.naprej li{margin-bottom:10px}
</style>
</head>
<body>
<div class="wrap">

<?php if ($zeNamescen): ?>

  <h1><?= $sporocilo ? 'Nameščeno' : 'Že nameščeno' ?></h1>

  <?php if ($sporocilo): ?>
    <p class="obvestilo ok"><?= h($sporocilo) ?></p>
    <div class="kartica">
      <h2 style="margin-top:0">Kaj še narediti</h2>
      <ol class="naprej">
        <li><strong>Izbriši <code>setup.php</code> s strežnika.</strong> Dokler je tam,
            je pot do prepisa nastavitev odprta vsakomur, ki bi kdaj izbrisal
            <code>config.php</code>.</li>
        <li>Vnesi storitve v <a href="admin/storitve.php">skrbniško stran</a> — cene, opise
            in oznako "od". Asistent bere izključno od tam.</li>
        <li>Popravi delovni čas v tabeli <code>ai_business_hours</code>, če se razlikuje od
            privzetega pon–pet 9–17. Asistent ga stranki pove kot dejstvo.</li>
        <li>Preizkusi pošiljanje e-pošte na strani <code>data-view.php</code> (ključ je v
            <code>config.php</code> pod <code>DATA_VIEW_KEY</code>).</li>
        <li>Vgradi klepet na stran:<br>
            <code>&lt;div id="tatjana-chat"&gt;&lt;/div&gt;&lt;script src="/asistent/widget.js" defer&gt;&lt;/script&gt;</code></li>
      </ol>
    </div>
  <?php else: ?>
    <p class="sub">Datoteka <code>config.php</code> že obstaja, zato je čarovnik izklopljen.</p>
    <div class="kartica">
      <p style="margin:0">Za ponovno namestitev najprej odstrani <code>config.php</code>.
      Ta zaščita obstaja zato, da nihče ne more prepisati nastavitev delujoče namestitve
      in preusmeriti baze ter ključev nase.</p>
      <p style="margin:14px 0 0"><a href="admin/">Skrbniška stran</a></p>
    </div>
  <?php endif; ?>

<?php else: ?>

  <h1>Namestitev asistenta</h1>
  <p class="sub">Ustvari tabele, zapiše nastavitve in zgenerira ključe. Traja nekaj minut.</p>

  <?php if ($napake): ?>
    <div class="obvestilo err">
      <strong>Namestitev ni uspela:</strong>
      <ul><?php foreach ($napake as $n): ?><li><?= h($n) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="kartica" style="margin-bottom:26px">
    <h2 style="margin-top:0">Strežnik</h2>
    <ul class="preverbe">
      <?php foreach ($preverbe as [$ime, $ok, $opomba]): ?>
        <li>
          <span><?= h($ime) ?> <span style="color:var(--muted);font-size:12.5px">— <?= h($opomba) ?></span></span>
          <span class="<?= $ok ? 'da' : 'ne' ?>"><?= $ok ? 'na voljo' : 'manjka' ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$vseVredu): ?>
      <p class="namig" style="margin-top:14px">Manjkajoče razširitve vklopiš v cPanelu pod
        <em>Select PHP Version → Extensions</em>. Brez PDO MySQL ne dela nič.</p>
    <?php endif; ?>
  </div>

  <form method="post" class="kartica">
    <h2>Baza</h2>
    <div class="mreza">
      <div><label for="db_host">Gostitelj</label>
        <input id="db_host" name="db_host" value="<?= v($vnos, 'db_host', 'localhost') ?>" required></div>
      <div><label for="db_name">Ime baze</label>
        <input id="db_name" name="db_name" value="<?= v($vnos, 'db_name') ?>" required></div>
      <div><label for="db_user">Uporabnik</label>
        <input id="db_user" name="db_user" value="<?= v($vnos, 'db_user') ?>" required></div>
      <div><label for="db_pass">Geslo</label>
        <input id="db_pass" name="db_pass" type="password" value="<?= v($vnos, 'db_pass') ?>"></div>
      <div><label for="db_prefix">Predpona tabel</label>
        <input id="db_prefix" name="db_prefix" value="<?= v($vnos, 'db_prefix', 'ai_') ?>">
        <p class="namig">Loči tabele asistenta od WordPressovih, kadar si delita bazo.</p></div>
    </div>

    <h2>Podjetje</h2>
    <div class="mreza">
      <div><label for="assistant_name">Ime asistenta</label>
        <input id="assistant_name" name="assistant_name" value="<?= v($vnos, 'assistant_name', 'Tatjana') ?>">
        <p class="namig">Tako se predstavi stranki.</p></div>
      <div><label for="business_name">Ime podjetja</label>
        <input id="business_name" name="business_name" value="<?= v($vnos, 'business_name') ?>" required></div>
      <div><label for="business_phone">Telefon</label>
        <input id="business_phone" name="business_phone" value="<?= v($vnos, 'business_phone') ?>"></div>
      <div><label for="business_email">E-pošta</label>
        <input id="business_email" name="business_email" type="email" value="<?= v($vnos, 'business_email') ?>"></div>
    </div>
    <div style="margin-top:16px">
      <label for="business_description">Kaj podjetje počne</label>
      <textarea id="business_description" name="business_description"><?= v($vnos, 'business_description') ?></textarea>
      <p class="namig">Ena do dve povedi. Gre neposredno v sistemski prompt asistenta.</p>
    </div>
    <div style="margin-top:16px">
      <label for="origins">Domene, kjer bo klepet vgrajen</label>
      <input id="origins" name="origins" value="<?= v($vnos, 'origins') ?>" placeholder="https://primer.si, https://www.primer.si">
      <p class="namig">Ločene z vejico. Prazno pomeni, da klepet sprejme klice z vseh strani.</p>
    </div>

    <h2>OpenAI in govor</h2>
    <div class="mreza">
      <div><label for="openai_key">OpenAI ključ</label>
        <input id="openai_key" name="openai_key" type="password" value="<?= v($vnos, 'openai_key') ?>"></div>
      <div><label for="azure_key">Azure ključ za govor</label>
        <input id="azure_key" name="azure_key" type="password" value="<?= v($vnos, 'azure_key') ?>">
        <p class="namig">Neobvezno. Brez njega govor uporabi OpenAI, ki slovenščino bere s tujim naglasom.</p></div>
      <div><label for="azure_region">Azure regija</label>
        <input id="azure_region" name="azure_region" value="<?= v($vnos, 'azure_region', 'italynorth') ?>"></div>
    </div>

    <h2>Obvestila o povpraševanjih</h2>
    <div class="mreza">
      <div><label for="inquiry_to">Naslov, kamor gredo obvestila</label>
        <input id="inquiry_to" name="inquiry_to" type="email" value="<?= v($vnos, 'inquiry_to') ?>"></div>
      <div><label for="smtp_host">SMTP strežnik</label>
        <input id="smtp_host" name="smtp_host" value="<?= v($vnos, 'smtp_host') ?>" placeholder="mail.primer.si">
        <p class="namig">Na večini gostovanj je <code>mail()</code> izklopljen, zato pošiljamo prek SMTP.</p></div>
      <div><label for="smtp_user">SMTP uporabnik</label>
        <input id="smtp_user" name="smtp_user" value="<?= v($vnos, 'smtp_user') ?>" placeholder="info@primer.si">
        <p class="namig">Cel naslov. Uporabi se tudi kot pošiljatelj.</p></div>
      <div><label for="smtp_pass">SMTP geslo</label>
        <input id="smtp_pass" name="smtp_pass" type="password" value="<?= v($vnos, 'smtp_pass') ?>"></div>
      <div><label for="smtp_port">Vrata</label>
        <input id="smtp_port" name="smtp_port" value="<?= v($vnos, 'smtp_port', '465') ?>"></div>
      <div><label for="smtp_secure">Šifriranje</label>
        <select id="smtp_secure" name="smtp_secure">
          <option value="ssl" <?= ($vnos['smtp_secure'] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL (vrata 465)</option>
          <option value="tls" <?= ($vnos['smtp_secure'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS (vrata 587)</option>
        </select></div>
    </div>

    <h2>Skrbniški dostop</h2>
    <div class="mreza">
      <div><label for="admin_password">Geslo za skrbniško stran</label>
        <input id="admin_password" name="admin_password" type="password" required minlength="10"
               value="<?= v($vnos, 'admin_password') ?>">
        <p class="namig">Najmanj deset znakov. Shrani se zgoščeno, ne v čisti obliki.</p></div>
      <div><label for="timezone">Časovni pas</label>
        <input id="timezone" name="timezone" value="<?= v($vnos, 'timezone', 'Europe/Ljubljana') ?>">
        <p class="namig">Gostovanja pogosto tečejo v UTC, kar pokvari izračun delovnega časa.</p></div>
    </div>

    <?php
    // Domene pretvorimo v PHP polje, ki gre v config.php.
    $origins = array_filter(array_map('trim', explode(',', (string) ($vnos['origins'] ?? ''))));
    $origins_php = $origins
        ? "['" . implode("', '", array_map(static fn($o) => str_replace("'", "\\'", $o), $origins)) . "']"
        : '[]';
    ?>
    <input type="hidden" name="origins_php" value="<?= h($origins_php) ?>">

    <button type="submit" <?= $vseVredu ? '' : 'disabled' ?>>Namesti</button>
    <?php if (!$vseVredu): ?>
      <p class="namig">Najprej uredi manjkajoče razširitve zgoraj.</p>
    <?php endif; ?>
  </form>

<?php endif; ?>

</div>
</body>
</html>
