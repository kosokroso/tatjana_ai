<?php
/**
 * POST /tools/business-info.php
 * { "info_type": "hours" }   // hours | delivery_regions | payments
 */
$registry = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/core/Endpoint.php';

Endpoint::handle($registry, 'business-info');
