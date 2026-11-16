<?php
// Leave (rules in core/hr.php). Approval follows the designer-controlled flow "leave_request" (default: team head, then HR).
//   GET  /hr-leaves[?status=&employee_id=]    GET /hr-leaves/{id}  (with the approval flow)    GET /hr-leaves/balances?employee_id=&year=
//   POST /hr-leaves {employee_id, leave_type_id, from_date, to_date, reason?}     an employee for himself (his account linked to his record), HR for anyone
//   POST /hr-leaves/{id}/decide {action: approve|reject, reason_code?, note?}     the person the current step names, never the requester
//   POST /hr-leaves/{id}/cancel {reason}
require_once __DIR__ . '/../core/hr.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$seg = $GLOBALS['parts'][1] ?? ''; $action = $GLOBALS['parts'][2] ?? '';
$id = ctype_digit((string)$seg) ? (int)$seg : 0;
if ($method === 'GET') {
    if ($seg === 'balances') json_response(hr_balances($cid, (int)($_GET['employee_id'] ?? 0), isset($_GET['year']) ? (int)$_GET['year'] : null));
    if ($id) json_response(hr_leave_detail($cid, $id));
    $w = 'l.company_id = ?'; $p = [$cid];
    foreach (['status', 'employee_id'] as $f) if (!empty($_GET[$f])) { $w .= " AND l.$f = ?"; $p[] = $_GET[$f]; }
    $q = db()->prepare("SELECT l.id, l.number, l.employee_id, l.employee_name, l.leave_type, l.from_date, l.to_date, l.days, l.status, l.reason, l.reject_reason, l.requested_by FROM leave_requests l WHERE $w ORDER BY l.id DESC LIMIT 300");
    $q->execute($p);
    json_response($q->fetchAll());
}
if ($method === 'POST' && !$id && $seg === '') json_response(hr_leave_create($u, $body), 201);
if ($method === 'POST' && $id) {
    switch ($action) {
        case 'decide': json_response(hr_leave_decide($u, $id, $body));
        case 'cancel': json_response(hr_leave_cancel($u, $id, $body));
    }
}
error_response('Not found', 404);
