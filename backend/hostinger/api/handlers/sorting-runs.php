<?php
// Sorting (rules in core/sorting.php).
//   GET  /sorting-runs[?status=]    GET /sorting-runs/{id}    GET /sorting-runs/queue  (trucks waiting for sorting)    GET /sorting-runs/report  (class shares per supplier)
//   GET  /sorting-runs/incidents    POST /sorting-runs/incidents/{id}/review {note, claim_supplier?}      a manager, never who filed it
//   POST /sorting-runs {delivery_id}                                          start sorting a truck that was routed to sorting
//   POST /sorting-runs/{id}/results {lines:[{line_id, class1_kg, class2_kg, class3_kg, shrink_kg?}]}     the sorter
//   POST /sorting-runs/{id}/incident {reason_code, note?}                      the quality person: required when a line is below the class-1 minimum
//   POST /sorting-runs/{id}/sign-off {rooms:{"1":cold_room_id,"2":...,"3":...}}    the quality person, never the sorter: the classes enter stock as grade A / B / C
//   POST /sorting-runs/{id}/cancel {reason}
require_once __DIR__ . '/../core/sorting.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$seg = $GLOBALS['parts'][1] ?? ''; $action = $GLOBALS['parts'][2] ?? '';
$id = ctype_digit((string)$seg) ? (int)$seg : 0;

if ($method === 'GET') {
    if ($seg === 'incidents') json_response(so_incidents($cid));
    if ($seg === 'report') json_response(so_report($cid));
    if ($seg === 'queue') {
        $q = db()->prepare("SELECT d.id, d.number, d.vehicle_plate, d.arrived_at, d.branch_id, d.finance_status, s.name AS supplier_name,
                                   (SELECT COALESCE(SUM(l.received_kg - l.rejected_kg),0) FROM delivery_lines l WHERE l.delivery_id = d.id) AS accepted_kg,
                                   (SELECT r.id FROM sorting_runs r WHERE r.delivery_id = d.id AND r.status IN ('In_Progress','Awaiting_Signoff') LIMIT 1) AS run_id
                            FROM deliveries d LEFT JOIN suppliers s ON s.id = d.supplier_id WHERE d.company_id = ? AND d.status = 'To_Sorting'" . branch_scope_sql('d.branch_id') . ' ORDER BY d.id');
        $q->execute([$cid]);
        json_response(array_map(function ($r) { $r['accepted_kg'] = (float)$r['accepted_kg']; return $r; }, $q->fetchAll()));
    }
    if ($id) json_response(so_detail($cid, $id));
    $w = 'r.company_id = ?' . branch_scope_sql('r.branch_id'); $p = [$cid];
    if (!empty($_GET['status'])) { $w .= ' AND r.status = ?'; $p[] = $_GET['status']; }
    $q = db()->prepare("SELECT r.id, r.number, r.status, r.branch_id, r.delivery_id, d.number AS delivery_number, s.name AS supplier_name, r.created_at, r.results_at, r.signed_at,
                               (SELECT COALESCE(SUM(input_kg),0) FROM sorting_lines WHERE run_id = r.id) AS input_kg,
                               (SELECT COALESCE(SUM(class1_kg),0) FROM sorting_lines WHERE run_id = r.id) AS class1_kg,
                               EXISTS(SELECT 1 FROM sorting_incidents i WHERE i.run_id = r.id) AS has_incident
                        FROM sorting_runs r JOIN deliveries d ON d.id = r.delivery_id LEFT JOIN suppliers s ON s.id = d.supplier_id WHERE $w ORDER BY r.id DESC LIMIT 300");
    $q->execute($p);
    json_response(array_map(function ($r) { $r['input_kg'] = (float)$r['input_kg']; $r['class1_kg'] = (float)$r['class1_kg']; $r['class1_pct'] = $r['input_kg'] > 0 ? round($r['class1_kg'] / $r['input_kg'] * 100, 1) : null; $r['has_incident'] = (bool)$r['has_incident']; return $r; }, $q->fetchAll()));
}
if ($method === 'POST' && $seg === 'incidents' && ctype_digit((string)$action) && ($GLOBALS['parts'][3] ?? '') === 'review') json_response(so_review($u, (int)$action, $body));
if ($method === 'POST' && !$id && $seg === '') json_response(so_create($u, $body), 201);
if ($method === 'POST' && $id) {
    switch ($action) {
        case 'results':  json_response(so_results($u, $id, $body));
        case 'incident': json_response(so_incident($u, $id, $body));
        case 'sign-off': json_response(so_signoff($u, $id, $body));
        case 'cancel':   json_response(so_cancel($u, $id, $body));
    }
}
error_response('Not found', 404);
