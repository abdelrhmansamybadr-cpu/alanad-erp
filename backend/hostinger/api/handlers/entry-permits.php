<?php
// Entry permits (the gate pass for a truck). Rules in core/fleet.php.
//   GET  /entry-permits[?status=]    GET /entry-permits/{id}  (with the space check while the store has not decided)   GET /entry-permits/space  (free cold-room space now)
//   POST /entry-permits {po_id, vehicle_id | vehicle_plate+driver_name, driver_id_no?, expected_kg?}     request: the truck is coming
//   POST /entry-permits/{id}/store-approve {note?}   |  /store-reject {reason}      the store (entry-permits.store), not the requester
//   POST /entry-permits/{id}/issue {gate_id}          the gate officer (entry-permits.gate)
//   POST /entry-permits/{id}/enter {gross_kg?}        the truck passes: the arrival (delivery) is registered from the permit
//   POST /entry-permits/{id}/cancel {reason}
require_once __DIR__ . '/../core/fleet.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$seg = $GLOBALS['parts'][1] ?? ''; $action = $GLOBALS['parts'][2] ?? '';
$id = ctype_digit((string)$seg) ? (int)$seg : 0;
if ($method === 'GET') {
    if ($seg === 'space') {
        $sc = $GLOBALS['branch_scope'] ?? null;
        json_response(fl_space($cid, isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : (is_array($sc) && count($sc) === 1 ? (int)$sc[0] : null)));
    }
    if ($id) json_response(fl_detail($cid, $id));
    $w = 'e.company_id = ?' . branch_scope_sql('e.branch_id'); $p = [$cid];
    if (!empty($_GET['status'])) { $w .= ' AND e.status = ?'; $p[] = $_GET['status']; }
    $q = db()->prepare("SELECT e.id, e.number, e.status, e.vehicle_plate, e.driver_name, e.expected_kg, e.branch_id, e.po_id, po.number AS po_number, po.supplier_name, g.code AS gate_code, e.created_at, e.delivery_id
                        FROM entry_permits e JOIN purchase_orders po ON po.id = e.po_id LEFT JOIN gates g ON g.id = e.gate_id WHERE $w ORDER BY e.id DESC LIMIT 300");
    $q->execute($p);
    json_response(array_map(function ($r) { $r['expected_kg'] = (float)$r['expected_kg']; return $r; }, $q->fetchAll()));
}
if ($method === 'POST' && !$id) json_response(fl_create($u, $body), 201);
if ($method === 'POST' && $id) {
    switch ($action) {
        case 'store-approve': json_response(fl_store_decide($u, $id, $body, true));
        case 'store-reject':  json_response(fl_store_decide($u, $id, $body, false));
        case 'issue':         json_response(fl_issue($u, $id, $body));
        case 'enter':         json_response(fl_enter($u, $id, $body));
        case 'cancel':        json_response(fl_cancel($u, $id, $body));
    }
}
error_response('Not found', 404);
