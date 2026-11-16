<?php
// Phase 10 (suite 16): HR core — employee master, expiring documents, leave types and balances, leave requests through the designed approval flow.
//   php hostinger/api/tests/phase16_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8200/api', '/');
$pdo  = new PDO($argv[2] ?? 'mysql:host=127.0.0.1;port=3410;dbname=alanad_fresh;charset=utf8mb4', $argv[3] ?? 'root', $argv[4] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0; $fails = [];
function http(string $m, string $path, ?array $body = null, ?string $tok = null, ?int $company = null, ?int $branch = null): array {
    global $base;
    $ch = curl_init($base . $path);
    $h = ['Content-Type: application/json'];
    if ($tok) $h[] = "Authorization: Bearer $tok";
    if ($company) $h[] = "X-Company-Id: $company";
    if ($branch) $h[] = "X-Branch-Id: $branch";
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status' => $st, 'json' => json_decode($raw, true)];
}
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($ok) { $pass++; echo "  ok   $name\n"; } else { $fail++; $fails[] = $name; echo "  FAIL $name $detail\n"; }
}
function login(string $e, string $p): ?string { return http('POST', '/auth/login', ['email' => $e, 'password' => $p])['json']['token'] ?? null; }

$u = substr((string)time(), -6);
$admin = login('admin@manzuma.com', 'password');
// Phase 14: the real "user" role now means self-service only. These older suites need a general worker who can create and edit, so they use a test-only role.
$pdo->exec("INSERT IGNORE INTO roles (company_id, role_key, name_en, name_ar, is_system) VALUES (NULL, 'test_worker', 'Test worker', 'عامل اختبار', 0)");
$wk = (int)$pdo->query("SELECT id FROM roles WHERE role_key = 'test_worker' AND company_id IS NULL")->fetchColumn();
foreach (['*.view', '*.create', '*.edit'] as $pm) $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission) VALUES ($wk, '$pm')");
$role = fn(string $k) => (int)$pdo->query("SELECT id FROM roles WHERE role_key = '$k' AND company_id IS NULL")->fetchColumn();
foreach ([["hh_$u@x.com", $role('hr_officer')], ["hm_$u@x.com", 2], ["he_$u@x.com", $wk], ["hf_$u@x.com", $wk], ["hv_$u@x.com", 4]] as [$e, $r]) http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => 'Strong-Pass-123', 'role_id' => $r], $admin);
$hr = login("hh_$u@x.com", 'Strong-Pass-123'); $mgr = login("hm_$u@x.com", 'Strong-Pass-123'); $emp = login("he_$u@x.com", 'Strong-Pass-123'); $other = login("hf_$u@x.com", 'Strong-Pass-123'); $viewer = login("hv_$u@x.com", 'Strong-Pass-123');
$uid = fn(string $e) => (int)$pdo->query("SELECT id FROM users WHERE email = '$e'")->fetchColumn();
$main = (int)http('GET', '/me', null, $admin)['json']['branches'][0]['id'];
$B1 = (int)http('POST', '/company-branches', ['code' => "HB$u", 'name_en' => "HR Jeddah $u"], $admin)['json']['id'];
$d = fn(int $days) => date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));
$wf = function () use ($admin) { foreach (http('GET', '/workflows', null, $admin)['json'] as $w) if ($w['wf_key'] === 'leave_request') return $w; return null; };

