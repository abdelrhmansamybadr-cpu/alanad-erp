<?php
// IT asset register. GET /it-assets[?type=&status=&branch_id=&q=]   GET /it-assets/expiring   plus the usual create / update / delete.
require_once __DIR__ . '/../core/crud.php';
require_once __DIR__ . '/../core/it.php';
$u = $GLOBALS['auth_user']; $cid = (int)$GLOBALS['company_id'];
if (($GLOBALS['parts'][1] ?? '') === 'expiring' && $GLOBALS['method'] === 'GET') {
    $days = isset($_GET['days']) ? max(1, (int)$_GET['days']) : (int)wf_setting($cid, 'it.warranty_warning_days', '60');
    $q = db()->prepare("SELECT id, code, name, type, location, warranty_end, DATEDIFF(warranty_end, CURDATE()) AS days_left FROM it_assets WHERE company_id = ? AND status <> 'Retired' AND warranty_end IS NOT NULL AND warranty_end <= DATE_ADD(CURDATE(), INTERVAL ? DAY)" . branch_scope_sql('branch_id') . ' ORDER BY warranty_end');
    $q->execute([$cid, $days]);
    json_response($q->fetchAll());
}
if (in_array($GLOBALS['method'], ['POST', 'PUT'], true)) {
    $b = $GLOBALS['body'];
    if ($GLOBALS['method'] === 'POST') {
        if (!trim((string)($b['code'] ?? '')) || !trim((string)($b['name'] ?? ''))) error_response('code and name are required', 422);
        if (empty($b['branch_id'])) $b['branch_id'] = branch_default($cid);
    }
    if (!empty($b['branch_id'])) $b['branch_id'] = branch_validate($cid, (int)$b['branch_id']);
    foreach (['purchase_date', 'warranty_end'] as $f) if (!empty($b[$f]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$b[$f])) error_response("$f must be a date (YYYY-MM-DD)", 422);
    if (!empty($b['purchase_date']) && !empty($b['warranty_end']) && $b['warranty_end'] < $b['purchase_date']) error_response('The warranty cannot end before the purchase date', 422);
    if (isset($b['cost']) && $b['cost'] !== '' && $b['cost'] !== null && (!is_numeric($b['cost']) || (float)$b['cost'] < 0)) error_response('cost must be a number', 422);
    if (!empty($b['assigned_employee_id'])) { $e = db()->prepare('SELECT 1 FROM employees WHERE id = ? AND company_id = ?'); $e->execute([(int)$b['assigned_employee_id'], $cid]); if (!$e->fetchColumn()) error_response('Employee not found', 422); }
    foreach (['purchase_date', 'warranty_end', 'cost', 'assigned_employee_id'] as $f) if (array_key_exists($f, $b) && $b[$f] === '') $b[$f] = null;
    $GLOBALS['body'] = $b;
}
crud_handle(['table' => 'it_assets', 'search' => ['code', 'name', 'serial_no', 'location'], 'filters' => ['type', 'status', 'branch_id'], 'order' => 'id DESC', 'branch_col' => 'branch_id']);
