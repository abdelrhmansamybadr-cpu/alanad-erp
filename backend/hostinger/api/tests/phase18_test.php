<?php
// Phase 12 (suite 18): IT core — helpdesk tickets (SLA, assignment, comments, status rules, privacy) and the IT asset register.
//   php hostinger/api/tests/phase18_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
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
$role = fn(string $k) => (int)$pdo->query("SELECT id FROM roles WHERE role_key = '$k' AND company_id IS NULL")->fetchColumn();
foreach ([["it1_$u@x.com", $role('it_staff')], ["it2_$u@x.com", $role('it_staff')], ["itm_$u@x.com", $role('it_manager')], ["ra_$u@x.com", 3], ["rb_$u@x.com", 3], ["rv_$u@x.com", 4]] as [$e, $r])
    http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => 'Strong-Pass-123', 'role_id' => $r], $admin);
$it1 = login("it1_$u@x.com", 'Strong-Pass-123'); $it2 = login("it2_$u@x.com", 'Strong-Pass-123'); $itm = login("itm_$u@x.com", 'Strong-Pass-123');
$ra = login("ra_$u@x.com", 'Strong-Pass-123'); $rb = login("rb_$u@x.com", 'Strong-Pass-123'); $viewer = login("rv_$u@x.com", 'Strong-Pass-123');
$uid = fn(string $e) => (int)$pdo->query("SELECT id FROM users WHERE email = '$e'")->fetchColumn();
$id1 = $uid("it1_$u@x.com"); $id2 = $uid("it2_$u@x.com");

echo "\n== the asset register\n";
check('a read-only user cannot add an asset (403)', http('POST', '/it-assets', ['code' => "A0$u", 'name' => 'x'], $viewer)['status'] === 403);
check('code and name are required (422)', http('POST', '/it-assets', ['name' => 'x'], $it1)['status'] === 422);
check('a warranty that ends before the purchase is refused (422)', http('POST', '/it-assets', ['code' => "A1$u", 'name' => 'x', 'purchase_date' => '2026-05-01', 'warranty_end' => '2026-01-01'], $it1)['status'] === 422);
check('a bad date or a bad cost is refused (422)', http('POST', '/it-assets', ['code' => "A2$u", 'name' => 'x', 'warranty_end' => '1/1/2027'], $it1)['status'] === 422 && http('POST', '/it-assets', ['code' => "A2$u", 'name' => 'x', 'cost' => -5], $it1)['status'] === 422);
check('an unknown branch or employee is refused (422)', http('POST', '/it-assets', ['code' => "A3$u", 'name' => 'x', 'branch_id' => 999999], $it1)['status'] === 422 && http('POST', '/it-assets', ['code' => "A3$u", 'name' => 'x', 'assigned_employee_id' => 999999], $it1)['status'] === 422);
$a1 = http('POST', '/it-assets', ['code' => "LT$u", 'name' => 'Laptop - warehouse office', 'type' => 'laptop', 'brand' => 'Dell', 'serial_no' => "SN$u", 'warranty_end' => date('Y-m-d', strtotime('+20 days')), 'purchase_date' => '2025-01-01', 'cost' => 3200], $it1);
check('IT adds a laptop (201)', $a1['status'] === 201 && !empty($a1['json']['id']), json_encode($a1['json']));
$a2 = http('POST', '/it-assets', ['code' => "SC$u", 'name' => 'Barcode scanner - gate', 'type' => 'scanner', 'warranty_end' => date('Y-m-d', strtotime('+400 days'))], $it1);
$a3 = http('POST', '/it-assets', ['code' => "OL$u", 'name' => 'Old printer', 'type' => 'printer', 'warranty_end' => date('Y-m-d', strtotime('-30 days')), 'status' => 'Retired'], $it1);
check('the same code twice is refused', http('POST', '/it-assets', ['code' => "LT$u", 'name' => 'dup'], $it1)['status'] >= 400);
$ex = http('GET', '/it-assets/expiring', null, $it1)['json'];
$codes = array_column(is_array($ex) ? $ex : [], 'code');
check('the warranty list holds the laptop (20 days left), not the far one and not the retired one', in_array("LT$u", $codes, true) && !in_array("SC$u", $codes, true) && !in_array("OL$u", $codes, true), json_encode($codes));
check('assets filter by type', count(array_filter(http('GET', '/it-assets?type=scanner', null, $it1)['json']['data'] ?? http('GET', '/it-assets?type=scanner', null, $it1)['json'], fn($r) => $r['type'] !== 'scanner')) === 0);
check('IT edits an asset (to Repair)', http('PUT', "/it-assets/{$a1['json']['id']}", ['status' => 'Repair'], $it1)['status'] === 200);

