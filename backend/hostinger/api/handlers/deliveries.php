<?php
// Deliveries: a truck from the gate to the cold room (rules in core/receiving.php).
//   GET  /deliveries[?status=&po_id=]                          list (with the minutes the truck has been waiting)
//   GET  /deliveries/{id}                                      detail: lines, the order with ordered / stored per item, limits
//   GET  /deliveries/supplier-rating                           reject % and grade A share per supplier
//   POST /deliveries {po_id, driver_name, driver_id_no?, vehicle_plate?, gross_kg?}      arrival (the order must be approved)
//   POST /deliveries/{id}/weigh-in {gross_kg}
//   POST /deliveries/{id}/temperature {truck_air_temp, product_temp}                     out of range -> Temp_Hold
//   POST /deliveries/{id}/temperature-decision {decision: accept|reject, note}           manager (deliveries.approve), not the person who measured
//   POST /deliveries/{id}/quality {lines:[{item_id, grade, received_kg, rejected_kg?, reject_reason_code?, sample_kg?, note?}]}
//   POST /deliveries/{id}/weigh-out {tare_kg}                                            net weight must match the lines
//   POST /deliveries/{id}/accept                                                         quality sign-off (deliveries.approve), not the person who registered the arrival
//   POST /deliveries/{id}/reject {reason_code, note?}                                    send the whole truck back
//   POST /deliveries/{id}/count {units, unit?, note?}                                    the counter (deliveries.count)
//   POST /deliveries/{id}/finance-check {papers_ok, goods_ok, delivery_note_no?, note_units?, note?}   the Finance person (deliveries.finance)
//   POST /deliveries/{id}/route {to: store|sorting}                                       after Finance
//   POST /deliveries/{id}/put-away {lines:[{line_id, cold_room_id, rack?, position?, expiry_date?}], close_order?}   needs stock.create
require_once __DIR__ . '/../core/receiving.php';
require_once __DIR__ . '/../core/receiving3.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$action = $GLOBALS['parts'][2] ?? '';

if ($method === 'GET') {
    if ($id === 'supplier-rating') json_response(rcv_supplier_rating($cid));
    if ($id) json_response(rcv_detail($cid, (int)$id));
    $w = 'd.company_id = ?' . branch_scope_sql('d.branch_id'); $p = [$cid];
    foreach (['status'] as $f) if (!empty($_GET[$f])) { $w .= " AND d.$f = ?"; $p[] = $_GET[$f]; }
    if (!empty($_GET['po_id'])) { $w .= ' AND d.purchase_order_id = ?'; $p[] = (int)$_GET['po_id']; }
    $q = db()->prepare("SELECT d.id, d.number, d.status, d.driver_name, d.vehicle_plate, d.arrived_at, d.gross_kg, d.net_kg, d.temp_ok, d.branch_id, d.purchase_order_id, po.number AS po_number, s.name AS supplier_name,
                               CASE WHEN d.status IN ('Stored','Rejected') THEN NULL ELSE TIMESTAMPDIFF(MINUTE, d.arrived_at, NOW()) END AS minutes_waiting
                        FROM deliveries d LEFT JOIN purchase_orders po ON po.id = d.purchase_order_id LEFT JOIN suppliers s ON s.id = d.supplier_id
                        WHERE $w ORDER BY d.id DESC LIMIT 300");
    $q->execute($p);
    $max = (int)inv_setting($cid, 'receiving.max_wait_minutes', '60');
    json_response(array_map(function ($r) use ($max) { $r['waiting_too_long'] = $r['minutes_waiting'] !== null && (int)$r['minutes_waiting'] > $max; return $r; }, $q->fetchAll()));
}
if ($method === 'POST' && !$id) json_response(rcv_create($u, $body), 201);
if ($method === 'POST' && $id && $action !== '') {
    $n = (int)$id;
    switch ($action) {
        case 'weigh-in':           json_response(rcv_weigh_in($u, $n, $body));
        case 'temperature':        json_response(rcv_temperature($u, $n, $body));
        case 'temperature-decision': json_response(rcv_temp_decision($u, $n, $body));
        case 'quality':            json_response(rcv_quality($u, $n, $body));
        case 'weigh-out':          json_response(rcv_weigh_out($u, $n, $body));
        case 'accept':             json_response(rcv_accept($u, $n));
        case 'reject':             json_response(rcv_reject($u, $n, $body));
        case 'put-away':           json_response(rcv_put_away($u, $n, $body));
        case 'count':              json_response(rcv3_count($u, $n, $body));
        case 'finance-check':      json_response(rcv3_finance($u, $n, $body));
        case 'route':              json_response(rcv3_route($u, $n, $body));
    }
}
error_response('Not found', 404);
