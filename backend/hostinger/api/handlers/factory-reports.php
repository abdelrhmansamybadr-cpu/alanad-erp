<?php
// Factory reports: GET /factory-reports[?from=YYYY-MM-DD&to=YYYY-MM-DD]  (default: the last 30 days)
require_once __DIR__ . '/../core/reports.php';
if ($GLOBALS['method'] !== 'GET') error_response('Method not allowed', 405);
$to = $_GET['to'] ?? date('Y-m-d'); $from = $_GET['from'] ?? date('Y-m-d', strtotime($to . ' -29 days'));
foreach (['from' => $from, 'to' => $to] as $k => $v) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) error_response("$k must be a date (YYYY-MM-DD)", 422);
if ($from > $to) error_response('from is after to', 422);
if ((strtotime($to) - strtotime($from)) / 86400 > 731) error_response('The period cannot be longer than two years', 422);
json_response(rp_overview((int)$GLOBALS['auth_user']['company_id'], $from, $to));
