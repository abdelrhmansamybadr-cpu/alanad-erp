<?php
// ─── Alanad ERP — request pipeline (Phase 0) ─────────────────
// Every API request passes here before any handler runs:
//   1. reject unsafe field names (handlers put body keys into SQL)
//   2. authenticate once, load role + permissions + company
//   3. module switch  (closed module = 403, data kept)
//   4. permission check (deny by default)
//   5. audit every write (append-only log)

// resource => module key (for the module switches)
const RESOURCE_MODULE = [
    'customers' => 'sales', 'client-orders' => 'sales', 'market-purchases' => 'purchase', 'vehicles' => 'purchase', 'gates' => 'purchase', 'entry-permits' => 'purchase', 'sorting-runs' => 'stock', 'pack-specs' => 'manufacturing', 'pack-orders' => 'manufacturing', 'shipments' => 'sales', 'sales-invoices' => 'sales', 'sales-prices' => 'sales', 'recalls' => 'stock', 'hr-leaves' => 'hr', 'hr-expiry' => 'hr', 'it-tickets' => 'it', 'it-assets' => 'it', 'quotations' => 'sales', 'sales-orders' => 'sales', 'invoices' => 'sales',
    'customer-groups' => 'sales', 'territories' => 'sales', 'sales-persons' => 'sales', 'price-lists' => 'sales',
    'suppliers' => 'purchase', 'purchase-orders' => 'purchase', 'purchase-requests' => 'purchase', 'supplier-prices' => 'purchase', 'supplier-groups' => 'purchase', 'payment-terms' => 'purchase',
    'items' => 'stock', 'item-groups' => 'stock', 'uom' => 'stock', 'brands' => 'stock',
    'warehouses' => 'stock', 'cold-rooms' => 'stock', 'stock' => 'stock', 'item-batches' => 'stock', 'unit-conversions' => 'stock',
    'alarms' => 'stock', 'quality-holds' => 'stock', 'deliveries' => 'quality',
    'ap-invoices' => 'finance', 'ap-payments' => 'finance', 'gl-accounts' => 'finance', 'gl-entries' => 'finance', 'finance-reports' => 'finance',
    'prep-specs' => 'manufacturing', 'prep-orders' => 'manufacturing', 'haccp-logs' => 'manufacturing', 'production-reports' => 'manufacturing',
    'leads' => 'crm', 'deals' => 'crm', 'contacts' => 'crm', 'organizations' => 'crm', 'addresses' => 'crm',
    'accounts' => 'finance', 'journal-entries' => 'finance', 'bank-accounts' => 'finance', 'currencies' => 'finance',
    'cost-centers' => 'finance', 'fiscal-years' => 'finance', 'tax-templates' => 'finance', 'payments' => 'finance',
    'payment-orders' => 'finance', 'terms-conditions' => 'finance',
    'employees' => 'hr', 'departments' => 'hr', 'designations' => 'hr', 'employment-types' => 'hr', 'salary-components' => 'hr',
    'salary-structures' => 'hr', 'salary-slips' => 'hr', 'leave-types' => 'hr', 'leave-requests' => 'hr', 'holiday-lists' => 'hr',
    'appraisals' => 'hr', 'promotions' => 'hr', 'transfers' => 'hr', 'separations' => 'hr', 'shift-types' => 'hr',
    'job-applicants' => 'hr', 'branches' => 'hr',
    'bom' => 'manufacturing', 'work-orders' => 'manufacturing', 'job-cards' => 'manufacturing',
    'assets' => 'assets', 'asset-movements' => 'assets',
    'projects' => 'projects', 'tasks' => 'projects', 'timesheets' => 'projects',
    'quality-procedures' => 'quality',
    'pos-profiles' => 'pos', 'pos-sessions' => 'pos', 'pos-invoices' => 'pos',
    'website-pages' => 'website', 'blog-posts' => 'website',
];

// Resources only an administrator (permission admin.manage) may touch at all.
const ADMIN_RESOURCES = ['users', 'roles', 'audit-logs', 'companies', 'modules', 'company-branches', 'branch-access'];

function pipeline_clean_keys(mixed $data, string $path = 'body'): void {
    if (!is_array($data)) return;
    foreach ($data as $k => $v) {
        if (is_string($k) && !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $k)) {
            error_response("Invalid field name in $path", 400);
        }
        pipeline_clean_keys($v, $path . '.' . $k);
    }
}

