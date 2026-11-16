<?php
// Module switches. GET lists; PUT /modules/{key} {"is_enabled": false} closes (hidden + blocked, data kept) or re-opens.
$db = db(); $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$cid = (int)$GLOBALS['company_id'];
if ($method === 'GET') {
    $q = $db->prepare('SELECT module_key, is_enabled, changed_at FROM module_switches WHERE company_id = ? ORDER BY module_key'); $q->execute([$cid]);
    json_response($q->fetchAll());
}
if ($method === 'PUT') {
    if (!$id || !array_key_exists('is_enabled', $body)) error_response('module key and is_enabled are required', 422);
    $q = $db->prepare('SELECT 1 FROM module_switches WHERE company_id = ? AND module_key = ?'); $q->execute([$cid, $id]);
    if (!$q->fetchColumn()) error_response('Unknown module', 404);
    $db->prepare('UPDATE module_switches SET is_enabled = ?, changed_by = ?, changed_at = NOW() WHERE company_id = ? AND module_key = ?')
       ->execute([$body['is_enabled'] ? 1 : 0, $GLOBALS['auth_user']['user_id'], $cid, $id]);
    json_response(['module_key' => $id, 'is_enabled' => (bool)$body['is_enabled']]);
}
error_response('Method not allowed', 405);
