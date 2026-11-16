<?php
// Phase 0 API tests. Run against a THROW-AWAY database:
//   ALANAD_CONFIG=/path/test_config.php php -S 127.0.0.1:8200 hostinger/api/router.php   (in one shell)
//   php hostinger/api/tests/phase0_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
// Seed login used: admin@manzuma.com / password (baseline schema), which this suite replaces by new users.

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8200/api', '/');
$dsn  = $argv[2] ?? 'mysql:host=127.0.0.1;port=3410;dbname=alanad_erp_dev;charset=utf8mb4';
$dbu  = $argv[3] ?? 'root';
$dbp  = $argv[4] ?? '';
$pdo  = new PDO($dsn, $dbu, $dbp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pass = 0; $fail = 0; $fails = [];
function http(string $method, string $path, ?array $body = null, ?string $token = null, array $headers = []): array {
    global $base;
    $ch = curl_init($base . $path);
    $h = ['Content-Type: application/json'];
    if ($token) $h[] = "Authorization: Bearer $token";
    foreach ($headers as $k => $v) $h[] = "$k: $v";
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode(substr($raw, $hs), true), 'headers' => substr($raw, 0, $hs)];
}
function check(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($cond) { $pass++; echo "  ok   $name\n"; }
    else { $fail++; $fails[] = $name; echo "  FAIL $name $detail\n"; }
}
function login(string $email, string $pw): ?string {
    $r = http('POST', '/auth/login', ['email' => $email, 'password' => $pw]);
    return $r['json']['token'] ?? null;
}

echo "\n== authentication & hardening\n";
$r = http('GET', '/customers');
check('no token -> 401', $r['status'] === 401);
$r = http('POST', '/auth/login', ['email' => 'nobody@x.com', 'password' => 'wrong']);
check('wrong password -> 401', $r['status'] === 401);
for ($i = 0; $i < 5; $i++) http('POST', '/auth/login', ['email' => 'lockme@x.com', 'password' => 'bad' . $i]);
$r = http('POST', '/auth/login', ['email' => 'lockme@x.com', 'password' => 'bad9']);
check('6th failed login -> 429 (throttle)', $r['status'] === 429, "got {$r['status']}");

$admin = login('admin@manzuma.com', 'password');
// Phase 14: the real "user" role now means self-service only. These older suites need a general worker who can create and edit, so they use a test-only role.
$pdo->exec("INSERT IGNORE INTO roles (company_id, role_key, name_en, name_ar, is_system) VALUES (NULL, 'test_worker', 'Test worker', 'عامل اختبار', 0)");
$wk = (int)$pdo->query("SELECT id FROM roles WHERE role_key = 'test_worker' AND company_id IS NULL")->fetchColumn();
foreach (['*.view', '*.create', '*.edit'] as $pm) $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission) VALUES ($wk, '$pm')");
$pdo->exec("UPDATE workflow_steps SET role_key = 'test_worker' WHERE role_key = 'user'");   // the seeded sample flow named the plain user role
check('admin can log in', (bool)$admin);
$me = http('GET', '/me', null, $admin);
check('/me returns permissions and modules', $me['status'] === 200 && in_array('*', $me['json']['permissions'] ?? []) && !empty($me['json']['modules']['sales']));