echo "\n== opening a ticket\n";
check('a ticket without a subject is refused (422)', http('POST', '/it-tickets', ['description' => 'x'], $ra)['status'] === 422);
check('an unknown priority or category is refused (422)', http('POST', '/it-tickets', ['subject' => 's', 'priority' => 'urgent'], $ra)['status'] === 422 && http('POST', '/it-tickets', ['subject' => 's', 'category' => 'x'], $ra)['status'] === 422);
check('an unknown asset is refused (422)', http('POST', '/it-tickets', ['subject' => 's', 'asset_id' => 999999], $ra)['status'] === 422);
$t1 = http('POST', '/it-tickets', ['subject' => 'Scale in room 2 shows no weight', 'description' => 'Screen is dark', 'category' => 'hardware', 'priority' => 'critical', 'asset_id' => (int)$a1['json']['id']], $ra);
check('any employee opens a ticket (201) with a number and status New', $t1['status'] === 201 && $t1['json']['status'] === 'New' && str_starts_with($t1['json']['number'], 'IT-'), json_encode($t1['json']));
$tid = (int)$t1['json']['id'];
$dbNow = fn() => strtotime($pdo->query('SELECT NOW()')->fetchColumn());   // MySQL and PHP may run in different time zones
$hrs = (strtotime($t1['json']['due_at']) - $dbNow()) / 3600;
check('a critical ticket is due in about 4 hours (the SLA setting)', $hrs > 3.5 && $hrs < 4.5, (string)$hrs);
$t2 = http('POST', '/it-tickets', ['subject' => 'Cannot print invoices', 'priority' => 'low'], $rb);
$hrs2 = (strtotime($t2['json']['due_at']) - $dbNow()) / 3600;
check('a low ticket is due in about 72 hours', $hrs2 > 71 && $hrs2 < 73, (string)$hrs2);

echo "\n== privacy\n";
$mine = http('GET', '/it-tickets', null, $ra)['json'];
check('an employee lists only his own tickets', count($mine) >= 1 && count(array_filter($mine, fn($r) => $r['requester_name'] !== "ra_$u@x.com")) === 0);
check('another employee cannot open my ticket (404)', http('GET', "/it-tickets/$tid", null, $rb)['status'] === 404);
check('IT sees every ticket', count(array_filter(http('GET', '/it-tickets', null, $it1)['json'], fn($r) => in_array($r['id'], [$tid, (int)$t2['json']['id']]))) === 2);
check('an employee cannot assign or work a ticket (403)', http('POST', "/it-tickets/$tid/assign", ['assignee_id' => $id1], $ra)['status'] === 403 && http('POST', "/it-tickets/$tid/status", ['status' => 'In_Progress'], $ra)['status'] === 403);
check('IT staff (not the manager) cannot assign (403)', http('POST', "/it-tickets/$tid/assign", ['assignee_id' => $id1], $it1)['status'] === 403);

echo "\n== assignment and work\n";
check('assigning to someone outside IT is refused (422)', http('POST', "/it-tickets/$tid/assign", ['assignee_id' => $uid("rb_$u@x.com")], $itm)['status'] === 422);
$as = http('POST', "/it-tickets/$tid/assign", ['assignee_id' => $id1], $itm);
check('the IT manager assigns: status Assigned, first response recorded', $as['status'] === 200 && $as['json']['status'] === 'Assigned' && $as['json']['first_response_at'] !== null, json_encode($as['json']));
check('the team list shows the IT people', count(array_filter(http('GET', '/it-tickets/team', null, $itm)['json'], fn($r) => $r['id'] === $id1)) === 1);
check('another IT person who is not the assignee cannot move it (403)', http('POST', "/it-tickets/$tid/status", ['status' => 'In_Progress'], $it2)['status'] === 403);
check('Assigned cannot jump to Resolved (409)', http('POST', "/it-tickets/$tid/status", ['status' => 'Resolved', 'resolution' => 'x'], $it1)['status'] === 409);
check('the assignee starts work', http('POST', "/it-tickets/$tid/status", ['status' => 'In_Progress'], $it1)['json']['status'] === 'In_Progress');
check('Resolved needs the resolution text (422)', http('POST', "/it-tickets/$tid/status", ['status' => 'Resolved'], $it1)['status'] === 422);
check('a bad status is refused (422)', http('POST', "/it-tickets/$tid/status", ['status' => 'Closed'], $it1)['status'] === 422);

