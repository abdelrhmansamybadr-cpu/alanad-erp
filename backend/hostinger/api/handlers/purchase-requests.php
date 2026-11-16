<?php
// Purchase requests (PR).
//   GET  /purchase-requests[?status=&search=]            GET /purchase-requests/{id}  (with lines)
//   GET  /purchase-requests/suggestions                  items below their reorder level
//   POST /purchase-requests {needed_by?, notes?, lines:[{item_id, qty, note?}]}            create (Draft)
//   PUT  /purchase-requests/{id}                         edit a Draft / Rejected request (requester only)
//   POST /purchase-requests/{id}/submit                  Draft -> Pending (supervisor approval) or Approved when approval is switched off
//   POST /purchase-requests/{id}/decide {action: approve|reject, reason_code?, note?}   (managers; never the submitter)
//   POST /purchase-requests/{id}/to-po {supplier_id}     Approved -> purchase order (prices come from the supplier's dated price list)
//   DELETE /purchase-requests/{id}                       Draft / Rejected only
require_once __DIR__ . '/../core/purchasing.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$action = $GLOBALS['parts'][2] ?? '';

if ($method === 'GET') {
    if ($id === 'suggestions') json_response(pur_suggestions($cid));
    if ($id) json_response(pur_pr($cid, (int)$id));
    $w = 'p.company_id = ?'; $p = [$cid];
    if (!empty($_GET['status'])) { $w .= ' AND p.status = ?'; $p[] = $_GET['status']; }
    if (!empty($_GET['search'])) { $w .= ' AND p.number LIKE ?'; $p[] = '%' . $_GET['search'] . '%'; }
    $q = db()->prepare("SELECT p.*, u.name AS requested_by_name, (SELECT COUNT(*) FROM purchase_request_lines l WHERE l.purchase_request_id = p.id) AS line_count
                        FROM purchase_requests p LEFT JOIN users u ON u.id = p.requested_by WHERE $w ORDER BY p.id DESC LIMIT 300");
    $q->execute($p); json_response($q->fetchAll());
}
if ($method === 'POST' && !$id) json_response(pur_pr_create($u, $body), 201);
if ($method === 'PUT' && $id) json_response(pur_pr_update($u, (int)$id, $body));
if ($method === 'POST' && $id && $action === 'submit') {
    $pr = pur_pr($cid, (int)$id);
    if ((int)$pr['requested_by'] !== (int)$u['user_id'] && $u['role'] !== 'admin') error_response('Only the requester can submit this request', 403);
    json_response(pur_pr_submit($u, (int)$id));
}
if ($method === 'POST' && $id && $action === 'decide') json_response(pur_decide($u, 'purchase_requests', (int)$id, $body));
if ($method === 'POST' && $id && $action === 'to-po') {
    if (!permission_allows($u['permissions'], 'purchase-orders', 'create')) error_response('Forbidden', 403);
    json_response(pur_pr_to_po($u, (int)$id, (int)($body['supplier_id'] ?? 0)), 201);
}
if ($method === 'DELETE' && $id) {
    $pr = pur_pr($cid, (int)$id);
    if (!in_array($pr['status'], ['Draft', 'Rejected'], true)) error_response('Only draft or rejected requests can be deleted', 409);
    if ((int)$pr['requested_by'] !== (int)$u['user_id'] && $u['role'] !== 'admin') error_response('Only the requester can delete this request', 403);
    db()->prepare('DELETE FROM purchase_requests WHERE id = ? AND company_id = ?')->execute([$id, $cid]);
    json_response(['message' => 'Deleted']);
}
error_response('Not found', 404);