function permission_allows(array $perms, string $resource, string $action): bool {
    foreach ($perms as $p) {
        if ($p === '*' || $p === "$resource.$action" || $p === "*.$action" || $p === "$resource.*") return true;
    }
    return false;
}

require_once __DIR__ . '/branches.php';

function pipeline_load_user(array $payload): array {
    $st = db()->prepare('SELECT u.id, u.name, u.email, u.role, u.role_id, u.company_id, u.lang, u.is_active, u.must_change_password
                         FROM users u WHERE u.id = ? LIMIT 1');
    $st->execute([$payload['user_id'] ?? 0]);
    $u = $st->fetch();
    if (!$u || !(int)$u['is_active']) error_response('Account disabled or not found', 401);

    $perms = [];
    $roleKey = $u['role'];
    if ($u['role_id']) {
        $p = db()->prepare('SELECT permission FROM role_permissions WHERE role_id = ?');
        $p->execute([$u['role_id']]);
        $perms = $p->fetchAll(PDO::FETCH_COLUMN);
        $rk = db()->prepare('SELECT role_key FROM roles WHERE id = ?');
        $rk->execute([$u['role_id']]);
        $roleKey = $rk->fetchColumn() ?: $roleKey;
    }
    // company context: header X-Company-Id, must be one the user may enter
    $allowed = [(int)$u['company_id']];
    $uc = db()->prepare('SELECT company_id FROM user_companies WHERE user_id = ?');
    $uc->execute([$u['id']]);
    foreach ($uc->fetchAll(PDO::FETCH_COLUMN) as $cid) $allowed[] = (int)$cid;
    $allowed = array_values(array_unique($allowed));
    $want = isset($_SERVER['HTTP_X_COMPANY_ID']) ? (int)$_SERVER['HTTP_X_COMPANY_ID'] : (int)$u['company_id'];
    if (!in_array($want, $allowed, true)) error_response('You cannot access this company', 403);

    $bc = branch_context($want, (int)$u['id'], (string)$roleKey, $perms);
    return [
        'user_id' => (int)$u['id'], 'email' => $u['email'], 'name' => $u['name'],
        'branch_access' => $bc['access'], 'branch_scope' => $bc['scope'], 'branches_all' => $bc['all'],
        'role' => $roleKey, 'permissions' => $perms, 'company_id' => $want,
        'companies' => $allowed, 'lang' => $u['lang'], 'must_change_password' => (int)$u['must_change_password'],
    ];
}

function module_enabled(int $companyId, string $module): bool {
    $st = db()->prepare('SELECT is_enabled FROM module_switches WHERE company_id = ? AND module_key = ?');
    $st->execute([$companyId, $module]);
    $v = $st->fetchColumn();
    return $v === false ? true : (bool)$v;   // no row = enabled
}

function audit(string $action, string $resource, ?string $resourceId, ?int $status, array $details = []): void {
    try {
        $a = $GLOBALS['auth_user'] ?? [];
        $redact = fn($v) => $v;
        $details = json_decode(preg_replace('/("[^"]*pass[^"]*"\s*:\s*)"[^"]*"/i', '$1"***"', json_encode($details, JSON_UNESCAPED_UNICODE)), true) ?? [];
        db()->prepare('INSERT INTO audit_log (company_id, user_id, user_email, action, resource, resource_id, http_status, details, ip, user_agent)
                       VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $a['company_id'] ?? null, $a['user_id'] ?? null, $a['email'] ?? ($details['email'] ?? null),
                $action, $resource, $resourceId, $status,
                $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
            ]);
    } catch (Throwable $e) { /* auditing must never break the request */ }
}

