<?php
// Shipments: the delivery note, loading check, tax invoice, exit permit and dispatch (rules in core/sales.php).
//   GET  /shipments[?status=]    GET /shipments/{id}  (units, lines per item and grade, invoice)    GET /shipments/available-units  (packed pallets / cartons not yet on a shipment)
//   POST /shipments {unit_ids:[...], client_order_id? | customer_id? | customer_name?, vehicle_id? | vehicle_plate + driver_name, driver_id_no?, notes?}
//   POST /shipments/{id}/load {codes:[...]}               the dispatcher scans every pallet / carton code (shipments.load)
//   POST /shipments/{id}/invoice {prices?:{"itemId:grade": price}}     the tax invoice (shipments.invoice): prices come from Sales → Prices unless given
//   POST /shipments/{id}/exit-permit                      the gate officer (shipments.gate): the exit permit (إذن خروج)
//   POST /shipments/{id}/dispatch                         the truck leaves: the units become Shipped
//   POST /shipments/{id}/deliver {received_by}            the client received it
//   POST /shipments/{id}/cancel {reason}                  before the invoice only
require_once __DIR__ . '/../core/sales.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$seg = $GLOBALS['parts'][1] ?? ''; $action = $GLOBALS['parts'][2] ?? '';
$id = ctype_digit((string)$seg) ? (int)$seg : 0;
if ($method === 'GET') {
    if ($seg === 'available-units') {
        $q = db()->prepare("SELECT u.id, u.kind, u.code, u.boxes, u.kg, o.number AS pack_order, o.customer_name, o.client_order_id, o.item_id, o.grade, i.name AS item_name, o.branch_id
                            FROM pack_units u JOIN pack_orders o ON o.id = u.order_id JOIN items i ON i.id = o.item_id
                            WHERE u.company_id = ? AND u.status = 'Packed'" . branch_scope_sql('o.branch_id') . " AND u.id NOT IN (SELECT su.unit_id FROM shipment_units su JOIN shipments s ON s.id = su.shipment_id WHERE s.status <> 'Cancelled')
                              AND (u.parent_id IS NULL OR u.parent_id NOT IN (SELECT su.unit_id FROM shipment_units su JOIN shipments s ON s.id = su.shipment_id WHERE s.status <> 'Cancelled'))
                            ORDER BY o.id, u.kind = 'carton', u.id LIMIT 500");
        $q->execute([$cid]);
        json_response(array_map(function ($r) { $r['kg'] = (float)$r['kg']; return $r; }, $q->fetchAll()));
    }
    if ($id) json_response(sh_detail($cid, $id));
    $w = 's.company_id = ?' . branch_scope_sql('s.branch_id'); $p = [$cid];
    if (!empty($_GET['status'])) { $w .= ' AND s.status = ?'; $p[] = $_GET['status']; }
    $q = db()->prepare("SELECT s.id, s.number, s.status, s.customer_name, s.vehicle_plate, s.exit_permit_no, s.branch_id, s.dispatched_at, s.invoice_id, si.number AS invoice_number,
                               (SELECT COALESCE(SUM(u.kg),0) FROM shipment_units su JOIN pack_units u ON u.id = su.unit_id WHERE su.shipment_id = s.id) AS kg
                        FROM shipments s LEFT JOIN sales_invoices si ON si.id = s.invoice_id WHERE $w ORDER BY s.id DESC LIMIT 300");
    $q->execute($p);
    json_response(array_map(function ($r) { $r['kg'] = (float)$r['kg']; return $r; }, $q->fetchAll()));
}
if ($method === 'POST' && !$id && $seg === '') json_response(sh_create($u, $body), 201);
if ($method === 'POST' && $id) {
    switch ($action) {
        case 'load':        json_response(sh_load($u, $id, $body));
        case 'invoice':     json_response(sh_invoice($u, $id, $body), 201);
        case 'exit-permit': json_response(sh_exit_permit($u, $id));
        case 'dispatch':    json_response(sh_dispatch($u, $id));
        case 'deliver':     json_response(sh_deliver($u, $id, $body));
        case 'cancel':      json_response(sh_cancel($u, $id, $body));
    }
}
error_response('Not found', 404);
