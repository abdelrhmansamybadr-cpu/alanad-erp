<?php
// Which branches each user may work in (administrators only: pipeline). No rows = every branch of the company.
//   GET /branch-access                          every user of the company with the branch ids he is limited to ([] = unrestricted)
//   PUT /branch-access/{user_id} {branch_ids: [..]}      replaces the list; an empty list removes the restriction
$db = db(); $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$cid = (int)$GLOBALS['company_id'];

if ($method === 'GET') {
    $q = $db->prepare('SELECT u.id, u.name, u.email, u.role, u.is_active FROM users u WHERE u.company_id = ? ORDER BY u.id');
    $q->execute([$cid]);
    $users = $q->fetchAll();
    $a = $db->prepare('SELECT ub.user_id, ub.branch_id FROM user_branches ub JOIN company_branches b ON b.id = ub.branch_id WHERE b.company_id = ?');
    $a->execute([$cid]);
    $map = [];
    foreach ($a->fetchAll() as $r) $map[(int)$r['user_id']][] = (int)$r['branch_id'];
    json_response(array_map(fn($u) => $u + ['branch_ids' => $map[(int)$u['id']] ?? []], $users));
}
if ($method === 'PUT' && $id) {
    $u = $db->prepare('SELECT id FROM users WHERE id = ? AND company_id = ?'); $u->execute([$id, $cid]);
    if (!$u->fetchColumn()) error_response('Not found', 404);
    $ids = $body['branch_ids'] ?? null;
    if (!is_array($ids)) error_response('branch_ids must be a list', 422);
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids) {
        $in = implode(',', $ids);
        $ok = (int)$db->query("SELECT COUNT(*) FROM company_branches WHERE company_id = $cid AND is_active = 1 AND id IN ($in)")->fetchColumn();
        if ($ok !== count($ids)) error_response('Every branch must be an active branch of this company', 422);
    }
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM user_branches WHERE user_id = ?')->execute([$id]);
        foreach ($ids as $b) $db->prepare('INSERT INTO user_branches (user_id, branch_id) VALUES (?,?)')->execute([$id, $b]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    json_response(['user_id' => (int)$id, 'branch_ids' => $ids]);
}
error_response('Method not allowed', 405);