// the leave flow may have been created by an older test with the HR step on the manager: bring it to the designed default (team head -> HR officer)
$lf = $wf();
if ($lf) http('PUT', "/workflows/{$lf['id']}", ['steps' => [['name_en' => 'Team head', 'name_ar' => 'رئيس الفريق', 'role_key' => 'manager', 'rule' => 'maker_not_checker'], ['name_en' => 'HR', 'name_ar' => 'الموارد البشرية', 'role_key' => 'hr_officer', 'rule' => 'maker_not_checker']], 'note' => 'default'], $admin);
echo "\n== the employee master\n";
check('a read-only user cannot add an employee (403)', http('POST', '/employees', ['employee_id' => "E0$u", 'first_name' => 'A', 'last_name' => 'B'], $viewer)['status'] === 403);
check('a bad Saudi ID is refused (422)', http('POST', '/employees', ['employee_id' => "E1$u", 'first_name' => 'A', 'last_name' => 'B', 'national_id' => '12345'], $hr)['status'] === 422);
check('a bad IBAN is refused (422)', http('POST', '/employees', ['employee_id' => "E2$u", 'first_name' => 'A', 'last_name' => 'B', 'iban' => 'SA12'], $hr)['status'] === 422);
check('a bad date or gender is refused (422)', http('POST', '/employees', ['employee_id' => "E3$u", 'first_name' => 'A', 'last_name' => 'B', 'iqama_expiry' => '31/12/2027'], $hr)['status'] === 422 && http('POST', '/employees', ['employee_id' => "E3$u", 'first_name' => 'A', 'last_name' => 'B', 'gender' => 'X'], $hr)['status'] === 422);
check('an unknown branch, manager or user link is refused (422)', http('POST', '/employees', ['employee_id' => "E4$u", 'first_name' => 'A', 'last_name' => 'B', 'branch_id' => 999999], $hr)['status'] === 422 && http('POST', '/employees', ['employee_id' => "E4$u", 'first_name' => 'A', 'last_name' => 'B', 'manager_id' => 999999], $hr)['status'] === 422 && http('POST', '/employees', ['employee_id' => "E4$u", 'first_name' => 'A', 'last_name' => 'B', 'user_id' => 999999], $hr)['status'] === 422);
$e1 = http('POST', '/employees', ['employee_id' => "EM1$u", 'first_name' => 'Khalid', 'last_name' => 'Alharbi', 'department' => 'Preparation', 'designation' => 'Packer', 'date_of_joining' => '2024-01-01', 'national_id' => '1098765432', 'nationality' => 'Saudi', 'gender' => 'M', 'iban' => 'sa03 8000 0000 6080 1016 7519', 'iqama_expiry' => $d(-3), 'health_card_expiry' => $d(10), 'passport_expiry' => $d(50), 'contract_end' => $d(200), 'branch_id' => $main, 'user_id' => $uid("he_$u@x.com"), 'status' => 'Active'], $hr);
$E1 = (int)($e1['json']['id'] ?? 0);
check('the HR officer creates an employee with all the Saudi fields; the IBAN is stored in capitals without spaces', $e1['status'] === 201 && $e1['json']['iban'] === 'SA0380000000608010167519' && $e1['json']['national_id'] === '1098765432' && (int)$e1['json']['branch_id'] === $main, json_encode($e1['json']['error'] ?? null));
check('the same user account cannot be linked to two employees (409)', http('POST', '/employees', ['employee_id' => "EM9$u", 'first_name' => 'X', 'last_name' => 'Y', 'user_id' => $uid("he_$u@x.com")], $hr)['status'] === 409);
$e2 = http('POST', '/employees', ['employee_id' => "EM2$u", 'first_name' => 'Omar', 'last_name' => 'Saleh', 'department' => 'Store', 'date_of_joining' => '2018-01-01', 'status' => 'Active', 'branch_id' => $B1, 'contract_end' => $d(400)], $hr);
$E2 = (int)$e2['json']['id'];
$e3 = http('POST', '/employees', ['employee_id' => "EM3$u", 'first_name' => 'Left', 'last_name' => 'Person', 'status' => 'Left', 'iqama_expiry' => $d(-30)], $hr);
check('search finds the employee by his national ID', in_array($E1, array_column(http('GET', '/employees?search=1098765432', null, $hr)['json']['data'] ?? http('GET', '/employees?search=1098765432', null, $hr)['json'], 'id')));
check('the list can be limited to a branch, and the employee of another branch is hidden while working in this one', (function () use ($hr, $B1, $E2, $E1) { $r = http('GET', '/employees', null, $hr, null, $B1)['json']; $r = $r['data'] ?? $r; $ids = array_column($r, 'id'); return in_array($E2, $ids) && !in_array($E1, $ids); })());

