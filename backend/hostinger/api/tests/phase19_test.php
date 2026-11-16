<?php
// Phase 14 (suite 19): two layers of tabs. The plain employee has only the fixed self-service (my profile, my leave, my tickets), every work role sees only
// its own work (view = its tabs, lookup = background lists with no tab), and nobody reads another person's leave.
//   php hostinger/api/tests/phase19_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8200/api', '/');
$pdo  = new PDO($argv[2] ?? 'mysql:host=127.0.0.1;port=3410;dbname=alanad_fresh;charset=utf8mb4', $argv[3] ?? 'root', $argv[4] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0; $fails = [];
function http(string $m, string $path, ?array $body = null, ?string $tok = null): array {
    global $base;
    $ch = curl_init($base . $path);
    $h = ['Content-Type: application/json'];
    if ($tok) $h[] = "Authorization: Bearer $tok";
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
check('admin login', (bool)$admin);
$role = fn(string $k) => (int)$pdo->query("SELECT id FROM roles WHERE role_key = '$k' AND company_id IS NULL")->fetchColumn();
$P = 'Strong-Pass-123';
$mk = function (string $tag, int $roleId) use ($u, $admin, $P) { $e = "{$tag}_$u@x.com"; $r = http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => $P, 'role_id' => $roleId], $admin); return ['id' => $r['json']['id'] ?? 0, 'email' => $e, 'tok' => login($e, $P)]; };
$emp = $mk('emp', $role('user')); $emp2 = $mk('emp2', $role('user')); $pk = $mk('pk', $role('packer')); $hr = $mk('hr', $role('hr_officer')); $mg = $mk('mg', 2); $it = $mk('it', $role('it_staff')); $sk = $mk('sk', $role('storekeeper'));
$card = function (array $usr, string $code) use ($admin, $u) { return http('POST', '/employees', ['employee_id' => "P19-$code-$u", 'first_name' => $code, 'last_name' => 'Test', 'department' => 'Test', 'designation' => 'Test', 'date_of_joining' => '2024-01-01', 'status' => 'Active', 'user_id' => $usr['id']], $admin); };

echo "\n— the plain employee has no work module at all\n";
foreach (['/stock/balance', '/items', '/cold-rooms', '/pack-orders', '/deliveries', '/purchase-orders', '/ap-invoices', '/client-orders', '/shipments', '/employees', '/hr-leaves', '/hr-expiry', '/it-assets', '/factory-reports', '/dashboard', '/users', '/settings'] as $p)
    check("employee cannot read $p (403)", http('GET', $p, null, $emp['tok'])['status'] === 403);
check('employee cannot create an item (403)', http('POST', '/items', ['name' => 'x'], $emp['tok'])['status'] === 403);
check('employee /me lists no work permission', ($m = http('GET', '/me', null, $emp['tok'])['json'] ?? []) && count($m['permissions'] ?? []) === 0, json_encode($m['permissions'] ?? null));

echo "\n— the fixed services work for him\n";
$pr = http('GET', '/my/profile', null, $emp['tok']);
check('my profile answers (200) with no employee card yet', $pr['status'] === 200 && $pr['json']['employee'] === null);
check('my leave without a card says so (409)', http('GET', '/my/leave', null, $emp['tok'])['status'] === 409);
check('/me says has_employee = false before the card', (http('GET', '/me', null, $emp['tok'])['json']['has_employee'] ?? null) === false);
check('HR links his account to an employee card (201)', $card($emp, 'A')['status'] === 201);
check('/me says has_employee = true after the card', (http('GET', '/me', null, $emp['tok'])['json']['has_employee'] ?? null) === true);
$pr = http('GET', '/my/profile', null, $emp['tok'])['json'];
check('my profile shows my card and no salary', ($pr['employee']['code'] ?? '') !== '' && !isset($pr['employee']['salary']) && !array_key_exists('iban', $pr['employee'] ?? []));
$lv = http('GET', '/my/leave', null, $emp['tok'])['json'];
check('my leave lists the leave types and my balances', count($lv['types'] ?? []) >= 3 && count($lv['balances'] ?? []) >= 3 && ($lv['requests'] ?? null) === []);
$typeId = (int)$lv['types'][0]['id'];
$next = new DateTime('next monday'); $from = $next->format('Y-m-d'); $to = (clone $next)->modify('+2 days')->format('Y-m-d');
$cr = http('POST', '/my/leave', ['leave_type_id' => $typeId, 'from_date' => $from, 'to_date' => $to, 'reason' => 'p19', 'employee_id' => 1], $emp['tok']);
check('I request leave for myself (201, Pending), even if I send another employee_id', $cr['status'] === 201 && $cr['json']['status'] === 'Pending', json_encode($cr['json']));
$myLeaveId = (int)($cr['json']['id'] ?? 0);
$own = (int)$pdo->query("SELECT employee_id FROM leave_requests WHERE id = $myLeaveId")->fetchColumn();
$mine = (int)$pdo->query("SELECT id FROM employees WHERE user_id = {$emp['id']}")->fetchColumn();
check('the request belongs to MY employee card, not the one I sent', $own === $mine && $own !== 1);
check('overlapping dates are refused (409)', http('POST', '/my/leave', ['leave_type_id' => $typeId, 'from_date' => $from, 'to_date' => $to], $emp['tok'])['status'] === 409);
$d = http('GET', "/my/leave/$myLeaveId", null, $emp['tok']);
check('I see where my request is (the approval flow)', $d['status'] === 200 && ($d['json']['flow']['current_step']['position'] ?? 0) === 1);

