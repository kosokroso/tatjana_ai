<?php
/**
 * Samotest mesečnega števca Krisp. Zagon: php tests/test-krisp.php
 */
require __DIR__ . '/../tools/core/KrispPoraba.php';

function preveri(string $opis, $dobljeno, $pricakovano): void
{
    if ($dobljeno !== $pricakovano) {
        fwrite(STDERR, "NAPAKA $opis: dobil " . var_export($dobljeno, true) . ", pričakoval " . var_export($pricakovano, true) . "\n");
        exit(1);
    }
    echo "  ok  $opis\n";
}

$mapa = sys_get_temp_dir() . '/krisp-test-' . bin2hex(random_bytes(4));
$k = new KrispPoraba($mapa);

preveri('prazno je 0', $k->sekundVMesecu('maja', '2026-10'), 0);
$k->dodaj('maja', 120, '2026-10');
$k->dodaj('maja', 45, '2026-10');
preveri('seštevek', $k->sekundVMesecu('maja', '2026-10'), 165);
preveri('drug agent ločen', $k->sekundVMesecu('tatjana', '2026-10'), 0);
preveri('drug mesec ločen', $k->sekundVMesecu('maja', '2026-11'), 0);
$k->dodaj('maja', -5, '2026-10');
$k->dodaj('maja', 0, '2026-10');
preveri('negativno in nič se ne zapišeta', $k->sekundVMesecu('maja', '2026-10'), 165);
preveri('neveljavno ime agenta', KrispPoraba::jeVeljavenAgent('../etc'), false);
preveri('veljavno ime agenta', KrispPoraba::jeVeljavenAgent('maja'), true);
$k->dodaj('../x', 100, '2026-10');
preveri('neveljavno ime ne ustvari datoteke', count(glob($mapa . '/*')), 1);

foreach (glob($mapa . '/*') as $f) { unlink($f); }
rmdir($mapa);
echo "\nvse v redu\n";
