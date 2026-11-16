<?php
// Food-safety (HACCP style) logs: wash-water temperature, sanitiser level, staff hygiene check.
//   GET  /haccp-logs[?kind=&ok=0|1&prep_order_id=&from=&to=]
//   POST /haccp-logs {kind: wash_water|sanitiser|hygiene, prep_order_id?, value?, person?, passed?, note?, corrective_action?}
//        wash_water value = °C (limit production.wash_water_max_c), sanitiser value = ppm (production.sanitiser_min_ppm .. _max_ppm),
//        hygiene needs person + passed. An out-of-range reading is stored as not OK and needs a corrective action.
require_once __DIR__ . '/../core/production.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
if ($method === 'GET') {
    $w = 'l.company_id = ?'; $p = [$cid];
    foreach (['kind', 'prep_order_id'] as $f) if (!empty($_GET[$f])) { $w .= " AND l.$f = ?"; $p[] = $_GET[$f]; }
    if (isset($_GET['ok']) && $_GET['ok'] !== '') { $w .= ' AND l.ok = ?'; $p[] = (int)$_GET['ok']; }
    if (!empty($_GET['from'])) { $w .= ' AND l.created_at >= ?'; $p[] = $_GET['from'] . ' 00:00:00'; }
    if (!empty($_GET['to'])) { $w .= ' AND l.created_at <= ?'; $p[] = $_GET['to'] . ' 23:59:59'; }
    $q = db()->prepare("SELECT l.*, o.number AS order_number, u.name AS by_name FROM haccp_logs l LEFT JOIN prep_orders o ON o.id = l.prep_order_id LEFT JOIN users u ON u.id = l.created_by WHERE $w ORDER BY l.id DESC LIMIT 300");
    $q->execute($p);
    json_response(array_map(function ($r) { $r['ok'] = (bool)$r['ok']; return $r; }, $q->fetchAll()));
}
if ($method === 'POST') json_response(haccp_create($u, $body), 201);
error_response('Method not allowed', 405);
