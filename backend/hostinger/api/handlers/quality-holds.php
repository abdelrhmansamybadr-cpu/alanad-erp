<?php
// Quality holds.  GET /quality-holds[?status=active]   POST /quality-holds {cold_room_id|batch_id, reason}   POST /quality-holds/{id}/release {note}  (needs stock.approve)
require_once __DIR__ . '/../core/inventory.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
inv_evaluate_alarms($cid);
if ($method === 'GET') {
    $w = 'h.company_id = ?' . (($GLOBALS['branch_scope'] ?? null) !== null ? ' AND (h.cold_room_id IS NULL OR h.cold_room_id IS NOT NULL' . branch_room_sql('h.cold_room_id') . ')' : ''); $p = [$cid];
    if (!empty($_GET['status'])) { $w .= ' AND h.status = ?'; $p[] = $_GET['status']; }
    $q = db()->prepare("SELECT h.*, r.name AS cold_room_name, b.batch_no FROM quality_holds h LEFT JOIN cold_rooms r ON r.id = h.cold_room_id LEFT JOIN item_batches b ON b.id = h.batch_id WHERE $w ORDER BY h.id DESC LIMIT 200");
    $q->execute($p); json_response($q->fetchAll());
}
if ($method === 'POST' && !$id) {
    if (trim((string)($body['reason'] ?? '')) === '') error_response('A reason is required', 422);
    $room = !empty($body['cold_room_id']) ? (int)$body['cold_room_id'] : null;
    $batch = !empty($body['batch_id']) ? (int)$body['batch_id'] : null;
    if (!$room && !$batch) error_response('cold_room_id or batch_id is required', 422);
    if ($room) inv_room($cid, $room);
    if ($batch) { $s = db()->prepare('SELECT 1 FROM item_batches WHERE id = ? AND company_id = ?'); $s->execute([$batch, $cid]); if (!$s->fetchColumn()) error_response('Batch not found', 404); }
    $hid = inv_open_hold($cid, $room, $batch, substr((string)$body['reason'], 0, 255), $u['user_id'], false);
    json_response(['id' => $hid, 'status' => 'active'], 201);
}
if ($method === 'POST' && $id && ($GLOBALS['parts'][2] ?? '') === 'release') {
    if (!permission_allows($u['permissions'], 'stock', 'approve')) error_response('Releasing a hold needs approval rights', 403);
    json_response(inv_release_hold($u, (int)$id, $body['note'] ?? null));
}
error_response('Not found', 404);
