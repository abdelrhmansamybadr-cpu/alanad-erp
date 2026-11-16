<?php
// Production reports (read only).
//   GET /production-reports/summary                      numbers for the production dashboard
//   GET /production-reports/yield?from=&to=              yield by product, loss by supplier and by cold room, waste by reason (completed orders)
//   GET /production-reports/waste-reasons                the waste reasons (class high = still has value, low = rubbish; default decision)
require_once __DIR__ . '/../core/production.php';
$cid = (int)$GLOBALS['auth_user']['company_id']; $report = $GLOBALS['id'];
if ($GLOBALS['method'] !== 'GET') error_response('Reports are read-only', 405);
switch ($report) {
    case 'summary': json_response(prod_summary($cid));
    case 'yield':
        $from = fin_date($_GET['from'] ?? date('Y-m-01'), 'from'); $to = fin_date($_GET['to'] ?? date('Y-m-t'), 'to');
        json_response(prod_yield_report($cid, $from, $to));
    case 'waste-reasons':
        $q = db()->prepare('SELECT code, name_en, name_ar, waste_class, default_decision FROM prep_waste_reasons WHERE company_id = ? AND is_active = 1 ORDER BY waste_class DESC, code');
        $q->execute([$cid]);
        json_response($q->fetchAll());
}
error_response('Not found', 404);
