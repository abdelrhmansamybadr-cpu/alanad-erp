<?php
// ─── HR core: expiring documents, leave balances and leave requests (approval by the designer-controlled "leave_request" flow) ───
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/crud.php';

function hr_text(mixed $v, int $max = 500): ?string {
    $v = trim((string)($v ?? ''));
    return $v === '' ? null : mb_substr($v, 0, $max);
}
function hr_date(mixed $v, string $name): string {
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) error_response("$name must be a date (YYYY-MM-DD)", 422);
    return $v;
}

/** a company created after the migration gets the two-step leave flow (team head, then HR) the first time it is needed */
function hr_ensure_leave_flow(int $cid): void {
    $q = db()->prepare("SELECT id FROM workflows WHERE company_id = ? AND wf_key = 'leave_request'"); $q->execute([$cid]);
    if ($q->fetchColumn()) return;
    db()->prepare("INSERT INTO workflows (company_id, wf_key, name_en, name_ar, doc_type) VALUES (?, 'leave_request', 'Leave request', 'طلب إجازة', 'leave')")->execute([$cid]);
    $id = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO workflow_steps (workflow_id, position, name_en, name_ar, role_key, rule) VALUES (?,1,'Team head','رئيس الفريق','manager','maker_not_checker'), (?,2,'HR','الموارد البشرية','hr_officer','maker_not_checker')")->execute([$id, $id]);
}

function hr_employee(int $cid, int $id): array {
    $q = db()->prepare('SELECT * FROM employees WHERE id = ? AND company_id = ?'); $q->execute([$id, $cid]);
    $e = $q->fetch();
    if (!$e) error_response('Employee not found', 404);
    return $e;
}

/** working days between two dates, inclusive, without the weekend days of the company (default Friday and Saturday) */
function hr_days(int $cid, string $from, string $to): int {
    $weekend = array_map('intval', array_filter(array_map('trim', explode(',', wf_setting($cid, 'hr.weekend_days', '5,6'))), 'strlen'));
    $n = 0;
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) if (!in_array((int)date('w', $t), $weekend, true)) $n++;
    return $n;
}

function hr_entitlement(int $cid, array $emp, array $type): int {
    $days = (int)$type['max_days_allowed'];
    if (($type['kind'] ?? null) === 'annual' && $emp['date_of_joining']) {
        $years = (strtotime(date('Y-m-d')) - strtotime($emp['date_of_joining'])) / (365.25 * 86400);
        if ($years >= (int)wf_setting($cid, 'hr.annual_after_years', '5')) $days = max($days, (int)wf_setting($cid, 'hr.annual_days_after_years', '30'));
    }
    return $days;
}

/** per leave type: the yearly entitlement, what was taken (approved), what waits for approval, and what is left */
function hr_balances(int $cid, int $employeeId, ?int $year = null): array {
    $emp = hr_employee($cid, $employeeId); $year = $year ?? (int)date('Y');
    $types = db()->prepare('SELECT * FROM leave_types WHERE company_id = ? ORDER BY id'); $types->execute([$cid]);
    $used = db()->prepare("SELECT leave_type_id, status, SUM(days) AS d FROM leave_requests WHERE company_id = ? AND employee_id = ? AND YEAR(from_date) = ? AND status IN ('Pending','Approved') GROUP BY leave_type_id, status");
    $used->execute([$cid, $employeeId, $year]);
    $u = []; foreach ($used->fetchAll() as $r) $u[(int)$r['leave_type_id']][$r['status']] = (int)$r['d'];
    $out = [];
    foreach ($types->fetchAll() as $t) {
        $ent = hr_entitlement($cid, $emp, $t); $taken = $u[(int)$t['id']]['Approved'] ?? 0; $pending = $u[(int)$t['id']]['Pending'] ?? 0;
        $out[] = ['leave_type_id' => (int)$t['id'], 'name' => $t['name'], 'kind' => $t['kind'], 'is_paid' => (int)$t['is_paid'], 'entitlement' => $ent, 'taken' => $taken, 'pending' => $pending, 'remaining' => max(0, $ent - $taken - $pending), 'unlimited' => $t['kind'] === 'unpaid'];
    }
    return $out;
}