echo "\n== comments\n";
check('an empty comment is refused (422)', http('POST', "/it-tickets/$tid/comment", ['body' => '  '], $ra)['status'] === 422);
check('an employee cannot write an internal note (403)', http('POST', "/it-tickets/$tid/comment", ['body' => 'x', 'internal' => true], $ra)['status'] === 403);
http('POST', "/it-tickets/$tid/comment", ['body' => 'Replaced the load cell cable, checking', 'internal' => true], $it1);
http('POST', "/it-tickets/$tid/comment", ['body' => 'Please keep the room closed'], $it1);
http('POST', "/it-tickets/$tid/comment", ['body' => 'Thank you'], $ra);
$for_req = http('GET', "/it-tickets/$tid", null, $ra)['json']['comments']; $for_it = http('GET', "/it-tickets/$tid", null, $it1)['json']['comments'];
check('the requester sees 2 comments and no internal note; IT sees all 3', count($for_req) === 2 && count($for_it) === 3 && count(array_filter($for_req, fn($c) => (int)$c['is_internal'] === 1)) === 0, count($for_req) . '/' . count($for_it));
check('another employee cannot comment on my ticket (404)', http('POST', "/it-tickets/$tid/comment", ['body' => 'hi'], $rb)['status'] === 404);

echo "\n== resolve, close, reopen\n";
$rs = http('POST', "/it-tickets/$tid/status", ['status' => 'Resolved', 'resolution' => 'Cable replaced, scale reads correctly'], $it1);
check('the assignee resolves with the resolution', $rs['status'] === 200 && $rs['json']['status'] === 'Resolved' && $rs['json']['resolved_at'] !== null);
check('another employee cannot close it (404)', http('POST', "/it-tickets/$tid/close", [], $rb)['status'] === 404);
check('reopening needs a reason (422)', http('POST', "/it-tickets/$tid/reopen", [], $ra)['status'] === 422);
$ro = http('POST', "/it-tickets/$tid/reopen", ['reason' => 'Still dark after an hour'], $ra);
check('the requester reopens: back to In_Progress and the reason is written as a comment', $ro['status'] === 200 && $ro['json']['status'] === 'In_Progress' && $ro['json']['resolved_at'] === null);
http('POST', "/it-tickets/$tid/status", ['status' => 'Waiting'], $it1);
check('Waiting goes back to In_Progress then Resolved', http('POST', "/it-tickets/$tid/status", ['status' => 'In_Progress'], $it1)['status'] === 200 && http('POST', "/it-tickets/$tid/status", ['status' => 'Resolved', 'resolution' => 'Replaced the display'], $it1)['status'] === 200);
check('closing a ticket that is not resolved is refused (409)', http('POST', "/it-tickets/{$t2['json']['id']}/close", [], $rb)['status'] === 409);
check('the requester closes the resolved ticket', http('POST', "/it-tickets/$tid/close", [], $ra)['json']['status'] === 'Closed');
check('a closed ticket takes no more comments (409)', http('POST', "/it-tickets/$tid/comment", ['body' => 'x'], $ra)['status'] === 409);

echo "\n== cancel\n";
$t2id = (int)$t2['json']['id'];
check('a reason is needed to cancel (422)', http('POST', "/it-tickets/$t2id/cancel", [], $rb)['status'] === 422);
check('another employee cannot cancel (404)', http('POST', "/it-tickets/$t2id/cancel", ['reason' => 'x'], $ra)['status'] === 404);
check('the requester cancels his own new ticket', http('POST', "/it-tickets/$t2id/cancel", ['reason' => 'Fixed itself'], $rb)['json']['status'] === 'Cancelled');

