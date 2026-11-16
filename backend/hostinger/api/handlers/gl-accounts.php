<?php
// Chart of accounts. Accounts are never deleted (deactivate instead); the accounts the system posts to (system_key) cannot be deactivated.
//   GET  /gl-accounts[?postable=1]     POST /gl-accounts {code, name_en, name_ar?, type, parent_id?, is_postable?}     PUT /gl-accounts/{id} {name_en?, name_ar?, is_active?}
require_once __DIR__ . '/../core/finance.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];

if ($method === 'GET') {
    $w = 'company_id = ?'; $p = [$cid];
    if (!empty($_GET['postable'])) $w .= ' AND is_postable = 1 AND is_active = 1';
    $q = db()->prepare("SELECT * FROM gl_accounts WHERE $w ORDER BY code");
    $q->execute($p);
    json_response($q->fetchAll());
}
if ($method === 'POST') {
    $code = trim((string)($body['code'] ?? ''));
    if (!preg_match('/^[0-9]{3,12}$/', $code)) error_response('code must be 3 to 12 digits', 422);
    if (trim((string)($body['name_en'] ?? '')) === '') error_response('name_en is required', 422);
    $type = $body['type'] ?? '';
    if (!in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) error_response('type must be asset, liability, equity, revenue or expense', 422);
    $parent = !empty($body['parent_id']) ? (int)$body['parent_id'] : null;
    if ($parent) {
        $pp = db()->prepare('SELECT type FROM gl_accounts WHERE id = ? AND company_id = ?'); $pp->execute([$parent, $cid]);
        $pt = $pp->fetchColumn();
        if (!$pt) error_response('Parent account not found', 422);
        if ($pt !== $type) error_response('An account must have the same type as its parent', 422);
    }
    try {
        db()->prepare('INSERT INTO gl_accounts (company_id, code, name_en, name_ar, type, parent_id, is_postable) VALUES (?,?,?,?,?,?,?)')
            ->execute([$cid, $code, substr((string)$body['name_en'], 0, 160), isset($body['name_ar']) ? substr((string)$body['name_ar'], 0, 160) : null, $type, $parent, array_key_exists('is_postable', $body) ? (int)(bool)$body['is_postable'] : 1]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) error_response('This account code already exists', 409);
        throw $e;
    }
    $s = db()->prepare('SELECT * FROM gl_accounts WHERE id = ?'); $s->execute([db()->lastInsertId()]); json_response($s->fetch(), 201);
}
if ($method === 'PUT' && $id) {
    $s = db()->prepare('SELECT * FROM gl_accounts WHERE id = ? AND company_id = ?'); $s->execute([$id, $cid]);
    $a = $s->fetch();
    if (!$a) error_response('Not found', 404);
    if (array_key_exists('is_active', $body) && !$body['is_active'] && $a['system_key']) error_response('This account is used by automatic postings and cannot be deactivated', 409);
    $set = []; $p = [];
    foreach (['name_en', 'name_ar', 'is_active'] as $f) if (array_key_exists($f, $body)) { $set[] = "`$f` = ?"; $p[] = $f === 'is_active' ? (int)(bool)$body[$f] : $body[$f]; }
    if (!$set) error_response('Nothing to update', 422);
    $p[] = $id;
    db()->prepare('UPDATE gl_accounts SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($p);
    $s->execute([$id, $cid]); json_response($s->fetch());
}
error_response('Method not allowed', 405);