echo "\n— nobody sees another person's leave\n";
$card($emp2, 'B');
check('another employee has an empty list', (http('GET', '/my/leave', null, $emp2['tok'])['json']['requests'] ?? null) === []);
check("another employee cannot open my request (404)", http('GET', "/my/leave/$myLeaveId", null, $emp2['tok'])['status'] === 404);
check("another employee cannot cancel my request (404)", http('POST', "/my/leave/$myLeaveId/cancel", ['reason' => 'x'], $emp2['tok'])['status'] === 404);
check('the whole HR list is closed to both (403)', http('GET', '/hr-leaves', null, $emp2['tok'])['status'] === 403);

echo "\n— approval still works through the flow, the employee follows it\n";
check('the team head (manager) approves step 1', http('POST', "/hr-leaves/$myLeaveId/decide", ['action' => 'approve'], $mg['tok'])['status'] === 200);
check('the HR officer sees all requests (200)', http('GET', '/hr-leaves', null, $hr['tok'])['status'] === 200);
$a2 = http('POST', "/hr-leaves/$myLeaveId/decide", ['action' => 'approve'], $hr['tok']);
check('HR approves step 2: Approved', $a2['status'] === 200 && $a2['json']['status'] === 'Approved', json_encode($a2['json']));
check('the employee sees Approved in his list', (http('GET', '/my/leave', null, $emp['tok'])['json']['requests'][0]['status'] ?? '') === 'Approved');
check('an approved request cannot be cancelled (409)', http('POST', "/my/leave/$myLeaveId/cancel", ['reason' => 'x'], $emp['tok'])['status'] === 409);
$cr2 = http('POST', '/my/leave', ['leave_type_id' => $typeId, 'from_date' => (clone $next)->modify('+14 days')->format('Y-m-d'), 'to_date' => (clone $next)->modify('+15 days')->format('Y-m-d')], $emp['tok']);
check('I cancel my own pending request with a reason', http('POST', "/my/leave/{$cr2['json']['id']}/cancel", ['reason' => 'changed my mind'], $emp['tok'])['status'] === 200);
check('cancelling needs a reason (422)', ($c3 = http('POST', '/my/leave', ['leave_type_id' => $typeId, 'from_date' => (clone $next)->modify('+21 days')->format('Y-m-d'), 'to_date' => (clone $next)->modify('+21 days')->format('Y-m-d')], $emp['tok'])) && http('POST', "/my/leave/{$c3['json']['id']}/cancel", [], $emp['tok'])['status'] === 422);

echo "\n— my IT tickets: mine only\n";
$t = http('POST', '/it-tickets', ['subject' => 'p19 scale', 'category' => 'hardware', 'priority' => 'medium'], $emp['tok']);
check('the employee opens a ticket (201)', $t['status'] === 201);
check('he sees it in his list', in_array((int)$t['json']['id'], array_map(fn($x) => (int)$x['id'], http('GET', '/it-tickets', null, $emp['tok'])['json'] ?? []), true));
check("another employee does not see it", !in_array((int)$t['json']['id'], array_map(fn($x) => (int)$x['id'], http('GET', '/it-tickets', null, $emp2['tok'])['json'] ?? []), true));
check('IT staff sees it', in_array((int)$t['json']['id'], array_map(fn($x) => (int)$x['id'], http('GET', '/it-tickets', null, $it['tok'])['json'] ?? []), true));

