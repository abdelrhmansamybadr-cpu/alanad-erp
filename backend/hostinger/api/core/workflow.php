<?php
// ─── Alanad ERP — workflow engine ────────────────────────────
// Ordered steps; each step names a role (or a person). Rules enforced here:
//   • the creator of a document can never approve it (maker ≠ checker)
//   • a rejection needs a reason code (and a note when the code is "other")
//   • a rejection returns the document to the configured step
//   • after N rejections (setting) a supervisor must decide
//   • every action is stored in workflow_actions

function wf_setting(int $companyId, string $key, string $default): string {
    $st = db()->prepare('SELECT setting_value FROM settings WHERE company_id = ? AND setting_key = ?');
    $st->execute([$companyId, $key]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? $default : (string)$v;
}

function wf_step(int $workflowId, int $position): ?array {
    $st = db()->prepare('SELECT * FROM workflow_steps WHERE workflow_id = ? AND position = ?');
    $st->execute([$workflowId, $position]);
    return $st->fetch() ?: null;
}

function wf_start(int $companyId, string $wfKey, string $docType, int $docId, int $userId): array {
    $wf = db()->prepare('SELECT * FROM workflows WHERE company_id = ? AND wf_key = ? AND is_active = 1');
    $wf->execute([$companyId, $wfKey]);
    $wf = $wf->fetch();
    if (!$wf) error_response('Workflow not found', 404);
    $first = db()->prepare('SELECT MIN(position) FROM workflow_steps WHERE workflow_id = ?');
    $first->execute([$wf['id']]);
    $pos = (int)$first->fetchColumn();
    if (!$pos) error_response('Workflow has no steps', 422);

    db()->prepare('INSERT INTO workflow_instances (company_id, workflow_id, doc_type, doc_id, current_position, created_by) VALUES (?,?,?,?,?,?)')
        ->execute([$companyId, $wf['id'], $docType, $docId, $pos, $userId]);
    $id = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO workflow_actions (instance_id, step_position, user_id, action) VALUES (?,?,?, 'submit')")
        ->execute([$id, $pos, $userId]);
    return wf_get($id, $companyId);
}

function wf_get(int $id, int $companyId): array {
    $st = db()->prepare('SELECT i.*, w.name_en AS workflow_name FROM workflow_instances i JOIN workflows w ON w.id = i.workflow_id
                         WHERE i.id = ? AND i.company_id = ?');
    $st->execute([$id, $companyId]);
    $i = $st->fetch();
    if (!$i) error_response('Not found', 404);
    $a = db()->prepare('SELECT * FROM workflow_actions WHERE instance_id = ? ORDER BY id');
    $a->execute([$id]);
    $i['actions'] = $a->fetchAll();
    $step = wf_step((int)$i['workflow_id'], (int)$i['current_position']);
    $i['current_step'] = $step ? ['position' => (int)$step['position'], 'name_en' => $step['name_en'], 'name_ar' => $step['name_ar'], 'role_key' => $step['role_key']] : null;
    return $i;
}

/** $action: approve | reject | decide_approve | decide_cancel | cancel */
function wf_act(int $instanceId, array $user, string $action, ?string $reasonCode, ?string $note): array {
    $companyId = (int)$user['company_id'];
    $inst = wf_get($instanceId, $companyId);
    if ($inst['status'] !== 'open') error_response('This document is already ' . $inst['status'], 409);

    $step = wf_step((int)$inst['workflow_id'], (int)$inst['current_position']);
    if (!$step) error_response('Workflow step missing', 500);
    $isAdmin = in_array($user['role'], ['admin'], true);

    // supervisor decision after too many rejections
    if ((int)$inst['needs_supervisor']) {
        if (!in_array($action, ['decide_approve', 'decide_cancel'], true)) {
            error_response('This document was rejected too many times: a supervisor must decide (decide_approve / decide_cancel)', 409);
        }
        if (!in_array($user['role'], ['admin', 'manager'], true)) error_response('Only a supervisor can decide', 403);
        if ((int)$inst['created_by'] === $user['user_id'] && wf_setting($companyId, 'workflow.maker_not_checker', '1') === '1') {
            error_response('You created this document: another supervisor must decide', 403);
        }
        if ($note === null || trim($note) === '') error_response('A note is required for a supervisor decision', 422);
        $newStatus = $action === 'decide_approve' ? 'approved' : 'cancelled';
        db()->prepare('UPDATE workflow_instances SET status = ?, needs_supervisor = 0 WHERE id = ?')->execute([$newStatus, $instanceId]);
        wf_log($instanceId, (int)$inst['current_position'], $user['user_id'], $action, null, $note);
        return wf_get($instanceId, $companyId);
    }
    if (in_array($action, ['decide_approve', 'decide_cancel'], true)) error_response('No supervisor decision is pending', 409);

    if ($action === 'cancel') {
        if ((int)$inst['created_by'] !== $user['user_id'] && !$isAdmin) error_response('Only the creator or an admin can cancel', 403);
        db()->prepare("UPDATE workflow_instances SET status = 'cancelled' WHERE id = ?")->execute([$instanceId]);
        wf_log($instanceId, (int)$inst['current_position'], $user['user_id'], 'cancel', null, $note);
        return wf_get($instanceId, $companyId);
    }
    if (!in_array($action, ['approve', 'reject'], true)) error_response('Unknown action', 422);

    // who may act on this step
    $roleOk = $step['role_key'] === null || $step['role_key'] === $user['role'] || $isAdmin
        || ($step['role_key'] === 'user' && $user['role'] === 'manager');   // managers outrank standard users
    $personOk = $step['user_id'] === null || (int)$step['user_id'] === $user['user_id'] || $isAdmin;
    if (!$roleOk || !$personOk) error_response('This step is not assigned to you', 403);

    // maker ≠ checker
    $mnc = $step['rule'] === 'maker_not_checker' && wf_setting($companyId, 'workflow.maker_not_checker', '1') === '1';
    if ($mnc && (int)$inst['created_by'] === $user['user_id']) {
        error_response('You created this document: someone else must approve it', 403);
    }

    if ($action === 'approve') {
        $next = db()->prepare('SELECT MIN(position) FROM workflow_steps WHERE workflow_id = ? AND position > ?');
        $next->execute([$inst['workflow_id'], $inst['current_position']]);
        $nextPos = $next->fetchColumn();
        if ($nextPos === false || $nextPos === null) {
            db()->prepare("UPDATE workflow_instances SET status = 'approved' WHERE id = ?")->execute([$instanceId]);
        } else {
            db()->prepare('UPDATE workflow_instances SET current_position = ? WHERE id = ?')->execute([(int)$nextPos, $instanceId]);
        }
        wf_log($instanceId, (int)$inst['current_position'], $user['user_id'], 'approve', null, $note);
        return wf_get($instanceId, $companyId);
    }

    // reject: reason is mandatory
    if ($reasonCode === null || $reasonCode === '') error_response('A reject reason is required', 422);
    $rr = db()->prepare('SELECT 1 FROM reject_reasons WHERE company_id = ? AND code = ? AND is_active = 1 LIMIT 1');
    $rr->execute([$companyId, $reasonCode]);
    if (!$rr->fetchColumn()) error_response('Unknown reject reason', 422);
    if ($reasonCode === 'other' && ($note === null || trim($note) === '')) error_response('A note is required when the reason is "other"', 422);

    $back = $step['reject_to_position'];
    if ($back === null) {
        $prev = db()->prepare('SELECT MAX(position) FROM workflow_steps WHERE workflow_id = ? AND position < ?');
        $prev->execute([$inst['workflow_id'], $inst['current_position']]);
        $back = $prev->fetchColumn();
    }
    $back = $back ?: (int)$inst['current_position'];
    $count = (int)$inst['reject_count'] + 1;
    $max = (int)wf_setting($companyId, 'workflow.max_rejects_before_escalation', '3');
    db()->prepare('UPDATE workflow_instances SET current_position = ?, reject_count = ?, needs_supervisor = ? WHERE id = ?')
        ->execute([(int)$back, $count, $count >= $max ? 1 : 0, $instanceId]);
    wf_log($instanceId, (int)$inst['current_position'], $user['user_id'], 'reject', $reasonCode, $note);
    return wf_get($instanceId, $companyId);
}

function wf_log(int $instanceId, int $position, int $userId, string $action, ?string $reason, ?string $note): void {
    db()->prepare('INSERT INTO workflow_actions (instance_id, step_position, user_id, action, reason_code, note) VALUES (?,?,?,?,?,?)')
        ->execute([$instanceId, $position, $userId, $action, $reason, $note]);
}


// ─── The designer's flows: one approval step each, the role of the step decides who may approve that document ───
const WF_DESIGNER_FLOWS = [
    'client_order_approval'    => ['Client order approval', 'اعتماد طلب العميل', 'client_order', 'Manager approval', 'اعتماد المدير'],
    'market_purchase_approval' => ['Market purchase approval (Finance)', 'اعتماد الشراء من السوق (المالية)', 'market_purchase', 'Finance approval', 'اعتماد المالية'],
    'prep_order_approval'      => ['Preparation order approval', 'اعتماد أمر التجهيز', 'prep_order', 'Plant manager approval', 'اعتماد مدير المصنع'],
    'waste_decision'           => ['Waste decision approval (Finance)', 'اعتماد قرار الهالك (المالية)', 'prep_waste', 'Finance approval', 'اعتماد المالية'],
];

/** a company created after the migration gets the designer's flows the first time they are needed */
function wf_ensure_defaults(int $cid): void {
    foreach (WF_DESIGNER_FLOWS as $key => [$en, $ar, $doc, $sEn, $sAr]) {
        $q = db()->prepare('SELECT id FROM workflows WHERE company_id = ? AND wf_key = ?'); $q->execute([$cid, $key]);
        if ($q->fetchColumn()) continue;
        db()->prepare('INSERT INTO workflows (company_id, wf_key, name_en, name_ar, doc_type) VALUES (?,?,?,?,?)')->execute([$cid, $key, $en, $ar, $doc]);
        $id = (int)db()->lastInsertId();
        db()->prepare("INSERT INTO workflow_steps (workflow_id, position, name_en, name_ar, role_key, rule) VALUES (?,1,?,?, 'manager', 'maker_not_checker')")->execute([$id, $sEn, $sAr]);
    }
}

/** may this user take the approval step of the flow? (no flow, an inactive flow or an open step = yes; the admin always may) */
function wf_allows(int $cid, string $key, array $user): bool {
    $q = db()->prepare('SELECT s.role_key, s.user_id FROM workflows w JOIN workflow_steps s ON s.workflow_id = w.id WHERE w.company_id = ? AND w.wf_key = ? AND w.is_active = 1 ORDER BY s.position LIMIT 1');
    $q->execute([$cid, $key]);
    $s = $q->fetch();
    if (!$s) { wf_ensure_defaults($cid); $q->execute([$cid, $key]); $s = $q->fetch(); }
    if (!$s || $user['role'] === 'admin') return true;
    $roleOk = $s['role_key'] === null || $s['role_key'] === $user['role'] || ($s['role_key'] === 'user' && $user['role'] === 'manager');
    $personOk = $s['user_id'] === null || (int)$s['user_id'] === (int)$user['user_id'];
    return $roleOk && $personOk;
}
function wf_require(int $cid, string $key, array $user): void {
    if (!wf_allows($cid, $key, $user)) error_response('The approval flow "' . $key . '" assigns this step to another role (change it in Admin → Process designer)', 403);
}
