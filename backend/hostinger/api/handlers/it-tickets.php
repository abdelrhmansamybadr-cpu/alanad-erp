<?php
// IT helpdesk (rules in core/it.php). People see only their own tickets; the IT team sees all.
//   GET  /it-tickets[?status=&priority=&category=&mine=1&overdue=1]   GET /it-tickets/{id}   GET /it-tickets/summary   GET /it-tickets/team
//   POST /it-tickets {subject, description?, category?, priority?, asset_id?, branch_id?}
//   POST /it-tickets/{id}/comment {body, internal?}      POST /it-tickets/{id}/assign {assignee_id}  [manage]
//   POST /it-tickets/{id}/status {status: In_Progress|Waiting|Resolved, resolution?}  [work: assignee or manager]
//   POST /it-tickets/{id}/close   POST /it-tickets/{id}/reopen {reason}   POST /it-tickets/{id}/cancel {reason}
require_once __DIR__ . '/../core/it.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$seg = $GLOBALS['parts'][1] ?? ''; $act = $GLOBALS['parts'][2] ?? '';
$id = ctype_digit((string)$seg) ? (int)$seg : 0;
if ($method === 'GET') {
    if ($seg === 'summary') json_response(it_summary($u));
    if ($seg === 'team') json_response(it_staff($u) ? it_team($cid) : []);
    if ($id) json_response(it_detail($u, $id));
    it_auto_close($cid);
    $w = 't.company_id = ?' . branch_scope_sql('t.branch_id'); $p = [$cid];
    if (!it_staff($u) || !empty($_GET['mine'])) { $w .= ' AND t.requester_id = ?'; $p[] = (int)$u['user_id']; }
    foreach (['status', 'priority', 'category'] as $f) if (!empty($_GET[$f])) { $w .= " AND t.$f = ?"; $p[] = $_GET[$f]; }
    if (!empty($_GET['assignee_id'])) { $w .= ' AND t.assignee_id = ?'; $p[] = (int)$_GET['assignee_id']; }
    if (!empty($_GET['overdue'])) $w .= " AND t.status IN ('New','Assigned','In_Progress','Waiting') AND t.due_at < NOW()";
    $q = db()->prepare("SELECT t.id, t.number, t.subject, t.category, t.priority, t.status, t.due_at, t.created_at, t.assignee_id, r.name AS requester_name, a.name AS assignee_name, (t.due_at < NOW() AND t.status IN ('New','Assigned','In_Progress','Waiting')) AS overdue FROM it_tickets t JOIN users r ON r.id = t.requester_id LEFT JOIN users a ON a.id = t.assignee_id WHERE $w ORDER BY FIELD(t.status,'New','Assigned','In_Progress','Waiting','Resolved','Closed','Cancelled'), t.due_at LIMIT 300");
    $q->execute($p);
    $rows = $q->fetchAll(); foreach ($rows as &$r) $r['overdue'] = (bool)$r['overdue']; unset($r);
    json_response($rows);
}
if ($method === 'POST') {
    if ($seg === '') json_response(it_create($u, $body), 201);
    if ($id) switch ($act) {
        case 'comment': json_response(it_comment($u, $id, $body));
        case 'assign':  json_response(it_assign($u, $id, $body));
        case 'status':  json_response(it_status($u, $id, $body));
        case 'close':   json_response(it_close($u, $id, $body, false));
        case 'reopen':  json_response(it_close($u, $id, $body, true));
        case 'cancel':  json_response(it_cancel($u, $id, $body));
    }
}
error_response('Not found', 404);