echo "\n— a work role sees only its own work\n";
check('packer reads pack orders (view)', http('GET', '/pack-orders', null, $pk['tok'])['status'] === 200);
check('packer reads items for the picker (lookup)', http('GET', '/items', null, $pk['tok'])['status'] === 200);
check('packer cannot CREATE an item (lookup is read only) (403)', http('POST', '/items', ['name' => 'x'], $pk['tok'])['status'] === 403);
foreach (['/hr-leaves', '/employees', '/hr-expiry', '/ap-invoices', '/gl-entries', '/sales-invoices', '/it-assets', '/entry-permits', '/market-purchases', '/dashboard'] as $p)
    check("packer cannot read $p (403)", http('GET', $p, null, $pk['tok'])['status'] === 403);
check('storekeeper reads entry permits', http('GET', '/entry-permits', null, $sk['tok'])['status'] === 200);
foreach (['/pack-orders', '/hr-leaves', '/ap-invoices', '/sorting-runs'] as $p) check("storekeeper cannot read $p (403)", http('GET', $p, null, $sk['tok'])['status'] === 403);
check('HR officer reads employees and expiry', http('GET', '/employees', null, $hr['tok'])['status'] === 200 && http('GET', '/hr-expiry', null, $hr['tok'])['status'] === 200);
foreach (['/it-assets', '/pack-orders', '/stock/balance', '/ap-invoices'] as $p) check("HR officer cannot read $p (403)", http('GET', $p, null, $hr['tok'])['status'] === 403);
check('IT staff reads assets, not HR', http('GET', '/it-assets', null, $it['tok'])['status'] === 200 && http('GET', '/employees', null, $it['tok'])['status'] === 403);
check('manager and admin still see the dashboard and HR leave', http('GET', '/dashboard', null, $mg['tok'])['status'] === 200 && http('GET', '/hr-leaves', null, $mg['tok'])['status'] === 200 && http('GET', '/dashboard', null, $admin)['status'] === 200);
check('no work role keeps the wildcard view right', (int)$pdo->query("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.company_id IS NULL AND r.role_key IN ('user','quality_inspector','storekeeper','gate_officer','counter','receiving_finance','sorter','head_of_preparation','packer','dispatcher','sales_accountant','hr_officer','it_staff','it_manager') AND rp.permission IN ('*.view','*.create','*.edit')")->fetchColumn() === 0);

echo "\n— HR links the login to the card by e-mail (so My leave works for every new employee)\n";
$ea = "al_$u@x.com";
$c1 = http('POST', '/employees', ['employee_id' => "P19-AL-$u", 'first_name' => 'Al', 'last_name' => 'Link', 'email' => $ea, 'status' => 'Active', 'date_of_joining' => '2024-01-01'], $admin);
check('a card created before the login has no account yet', $c1['status'] === 201 && ($c1['json']['user_id'] ?? null) === null);
$al = $mk('al', $role('user'));
check('creating the login with the same e-mail links the waiting card', (int)$pdo->query("SELECT user_id FROM employees WHERE id = " . (int)$c1['json']['id'])->fetchColumn() === $al['id']);
check('the new employee can use My leave at once (200)', http('GET', '/my/leave', null, $al['tok'])['status'] === 200);
$bl = $mk('bl', $role('user'));
$c2 = http('POST', '/employees', ['employee_id' => "P19-BL-$u", 'first_name' => 'Bl', 'last_name' => 'Link', 'email' => "bl_$u@x.com", 'status' => 'Active', 'date_of_joining' => '2024-01-01'], $admin);
check('a card created after the login is linked on creation', ($c2['json']['user_id'] ?? null) == $bl['id']);
$c3 = http('POST', '/employees', ['employee_id' => "P19-BL2-$u", 'first_name' => 'Bl', 'last_name' => 'Two', 'email' => "bl_$u@x.com", 'status' => 'Active', 'date_of_joining' => '2024-01-01'], $admin);
check('a second card with the same e-mail does NOT steal the login', $c3['status'] === 201 && ($c3['json']['user_id'] ?? null) === null);

echo "\n$pass passed, $fail failed\n";
if ($fail) { echo "FAILED: " . implode(' | ', $fails) . "\n"; exit(1); }
