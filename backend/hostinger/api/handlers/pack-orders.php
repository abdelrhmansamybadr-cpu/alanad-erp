<?php
// Pack orders (rules in core/packing.php).
//   GET  /pack-orders[?status=]    GET /pack-orders/{id}  (spec, batches, cartons and pallets)    GET /pack-orders/trace?code=CTN-...   (batches behind a carton / pallet)
//   POST /pack-orders {item_id?, required_kg?, client_order_id?, customer_name?, due_date?, grade?, notes?}      the planner (from a client order the kg, client and date are filled in)
//   POST /pack-orders/{id}/decide {box_kg}        the head of preparation (pack-orders.decide): one of the allowed box sizes
//   POST /pack-orders/{id}/start                  the packer: the stock is issued (grade A, oldest expiry first, the client order's held batches first)
//   POST /pack-orders/{id}/pack {boxes_packed, loss_kg?}      cartons and pallets are created
//   POST /pack-orders/{id}/cancel {reason}        before packing starts
require_once __DIR__ . '/../core/packing.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$seg = $GLOBALS['parts'][1] ?? ''; $action = $GLOBALS['parts'][2] ?? '';
$id = ctype_digit((string)$seg) ? (int)$seg : 0;
if ($method === 'GET') {
    if ($seg === 'trace') json_response(pk_trace($cid, (string)($_GET['code'] ?? '')));
    if ($id) json_response(pk_detail($cid, $id));
    $w = 'o.company_id = ?' . branch_scope_sql('o.branch_id'); $p = [$cid];
    if (!empty($_GET['status'])) { $w .= ' AND o.status = ?'; $p[] = $_GET['status']; }
    $q = db()->prepare("SELECT o.id, o.number, o.status, o.customer_name, o.item_id, i.name AS item_name, o.grade, o.required_kg, o.box_kg, o.due_date, o.boxes_packed, o.cartons, o.pallets, o.client_order_id, o.branch_id
                        FROM pack_orders o JOIN items i ON i.id = o.item_id WHERE $w ORDER BY o.id DESC LIMIT 300");
    $q->execute($p);
    json_response(array_map(function ($r) { $r['required_kg'] = (float)$r['required_kg']; if ($r['box_kg'] !== null) $r['box_kg'] = (float)$r['box_kg']; return $r; }, $q->fetchAll()));
}
if ($method === 'POST' && !$id && $seg === '') json_response(pk_create($u, $body), 201);
if ($method === 'POST' && $id) {
    switch ($action) {
        case 'decide': json_response(pk_decide($u, $id, $body));
        case 'start':  json_response(pk_start($u, $id));
        case 'pack':   json_response(pk_pack($u, $id, $body));
        case 'cancel': json_response(pk_cancel($u, $id, $body));
    }
}
error_response('Not found', 404);
