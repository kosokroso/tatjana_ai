<?php
/**
 * POST /tools/appointment.php
 * { "action": "find" }
 * { "action": "book", "starts_at": "2026-09-27 10:00", "name": "...", "phone": "..." }
 */
$registry = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/core/Endpoint.php';

Endpoint::handle($registry, 'appointment');
