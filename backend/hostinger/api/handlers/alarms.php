<?php
// Cold-room alarms.  GET /alarms[?status=open|acknowledged|recovered&cold_room_id=]   POST /alarms/{id}/ack {note}
require_once __DIR__ . '/../core/inventory.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
inv_evaluate_alarms($cid);
if ($method === 'GET') {
    $w = 'a.company_id = ?' . branch_room_sql('a.cold_room_id'); $p = [$cid];
    foreach (['status', 'cold_room_id'] as $f) if (!empty($_GET[$f])) { $w .= " AND a.$f = ?"; $p[] = $_GET[$f]; }
    $q = db()->prepare("SELECT a.*, r.name AS cold_room_name, r.temp_min, r.temp_max FROM cold_room_alarms a JOIN cold_rooms r ON r.id = a.cold_room_id WHERE $w ORDER BY a.id DESC LIMIT 200");
    $q->execute($p); json_response($q->fetchAll());
}
if ($method === 'POST' && $id && ($GLOBALS['parts'][2] ?? '') === 'ack') {
    if (trim((string)($body['note'] ?? '')) === '') error_response('A note is required (what was done?)', 422);
    $st = db()->prepare("UPDATE cold_room_alarms SET status = 'acknowledged', acknowledged_by = ?, acknowledged_note = ? WHERE id = ? AND company_id = ? AND status = 'open'");
    $st->execute([$u['user_id'], substr((string)$body['note'], 0, 255), $id, $cid]);
    if (!$st->rowCount()) error_response('Alarm not found or not open', 404);
    json_response(['id' => (int)$id, 'status' => 'acknowledged']);
}
error_response('Not found', 404);
