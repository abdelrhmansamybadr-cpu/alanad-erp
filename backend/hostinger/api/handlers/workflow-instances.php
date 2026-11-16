<?php
// Start a workflow for a document and act on it.
//   POST /workflow-instances                {wf_key, doc_type, doc_id}
//   GET  /workflow-instances[?status=open&doc_type=..&doc_id=..]   GET /workflow-instances/{id}
//   POST /workflow-instances/{id}/act       {action: approve|reject|cancel|decide_approve|decide_cancel, reason_code?, note?}
require_once __DIR__ . '/../core/workflow.php';
$db = db(); $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body']; $parts = $GLOBALS['parts'];
$user = $GLOBALS['auth_user']; $cid = (int)$user['company_id'];
if ($method === 'GET') {
    if (!permission_allows($user['permissions'], 'workflow-instances', 'view')) error_response('Forbidden', 403);
    if ($id) json_response(wf_get((int)$id, $cid));
    $w = 'i.company_id = ?'; $p = [$cid];
    foreach (['status', 'doc_type', 'doc_id'] as $f) if (!empty($_GET[$f])) { $w .= " AND i.$f = ?"; $p[] = $_GET[$f]; }
    $q = $db->prepare("SELECT i.*, w.name_en AS workflow_name, w.name_ar AS workflow_name_ar, w.wf_key,
                              s.name_en AS step_name_en, s.name_ar AS step_name_ar, s.role_key AS step_role_key, s.rule AS step_rule,
                              (SELECT COUNT(*) FROM workflow_steps x WHERE x.workflow_id = i.workflow_id) AS step_count
                       FROM workflow_instances i
                       JOIN workflows w ON w.id = i.workflow_id
                       LEFT JOIN workflow_steps s ON s.workflow_id = i.workflow_id AND s.position = i.current_position
                       WHERE $w ORDER BY i.id DESC LIMIT 200");
    $q->execute($p); json_response($q->fetchAll());
}
if ($method === 'POST' && !$id) {
    if (!permission_allows($user['permissions'], 'workflow-instances', 'create')) error_response('Forbidden', 403);
    foreach (['wf_key', 'doc_type', 'doc_id'] as $f) if (empty($body[$f])) error_response("$f is required", 422);
    json_response(wf_start($cid, $body['wf_key'], $body['doc_type'], (int)$body['doc_id'], $user['user_id']), 201);
}
if ($method === 'POST' && $id && ($parts[2] ?? '') === 'act') {
    if (!permission_allows($user['permissions'], 'workflow-instances', 'view')) error_response('Forbidden', 403);
    if (empty($body['action'])) error_response('action is required', 422);
    json_response(wf_act((int)$id, $user, $body['action'], $body['reason_code'] ?? null, $body['note'] ?? null));
}
error_response('Not found', 404);
