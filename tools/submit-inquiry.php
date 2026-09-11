<?php
/**
 * POST /tools/submit-inquiry.php
 * { "name": "Janez Novak", "phone": "041 234 567", "product": "bukova drva",
 *   "quantity": "3 kubike", "email": "janez@example.com", "note": "ozek dovoz" }
 *
 * Obvezna sta "name" in "phone". Glej tools/implementations/InquiryTool.php.
 */
$registry = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/core/Endpoint.php';

Endpoint::handle($registry, 'submit-inquiry');
