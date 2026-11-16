<?php
// Self-service: the FIXED tabs every signed-in person has (my profile, my leave). No permission is needed because every
// answer is about the signed-in person ONLY: the employee card is found through employees.user_id of the token's user,
// never through an id the caller sends. (My IT tickets live in /it-tickets, which already shows people only their own.)
//   GET  /my/profile                  my employee card (no salary), my documents with days left, my team head
//   GET  /my/leave                    leave types, my balances for this year, my requests
//   GET  /my/leave/{id}               one of MY requests with its approval flow
//   POST /my/leave {leave_type_id, from_date, to_date, reason?}      a request for myself
//   POST /my/leave/{id}/cancel {reason}                              cancel my own pending request
require_once __DIR__ . '/../core/hr.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $uid = (int)$u['user_id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$seg = $GLOBALS['parts'][1] ?? ''; $sub = $GLOBALS['parts'][2] ?? ''; $act = $GLOBALS['parts'][3] ?? '';

function my_employee(int $cid, int $uid): ?array {
    $q = db()->prepare("SELECT * FROM employees WHERE company_id = ? AND user_id = ? AND status = 'Active' LIMIT 1");
    $q->execute([$cid, $uid]);
    return $q->fetch() ?: null;
}
function my_need_employee(int $cid, int $uid): array {
    $e = my_employee($cid, $uid);
    if (!$e) error_response('Your account is not linked to an employee record yet. Ask HR to link it.', 409);
    return $e;
}

if ($seg === 'profile' && $method === 'GET') {
    $e = my_employee($cid, $uid);
    $out = ['user' => ['id' => $uid, 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']], 'employee' => null, 'documents' => [], 'team_head' => null];
    if ($e) {
        $branch = null;
        if (!empty($e['branch_id'])) { $b = db()->prepare('SELECT name_en, name_ar FROM company_branches WHERE id = ? AND company_id = ?'); $b->execute([(int)$e['branch_id'], $cid]); $branch = $b->fetch() ?: null; }
        if (!empty($e['manager_id'])) { $m = db()->prepare('SELECT first_name, last_name FROM employees WHERE id = ? AND company_id = ?'); $m->execute([(int)$e['manager_id'], $cid]); if ($mm = $m->fetch()) $out['team_head'] = trim($mm['first_name'] . ' ' . $mm['last_name']); }
        $out['employee'] = [
            'code' => $e['employee_id'], 'name' => trim($e['first_name'] . ' ' . $e['last_name']), 'department' => $e['department'], 'designation' => $e['designation'],
            'joined' => $e['date_of_joining'], 'status' => $e['status'], 'mobile' => $e['mobile'], 'email' => $e['email'], 'branch' => $branch,
        ];
        foreach ([['Iqama', 'iqama_expiry'], ['Passport', 'passport_expiry'], ['Health card', 'health_card_expiry'], ['Contract', 'contract_end']] as [$label, $col]) {
            if (empty($e[$col])) continue;
            $out['documents'][] = ['document' => $label, 'expires_on' => $e[$col], 'days_left' => (int)floor((strtotime($e[$col]) - strtotime(date('Y-m-d'))) / 86400)];
        }
    }
    json_response($out);
}

if ($seg === 'leave') {
    $e = my_need_employee($cid, $uid);
    if ($method === 'GET' && $sub === '') {
        $types = db()->prepare('SELECT id, name, kind, is_paid, max_days_allowed, description FROM leave_types WHERE company_id = ? ORDER BY id'); $types->execute([$cid]);
        $reqs = db()->prepare('SELECT l.id, l.number, l.leave_type, l.from_date, l.to_date, l.days, l.status, l.reason, l.reject_reason FROM leave_requests l WHERE l.company_id = ? AND l.employee_id = ? ORDER BY l.id DESC LIMIT 100');
        $reqs->execute([$cid, (int)$e['id']]);
        json_response(['types' => $types->fetchAll(), 'balances' => hr_balances($cid, (int)$e['id']), 'requests' => $reqs->fetchAll()]);
    }
    if ($method === 'POST' && $sub === '') {
        $b = $body; $b['employee_id'] = (int)$e['id'];      // always for myself, whatever the caller sent
        json_response(hr_leave_create($u, $b), 201);
    }
    if (ctype_digit((string)$sub)) {
        $row = hr_leave_row($cid, (int)$sub);
        if ((int)$row['employee_id'] !== (int)$e['id']) error_response('Not found', 404);        // somebody else's request looks like it does not exist
        if ($method === 'GET' && $act === '') json_response(hr_leave_detail($cid, (int)$sub));
        if ($method === 'POST' && $act === 'cancel') json_response(hr_leave_cancel($u, (int)$sub, $body));
    }
}
error_response('Not found', 404);
