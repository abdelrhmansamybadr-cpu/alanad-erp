"""One-time seed for the LIVE site, run through a temporary PHP file (same pattern as the migrations): creates the demo accounts from demo_accounts.json
(skips any e-mail that already exists) and switches on the owner's approved process (entry permit + three-person receiving) for the main company.
   FTP_PW='...' python seed_live.py"""
import io, json, secrets, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
import deploy

HERE = Path(__file__).resolve().parent
accounts = json.loads((HERE / "demo_accounts.json").read_text(encoding="utf-8"))
sys.path.insert(0, str(HERE))
import importlib.util
spec = importlib.util.spec_from_file_location("seed_demo_accounts_cfg", HERE / "seed_demo_accounts.py")
src = (HERE / "seed_demo_accounts.py").read_text(encoding="utf-8")
# take only the ACCOUNTS list (employee info) without running the script
start = src.index("ACCOUNTS = ["); end = src.index("\n]\n", start) + 3
ns = {}
exec(src[start:end], ns)
emp = {a[0]: a[5] for a in ns["ACCOUNTS"]}
payload = [{"role": a["role"], "email": a["email"], "name": a["name_ar"], "password": a["password"], "emp": emp.get(a["role"])} for a in accounts]

PHP = r"""<?php
if (($_GET['k'] ?? '') !== '__K__') { http_response_code(404); exit; }
header('Content-Type: text/plain; charset=utf-8');
require __DIR__ . '/api/config.php';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$accounts = json_decode('__JSON__', true);
$cid = (int)($pdo->query("SELECT id FROM companies WHERE name_en LIKE 'Al Anad%' ORDER BY id LIMIT 1")->fetchColumn() ?: $pdo->query("SELECT id FROM companies ORDER BY id LIMIT 1")->fetchColumn());
$branch = $pdo->prepare('SELECT id FROM company_branches WHERE company_id = ? AND is_main = 1 LIMIT 1'); $branch->execute([$cid]); $branch = $branch->fetchColumn() ?: null;
$roles = []; foreach ($pdo->query('SELECT id, role_key FROM roles WHERE company_id IS NULL')->fetchAll() as $r) $roles[$r['role_key']] = (int)$r['id'];
echo "company $cid branch " . var_export($branch, true) . "\n";
$made = 0; $skipped = 0;
foreach ($accounts as $a) {
    $ex = $pdo->prepare('SELECT id FROM users WHERE email = ?'); $ex->execute([$a['email']]);
    if ($ex->fetchColumn()) { $skipped++; echo "exists  {$a['email']}\n"; continue; }
    if (!isset($roles[$a['role']])) { echo "NO ROLE {$a['role']}\n"; continue; }
    $legacy = in_array($a['role'], ['admin', 'user', 'viewer'], true) ? $a['role'] : 'user';
    $pdo->prepare('INSERT INTO users (name, email, password_hash, role, role_id, company_id, lang, must_change_password) VALUES (?,?,?,?,?,?,?,0)')
        ->execute([$a['name'], $a['email'], password_hash($a['password'], PASSWORD_BCRYPT), $legacy, $roles[$a['role']], $cid, 'ar']);
    $uid = (int)$pdo->lastInsertId();
    if ($a['emp']) {
        $code = 'DEMO-' . strtoupper(substr($a['role'], 0, 6));
        $pdo->prepare("INSERT INTO employees (company_id, employee_id, first_name, last_name, department, designation, date_of_joining, status, user_id, branch_id) VALUES (?,?,?,?,?,?, '2024-01-01', 'Active', ?, ?)")
            ->execute([$cid, $code, $a['emp'][0], $a['emp'][1], $a['emp'][2], $a['emp'][3], $uid, $branch]);
    }
    $made++; echo "created {$a['email']}\n";
}
$u = $pdo->prepare("UPDATE settings SET setting_value = '1' WHERE company_id = ? AND setting_key IN ('fleet.require_entry_permit','receiving.three_person_rule')");
$u->execute([$cid]);
echo "settings switched on: " . $u->rowCount() . "\n";
echo "accounts created $made, already there $skipped\n";
@unlink(__FILE__);
"""
key = secrets.token_hex(10)
php = PHP.replace("__K__", key).replace("__JSON__", json.dumps(payload, ensure_ascii=False).replace("\\", "\\\\").replace("'", "\\'"))
f = deploy.ftp()
f.storbinary(f"STOR {deploy.ROOT}/_seed_{key}.php", io.BytesIO(php.encode("utf-8")))
try:
    print(deploy.call(f"{deploy.SITE}/_seed_{key}.php?k={key}").strip())
finally:
    try: f.delete(f"{deploy.ROOT}/_seed_{key}.php")
    except Exception: pass
    print("leftovers:", [n for n in f.nlst(deploy.ROOT) if key in n])
