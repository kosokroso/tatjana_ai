<?php
/**
 * Skupna inicializacija za vse vstopne točke (HTTP endpointi, chat, testi).
 *
 * Naloži konfiguracijo in razrede ter vrne pripravljen ToolRegistry:
 *
 *     $registry = require __DIR__ . '/bootstrap.php';
 *     $response = $registry->call('product-lookup', ['query' => 'pelet']);
 */

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    define('APP_BOOTSTRAPPED', true);

    $configFile = __DIR__ . '/config.php';
    if (!is_readable($configFile)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'data'    => null,
            'error'   => 'Manjka config.php. Kopiraj config.example.php v config.php in vpiši podatke.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once $configFile;

    // Če je config.php shranjen z BOM (Notepad in PowerShell to naredita
    // privzeto), gredo ti bajti ven pred glave. Posledica: vsak header()
    // odpove, vse odgovori s 200 namesto s pravo kodo, v JSON pa se prilepijo
    // opozorila. Brez tega sporočila je vzrok skoraj nemogoče uganiti.
    if (headers_sent($sentFile, $sentLine)) {
        echo json_encode([
            'success' => false,
            'data'    => null,
            'error'   => "Izpis se je začel že v {$sentFile}:{$sentLine}, zato odgovora ni mogoče "
                . 'pravilno oblikovati. Najpogostejši vzrok je datoteka, shranjena kot "UTF-8 z BOM" '
                . '— shrani jo kot UTF-8 brez BOM in brez prazne vrstice pred <?php.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Brez teh razširitev koda odpove sredi izvajanja z nejasno napako.
    // Bolje je povedati takoj in jasno — na shared hostingu se vklopita
    // v cPanelu pod "Select PHP Version" -> "Extensions".
    foreach (['mbstring', 'pdo_mysql'] as $required) {
        if (!extension_loaded($required)) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'data'    => null,
                'error'   => "Na strežniku manjka razširitev PHP '{$required}'.",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // Shared hosting pogosto teče v UTC — brez tega bi "ali je zdaj odprto"
    // in datumi dostave odstopali za eno ali dve uri.
    date_default_timezone_set(defined('TIMEZONE') ? TIMEZONE : 'Europe/Ljubljana');

    require_once __DIR__ . '/tools/core/ToolResponse.php';
    require_once __DIR__ . '/tools/core/AdapterInterface.php';
    require_once __DIR__ . '/tools/core/Tool.php';
    require_once __DIR__ . '/tools/core/Logger.php';
    require_once __DIR__ . '/tools/core/RateLimiter.php';
    require_once __DIR__ . '/tools/core/SlovenianDate.php';
    require_once __DIR__ . '/tools/core/ToolRegistry.php';

    require_once __DIR__ . '/tools/adapters/DirectMySQLAdapter.php';
    require_once __DIR__ . '/tools/adapters/VascoAdapter.php';

    require_once __DIR__ . '/tools/implementations/ProductTool.php';
    require_once __DIR__ . '/tools/implementations/OrderTool.php';
    require_once __DIR__ . '/tools/implementations/BusinessInfoTool.php';
    require_once __DIR__ . '/tools/implementations/InquiryTool.php';
}

return ToolRegistry::create();
