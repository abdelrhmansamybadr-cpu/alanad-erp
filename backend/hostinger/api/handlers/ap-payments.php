<?php
// Supplier payments: entered, then approved by a second person; approval posts Dr Trade payables / Cr Bank (core/finance.php).
//   GET  /ap-payments[?status=&supplier_id=]    GET /ap-payments/{id}  (with allocations)
//   POST /ap-payments {supplier_id, date?, amount, method?, reference?, bank_account_id?, allocations:[{invoice_id, amount}]}     Pending
//   POST /ap-payments/{id}/decide {action: approve|reject, reason_code?, note?}          ap-payments.approve, never the person who entered it
require_once __DIR__ . '/../core/finance.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$action = $GLOBALS['parts'][2] ?? '';

if ($method === 'GET') {
    if ($id) json_response(pay_detail($cid, (int)$id));
    $w = 'p.company_id = ?'; $p = [$cid];
    foreach (['status', 'supplier_id'] as $f) if (!empty($_GET[$f])) { $w .= " AND p.$f = ?"; $p[] = $_GET[$f]; }
    $q = db()->prepare("SELECT p.id, p.number, p.date, p.amount, p.method, p.reference, p.status, p.created_by, p.supplier_id, s.name AS supplier_name FROM ap_payments p JOIN suppliers s ON s.id = p.supplier_id WHERE $w ORDER BY p.id DESC LIMIT 300");
    $q->execute($p);
    json_response(array_map(function ($r) { $r['amount'] = (float)$r['amount']; return $r; }, $q->fetchAll()));
}
if ($method === 'POST' && !$id) json_response(pay_create($u, $body), 201);
if ($method === 'POST' && $id && $action === 'decide') json_response(pay_decide($u, (int)$id, $body));
error_response('Not found', 404);
