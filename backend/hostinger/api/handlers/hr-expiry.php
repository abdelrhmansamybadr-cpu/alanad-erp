<?php
// Documents that are expired or about to expire (iqama, passport, health card, contract end), and the HR home numbers.
//   GET /hr-expiry[?days=60]      GET /hr-expiry/summary
require_once __DIR__ . '/../core/hr.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id'];
if ($GLOBALS['method'] !== 'GET') error_response('Method not allowed', 405);
if (($GLOBALS['parts'][1] ?? '') === 'summary') json_response(hr_summary($cid));
json_response(hr_expiry($cid, isset($_GET['days']) ? max(1, (int)$_GET['days']) : null));
