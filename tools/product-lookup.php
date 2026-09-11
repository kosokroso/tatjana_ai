<?php
/**
 * POST /tools/product-lookup.php
 * { "query": "bukova drva", "action": "get_price", "category": "drva" }
 */
$registry = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/core/Endpoint.php';

Endpoint::handle($registry, 'product-lookup');
