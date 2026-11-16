<?php
// Cold rooms (CRUD) + readings:  GET /cold-rooms/{id}/readings[?limit=]   POST /cold-rooms/{id}/readings {temperature, humidity?, source?}
require_once __DIR__ . '/../core/crud.php';
require_once __DIR__ . '/../core/inventory.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];

if ($id && ($GLOBALS['parts'][2] ?? '') === 'readings') {
    $room = inv_room($cid, (int)$id);
    if ($method === 'GET') {
        $q = db()->prepare('SELECT id, temperature, humidity, source, user_id, created_at FROM temperature_readings WHERE company_id = ? AND cold_room_id = ? ORDER BY id DESC LIMIT ' . min(500, max(1, (int)($_GET['limit'] ?? 100))));
        $q->execute([$cid, $room['id']]);
        json_response($q->fetchAll());
    }
    if ($method === 'POST') json_response(inv_reading($u, $room, $body), 201);
    error_response('Method not allowed', 405);
}

if (in_array($method, ['POST', 'PUT'], true)) {
    foreach (['temp_min', 'temp_max'] as $f) if (isset($body[$f]) && !is_numeric($body[$f])) error_response("$f must be a number", 422);
    if (isset($body['temp_min'], $body['temp_max']) && (float)$body['temp_min'] >= (float)$body['temp_max']) error_response('temp_min must be lower than temp_max', 422);
    if ($method === 'POST' && (!isset($body['temp_min']) || !isset($body['temp_max']))) error_response('temp_min and temp_max are required', 422);
    unset($body['status'], $body['branch_id']);     // status is set only by alarms / holds; the branch always comes from the warehouse
    if ($method === 'PUT' && $id) { $own = db()->prepare('SELECT 1 FROM cold_rooms WHERE id = ? AND company_id = ?'); $own->execute([(int)$id, $cid]); if (!$own->fetchColumn()) error_response('Not found', 404); }
    if (!empty($body['warehouse_id'])) {
        $wb = db()->prepare('SELECT branch_id FROM warehouses WHERE id = ? AND company_id = ?'); $wb->execute([(int)$body['warehouse_id'], $cid]);
        $wbv = $wb->fetchColumn();
        if ($wbv === false) error_response('Warehouse not found', 422);
        $body['branch_id'] = $wbv;
    }
    $GLOBALS['body'] = $body;
}
if ($method === 'GET') inv_evaluate_alarms($cid);
crud_handle(['table' => 'cold_rooms', 'search' => ['name', 'code', 'name_ar'], 'filters' => ['status', 'warehouse_id', 'is_active'], 'order' => 'code ASC', 'branch_col' => 'branch_id']);
