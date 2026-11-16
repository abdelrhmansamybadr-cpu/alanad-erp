<?php
// Employees: company-scoped CRUD (core/crud.php) with checks on the HR fields (Saudi ID / iqama, IBAN, dates, branch and user links).
require_once __DIR__ . '/../core/crud.php';
if (in_array($GLOBALS['method'], ['POST', 'PUT'], true)) {
    $b = $GLOBALS['body']; $cid = (int)$GLOBALS['company_id'];
    foreach (['date_of_birth', 'iqama_expiry', 'passport_expiry', 'health_card_expiry', 'contract_end', 'date_of_joining'] as $f) {
        if (isset($b[$f]) && $b[$f] !== null && $b[$f] !== '') { if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$b[$f]) || !checkdate((int)substr($b[$f], 5, 2), (int)substr($b[$f], 8, 2), (int)substr($b[$f], 0, 4))) error_response("$f must be a date (YYYY-MM-DD)", 422); }
        elseif (array_key_exists($f, $b)) $b[$f] = null;
    }
    if (!empty($b['national_id']) && !preg_match('/^[12]\d{9}$/', (string)$b['national_id'])) error_response('national_id must be 10 digits starting with 1 (Saudi ID) or 2 (iqama)', 422);
    if (!empty($b['iban']) && !preg_match('/^SA\d{22}$/', strtoupper(str_replace(' ', '', (string)$b['iban'])))) error_response('iban must be a Saudi IBAN (SA + 22 digits)', 422);
    if (!empty($b['iban'])) $b['iban'] = strtoupper(str_replace(' ', '', (string)$b['iban']));
    if (isset($b['gender']) && $b['gender'] !== '' && !in_array($b['gender'], ['M', 'F'], true)) error_response("gender must be 'M' or 'F'", 422);
    if (array_key_exists('gender', $b) && $b['gender'] === '') $b['gender'] = null;
    if (!empty($b['branch_id'])) $b['branch_id'] = branch_validate($cid, (int)$b['branch_id']); elseif (array_key_exists('branch_id', $b)) $b['branch_id'] = null;
    // auto-link: HR types the employee's e-mail; when it is the e-mail of an active login of this company that no other card uses, the card is linked to that login
    // (the fixed "my leave" and "my profile" tabs find the person through this link)
    if (!array_key_exists('user_id', $b) && !empty($b['email'])) {
        $already = false;
        if ($GLOBALS['id']) { $cur = db()->prepare('SELECT user_id FROM employees WHERE id = ? AND company_id = ?'); $cur->execute([(int)$GLOBALS['id'], $cid]); $already = (bool)$cur->fetchColumn(); }
        if (!$already) {
            $q = db()->prepare('SELECT u.id FROM users u WHERE u.company_id = ? AND u.is_active = 1 AND LOWER(u.email) = LOWER(?) AND NOT EXISTS (SELECT 1 FROM employees e WHERE e.user_id = u.id AND e.company_id = ?' . ($GLOBALS['id'] ? ' AND e.id <> ' . (int)$GLOBALS['id'] : '') . ') LIMIT 1');
            $q->execute([$cid, $b['email'], $cid]);
            if ($uid = $q->fetchColumn()) $b['user_id'] = (int)$uid;
        }
    }
    foreach (['manager_id', 'user_id'] as $f) {
        if (!empty($b[$f])) {
            $t = $f === 'user_id' ? 'users' : 'employees';
            $q = db()->prepare("SELECT 1 FROM $t WHERE id = ? AND company_id = ?"); $q->execute([(int)$b[$f], $cid]);
            if (!$q->fetchColumn()) error_response("$f not found", 422);
        } elseif (array_key_exists($f, $b)) $b[$f] = null;
    }
    if (!empty($b['user_id'])) {
        $d = db()->prepare('SELECT id FROM employees WHERE user_id = ? AND company_id = ?' . ($GLOBALS['id'] ? ' AND id <> ' . (int)$GLOBALS['id'] : '')); $d->execute([(int)$b['user_id'], $cid]);
        if ($d->fetchColumn()) error_response('This user account is already linked to another employee', 409);
    }
    $GLOBALS['body'] = $b;
}
crud_handle([
    'table' => 'employees',
    'search' => ['first_name', 'last_name', 'email', 'employee_id', 'national_id'],
    'filters' => ['status', 'department', 'branch_id'],
    'order' => 'id DESC',
    'branch_col' => 'branch_id',
]);