function hr_leave_row(int $cid, int $id): array {
    $q = db()->prepare('SELECT l.*, t.name AS type_name, t.kind AS type_kind FROM leave_requests l LEFT JOIN leave_types t ON t.id = l.leave_type_id WHERE l.id = ? AND l.company_id = ?'); $q->execute([$id, $cid]);
    $r = $q->fetch();
    if (!$r) error_response('Not found', 404);
    return $r;
}
function hr_leave_detail(int $cid, int $id): array {
    $r = hr_leave_row($cid, $id);
    if ($r['workflow_instance_id']) { $r['flow'] = wf_get((int)$r['workflow_instance_id'], $cid); }
    return $r;
}

function hr_leave_create(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $emp = hr_employee($cid, (int)($b['employee_id'] ?? 0));
    if ($emp['status'] !== 'Active') error_response('This employee is ' . $emp['status'], 409);
    if (!permission_allows($u['permissions'], 'hr-leaves', 'manage') && (int)($emp['user_id'] ?? 0) !== (int)$u['user_id']) error_response('You can request leave only for yourself (your account must be linked to your employee record)', 403);
    $t = db()->prepare('SELECT * FROM leave_types WHERE id = ? AND company_id = ?'); $t->execute([(int)($b['leave_type_id'] ?? 0), $cid]);
    $type = $t->fetch();
    if (!$type) error_response('Leave type not found', 422);
    $from = hr_date($b['from_date'] ?? null, 'from_date'); $to = hr_date($b['to_date'] ?? null, 'to_date');
    if ($to < $from) error_response('The end date is before the start date', 422);
    if ((strtotime($to) - strtotime($from)) / 86400 > 366) error_response('A leave cannot be longer than a year', 422);
    $days = hr_days($cid, $from, $to);
    if ($days <= 0) error_response('These dates are all weekend days: nothing to request', 422);
    $ov = db()->prepare("SELECT number FROM leave_requests WHERE company_id = ? AND employee_id = ? AND status IN ('Pending','Approved') AND from_date <= ? AND to_date >= ? LIMIT 1"); $ov->execute([$cid, $emp['id'], $to, $from]);
    if ($n = $ov->fetchColumn()) error_response("These dates overlap the leave request $n", 409);
    if ($type['kind'] !== 'unpaid') {
        foreach (hr_balances($cid, (int)$emp['id'], (int)substr($from, 0, 4)) as $bal) if ((int)$bal['leave_type_id'] === (int)$type['id'] && $days > $bal['remaining']) error_response("Not enough balance: $days day(s) requested, " . $bal['remaining'] . ' left of ' . $bal['entitlement'] . ' this year', 409);
    }
    hr_ensure_leave_flow($cid);
    db()->beginTransaction();
    try {
        $number = crud_next_number('LR', 'leave_requests', $cid);
        db()->prepare("INSERT INTO leave_requests (company_id, number, employee_id, employee_name, leave_type, leave_type_id, from_date, to_date, days, reason, status, requested_by) VALUES (?,?,?,?,?,?,?,?,?,?, 'Pending', ?)")
            ->execute([$cid, $number, $emp['id'], trim($emp['first_name'] . ' ' . $emp['last_name']), $type['name'], $type['id'], $from, $to, $days, hr_text($b['reason'] ?? null, 1000), $u['user_id']]);
        $id = (int)db()->lastInsertId();
        $inst = wf_start($cid, 'leave_request', 'leave', $id, (int)$u['user_id']);
        db()->prepare('UPDATE leave_requests SET workflow_instance_id = ? WHERE id = ?')->execute([$inst['id'], $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return hr_leave_detail($cid, $id);
}

/** approve / reject at the current step of the designed flow; the last approval makes the leave Approved */
function hr_leave_decide(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $r = hr_leave_row($cid, $id);
    if ($r['status'] !== 'Pending' || !$r['workflow_instance_id']) error_response('This leave is not waiting for a decision (it is ' . $r['status'] . ')', 409);
    $action = $b['action'] ?? '';
    if (!in_array($action, ['approve', 'reject'], true)) error_response('action must be approve or reject', 422);
    $inst = wf_act((int)$r['workflow_instance_id'], $u, $action, $b['reason_code'] ?? null, $b['note'] ?? null);
    if ($action === 'approve' && $inst['status'] === 'approved') {
        db()->prepare("UPDATE leave_requests SET status = 'Approved', decided_by = ?, decided_at = NOW() WHERE id = ?")->execute([$u['user_id'], $id]);
    } elseif ($action === 'reject') {
        db()->prepare("UPDATE workflow_instances SET status = 'cancelled', needs_supervisor = 0 WHERE id = ?")->execute([$inst['id']]);
        $why = substr(trim(($b['reason_code'] ?? '') . ': ' . ($b['note'] ?? ''), ': '), 0, 255);
        db()->prepare("UPDATE leave_requests SET status = 'Rejected', decided_by = ?, decided_at = NOW(), reject_reason = ? WHERE id = ?")->execute([$u['user_id'], $why, $id]);
    }
    return hr_leave_detail($cid, $id);
}

function hr_leave_cancel(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $r = hr_leave_row($cid, $id);
    if ($r['status'] !== 'Pending') error_response('Only a pending request can be cancelled (this one is ' . $r['status'] . ')', 409);
    if ((int)$r['requested_by'] !== (int)$u['user_id'] && !permission_allows($u['permissions'], 'hr-leaves', 'manage')) error_response('Only the requester or HR can cancel', 403);
    if (!hr_text($b['reason'] ?? null)) error_response('A reason is required', 422);
    db()->prepare("UPDATE workflow_instances SET status = 'cancelled' WHERE id = ?")->execute([$r['workflow_instance_id']]);
    db()->prepare("UPDATE leave_requests SET status = 'Cancelled', reject_reason = ? WHERE id = ?")->execute([hr_text($b['reason'], 255), $id]);
    return hr_leave_detail($cid, $id);
}

/** documents that are expired or expire within the warning window, newest problems first */
function hr_expiry(int $cid, ?int $days = null): array {
    $days = $days ?? (int)wf_setting($cid, 'hr.expiry_warning_days', '60');
    $kinds = ['iqama_expiry' => 'Iqama', 'passport_expiry' => 'Passport', 'health_card_expiry' => 'Health card', 'contract_end' => 'Contract'];
    $q = db()->prepare("SELECT id, employee_id, first_name, last_name, department, iqama_expiry, passport_expiry, health_card_expiry, contract_end FROM employees WHERE company_id = ? AND status = 'Active'" . branch_scope_sql('branch_id'));
    $q->execute([$cid]);
    $out = []; $today = strtotime(date('Y-m-d'));
    foreach ($q->fetchAll() as $e) foreach ($kinds as $col => $label) {
        if (!$e[$col]) continue;
        $left = (int)round((strtotime($e[$col]) - $today) / 86400);
        if ($left > $days) continue;
        $out[] = ['employee_id' => (int)$e['id'], 'code' => $e['employee_id'], 'name' => trim($e['first_name'] . ' ' . $e['last_name']), 'department' => $e['department'], 'document' => $label, 'expires_on' => $e[$col], 'days_left' => $left, 'severity' => $left < 0 ? 'expired' : ($left <= 14 ? 'critical' : 'soon')];
    }
    usort($out, fn($a, $b) => $a['days_left'] <=> $b['days_left']);
    return $out;
}

function hr_summary(int $cid): array {
    $w = 'company_id = ?' . branch_scope_sql('branch_id');
    $a = db()->prepare("SELECT COUNT(*) FROM employees WHERE $w AND status = 'Active'"); $a->execute([$cid]);
    $l = db()->prepare("SELECT COUNT(*) FROM leave_requests WHERE company_id = ? AND status = 'Approved' AND from_date <= CURDATE() AND to_date >= CURDATE()"); $l->execute([$cid]);
    $p = db()->prepare("SELECT COUNT(*) FROM leave_requests WHERE company_id = ? AND status = 'Pending'"); $p->execute([$cid]);
    $ex = hr_expiry($cid);
    return ['active_employees' => (int)$a->fetchColumn(), 'on_leave_today' => (int)$l->fetchColumn(), 'pending_leaves' => (int)$p->fetchColumn(),
            'expired' => count(array_filter($ex, fn($x) => $x['severity'] === 'expired')), 'expiring_soon' => count(array_filter($ex, fn($x) => $x['severity'] !== 'expired')), 'portal_url' => wf_setting($cid, 'hr.portal_url', '')];
}
