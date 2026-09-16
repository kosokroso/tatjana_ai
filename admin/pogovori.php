<?php
/**
 * Pregled pogovorov.
 *
 * `logs/conversations-*.log` nastaja od začetka, a ga nihče ni bral. To je edini
 * vir, iz katerega se vidi, kje je asistentka zgrešila: kdaj je odgovorila brez
 * klica orodja, kdaj je sistem odpovedal in kje je stranka odnehala. Brez tega
 * se prompt izboljšuje na slepo.
 *
 * Ni namenjen iskanju strank — osebni podatki so v dnevniku maskirani (GDPR).
 * Za kontakte je stran s povpraševanji.
 *
 * Bere neposredno datoteke, ne skozi adapter: dnevnik ni poslovni podatek in
 * pri drugem viru podatkov ostane enak.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['dejanje'] ?? '') === 'odjava') {
    adminOdjava();
    header('Location: ./');
    exit;
}

if (!adminPrijavljen()) {
    header('Location: ./');
    exit;
}

/** Koliko zadnjih vrstic preberemo na datoteko — dnevnik lahko zraste. */
const NAJVEC_VRSTIC = 400;

/**
 * Prebere zadnjih N vrstic datoteke brez nalaganja celote v pomnilnik.
 *
 * @return string[]
 */
function zadnjeVrstice(string $pot, int $koliko): array
{
    $f = @fopen($pot, 'r');
    if ($f === false) {
        return [];
    }

    $vrstice = [];
    $blok    = 8192;
    $ostanek = '';

    fseek($f, 0, SEEK_END);
    $polozaj = ftell($f);

    while ($polozaj > 0 && count($vrstice) <= $koliko) {
        $beri    = (int) min($blok, $polozaj);
        $polozaj -= $beri;
        fseek($f, $polozaj);
        $del = (string) fread($f, $beri);

        $kos     = $del . $ostanek;
        $deli    = explode("\n", $kos);
        $ostanek = array_shift($deli) ?? '';
        $vrstice = array_merge($deli, $vrstice);
    }

    if ($ostanek !== '') {
        array_unshift($vrstice, $ostanek);
    }

    return array_values(array_filter(array_map('trim', $vrstice), static fn($v) => $v !== ''));
}

$dni     = max(1, min((int) ($_GET['dni'] ?? 3), 14));
$samoTez = isset($_GET['tezave']);

$pogovori = [];
for ($i = 0; $i < $dni; $i++) {
    $datum = date('Y-m-d', strtotime("-{$i} days"));
    $pot   = LOG_DIR . '/conversations-' . $datum . '.log';

    foreach (zadnjeVrstice($pot, NAJVEC_VRSTIC) as $vrstica) {
        $z = json_decode($vrstica, true);
        if (!is_array($z)) {
            continue;
        }

        $orodja   = $z['tool_calls'] ?? [];
        $napaka   = $z['error'] ?? null;
        $odgovor  = (string) ($z['assistant_message'] ?? '');

        // Kaj šteje za težavo:
        //  - sistem je odpovedal (zapisana napaka)
        //  - asistentka je odgovorila brez orodja na vprašanje, ki zveni kot
        //    vprašanje o ceni; tak odgovor je lahko izmišljen
        //  - porabila je vse kroge klicanja orodij in vrnila rezervni odgovor
        $vprasanje  = (string) ($z['user_message'] ?? '');
        $zvenikotCena = (bool) preg_match('/cen|stan|kolik|€|eur|ponudb|placi|plači/iu', $vprasanje);
        $brezOrodja = $zvenikotCena && !$orodja;
        $rezervni   = strpos($odgovor, 'sistem mi trenutno ne odgovori') !== false
                    || strpos($odgovor, 'Sistem mi trenutno') !== false;

        $z['_tezava'] = $napaka !== null || $brezOrodja || $rezervni;
        $z['_razlog'] = $napaka !== null ? 'napaka sistema'
            : ($rezervni ? 'rezervni odgovor'
            : ($brezOrodja ? 'brez orodja pri vprašanju o ceni' : ''));

        if (!$samoTez || $z['_tezava']) {
            $pogovori[] = $z;
        }
    }
}

usort($pogovori, static fn($a, $b) => strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? '')));

