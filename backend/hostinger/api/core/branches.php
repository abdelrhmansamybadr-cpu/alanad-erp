<?php
// ─── Alanad ERP — branches (Phase 1 of the owner's plan; tests/phase7_test.php) ───
// A company has branches in different places. The caller works in ONE branch (header X-Branch-Id) or, without the header,
// in all the branches he may use. A user with rows in user_branches may use only those; admins and users without rows use all.
//   $GLOBALS['branch_scope']  = null (every branch of the company) or an array of branch ids   — set by the pipeline
//   $GLOBALS['branch_access'] = the branch ids the user may use at all

/** the branches this user may use, and the working scope for this request (computed once per request in pipeline_load_user) */
function branch_context(int $companyId, int $userId, string $roleKey, array $perms): array {
    $q = db()->prepare('SELECT id FROM company_branches WHERE company_id = ? AND is_active = 1 ORDER BY is_main DESC, id');
    $q->execute([$companyId]);
    $all = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    $access = $all;
    $isAdmin = $roleKey === 'admin' || in_array('*', $perms, true);
    if (!$isAdmin) {
        $r = db()->prepare('SELECT ub.branch_id FROM user_branches ub JOIN company_branches b ON b.id = ub.branch_id WHERE ub.user_id = ? AND b.company_id = ? AND b.is_active = 1');
        $r->execute([$userId, $companyId]);
        $mine = array_map('intval', $r->fetchAll(PDO::FETCH_COLUMN));
        if ($mine) $access = array_values(array_intersect($all, $mine));
    }
    $want = isset($_SERVER['HTTP_X_BRANCH_ID']) && is_numeric($_SERVER['HTTP_X_BRANCH_ID']) ? (int)$_SERVER['HTTP_X_BRANCH_ID'] : 0;
    if ($want > 0) {
        if (!in_array($want, $access, true)) error_response('You cannot work in this branch', 403);
        $scope = [$want];
    } else {
        $scope = count($access) === count($all) ? null : $access;      // everything the company has, or just what this user may see
    }
    return ['access' => $access, 'scope' => $scope, 'all' => $all];
}

function branch_main_id(int $companyId): ?int {
    $q = db()->prepare('SELECT id FROM company_branches WHERE company_id = ? AND is_active = 1 ORDER BY is_main DESC, id LIMIT 1');
    $q->execute([$companyId]);
    $id = $q->fetchColumn();
    return $id === false ? null : (int)$id;
}

/** the branch a new document belongs to: the one the user works in, else his first allowed branch, else the main one */
function branch_default(int $companyId): ?int {
    $scope = $GLOBALS['branch_scope'] ?? null;
    if (is_array($scope) && $scope) return (int)$scope[0];
    $access = $GLOBALS['branch_access'] ?? [];
    return $access ? (int)$access[0] : branch_main_id($companyId);
}

/** " AND col IN (1,2)" for the working scope ("" when the user works across all branches). Ids are integers, so inlining is safe. */
function branch_scope_sql(string $col): string {
    $scope = $GLOBALS['branch_scope'] ?? null;
    if ($scope === null) return '';
    return $scope ? " AND $col IN (" . implode(',', array_map('intval', $scope)) . ')' : ' AND 1 = 0';
}
/** same, for a column that holds a warehouse id / a cold-room id */
function branch_wh_sql(string $col): string {
    $scope = $GLOBALS['branch_scope'] ?? null;
    if ($scope === null) return '';
    return " AND $col IN (SELECT id FROM warehouses WHERE branch_id IN (" . ($scope ? implode(',', array_map('intval', $scope)) : '0') . '))';
}
function branch_room_sql(string $col): string {
    $scope = $GLOBALS['branch_scope'] ?? null;
    if ($scope === null) return '';
    return " AND $col IN (SELECT id FROM cold_rooms WHERE branch_id IN (" . ($scope ? implode(',', array_map('intval', $scope)) : '0') . '))';
}
function branch_in_scope(?int $branchId): bool {
    $scope = $GLOBALS['branch_scope'] ?? null;
    return $scope === null || ($branchId !== null && in_array($branchId, array_map('intval', $scope), true));
}
/** a branch id given by a client must be a branch of this company that the user may use */
function branch_validate(int $companyId, int $branchId): int {
    $q = db()->prepare('SELECT 1 FROM company_branches WHERE id = ? AND company_id = ? AND is_active = 1');
    $q->execute([$branchId, $companyId]);
    if (!$q->fetchColumn()) error_response('Branch not found', 422);
    if (!in_array($branchId, $GLOBALS['branch_access'] ?? [$branchId], true)) error_response('You cannot work in this branch', 403);
    return $branchId;
}
/** a stock move must happen in a cold room of the right branch */
function branch_check_room(array $room, ?int $requiredBranch = null): void {
    $rb = $room['branch_id'] !== null ? (int)$room['branch_id'] : null;
    if ($requiredBranch !== null && $rb !== $requiredBranch) error_response('This cold room belongs to another branch than the document', 422);
    if (!branch_in_scope($rb)) error_response('This cold room belongs to a branch you are not working in', 403);
}