echo "\n== overdue, auto-close, summary\n";
$t3 = http('POST', '/it-tickets', ['subject' => 'Wi-Fi down in cold room 3', 'priority' => 'high', 'category' => 'network'], $ra)['json'];
$pdo->prepare("UPDATE it_tickets SET due_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = ?")->execute([$t3['id']]);
check('a ticket past its due time is flagged overdue', http('GET', "/it-tickets/{$t3['id']}", null, $it1)['json']['overdue'] === true && count(http('GET', '/it-tickets?overdue=1', null, $it1)['json']) >= 1);
$t4 = http('POST', '/it-tickets', ['subject' => 'Mouse broken'], $ra)['json'];
http('POST', "/it-tickets/{$t4['id']}/status", ['status' => 'In_Progress'], $itm);
http('POST', "/it-tickets/{$t4['id']}/status", ['status' => 'Resolved', 'resolution' => 'New mouse'], $itm);
$pdo->prepare("UPDATE it_tickets SET resolved_at = DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE id = ?")->execute([$t4['id']]);
http('GET', '/it-tickets/summary', null, $it1);
check('a resolved ticket nobody answered closes itself after the configured days', http('GET', "/it-tickets/{$t4['id']}", null, $it1)['json']['status'] === 'Closed');
$sm = http('GET', '/it-tickets/summary', null, $itm)['json'];
check('the summary counts open, overdue, assets and warranty alerts', isset($sm['open'], $sm['overdue'], $sm['assets'], $sm['warranty_expiring'], $sm['by_category']) && $sm['overdue'] >= 1 && $sm['warranty_expiring'] >= 1 && $sm['is_it'] === true, json_encode($sm));
$sm2 = http('GET', '/it-tickets/summary', null, $ra)['json'];
check('an employee\'s summary covers only his tickets and is marked not-IT', $sm2['is_it'] === false && ($sm2['by_status']['Closed'] ?? 0) <= 2);

echo "\n== another company\n";
$co = http('POST', '/companies', ['name_en' => "IT other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
$rowsOf = fn($r) => $r['json']['data'] ?? $r['json'];
check('company 2 sees none of company 1\'s tickets or assets', count($rowsOf(http('GET', '/it-tickets', null, $admin, $co))) === 0 && count($rowsOf(http('GET', '/it-assets', null, $admin, $co))) === 0);
check('company 2 cannot read, comment on or assign a ticket of company 1 (404)', http('GET', "/it-tickets/$tid", null, $admin, $co)['status'] === 404 && http('POST', "/it-tickets/$tid/comment", ['body' => 'x'], $admin, $co)['status'] === 404 && http('POST', "/it-tickets/$tid/assign", ['assignee_id' => $id1], $admin, $co)['status'] === 404);
check('company 2 cannot read or edit an asset of company 1 (404)', http('GET', "/it-assets/{$a1['json']['id']}", null, $admin, $co)['status'] === 404 && http('PUT', "/it-assets/{$a1['json']['id']}", ['status' => 'Retired'], $admin, $co)['status'] === 404);
check('company 2 cannot attach company 1\'s asset to its ticket (422)', http('POST', '/it-tickets', ['subject' => 's', 'asset_id' => (int)$a1['json']['id']], $admin, $co)['status'] === 422);

echo "\n== settings and module switch\n";
http('PUT', '/settings', ['it.sla_hours_medium' => 6], $admin);
$t5 = http('POST', '/it-tickets', ['subject' => 'Slow laptop'], $ra)['json']; $h5 = (strtotime($t5['due_at']) - $dbNow()) / 3600;
check('changing the medium SLA setting to 6 hours changes new tickets', $h5 > 5 && $h5 < 7, (string)$h5);
http('PUT', '/settings', ['it.sla_hours_medium' => 24], $admin);
check('an unknown ticket is 404', http('GET', '/it-tickets/99999999', null, $it1)['status'] === 404);
check('without a token the IT API is 401', http('GET', '/it-tickets')['status'] === 401);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
