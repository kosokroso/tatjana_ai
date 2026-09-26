<?php
/**
 * POST /tools/knowledge-lookup.php
 * { "query": "ali delate tudi za drustva" }
 */
$registry = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/core/Endpoint.php';

Endpoint::handle($registry, 'knowledge-lookup');
