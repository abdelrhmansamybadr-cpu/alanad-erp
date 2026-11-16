<?php
// Preparation orders (rules in core/production.php).
//   GET  /prep-orders[?status=&spec_id=]       GET /prep-orders/{id}  (inputs with batch cost, outputs, waste, food-safety logs, stock available, timer)
//   POST /prep-orders {spec_id, planned_input_kg, notes?}                 Draft
//   POST /prep-orders/{id}/approve                                         a manager (prep-orders.approve), never the planner
//   POST /prep-orders/{id}/cancel {reason}                                 Draft / Approved only
//   POST /prep-orders/{id}/request-materials                               draft purchase request for the shortage
//   POST /prep-orders/{id}/start {kg?}                                     issues the material oldest-expiry first at its real cost; the outside-cold timer starts
//   POST /prep-orders/{id}/results {outputs:[{grade,kg}], waste:[{reason_code,kg,decision,scrap_income?}], yield_reason?, overtime_note?}   stops the timer, checks yield and balance
//   POST /prep-orders/{id}/sign-off {store:[{grade,cold_room_id,rack?,position?,expiry_date?}], waste_room_id?, note?}   named inspector, never the person who entered the results; needs stock.create
//   POST /prep-orders/{id}/waste/{waste_id}/decide {action: approve|reject, note?}      Finance (gl-entries.approve) approves a write-off / scrap sale
//   POST /prep-orders/{id}/waste/{waste_id}/redecide {decision, scrap_income?, cold_room_id?, note?}   after Finance returned it
require_once __DIR__ . '/../core/production.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$action = $GLOBALS['parts'][2] ?? '';
$wid = isset($GLOBALS['parts'][3]) ? (int)$GLOBALS['parts'][3] : 0;
$sub = $GLOBALS['parts'][4] ?? '';

if ($method === 'GET') {
    if ($id) json_response(prod_detail($cid, (int)$id));
    $w = 'o.company_id = ?' . branch_scope_sql('o.branch_id'); $p = [$cid];
    foreach (['status', 'spec_id'] as $f) if (!empty($_GET[$f])) { $w .= " AND o.$f = ?"; $p[] = $_GET[$f]; }
    $q = db()->prepare("SELECT o.id, o.number, o.status, o.planned_input_kg, o.input_kg, o.output_kg, o.waste_kg, o.yield_pct, o.waste_pct, o.result, o.over_time, o.started_at, o.created_by, o.results_by, o.branch_id, s.code AS spec_code, s.name_en AS spec_name, s.name_ar AS spec_name_ar, s.max_minutes_outside,
                               CASE WHEN o.status = 'In_Progress' THEN TIMESTAMPDIFF(MINUTE, o.started_at, NOW()) END AS minutes_running,
                               (SELECT COUNT(*) FROM prep_waste w WHERE w.order_id = o.id AND w.status IN ('Pending','Returned')) AS waste_pending
                        FROM prep_orders o JOIN prep_specs s ON s.id = o.spec_id WHERE $w ORDER BY o.id DESC LIMIT 300");
    $q->execute($p);
    json_response(array_map(function ($r) {
        foreach (['planned_input_kg', 'input_kg', 'output_kg', 'waste_kg', 'yield_pct', 'waste_pct'] as $f) if ($r[$f] !== null) $r[$f] = (float)$r[$f];
        $r['over_time_now'] = $r['minutes_running'] !== null && (int)$r['minutes_running'] > (int)$r['max_minutes_outside'];
        return $r;
    }, $q->fetchAll()));
}
if ($method === 'POST' && !$id) json_response(prod_create($u, $body), 201);
if ($method === 'POST' && $id) {
    $n = (int)$id;
    switch ($action) {
        case 'approve':            json_response(prod_approve($u, $n));
        case 'cancel':             json_response(prod_cancel($u, $n, $body));
        case 'request-materials':  json_response(prod_request_materials($u, $n), 201);
        case 'start':              json_response(prod_start($u, $n, $body));
        case 'results':            json_response(prod_results($u, $n, $body));
        case 'sign-off':           json_response(prod_sign_off($u, $n, $body));
        case 'waste':
            if ($sub === 'decide') json_response(prod_waste_decide($u, $n, $wid, $body));
            if ($sub === 'redecide') json_response(prod_waste_redecide($u, $n, $wid, $body));
    }
}
error_response('Not found', 404);
