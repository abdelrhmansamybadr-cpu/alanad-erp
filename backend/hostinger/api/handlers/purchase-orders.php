<?php
// Purchase orders. Generic CRUD for drafts, but status and approval fields are controlled by the server only.
//   POST /purchase-orders/{id}/submit                    Draft -> Approved (at or below the finance limit) or Submitted (needs a manager)
//   POST /purchase-orders/{id}/decide {action, reason_code?, note?}   finance approval (never the submitter)
// Orders normally come from an approved purchase request:  POST /purchase-requests/{id}/to-po
require_once __DIR__ . '/../core/purchasing.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$action = $GLOBALS['parts'][2] ?? '';

if ($method === 'POST' && $id && $action === 'submit') json_response(pur_po_submit($u, (int)$id));
if ($method === 'POST' && $id && $action === 'decide') json_response(pur_decide($u, 'purchase_orders', (int)$id, $body));

$protected = ['status', 'purchase_request_id', 'workflow_instance_id', 'approved_by', 'approved_at', 'created_by'];
if ($method === 'POST') {
    foreach ($protected as $f) unset($body[$f]);
    $body['created_by'] = $u['user_id'];
    $GLOBALS['body'] = $body;
}
if (in_array($method, ['PUT', 'DELETE'], true) && $id) {
    $st = db()->prepare('SELECT status FROM purchase_orders WHERE id = ? AND company_id = ?');
    $st->execute([$id, $cid]);
    $status = $st->fetchColumn();
    if ($status !== false && $status !== 'Draft') error_response('Only draft orders can be changed or deleted', 409);
    if ($method === 'PUT') { foreach ($protected as $f) unset($body[$f]); $GLOBALS['body'] = $body; }
}

require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'purchase_orders',
    'search' => ['number', 'supplier_name'],
    'filters' => ['status', 'supplier_id'],
    'order' => 'created_at DESC',
    'dates' => true,
    'prefix' => 'PO',
    'branch_col' => 'branch_id',
    'child' => ['key' => 'lines', 'table' => 'purchase_order_lines', 'fk' => 'purchase_order_id'],
]);
