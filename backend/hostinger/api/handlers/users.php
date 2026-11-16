<?php
// Users — administrators only (enforced by the pipeline: admin.manage). Never returns password hashes.
$db = db(); $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$cols = 'id, name, email, role, role_id, company_id, lang, is_active, must_change_password, created_at, updated_at';
$cid = (int)$GLOBALS['company_id'];

function users_validate_password(string $pw): void {
    if (strlen($pw) < 10) error_response('Password must be at least 10 characters', 422);
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) error_response('Password must contain letters and numbers', 422);
}
function users_resolve_role(PDO $db, array &$b): void {
    if (!empty($b['role_id'])) {
        $r = $db->prepare('SELECT role_key FROM roles WHERE id = ?'); $r->execute([$b['role_id']]);
        $key = $r->fetchColumn(); if (!$key) error_response('Unknown role', 422);
        $b['role'] = in_array($key, ['admin', 'user', 'viewer'], true) ? $key : 'user';   // legacy enum column
    }
}

switch ($method) {
case 'GET':
    if ($id) { $s = $db->prepare("SELECT $cols FROM users WHERE id = ? AND company_id = ?"); $s->execute([$id, $cid]); $r = $s->fetch(); if (!$r) error_response('Not found', 404); json_response($r); }
    $s = $db->prepare("SELECT $cols FROM users WHERE company_id = ? ORDER BY id DESC"); $s->execute([$cid]); json_response($s->fetchAll());
case 'POST':
    foreach (['name', 'email', 'password'] as $f) if (empty($body[$f])) error_response("$f is required", 422);
    if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) error_response('Invalid email', 422);
    users_validate_password($body['password']);
    $b = ['role_id' => $body['role_id'] ?? 3]; users_resolve_role($db, $b);
    $ex = $db->prepare('SELECT 1 FROM users WHERE email = ?'); $ex->execute([$body['email']]); if ($ex->fetchColumn()) error_response('Email already used', 409);
    $db->prepare('INSERT INTO users (name, email, password_hash, role, role_id, company_id, lang, must_change_password) VALUES (?,?,?,?,?,?,?,1)')
       ->execute([$body['name'], $body['email'], password_hash($body['password'], PASSWORD_BCRYPT), $b['role'], $b['role_id'], $cid, $body['lang'] ?? 'ar']);
    $newId = (int)$db->lastInsertId();
    // the new login takes the Active employee card of this company that has the same e-mail and no login yet (so "my leave" works at once)
    $db->prepare("UPDATE employees SET user_id = ? WHERE company_id = ? AND user_id IS NULL AND status = 'Active' AND LOWER(email) = LOWER(?) LIMIT 1")->execute([$newId, $cid, $body['email']]);
    $s = $db->prepare("SELECT $cols FROM users WHERE id = ?"); $s->execute([$newId]); json_response($s->fetch(), 201);
case 'PUT':
    if (!$id) error_response('ID required');
    $chk = $db->prepare('SELECT 1 FROM users WHERE id = ? AND company_id = ?'); $chk->execute([$id, $cid]); if (!$chk->fetchColumn()) error_response('Not found', 404);
    $set = []; $p = [];
    foreach (['name', 'lang', 'is_active'] as $f) if (array_key_exists($f, $body)) { $set[] = "`$f` = ?"; $p[] = $body[$f]; }
    if (!empty($body['email'])) { if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) error_response('Invalid email', 422); $set[] = 'email = ?'; $p[] = $body['email']; }
    if (!empty($body['role_id'])) { $b = ['role_id' => $body['role_id']]; users_resolve_role($db, $b); $set[] = 'role_id = ?'; $p[] = $b['role_id']; $set[] = 'role = ?'; $p[] = $b['role']; }
    if (!empty($body['password'])) { users_validate_password($body['password']); $set[] = 'password_hash = ?'; $p[] = password_hash($body['password'], PASSWORD_BCRYPT); $set[] = 'must_change_password = 1'; }
    if (!$set) error_response('Nothing to update', 422);
    if ((int)$id === $GLOBALS['auth_user']['user_id'] && array_key_exists('is_active', $body) && !$body['is_active']) error_response('You cannot disable yourself', 422);
    $p[] = $id; $db->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($p);
    $s = $db->prepare("SELECT $cols FROM users WHERE id = ?"); $s->execute([$id]); json_response($s->fetch());
case 'DELETE':
    if (!$id) error_response('ID required');
    if ((int)$id === $GLOBALS['auth_user']['user_id']) error_response('You cannot delete yourself', 422);
    // never hard-delete people who appear in the audit trail: disable instead
    $db->prepare('UPDATE users SET is_active = 0 WHERE id = ? AND company_id = ?')->execute([$id, $cid]);
    json_response(['message' => 'User disabled']);
}