echo "\n== leave types and balances\n";
$types = http('GET', '/leave-types', null, $hr)['json']; $types = $types['data'] ?? $types; $T = []; foreach ($types as $t) $T[$t['name']] = $t;
check('the Saudi leave types are there: annual 21, sick 120, marriage 5, maternity 70, unpaid', isset($T['Annual leave'], $T['Sick leave'], $T['Maternity leave'], $T['Unpaid leave']) && (int)$T['Annual leave']['max_days_allowed'] === 21 && $T['Annual leave']['kind'] === 'annual');
$AN = (int)$T['Annual leave']['id']; $SK = (int)$T['Sick leave']['id']; $UN = (int)$T['Unpaid leave']['id'];
$b1 = http('GET', "/hr-leaves/balances?employee_id=$E1&year=2027", null, $hr)['json']; $b2 = http('GET', "/hr-leaves/balances?employee_id=$E2&year=2027", null, $hr)['json'];
$ann = fn($b) => array_values(array_filter($b, fn($x) => $x['kind'] === 'annual'))[0];
check('annual leave: 21 days for an employee with under 5 years, 30 days after 5 years of service', $ann($b1)['entitlement'] === 21 && $ann($b2)['entitlement'] === 30 && $ann($b1)['remaining'] === 21);
check('unpaid leave has no limit', array_values(array_filter($b1, fn($x) => $x['kind'] === 'unpaid'))[0]['unlimited'] === true);
check('balances of an unknown employee (404)', http('GET', '/hr-leaves/balances?employee_id=999999', null, $hr)['status'] === 404);

echo "\n== requesting leave\n";
$mon = '2027-01-04';                                            // a Monday
check('an unlinked standard user cannot request leave for someone else (403)', http('POST', '/hr-leaves', ['employee_id' => $E1, 'leave_type_id' => $AN, 'from_date' => $mon, 'to_date' => '2027-01-07'], $other)['status'] === 403);
check('an employee cannot request for another employee (403)', http('POST', '/hr-leaves', ['employee_id' => $E2, 'leave_type_id' => $AN, 'from_date' => $mon, 'to_date' => '2027-01-07'], $emp)['status'] === 403);
check('the end date before the start (422)', http('POST', '/hr-leaves', ['employee_id' => $E1, 'leave_type_id' => $AN, 'from_date' => '2027-01-07', 'to_date' => '2027-01-04'], $emp)['status'] === 422);
check('a leave that is only Friday and Saturday has nothing to count (422)', http('POST', '/hr-leaves', ['employee_id' => $E1, 'leave_type_id' => $AN, 'from_date' => '2027-01-08', 'to_date' => '2027-01-09'], $emp)['status'] === 422);
check('an unknown leave type (422) and an employee who left (409)', http('POST', '/hr-leaves', ['employee_id' => $E1, 'leave_type_id' => 999999, 'from_date' => $mon, 'to_date' => '2027-01-07'], $emp)['status'] === 422 && http('POST', '/hr-leaves', ['employee_id' => (int)$e3['json']['id'], 'leave_type_id' => $AN, 'from_date' => $mon, 'to_date' => '2027-01-07'], $hr)['status'] === 409);
$big = http('POST', '/hr-leaves', ['employee_id' => $E1, 'leave_type_id' => $AN, 'from_date' => '2027-02-01', 'to_date' => '2027-03-31'], $emp);
check('more days than the balance is refused (409)', $big['status'] === 409 && stripos($big['json']['error'], 'balance') !== false, json_encode($big['json']));
$l1 = http('POST', '/hr-leaves', ['employee_id' => $E1, 'leave_type_id' => $AN, 'from_date' => $mon, 'to_date' => '2027-01-07', 'reason' => 'family visit'], $emp);
$L1 = (int)($l1['json']['id'] ?? 0);
check('the employee requests 4 days (Monday to Thursday): Pending, numbered LR-, in the approval flow at step 1 (team head)', $l1['status'] === 201 && $l1['json']['status'] === 'Pending' && (int)$l1['json']['days'] === 4 && $l1['json']['flow']['current_step']['name_en'] === 'Team head' && strpos($l1['json']['number'], 'LR-') === 0, json_encode($l1['json']['error'] ?? $l1['json']['flow']['current_step'] ?? null));
$lHr = http('POST', '/hr-leaves', ['employee_id' => $E2, 'leave_type_id' => $AN, 'from_date' => '2027-01-14', 'to_date' => '2027-01-17', 'reason' => 'HR on behalf'], $hr)['json'];
check('HR can request on behalf of an employee; Thursday to Sunday counts 2 working days (Friday and Saturday are the weekend)', (int)$lHr['days'] === 2 && $lHr['status'] === 'Pending');
check('overlapping dates are refused (409)', http('POST', '/hr-leaves', ['employee_id' => $E1, 'leave_type_id' => $UN, 'from_date' => '2027-01-05', 'to_date' => '2027-01-06'], $emp)['status'] === 409);
check('the pending days already count against the balance (17 left)', $ann(http('GET', "/hr-leaves/balances?employee_id=$E1&year=2027", null, $hr)['json'])['remaining'] === 17 && $ann(http('GET', "/hr-leaves/balances?employee_id=$E1&year=2027", null, $hr)['json'])['pending'] === 4);

