<?php
// Branches of the company (administrators only: pipeline). A branch is never deleted; it is deactivated.
//   GET  /company-branches                      with how many warehouses, cold rooms and restricted users each has
//   POST /company-branches {code, name_en, name_ar?, city?, address?, latitude?, longitude?, phone?, is_main?}
//   PUT  /company-branches/{id}                 same fields + is_active (the main branch and a branch with active warehouses cannot be deactivated)
require_once __DIR__ . '/../core/branches.php';
$db = db(); $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$cid = (int)$GLOBALS['company_id'];
$fields = ['code', 'name_en', 'name_ar', 'city', 'address', 'latitude', 'longitude', 'phone'];

function branch_row(int $cid, int $id): array {
    $s = db()->prepare('SELECT * FROM company_branches WHERE id = ? AND company_id = ?'); $s->execute([$id, $cid]);
    $r = $s->fetch();
    if (!$r) error_response('Not found', 404);
    return $r;
}
function branch_check_body(array $b, bool $create): void {
    if ($create && !preg_match('/^[A-Za-z0-9_-]{2,20}$/', (string)($b['code'] ?? ''))) error_response('code must be 2 to 20 letters, digits, - or _', 422);
    if ($create && trim((string)($b['name_en'] ?? '')) === '') error_response('name_en is required', 422);
    foreach (['latitude' => 90, 'longitude' => 180] as $f => $max) {
        if (isset($b[$f]) && $b[$f] !== '' && $b[$f] !== null && (!is_numeric($b[$f]) || abs((float)$b[$f]) > $max)) error_response("$f must be a number between -$max and $max", 422);
    }
}

if ($method === 'GET') {
    $q = $db->prepare('SELECT b.*, (SELECT COUNT(*) FROM warehouses w WHERE w.branch_id = b.id AND w.is_active = 1) AS warehouses,
                              (SELECT COUNT(*) FROM cold_rooms r WHERE r.branch_id = b.id AND r.is_active = 1) AS cold_rooms,
                              (SELECT COUNT(*) FROM user_branches ub WHERE ub.branch_id = b.id) AS restricted_users
                       FROM company_branches b WHERE b.company_id = ? ORDER BY b.is_main DESC, b.id');
    $q->execute([$cid]);
    json_response($q->fetchAll());
}
if ($method === 'POST') {
    branch_check_body($body, true);
    $db->beginTransaction();
    try {
        $main = !empty($body['is_main']) ? 1 : 0;
        if ($main) $db->prepare('UPDATE company_branches SET is_main = 0 WHERE company_id = ?')->execute([$cid]);
        $vals = [$cid]; foreach ($fields as $f) $vals[] = isset($body[$f]) && $body[$f] !== '' ? $body[$f] : null;
        $db->prepare('INSERT INTO company_branches (company_id, code, name_en, name_ar, city, address, latitude, longitude, phone, is_main) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($vals, [$main]));
        $new = (int)$db->lastInsertId();
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ((int)($e->errorInfo[1] ?? 0) === 1062) error_response('This branch code already exists', 409);
        throw $e;
    }
    json_response(branch_row($cid, $new), 201);
}
if ($method === 'PUT' && $id) {
    $b = branch_row($cid, (int)$id);
    branch_check_body($body, false);
    $set = []; $p = [];
    foreach ($fields as $f) if (array_key_exists($f, $body)) {
        if ($f === 'code' && !preg_match('/^[A-Za-z0-9_-]{2,20}$/', (string)$body[$f])) error_response('code must be 2 to 20 letters, digits, - or _', 422);
        if ($f === 'name_en' && trim((string)$body[$f]) === '') error_response('name_en cannot be empty', 422);
        $set[] = "`$f` = ?"; $p[] = $body[$f] === '' ? null : $body[$f];
    }
    if (array_key_exists('is_active', $body) && !$body['is_active']) {
        if ((int)$b['is_main']) error_response('The main branch cannot be deactivated: make another branch the main one first', 409);
        $w = $db->prepare('SELECT COUNT(*) FROM warehouses WHERE branch_id = ? AND is_active = 1'); $w->execute([$id]);
        if ((int)$w->fetchColumn() > 0) error_response('This branch still has active warehouses: deactivate or move them first', 409);
        $set[] = 'is_active = 0';
    } elseif (array_key_exists('is_active', $body)) $set[] = 'is_active = 1';
    $db->beginTransaction();
    try {
        if (!empty($body['is_main'])) {
            if (!(int)$b['is_active'] && !(array_key_exists('is_active', $body) && $body['is_active'])) error_response('An inactive branch cannot be the main one', 409);
            $db->prepare('UPDATE company_branches SET is_main = 0 WHERE company_id = ?')->execute([$cid]);
            $set[] = 'is_main = 1';
        }
        if (!$set) { $db->rollBack(); error_response('Nothing to update', 422); }
        $p[] = $id; $p[] = $cid;
        $db->prepare('UPDATE company_branches SET ' . implode(', ', $set) . ' WHERE id = ? AND company_id = ?')->execute($p);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ((int)($e->errorInfo[1] ?? 0) === 1062) error_response('This branch code already exists', 409);
        throw $e;
    }
    json_response(branch_row($cid, (int)$id));
}
error_response('Method not allowed', 405);