echo "\n== SQL-injection through field names\n";
$r = http('POST', '/customers', ['name' => 'x', 'name`) VALUES (1); DROP TABLE users;-- ' => 1], $admin);
check('bad field name rejected (400)', $r['status'] === 400, "got {$r['status']}");
$r = http('POST', '/customers', ['name' => 'x', 'nested' => ['a b' => 1]], $admin);
check('bad nested field name rejected (400)', $r['status'] === 400);
check('users table still exists', (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn() === 1);

echo "\n== users API (admin only, no hashes, strong passwords)\n";
$r = http('POST', '/users', ['name' => 'W', 'email' => 'w@x.com', 'password' => 'short', 'role_id' => 4], $admin);
check('weak password rejected (422)', $r['status'] === 422);
$mk = function (string $email, int $roleId) use ($admin) {
    $r = http('POST', '/users', ['name' => $email, 'email' => $email, 'password' => 'Strong-Pass-123', 'role_id' => $roleId], $admin);
    return $r;
};
$rv = $mk('viewer@x.com', 4);   check('create viewer 201', $rv['status'] === 201, json_encode($rv['json']));
check('user response has no password_hash', !array_key_exists('password_hash', $rv['json'] ?? []));
$ru = $mk('userA@x.com', $wk);    $ub = $mk('userB@x.com', $wk);   $rm = $mk('manager@x.com', 2);
$rm2 = $mk('manager2@x.com', 2);
$vt = login('viewer@x.com', 'Strong-Pass-123');
$ua = login('userA@x.com', 'Strong-Pass-123');
$ub_t = login('userB@x.com', 'Strong-Pass-123');
$mt = login('manager@x.com', 'Strong-Pass-123');
$mt2 = login('manager2@x.com', 'Strong-Pass-123');
check('new users can log in', $vt && $ua && $ub_t && $mt && $mt2);
$list = http('GET', '/users', null, $admin);
check('user list has no hashes', $list['status'] === 200 && strpos(json_encode($list['json']), 'password_hash') === false);

echo "\n== permissions (deny by default)\n";
check('viewer may read customers', http('GET', '/customers', null, $vt)['status'] === 200);
check('viewer cannot create customers (403)', http('POST', '/customers', ['name' => 'v'], $vt)['status'] === 403);
check('viewer cannot read users (403)', http('GET', '/users', null, $vt)['status'] === 403);
check('viewer cannot promote himself (403)', http('PUT', '/users/' . $rv['json']['id'], ['role_id' => 1], $vt)['status'] === 403);
check('viewer cannot read audit log (403)', http('GET', '/audit-logs', null, $vt)['status'] === 403);
$c = http('POST', '/customers', ['name' => 'Customer One', 'code' => 'C-ONE'], $ua);
check('standard user can create customers', $c['status'] === 201, json_encode($c['json']));
check('standard user cannot delete (403)', http('DELETE', '/customers/' . ($c['json']['id'] ?? 0), null, $ua)['status'] === 403);
check('standard user cannot read roles (403)', http('GET', '/roles', null, $ua)['status'] === 403);
check('manager cannot manage roles (403)', http('GET', '/roles', null, $mt)['status'] === 403);

echo "\n== change password\n";
check('change-password needs a session (401)', http('POST', '/auth/change-password', ['current_password' => 'x', 'new_password' => 'Another-Pass-456'])['status'] === 401);
check('wrong current password -> 403', http('POST', '/auth/change-password', ['current_password' => 'nope', 'new_password' => 'Another-Pass-456'], $ua)['status'] === 403);
check('weak new password -> 422', http('POST', '/auth/change-password', ['current_password' => 'Strong-Pass-123', 'new_password' => 'short1'], $ua)['status'] === 422);
check('user created by admin must change password', (int)$pdo->query("SELECT must_change_password FROM users WHERE email = 'userA@x.com'")->fetchColumn() === 1);
check('user changes own password', http('POST', '/auth/change-password', ['current_password' => 'Strong-Pass-123', 'new_password' => 'Another-Pass-456'], $ua)['status'] === 200);
check('flag cleared and new password works', (int)$pdo->query("SELECT must_change_password FROM users WHERE email = 'userA@x.com'")->fetchColumn() === 0 && (bool)login('userA@x.com', 'Another-Pass-456'));
$ua = login('userA@x.com', 'Another-Pass-456');
check('old password no longer works', login('userA@x.com', 'Strong-Pass-123') === null);

echo "\n== custom roles\n";
check('invalid permission rejected (422)', http('POST', '/roles', ['role_key' => 'qc', 'name_en' => 'QC', 'permissions' => ['customers;drop']], $admin)['status'] === 422);
$role = http('POST', '/roles', ['role_key' => 'qc_officer', 'name_en' => 'QC Officer', 'name_ar' => 'مسؤول جودة', 'permissions' => ['customers.view']], $admin);
check('create custom role 201', $role['status'] === 201);
$qc = http('POST', '/users', ['name' => 'qc', 'email' => 'qc@x.com', 'password' => 'Strong-Pass-123', 'role_id' => $role['json']['id']], $admin);
$qt = login('qc@x.com', 'Strong-Pass-123');
check('custom role can view customers', http('GET', '/customers', null, $qt)['status'] === 200);
check('custom role cannot create customers (403)', http('POST', '/customers', ['name' => 'q'], $qt)['status'] === 403);
check('custom role cannot view invoices (403)', http('GET', '/invoices', null, $qt)['status'] === 200 ? false : true);
check('system role cannot be deleted (403)', http('DELETE', '/roles/2', null, $admin)['status'] === 403);

echo "\n== module switch\n";
check('close sales module', http('PUT', '/modules/sales', ['is_enabled' => false], $admin)['status'] === 200);
check('closed module -> 403 even for admin', http('GET', '/customers', null, $admin)['status'] === 403);
check('other modules unaffected', http('GET', '/items', null, $admin)['status'] === 200);
check('/me shows module closed', ($me2 = http('GET', '/me', null, $admin))['json']['modules']['sales'] === false);
check('re-open sales module', http('PUT', '/modules/sales', ['is_enabled' => true], $admin)['status'] === 200);
check('data kept after re-open', http('GET', '/customers', null, $admin)['status'] === 200 && count(http('GET', '/customers', null, $admin)['json']) >= 1);

echo "\n== companies\n";
check('unknown company header -> 403', http('GET', '/customers', null, $admin, ['X-Company-Id' => '999'])['status'] === 403);
$co = http('POST', '/companies', ['name_en' => 'Second Factory', 'name_ar' => 'المصنع الثاني', 'vat_number' => '300000000000003'], $admin);
check('admin creates a company', $co['status'] === 201);
$cid2 = $co['json']['id'] ?? 0;
check('new company cloned settings', (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE company_id = $cid2")->fetchColumn() >= 10);
check('standard user cannot enter company 2 (403)', http('GET', '/customers', null, $ua, ['X-Company-Id' => (string)$cid2])['status'] === 403);
check('companies cannot be deleted (405)', http('DELETE', "/companies/$cid2", null, $admin)['status'] === 405);

echo "\n== settings\n";
$s = http('GET', '/settings', null, $vt);
check('viewer can read settings', $s['status'] === 200 && count($s['json']) >= 10);
check('viewer cannot change settings (403)', http('PUT', '/settings', ['finance.vat_rate_pct' => 99], $vt)['status'] === 403);
$u = http('PUT', '/settings', ['finance.vat_rate_pct' => 16], $admin);
$v = array_values(array_filter($u['json'] ?? [], fn($x) => $x['setting_key'] === 'finance.vat_rate_pct'))[0]['value'] ?? null;
check('admin changes a setting', $u['status'] === 200 && $v == 16.0);
check('unknown setting rejected (422)', http('PUT', '/settings', ['no.such' => 1], $admin)['status'] === 422);
check('number setting rejects text (422)', http('PUT', '/settings', ['finance.vat_rate_pct' => 'abc'], $admin)['status'] === 422);
http('PUT', '/settings', ['finance.vat_rate_pct' => 15], $admin);

echo "\n== audit log\n";
$a = http('GET', '/audit-logs?resource=customers', null, $admin);
check('customer creation is in the audit log', $a['status'] === 200 && count($a['json']['data'] ?? []) >= 1);
$pw = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE details LIKE '%Strong-Pass-123%'")->fetchColumn();
check('passwords are never written to the audit log', (int)$pw === 0, "found $pw");
$li = http('GET', '/audit-logs?action=login', null, $admin);
check('admin sees login events in the company audit log', $li['status'] === 200 && ($li['json']['total'] ?? 0) >= 1);
check('login events are audited', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'login'")->fetchColumn() >= 3);
try { $pdo->exec("UPDATE audit_log SET action = 'x' WHERE id = 1"); $upd = true; } catch (Throwable $e) { $upd = false; }
try { $pdo->exec("DELETE FROM audit_log WHERE id = 1"); $del = true; } catch (Throwable $e) { $del = false; }
check('audit log refuses UPDATE and DELETE (database triggers)', !$upd && !$del);
check('audit log is read-only through the API (405)', http('DELETE', '/audit-logs/1', null, $admin)['status'] === 405);

echo "\n== workflow engine (goods-in template: Quality -> Finance -> Store)\n";
$docId = 4242;
$wf = http('POST', '/workflow-instances', ['wf_key' => 'goods_in_standard', 'doc_type' => 'goods_receipt', 'doc_id' => $docId], $ua);
check('user A starts the workflow', $wf['status'] === 201, json_encode($wf['json']));
$iid = $wf['json']['id'] ?? 0;
$act = fn(string $tok, string $action, ?string $reason = null, ?string $note = null) =>
    http('POST', "/workflow-instances/$iid/act", array_filter(['action' => $action, 'reason_code' => $reason, 'note' => $note], fn($x) => $x !== null), $tok);
check('creator cannot approve own document (403)', $act($ua, 'approve')['status'] === 403);
$r = $act($ub_t, 'approve');
check('user B approves step 1 -> moves to Finance', $r['status'] === 200 && $r['json']['current_position'] == 2, json_encode($r['json']));
check('standard user cannot act on Finance step (403)', $act($ub_t, 'approve')['status'] === 403);
check('reject without reason -> 422', $act($mt, 'reject')['status'] === 422);
check('reject "other" without note -> 422', $act($mt, 'reject', 'other')['status'] === 422);
check('reject with unknown reason -> 422', $act($mt, 'reject', 'made_up')['status'] === 422);
$r = $act($mt, 'approve');
check('manager approves Finance -> Store', $r['status'] === 200 && $r['json']['current_position'] == 3);
for ($n = 1; $n <= 3; $n++) {
    $r = $act($ub_t, 'reject', 'quality_issue', 'box ' . $n);
    check("store rejects #$n -> back to Finance", $r['status'] === 200 && $r['json']['current_position'] == 2, json_encode($r['json']));
    if ($n < 3) $act($mt, 'approve');
}
check('after 3 rejections a supervisor must decide', ($r['json']['needs_supervisor'] ?? 0) == 1);
check('normal approve is blocked (409)', $act($mt, 'approve')['status'] === 409);
check('supervisor decision needs a note (422)', $act($mt, 'decide_approve')['status'] === 422);
$r = $act($mt2, 'decide_approve', null, 'checked on site');
check('supervisor decides -> approved', $r['status'] === 200 && $r['json']['status'] === 'approved', json_encode($r['json']));
$acts = (int)$pdo->query("SELECT COUNT(*) FROM workflow_actions WHERE instance_id = $iid")->fetchColumn();
check('every action stored (submit + 2 approve + 3 reject + 2 approve + decide)', $acts >= 9, "actions=$acts");
check('closed document cannot be acted on (409)', $act($mt, 'approve')['status'] === 409);
$bad = http('POST', '/workflows', ['wf_key' => 'x y', 'name_en' => 'X', 'doc_type' => 'x', 'steps' => [['name_en' => 'A']]], $admin);
check('bad workflow key rejected (422)', $bad['status'] === 422);
$new = http('POST', '/workflows', ['wf_key' => 'leave_request', 'name_en' => 'Leave request', 'doc_type' => 'leave',
    'steps' => [['name_en' => 'Team head', 'role_key' => 'manager'], ['name_en' => 'HR', 'role_key' => 'manager']]], $admin);
check('admin defines a new workflow template', $new['status'] === 201 && count($new['json']['steps'] ?? []) === 2);
check('standard user cannot define workflows (403)', http('POST', '/workflows', ['wf_key' => 'zzz', 'name_en' => 'Z', 'doc_type' => 'z', 'steps' => [['name_en' => 'A']]], $ua)['status'] === 403);

echo "\n== notifications\n";
check('only admins create notifications (403)', http('POST', '/notifications', ['title' => 'x', 'body' => 'y'], $ua)['status'] === 403);
check('admin broadcasts a notification', http('POST', '/notifications', ['title' => 'Hello', 'body' => 'World', 'type' => 'system'], $admin)['status'] === 201);
$n = http('GET', '/notifications?unread=1', null, $ub_t);
check('user sees broadcast with unread count', $n['status'] === 200 && ($n['json']['unread_count'] ?? 0) >= 1 && $n['json']['data'][0]['is_read'] === false);
check('mark all read', http('PUT', '/notifications', [], $ub_t)['status'] === 200);

echo "\n== CORS\n";
$r = http('GET', '/customers', null, null, ['Origin' => 'https://evil.example']);
check('foreign origin gets no CORS header', stripos($r['headers'], 'Access-Control-Allow-Origin: https://evil.example') === false && stripos($r['headers'], 'Access-Control-Allow-Origin: *') === false);
$r = http('GET', '/customers', null, null, ['Origin' => 'http://localhost:3000']);
check('allowed origin gets CORS header', stripos($r['headers'], 'Access-Control-Allow-Origin: http://localhost:3000') !== false);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
