<?php
// In-app / push notifications. Everyone sees only their own (and broadcast) notifications.
//   GET  /notifications[?unread=1&limit=30&offset=0]  ->  {data:[...], total, unread_count}
//   PUT  /notifications/{id}   mark one as read       PUT /notifications   mark all as read
//   POST /notifications        (administrators) create {title, body, type?, user_id? (empty = everyone in the company)}
$db = db(); $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$u = $GLOBALS['auth_user'];

if ($method === 'GET') {
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 30))); $offset = max(0, (int)($_GET['offset'] ?? 0));
    $where = '(n.user_id = ? OR (n.user_id IS NULL))';
    if (!empty($_GET['unread'])) $where .= ' AND n.is_read = 0';
    $q = $db->prepare("SELECT n.id, n.type, n.title, n.body, n.data, n.is_read, n.created_at FROM notifications n WHERE $where ORDER BY n.id DESC LIMIT $limit OFFSET $offset");
    $q->execute([$u['user_id']]);
    $rows = array_map(function ($r) { $r['is_read'] = (bool)$r['is_read']; $r['data'] = $r['data'] ? json_decode($r['data'], true) : null; return $r; }, $q->fetchAll());
    $c = $db->prepare('SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0'); $c->execute([$u['user_id']]);
    $t = $db->prepare('SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL)'); $t->execute([$u['user_id']]);
    json_response(['data' => $rows, 'total' => (int)$t->fetchColumn(), 'unread_count' => (int)$c->fetchColumn()]);
}
if ($method === 'PUT') {
    if ($id) $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $u['user_id']]);
    else $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$u['user_id']]);
    json_response(['message' => 'ok']);
}
if ($method === 'POST') {
    if (!permission_allows($u['permissions'], 'admin', 'manage')) error_response('Forbidden', 403);
    if (empty($body['title']) || empty($body['body'])) error_response('title and body are required', 422);
    $db->prepare('INSERT INTO notifications (user_id, type, title, body, data) VALUES (?,?,?,?,?)')
       ->execute([$body['user_id'] ?? null, $body['type'] ?? 'system', $body['title'], $body['body'], isset($body['data']) ? json_encode($body['data']) : null]);
    json_response(['id' => (int)$db->lastInsertId()], 201);
}
error_response('Method not allowed', 405);