echo "\n== the approval flow: team head, then HR\n";
check('the requester cannot approve his own leave', http('POST', "/hr-leaves/$L1/decide", ['action' => 'approve'], $emp)['status'] === 403);
check('an action must be approve or reject (422)', http('POST', "/hr-leaves/$L1/decide", ['action' => 'maybe'], $mgr)['status'] === 422);
$a1 = http('POST', "/hr-leaves/$L1/decide", ['action' => 'approve'], $mgr);
check('the team head (manager) approves step 1: still Pending, now at HR', $a1['status'] === 200 && $a1['json']['status'] === 'Pending' && $a1['json']['flow']['current_step']['name_en'] === 'HR', json_encode($a1['json']['error'] ?? null));
check('the manager cannot also take the HR step: it belongs to the HR officer (403)', http('POST', "/hr-leaves/$L1/decide", ['action' => 'approve'], $mgr)['status'] === 403);
$a2 = http('POST', "/hr-leaves/$L1/decide", ['action' => 'approve'], $hr);
check('the HR officer approves: Approved with the decision time', $a2['status'] === 200 && $a2['json']['status'] === 'Approved' && !empty($a2['json']['decided_at']), json_encode($a2['json']['error'] ?? null));
$bal = $ann(http('GET', "/hr-leaves/balances?employee_id=$E1&year=2027", null, $hr)['json']);
check('the balance now shows 4 taken and 17 left', $bal['taken'] === 4 && $bal['remaining'] === 17 && $bal['pending'] === 0);
check('an approved leave cannot be decided again or cancelled (409)', http('POST', "/hr-leaves/$L1/decide", ['action' => 'approve'], $hr)['status'] === 409 && http('POST', "/hr-leaves/$L1/cancel", ['reason' => 'x'], $emp)['status'] === 409);
$l2 = (int)http('POST', '/hr-leaves', ['employee_id' => $E1, 'leave_type_id' => $UN, 'from_date' => '2027-02-01', 'to_date' => '2027-02-03'], $emp)['json']['id'];
check('a rejection needs a reason (422)', http('POST', "/hr-leaves/$l2/decide", ['action' => 'reject'], $mgr)['status'] === 422);
$rj = http('POST', "/hr-leaves/$l2/decide", ['action' => 'reject', 'reason_code' => 'other', 'note' => 'busy season'], $mgr);
check('the team head rejects with a reason: Rejected', $rj['status'] === 200 && $rj['json']['status'] === 'Rejected' && stripos($rj['json']['reject_reason'], 'busy season') !== false, json_encode($rj['json']['error'] ?? null));
$l3 = (int)http('POST', '/hr-leaves', ['employee_id' => $E1, 'leave_type_id' => $UN, 'from_date' => '2027-02-08', 'to_date' => '2027-02-09'], $emp)['json']['id'];
check('cancelling needs a reason (422), and only the requester or HR may cancel (403)', http('POST', "/hr-leaves/$l3/cancel", [], $emp)['status'] === 422 && http('POST', "/hr-leaves/$l3/cancel", ['reason' => 'x'], $other)['status'] === 403);
check('the requester cancels his pending leave', http('POST', "/hr-leaves/$l3/cancel", ['reason' => 'plans changed'], $emp)['json']['status'] === 'Cancelled');
check('the list can be filtered by status and shows the approved leave', in_array($L1, array_column(http('GET', '/hr-leaves?status=Approved', null, $hr)['json'], 'id')));