function pipeline_run(string $resource, string $method, ?string $id, array $parts): void {
    // 1. unsafe field names
    if ($resource !== 'settings') pipeline_clean_keys($GLOBALS['body'] ?? []);

    $isLogin = $resource === 'auth' && $method === 'POST' && in_array($parts[1] ?? '', ['login', 'logout'], true);
    if ($isLogin) {
        register_shutdown_function(function () use ($method) {
            $b = $GLOBALS['body'] ?? [];
            // attach the login to the user's company so company admins can see it in their audit log
            try {
                $st = db()->prepare('SELECT id, email, company_id FROM users WHERE email = ? LIMIT 1');
                $st->execute([$b['email'] ?? '']);
                if ($u = $st->fetch()) $GLOBALS['auth_user'] = ['user_id' => (int)$u['id'], 'email' => $u['email'], 'company_id' => (int)$u['company_id']];
            } catch (Throwable $e) { /* ignore */ }
            audit('login', 'auth', null, http_response_code(), ['email' => $b['email'] ?? null]);
        });
        return;                                   // login is public; it throttles itself
    }

    // 2. authenticate once
    $payload = auth_required();                   // 401 if missing/invalid
    $GLOBALS['auth_user'] = pipeline_load_user($payload);
    $user = $GLOBALS['auth_user'];
    $GLOBALS['company_id'] = $user['company_id'];
    $GLOBALS['branch_scope'] = $user['branch_scope'];
    $GLOBALS['branch_access'] = $user['branch_access'];

    // 3. module switch
    if (isset(RESOURCE_MODULE[$resource]) && !module_enabled($user['company_id'], RESOURCE_MODULE[$resource])) {
        error_response('This module is closed for your company', 403);
    }

    // 4. permission check (deny by default)
    $action = match ($method) { 'GET' => 'view', 'POST' => 'create', 'PUT', 'PATCH' => 'edit', 'DELETE' => 'delete', default => 'view' };
    if ($resource === 'market-purchases' && $method === 'POST' && ($parts[2] ?? '') === 'inspect') $action = 'inspect';   // the quality person needs only the inspect right
    if ($resource === 'entry-permits' && $method === 'POST' && in_array($parts[2] ?? '', ['store-approve', 'store-reject'], true)) $action = 'store';   // the store keeper
    if ($resource === 'entry-permits' && $method === 'POST' && in_array($parts[2] ?? '', ['issue', 'enter'], true)) $action = 'gate';             // the gate officer
    if ($resource === 'deliveries' && $method === 'POST' && ($parts[2] ?? '') === 'count') $action = 'count';                                     // the counter
    if ($resource === 'deliveries' && $method === 'POST' && in_array($parts[2] ?? '', ['finance-check', 'route'], true)) $action = 'finance';    // the Finance person at the gate
    if ($resource === 'sorting-runs' && $method === 'POST' && in_array($parts[2] ?? '', ['sign-off', 'incident'], true)) $action = 'signoff';   // the quality person
    if ($resource === 'pack-orders' && $method === 'POST' && ($parts[2] ?? '') === 'decide') $action = 'decide';   // the head of preparation chooses the box size
    if ($resource === 'shipments' && $method === 'POST') {                                                     // each step of the dispatch has its own right
        $sub = $parts[2] ?? '';
        if ($sub === 'load') $action = 'load'; elseif ($sub === 'invoice') $action = 'invoice'; elseif (in_array($sub, ['exit-permit', 'dispatch'], true)) $action = 'gate';
    }
    if ($resource === 'it-tickets' && $method === 'POST') { $sub = $parts[2] ?? ''; if ($sub === 'assign') $action = 'manage'; elseif ($sub === 'status') $action = 'work'; }   // everything else: any signed-in person (the handler shows people only their own tickets)
    if ($resource === 'purchase-requests' && $action === 'delete') $action = 'edit';   // a requester may remove their own unsubmitted draft (the handler checks ownership and status)
    if (in_array($resource, ADMIN_RESOURCES, true) || ($resource === 'settings' && $action !== 'view')
        || ($resource === 'workflows' && $action !== 'view')) {
        if (!permission_allows($user['permissions'], 'admin', 'manage')) error_response('Forbidden', 403);
    } elseif (!in_array($resource, ['me', 'my', 'review-notes', 'auth', 'dashboard', 'workflow-instances', 'notifications'], true)
        && !($resource === 'it-tickets' && !in_array($action, ['manage', 'work'], true))
        && !permission_allows($user['permissions'], $resource, $action)
        && !($action === 'view' && permission_allows($user['permissions'], $resource, 'lookup'))) {   // 'lookup' = may READ the list for own screens, but has no tab for it
        error_response('Forbidden: you do not have permission to ' . $action . ' ' . $resource, 403);
    }

    // 5. audit writes
    if ($method !== 'GET') {
        register_shutdown_function(function () use ($method, $resource, $id) {
            $b = $GLOBALS['body'] ?? [];
            $keys = array_slice(array_keys($b), 0, 30);
            audit($method, $resource, $id, http_response_code(), ['fields' => $keys, 'body' => $b]);
        });
    }
}