$skupaj  = count($pogovori);
$stTezav = count(array_filter($pogovori, static fn($z) => !empty($z['_tezava'])));
$naslov  = defined('BUSINESS_NAME') ? BUSINESS_NAME : 'Asistent';
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Pogovori — <?= h($naslov) ?></title>
<style>
  :root{--bg:#fdf9f4;--bg-2:#fbf4ea;--panel:#fff;--ink:#1f1c19;--muted:#8d8279;
    --line:#ece2d6;--accent:#e8943a;--teal:#3aaecf;--zebra:#fbf6ef}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);
    font:15px/1.6 "Segoe UI",-apple-system,Roboto,system-ui,sans-serif}
  a{color:var(--accent);text-decoration:none}
  a:hover{text-decoration:underline}
  .wrap{max-width:1000px;margin:0 auto;padding:0 24px 64px}
  header{display:flex;align-items:center;justify-content:space-between;
    padding:22px 0;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:12px}
  .brand{font-weight:800;font-size:17px}
  .brand span{color:var(--teal)}
  nav a{margin-right:16px;color:var(--ink);font-size:14px}
  nav a.aktiven{color:var(--accent);font-weight:600}
  h1{font-size:clamp(26px,4vw,38px);font-weight:300;letter-spacing:-.02em;margin:34px 0 6px}
  .sub{color:var(--muted);font-size:14px;margin:0 0 24px}

  .zavihki{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px;align-items:center}
  .zavihki a{padding:8px 16px;border:1px solid var(--line);border-radius:999px;
    color:var(--ink);font-size:14px;background:var(--panel);transition:border-color .2s,color .2s}
  .zavihki a:hover{border-color:var(--accent);color:var(--accent);text-decoration:none}
  .zavihki a.aktiven{background:var(--ink);color:#fff;border-color:var(--ink)}

  .pogovor{background:var(--panel);border:1px solid var(--line);border-radius:16px;
    padding:20px 22px;margin-bottom:14px;box-shadow:0 4px 18px rgba(31,28,25,.04);
    animation:vstop .4s ease both}
  .pogovor.tezava{border-left:3px solid #d9534f}
  @keyframes vstop{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

  .glava{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;
    font-size:12px;color:var(--muted);margin-bottom:12px}
  .kdo{font-size:11px;text-transform:uppercase;letter-spacing:.1em;
    color:var(--muted);font-weight:700;margin:0 0 3px}
  .vprasanje{background:var(--bg-2);border-radius:12px;padding:10px 15px;margin:0 0 14px}
  .odgovor{margin:0 0 12px;white-space:pre-wrap}
  .znacke{display:flex;gap:6px;flex-wrap:wrap}
  .znacka{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px}
  .znacka.orodje{background:#e4f2f7;color:#1f7d99;
    font-family:ui-monospace,Consolas,monospace;font-size:11.5px}
  .znacka.opozorilo{background:#fdeeec;color:#a4302a;font-weight:600}
  .prazno{padding:50px;text-align:center;color:var(--muted);
    background:var(--panel);border:1px solid var(--line);border-radius:16px}
  .opomba{font-size:13px;color:var(--muted);margin-top:26px;
    border-top:1px solid var(--line);padding-top:18px}
  @media (prefers-reduced-motion: reduce){
    *,*::before,*::after{animation-duration:.01ms !important;transition-duration:.01ms !important}
  }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="brand"><?= h($naslov) ?> <span>— pogovori</span></div>
    <div>
      <nav style="display:inline">
        <a href="./">Povpraševanja</a>
        <a href="storitve.php">Storitve</a>
        <a href="pogovori.php" class="aktiven">Pogovori</a>
      </nav>
      <form method="post" style="display:inline">
        <input type="hidden" name="dejanje" value="odjava">
        <button type="submit" style="background:transparent;color:var(--muted);
          border:1px solid var(--line);font:inherit;font-size:13px;padding:7px 14px;
          border-radius:999px;cursor:pointer">Odjava</button>
      </form>
    </div>
  </header>

  <h1>Pogovori</h1>
  <p class="sub">
    <?= $skupaj ?> vprašanj v zadnjih <?= $dni ?> dneh<?= $stTezav ? ', od tega ' . $stTezav . ' s težavo' : '' ?>.
    Osebni podatki so v dnevniku maskirani.
  </p>

  <div class="zavihki">
    <?php foreach ([1 => 'danes', 3 => '3 dni', 7 => 'teden', 14 => '14 dni'] as $d => $oznaka): ?>
      <a href="?dni=<?= $d ?><?= $samoTez ? '&tezave=1' : '' ?>" class="<?= $dni === $d ? 'aktiven' : '' ?>"><?= h($oznaka) ?></a>
    <?php endforeach; ?>
    <span style="color:var(--muted)">|</span>
    <a href="?dni=<?= $dni ?><?= $samoTez ? '' : '&tezave=1' ?>" class="<?= $samoTez ? 'aktiven' : '' ?>">samo težave</a>
  </div>

<?php if (!$pogovori): ?>
  <div class="prazno">
    <?= $samoTez ? 'V tem obdobju ni zaznanih težav.' : 'V tem obdobju ni zabeleženih pogovorov.' ?>
  </div>
<?php else: ?>
  <?php foreach ($pogovori as $z): ?>
    <div class="pogovor <?= !empty($z['_tezava']) ? 'tezava' : '' ?>">
      <div class="glava">
        <span><?= h(date('j. n. Y H:i', strtotime((string) ($z['ts'] ?? 'now')))) ?></span>
        <span style="font-family:ui-monospace,Consolas,monospace"><?= h((string) ($z['request_id'] ?? '')) ?></span>
      </div>

      <?php if (!empty($z['user_message'])): ?>
        <p class="kdo">Stranka</p>
        <p class="vprasanje"><?= h((string) $z['user_message']) ?></p>
      <?php endif; ?>

      <?php if (!empty($z['assistant_message'])): ?>
        <p class="kdo">Tatjana</p>
        <p class="odgovor"><?= h((string) $z['assistant_message']) ?></p>
      <?php endif; ?>

      <div class="znacke">
        <?php foreach ((array) ($z['tool_calls'] ?? []) as $o): ?>
          <span class="znacka orodje"><?= h((string) $o) ?></span>
        <?php endforeach; ?>
        <?php if (!empty($z['_razlog'])): ?>
          <span class="znacka opozorilo"><?= h((string) $z['_razlog']) ?></span>
        <?php endif; ?>
        <?php if (!empty($z['error'])): ?>
          <span class="znacka opozorilo"><?= h(mb_strimwidth((string) $z['error'], 0, 90, '…')) ?></span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

  <p class="opomba">
    <strong>Kaj iskati.</strong> Odgovor brez oznake orodja pri vprašanju o ceni pomeni,
    da je asistentka znesek povedala po spominu — to je najhujša napaka in razlog za
    popravek sistemskega prompta. Rezervni odgovor pomeni, da OpenAI ni odgovoril.
    Dnevnik se hrani <?= defined('LOG_RETENTION_DAYS') ? (int) LOG_RETENTION_DAYS : 14 ?> dni,
    nato se sam pobriše.
  </p>
</div>
</body>
</html>