echo "\n== the designer decides who approves leave\n";
$flow = $wf();
$save = fn(array $steps) => http('PUT', "/workflows/{$flow['id']}", ['steps' => $steps, 'note' => 'phase 10 test'], $GLOBALS['admin']);
$st = fn($n, $r) => ['name_en' => $n, 'name_ar' => $n, 'role_key' => $r, 'rule' => 'maker_not_checker'];
$orig = array_map(fn($s) => ['name_en' => $s['name_en'], 'name_ar' => $s['name_ar'], 'role_key' => $s['role_key'], 'rule' => $s['rule']], $flow['steps']);
check('the default leave flow has two steps: team head (manager) and HR (hr_officer)', count($orig) === 2 && $orig[0]['role_key'] === 'manager' && $orig[1]['role_key'] === 'hr_officer', json_encode($orig));
$pdo->exec("UPDATE workflow_instances SET status = 'cancelled' WHERE doc_type = 'leave' AND status = 'open' AND workflow_id = {$flow['id']} AND doc_id <> {$lHr['id']}");
check('the number of steps cannot change while a leave is waiting for approval (409)', $save([$st('Team head', 'manager')])['status'] === 409);
http('POST', "/hr-leaves/{$lHr['id']}/cancel", ['reason' => 'test cleanup'], $hr);
$pdo->exec("UPDATE workflow_instances SET status = 'cancelled' WHERE doc_type = 'leave' AND status = 'open' AND workflow_id = {$flow['id']}");
check('but who approves a step can change even while leaves are waiting (same number of steps)', http('PUT', "/workflows/{$flow['id']}", ['steps' => [$st('Team head', 'manager'), $st('HR', 'hr_officer')], 'note' => 'same steps'], $GLOBALS['admin'])['status'] === 200);
check('with nothing waiting, the designer cuts the flow to one step', $save([$st('Team head', 'manager')])['status'] === 200);
$l4 = (int)http('POST', '/hr-leaves', ['employee_id' => $E1, 'leave_type_id' => $SK, 'from_date' => '2027-03-01', 'to_date' => '2027-03-02'], $emp)['json']['id'];
check('after the designer cut the flow to one step, the team head alone approves a new leave', http('POST', "/hr-leaves/$l4/decide", ['action' => 'approve'], $mgr)['json']['status'] === 'Approved');
$save($orig);
check('the original two-step flow is back', count($wf()['steps']) === 2 && $wf()['steps'][1]['role_key'] === 'hr_officer');

