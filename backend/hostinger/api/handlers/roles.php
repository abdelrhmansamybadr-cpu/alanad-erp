<?php
// Roles and their permissions — administrators only.
$db = db(); $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$cid = (int)$GLOBALS['company_id'];
function role_with_perms(PDO $db, array $r): array {
    $p = $db->prepare('SELECT permission FROM role_permissions WHERE role_id = ? ORDER BY permission'); $p->execute([$r['id']]);
    $r['permissions'] = $p->fetchAll(PDO::FETCH_COLUMN); return $r;
}
function role_check_perms(array $perms): void {
    foreach ($perms as $p) {
        if (!is_string($p) || !preg_match('/^(\*|([a-z0-9\-]+|\*)\.(\*|view|create|edit|delete|approve|export|manage))$/', $p)) {
            error_response('Invalid permission: ' . json_encode($p), 422);
        }
    }
}
switch ($method) {
case 'GET':
    if ($id) { $s = $db->prepare('SELECT * FROM roles WHERE id = ? AND (company_id IS NULL OR company_id = ?)'); $s->execute([$id, $cid]); $r = $s->fetch(); if (!$r) error_response('Not found', 404); json_response(role_with_perms($db, $r)); }
    $s = $db->prepare('SELECT * FROM roles WHERE company_id IS NULL OR company_id = ? ORDER BY id'); $s->execute([$cid]);
    json_response(array_map(fn($r) => role_with_perms($db, $r), $s->fetchAll()));
case 'POST':
    if (empty($body['role_key']) || empty($body['name_en'])) error_response('role_key and name_en are required', 422);
    if (!preg_match('/^[a-z][a-z0-9_]{1,50}$/', $body['role_key'])) error_response('role_key: lowercase letters, numbers, underscore', 422);
    $perms = $body['permissions'] ?? []; role_check_perms($perms);
    $db->prepare('INSERT INTO roles (company_id, role_key, name_en, name_ar) VALUES (?,?,?,?)')->execute([$cid, $body['role_key'], $body['name_en'], $body['name_ar'] ?? null]);
    $rid = (int)$db->lastInsertId();
    foreach (array_unique($perms) as $p) $db->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?,?)')->execute([$rid, $p]);
    $s = $db->prepare('SELECT * FROM roles WHERE id = ?'); $s->execute([$rid]); json_response(role_with_perms($db, $s->fetch()), 201);
case 'PUT':
    if (!$id) error_response('ID required');
    $s = $db->prepare('SELECT * FROM roles WHERE id = ?'); $s->execute([$id]); $r = $s->fetch();
    if (!$r || ($r['company_id'] !== null && (int)$r['company_id'] !== $cid)) error_response('Not found', 404);
    if ((int)$r['is_system'] === 1 && $r['role_key'] === 'admin') error_response('The admin role cannot be changed', 403);
    if (isset($body['permissions'])) {
        role_check_perms($body['permissions']);
        $db->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$id]);
        foreach (array_unique($body['permissions']) as $p) $db->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?,?)')->execute([$id, $p]);
    }
    foreach (['name_en', 'name_ar'] as $f) if (isset($body[$f])) $db->prepare("UPDATE roles SET $f = ? WHERE id = ?")->execute([$body[$f], $id]);
    $s->execute([$id]); json_response(role_with_perms($db, $s->fetch()));
case 'DELETE':
    if (!$id) error_response('ID required');
    $s = $db->prepare('SELECT * FROM roles WHERE id = ?'); $s->execute([$id]); $r = $s->fetch();
    if (!$r || (int)$r['is_system'] === 1) error_response('System roles cannot be deleted', 403);
    $u = $db->prepare('SELECT COUNT(*) FROM users WHERE role_id = ?'); $u->execute([$id]); if ($u->fetchColumn() > 0) error_response('Role is in use', 409);
    $db->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]); json_response(['message' => 'Deleted']);
}
