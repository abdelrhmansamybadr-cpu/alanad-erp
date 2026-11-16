<?php
// Finance reports (read only).
//   GET /finance-reports/summary                              numbers for the finance dashboard
//   GET /finance-reports/trial-balance[?to=YYYY-MM-DD]
//   GET /finance-reports/aging                                open supplier invoices by days overdue
//   GET /finance-reports/statement?supplier_id=               invoices and payments with a running balance
//   GET /finance-reports/vat-return?from=&to=                 input VAT from approved purchase invoices, output VAT from the ledger
require_once __DIR__ . '/../core/finance.php';
$cid = (int)$GLOBALS['auth_user']['company_id']; $report = $GLOBALS['id'];
if ($GLOBALS['method'] !== 'GET') error_response('Reports are read-only', 405);
switch ($report) {
    case 'summary':       json_response(fin_summary($cid));
    case 'trial-balance': json_response(fin_trial_balance($cid, !empty($_GET['to']) ? fin_date($_GET['to'], 'to') : null));
    case 'aging':         json_response(fin_aging($cid));
    case 'statement':     json_response(fin_statement($cid, (int)($_GET['supplier_id'] ?? 0)));
    case 'vat-return':
        $from = fin_date($_GET['from'] ?? date('Y-m-01'), 'from'); $to = fin_date($_GET['to'] ?? date('Y-m-t'), 'to');
        json_response(fin_vat_return($cid, $from, $to));
}
error_response('Not found', 404);
