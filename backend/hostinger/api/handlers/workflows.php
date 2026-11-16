<?php
// Workflow templates. View: any user; create/edit: administrators (pipeline).
$db = db(); $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$cid = (int)$GLOBALS['company_id'];
function wf_full(PDO $db, array $w): array {
    $s = $db->prepare('SELECT position, name_en, name_ar, role_key, user_id, rule, time_limit_hours, reject_to_position, reject_label_en, reject_label_ar, pos_x, pos_y FROM workflow_steps WHERE workflow_id = ? ORDER BY position');
    $s->execute([$w['id']]); $w['steps'] = $s->fetchAll(); return $w;
}
function wf_save_steps(PDO $db, int $wid, array $steps): void {
    if (!$steps) error_response('A workflow needs at least one step', 422);
    $db->prepare('DELETE FROM workflow_steps WHERE workflow_id = ?')->execute([$wid]);
    foreach (array_values($steps) as $i => $s) {
        if (empty($s['name_en'])) error_response('Every step needs name_en', 422);
        $rule = ($s['rule'] ?? 'maker_not_checker'); if (!in_array($rule, ['maker_not_checker', 'any'], true)) error_response('Bad rule', 422);
        $rk = $s['role_key'] ?? null;
        if ($rk !== null && $rk !== '') {
            $rq = $db->prepare('SELECT 1 FROM roles WHERE role_key = ? AND (company_id IS NULL OR company_id = ?) LIMIT 1'); $rq->execute([$rk, (int)$GLOBALS['company_id']]);
            if (!$rq->fetchColumn()) error_response("Unknown role '$rk' on step " . ($i + 1), 422);
        } else $rk = null;
        $back = $s['reject_to_position'] ?? null;
        if ($back !== null && $back !== '' && (!is_numeric($back) || (int)$back < 1 || (int)$back > $i + 1)) error_response('A rejected document can only go back to this step or an earlier one (step ' . ($i + 1) . ')', 422);
        $rle = trim((string)($s['reject_label_en'] ?? '')); $rla = trim((string)($s['reject_label_ar'] ?? ''));
        if (mb_strlen($rle) > 60 || mb_strlen($rla) > 60) error_response('Arrow label is too long (60 characters)', 422);
        $rle = $rle === '' ? null : $rle; $rla = $rla === '' ? null : $rla;
        $tl = $s['time_limit_hours'] ?? null;
        if ($tl !== null && $tl !== '' && (!is_numeric($tl) || (int)$tl < 1)) error_response('time_limit_hours must be a positive number', 422);
        $px = isset($s['pos_x']) && is_numeric($s['pos_x']) ? (int)$s['pos_x'] : null; $py = isset($s['pos_y']) && is_numeric($s['pos_y']) ? (int)$s['pos_y'] : null;
        $db->prepare('INSERT INTO workflow_steps (workflow_id, position, name_en, name_ar, role_key, user_id, rule, time_limit_hours, reject_to_position, reject_label_en, reject_label_ar, pos_x, pos_y) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$wid, $i + 1, $s['name_en'], $s['name_ar'] ?? null, $rk, $s['user_id'] ?? null, $rule, $tl === '' ? null : $tl, $back === '' ? null : $back, $rle, $rla, $px, $py]);
    }
}
require_once __DIR__ . '/../core/workflow.php';
function wf_snapshot(PDO $db, int $wid, string $note = ''): int {
    $s = $db->prepare('SELECT position, name_en, name_ar, role_key, user_id, rule, time_limit_hours, reject_to_position, reject_label_en, reject_label_ar, pos_x, pos_y FROM workflow_steps WHERE workflow_id = ? ORDER BY position');
    $s->execute([$wid]);
    $v = $db->prepare('SELECT COALESCE(MAX(version),0) + 1 FROM workflow_versions WHERE workflow_id = ?'); $v->execute([$wid]); $n = (int)$v->fetchColumn();
    $db->prepare('INSERT INTO workflow_versions (workflow_id, version, steps_json, note, saved_by) VALUES (?,?,?,?,?)')->execute([$wid, $n, json_encode($s->fetchAll(), JSON_UNESCAPED_UNICODE), $note !== '' ? mb_substr($note, 0, 255) : null, $GLOBALS['auth_user']['user_id'] ?? null]);
    return $n;
}
/** while documents are in progress the approvers, names and rules of the steps may change, but not the NUMBER of steps (a document is at a step position) */
function wf_no_open(PDO $db, int $wid, ?int $newCount = null): void {
    $open = $db->prepare("SELECT COUNT(*) FROM workflow_instances WHERE workflow_id = ? AND status = 'open'"); $open->execute([$wid]);
    if ($open->fetchColumn() == 0) return;
    $cur = $db->prepare('SELECT COUNT(*) FROM workflow_steps WHERE workflow_id = ?'); $cur->execute([$wid]);
    if ($newCount === null || $newCount !== (int)$cur->fetchColumn()) error_response('Steps cannot be added or removed while documents are in progress (you can still change who approves each step)', 409);
}
wf_ensure_defaults($cid);
$sub = $GLOBALS['parts'][2] ?? '';
if ($id && $sub === 'versions' && $method === 'GET') {
    $s = $db->prepare('SELECT id FROM workflows WHERE id = ? AND company_id = ?'); $s->execute([$id, $cid]); if (!$s->fetch()) error_response('Not found', 404);
    $q = $db->prepare('SELECT v.version, v.note, v.created_at, u.name AS saved_by_name, v.steps_json FROM workflow_versions v LEFT JOIN users u ON u.id = v.saved_by WHERE v.workflow_id = ? ORDER BY v.version DESC LIMIT 50');
    $q->execute([$id]);
    json_response(array_map(function ($r) { $r['steps'] = json_decode($r['steps_json'], true); unset($r['steps_json']); return $r; }, $q->fetchAll()));
}
if ($id && $sub === 'restore' && $method === 'POST') {
    $s = $db->prepare('SELECT * FROM workflows WHERE id = ? AND company_id = ?'); $s->execute([$id, $cid]); $w = $s->fetch(); if (!$w) error_response('Not found', 404);
    $q0 = $db->prepare('SELECT steps_json FROM workflow_versions WHERE workflow_id = ? AND version = ?'); $q0->execute([$id, (int)($body['version'] ?? 0)]); $j0 = $q0->fetchColumn();
    wf_no_open($db, (int)$id, $j0 === false ? 0 : count(json_decode($j0, true) ?: []));
    $q = $db->prepare('SELECT steps_json FROM workflow_versions WHERE workflow_id = ? AND version = ?'); $q->execute([$id, (int)($body['version'] ?? 0)]);
    $j = $q->fetchColumn(); if ($j === false) error_response('Version not found', 404);
    $db->beginTransaction();
    try { wf_save_steps($db, (int)$id, json_decode($j, true)); wf_snapshot($db, (int)$id, 'Restored version ' . (int)$body['version']); $db->commit(); }
    catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    json_response(wf_full($db, $w));
}
switch ($method) {
case 'GET':
    if ($id) { $s = $db->prepare('SELECT * FROM workflows WHERE id = ? AND company_id = ?'); $s->execute([$id, $cid]); $w = $s->fetch(); if (!$w) error_response('Not found', 404); json_response(wf_full($db, $w)); }
    $s = $db->prepare('SELECT * FROM workflows WHERE company_id = ? ORDER BY id'); $s->execute([$cid]);
    json_response(array_map(fn($w) => wf_full($db, $w), $s->fetchAll()));
case 'POST':
    foreach (['wf_key', 'name_en', 'doc_type'] as $f) if (empty($body[$f])) error_response("$f is required", 422);
    if (!preg_match('/^[a-z][a-z0-9_]{1,58}$/', $body['wf_key'])) error_response('wf_key: lowercase letters, numbers, underscore', 422);
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO workflows (company_id, wf_key, name_en, name_ar, doc_type) VALUES (?,?,?,?,?)')->execute([$cid, $body['wf_key'], $body['name_en'], $body['name_ar'] ?? null, $body['doc_type']]);
        $wid = (int)$db->lastInsertId(); wf_save_steps($db, $wid, $body['steps'] ?? []); wf_snapshot($db, $wid, (string)($body['note'] ?? 'Created')); $db->commit();
    } catch (PDOException $e) { $db->rollBack(); error_response('Workflow key already exists', 409); }
    $s = $db->prepare('SELECT * FROM workflows WHERE id = ?'); $s->execute([$wid]); json_response(wf_full($db, $s->fetch()), 201);
case 'PUT':
    if (!$id) error_response('ID required');
    $s = $db->prepare('SELECT * FROM workflows WHERE id = ? AND company_id = ?'); $s->execute([$id, $cid]); if (!$s->fetch()) error_response('Not found', 404);
    if (isset($body['steps'])) wf_no_open($db, (int)$id, is_array($body['steps']) ? count($body['steps']) : 0);
    foreach (['name_en', 'name_ar', 'is_active'] as $f) if (array_key_exists($f, $body)) $db->prepare("UPDATE workflows SET $f = ? WHERE id = ?")->execute([$body[$f], $id]);
    if (isset($body['steps'])) { $hv = $db->prepare('SELECT COUNT(*) FROM workflow_versions WHERE workflow_id = ?'); $hv->execute([$id]); if (!(int)$hv->fetchColumn()) wf_snapshot($db, (int)$id, 'Before the first designer change'); $db->beginTransaction(); try { wf_save_steps($db, (int)$id, $body['steps']); wf_snapshot($db, (int)$id, (string)($body['note'] ?? '')); $db->commit(); } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; } }
    $s->execute([$id, $cid]); json_response(wf_full($db, $s->fetch()));
case 'DELETE':
    error_response('Workflows are never deleted. Set is_active = 0 instead.', 405);
}