echo "\n== documents that expire\n";
$ex = http('GET', '/hr-expiry', null, $hr)['json'];
$mine = array_values(array_filter($ex, fn($x) => $x['employee_id'] === $E1)); $docs = []; foreach ($mine as $x) $docs[$x['document']] = $x;
check('the expired iqama (3 days ago) is "expired", the health card (10 days) is "critical", the passport (50 days) is "soon"; the contract (200 days) is not listed', ($docs['Iqama']['severity'] ?? '') === 'expired' && ($docs['Iqama']['days_left'] ?? 0) === -3 && ($docs['Health card']['severity'] ?? '') === 'critical' && ($docs['Passport']['severity'] ?? '') === 'soon' && !isset($docs['Contract']), json_encode($docs));
check('the list is sorted with the worst first and an employee who left is not listed', $ex[0]['days_left'] <= $ex[count($ex) - 1]['days_left'] && count(array_filter($ex, fn($x) => $x['employee_id'] === (int)$e3['json']['id'])) === 0);
check('a shorter window (?days=20) hides the passport', count(array_filter(http('GET', '/hr-expiry?days=20', null, $hr)['json'], fn($x) => $x['employee_id'] === $E1 && $x['document'] === 'Passport')) === 0);
$sum = http('GET', '/hr-expiry/summary', null, $hr)['json'];
check('the HR summary counts active employees, who is on leave today, pending requests, expired and expiring documents and gives the portal address', $sum['active_employees'] >= 2 && $sum['expired'] >= 1 && $sum['expiring_soon'] >= 2 && strpos($sum['portal_url'], 'https://') === 0);
check('working in Jeddah only the Jeddah employees\' documents are shown', count(array_filter(http('GET', '/hr-expiry', null, $hr, null, $B1)['json'], fn($x) => $x['employee_id'] === $E1)) === 0);
http('PUT', '/settings', ['hr.expiry_warning_days' => 5], $admin);
check('the warning window is a setting (5 days: the passport and health card disappear, the expired iqama stays)', (function () use ($hr, $E1) { $r = array_values(array_filter(http('GET', '/hr-expiry', null, $hr)['json'], fn($x) => $x['employee_id'] === $E1)); return count($r) === 1 && $r[0]['document'] === 'Iqama'; })());
http('PUT', '/settings', ['hr.expiry_warning_days' => 60], $admin);

echo "\n== the weekend is a setting\n";
http('PUT', '/settings', ['hr.weekend_days' => '5'], $admin);
$lw = http('POST', '/hr-leaves', ['employee_id' => $E2, 'leave_type_id' => $UN, 'from_date' => '2027-04-01', 'to_date' => '2027-04-03'], $hr)['json'];
check('with only Friday as weekend, Thursday to Saturday counts 2 days', (int)$lw['days'] === 2);
http('PUT', '/settings', ['hr.weekend_days' => '5,6'], $admin);

echo "\n== companies stay apart\n";
$co = http('POST', '/companies', ['name_en' => "HR other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
check('company 2 gets the leave types by itself and sees no employees, leaves or expiring documents of company 1', count(http('GET', '/leave-types', null, $admin, $co)['json']['data'] ?? http('GET', '/leave-types', null, $admin, $co)['json']) >= 8 && count(http('GET', '/hr-leaves', null, $admin, $co)['json']) === 0 && count(http('GET', '/hr-expiry', null, $admin, $co)['json']) === 0);
check('company 2 cannot read company 1\'s balances, leave or decide on it (404)', http('GET', "/hr-leaves/balances?employee_id=$E1", null, $admin, $co)['status'] === 404 && http('GET', "/hr-leaves/$L1", null, $admin, $co)['status'] === 404 && http('POST', "/hr-leaves/$L1/decide", ['action' => 'approve'], $admin, $co)['status'] === 404 && http('GET', "/employees/$E1", null, $admin, $co)['status'] === 404);
check('company 2 cannot request leave for company 1\'s employee (404)', http('POST', '/hr-leaves', ['employee_id' => $E1, 'leave_type_id' => $AN, 'from_date' => '2027-05-03', 'to_date' => '2027-05-04'], $admin, $co)['status'] === 404);
$cl = http('GET', '/leave-types', null, $admin, $co)['json']; $cl = $cl['data'] ?? $cl;
$e = http('POST', '/employees', ['employee_id' => "CO$u", 'first_name' => 'Other', 'last_name' => 'Co', 'status' => 'Active', 'date_of_joining' => '2025-01-01'], $admin, $co)['json'];
$lo = http('POST', '/hr-leaves', ['employee_id' => $e['id'], 'leave_type_id' => $cl[0]['id'], 'from_date' => '2027-05-03', 'to_date' => '2027-05-04'], $admin, $co);
check('company 2 gets its own two-step leave flow on first use', $lo['status'] === 201 && $lo['json']['flow']['current_step']['name_en'] === 'Team head', json_encode($lo['json']['error'] ?? null));
check('company 2 cannot use company 1\'s leave type for its employee (422)', http('POST', '/hr-leaves', ['employee_id' => $e['id'], 'leave_type_id' => $AN, 'from_date' => '2027-06-07', 'to_date' => '2027-06-08'], $admin, $co)['status'] === 422);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
