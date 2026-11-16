<?php
// Client orders (rules in core/client_orders.php).
//   GET  /client-orders[?status=&search=]     GET /client-orders/{id}  (lines, reservations)
//   POST /client-orders {customer_id?|customer_name, channel?, due_date?, notes?, branch_id?, lines:[{item_id, qty_kg, note?}]}
//   PUT  /client-orders/{id}                  draft only
//   GET  /client-orders/{id}/check            read-only stock check: free kg per line, shortage, batches
//   POST /client-orders/{id}/approve          a second person (client-orders.approve) when the order is above sales.approval_above_kg
//   POST /client-orders/{id}/reserve          hold stock oldest-expiry-first (Reserved, or Short when not enough)
//   POST /client-orders/{id}/release          drop the hold
//   POST /client-orders/{id}/cancel {reason}
//   POST /client-orders/{id}/request-purchase draft purchase request for the shortage
require_once __DIR__ . '/../core/client_orders.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $id = (int)$GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$action = $GLOBALS['parts'][2] ?? '';

if ($method === 'GET') {
    if ($id && $action === 'check') json_response(co_check($cid, $id));
    if ($id) json_response(co_detail($cid, $id));
    $w = 'o.company_id = ?' . branch_scope_sql('o.branch_id'); $p = [$cid];
    if (!empty($_GET['status'])) { $w .= ' AND o.status = ?'; $p[] = $_GET['status']; }
    if (!empty($_GET['search'])) { $w .= ' AND (o.number LIKE ? OR o.customer_name LIKE ?)'; $p[] = '%' . $_GET['search'] . '%'; $p[] = '%' . $_GET['search'] . '%'; }
    $q = db()->prepare("SELECT o.id, o.number, o.customer_name, o.channel, o.order_date, o.due_date, o.status, o.branch_id, o.purchase_request_id,
                               (SELECT COALESCE(SUM(qty_kg),0) FROM client_order_lines WHERE order_id = o.id) AS total_kg,
                               (SELECT COALESCE(SUM(reserved_kg),0) FROM client_order_lines WHERE order_id = o.id) AS reserved_kg
                        FROM client_orders o WHERE $w ORDER BY o.id DESC LIMIT 300");
    $q->execute($p);
    json_response(array_map(function ($r) { $r['total_kg'] = (float)$r['total_kg']; $r['reserved_kg'] = (float)$r['reserved_kg']; return $r; }, $q->fetchAll()));
}
if ($method === 'POST' && !$id) json_response(co_create($u, $body), 201);
if ($method === 'PUT' && $id) json_response(co_update($u, $id, $body));
if ($method === 'POST' && $id) {
    switch ($action) {
        case 'approve':          json_response(co_approve($u, $id));
        case 'reserve':          json_response(co_reserve($u, $id));
        case 'release':          json_response(co_release($u, $id));
        case 'cancel':           json_response(co_cancel($u, $id, $body));
        case 'request-purchase': json_response(co_request_purchase($u, $id), 201);
    }
}
error_response('Not found', 404);
