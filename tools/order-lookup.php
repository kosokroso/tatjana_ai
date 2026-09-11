<?php
/**
 * POST /tools/order-lookup.php
 * { "order_id": "10005", "verify": "041 234 567" }
 *
 * "verify" je obvezen — glej razlago v tools/implementations/OrderTool.php.
 */
$registry = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/core/Endpoint.php';

Endpoint::handle($registry, 'order-lookup');
