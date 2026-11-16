<?php
// The journal. Entries are append-only; a manual entry is corrected by a reversal. Documents post their own entries automatically.
//   GET  /gl-entries[?source_type=&status=&from=&to=&account_id=]    GET /gl-entries/{id}  (with lines)
//   POST /gl-entries {date?, description, lines:[{account_id, debit|credit, memo?}]}     manual entry (gl-entries.approve), must balance
//   POST /gl-entries/{id}/reverse {reason, date?}                       manual entries only
require_once __DIR__ . '/../core/finance.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$action = $GLOBALS['parts'][2] ?? '';

if ($method === 'GET') {
    if ($id) json_response(gl_entry_detail($cid, (int)$id));
    $w = 'e.company_id = ?'; $p = [$cid];
    foreach (['source_type', 'status'] as $f) if (!empty($_GET[$f])) { $w .= " AND e.$f = ?"; $p[] = $_GET[$f]; }
    if (!empty($_GET['from'])) { $w .= ' AND e.date >= ?'; $p[] = $_GET['from']; }
    if (!empty($_GET['to'])) { $w .= ' AND e.date <= ?'; $p[] = $_GET['to']; }
    if (!empty($_GET['account_id'])) { $w .= ' AND EXISTS (SELECT 1 FROM gl_lines x WHERE x.entry_id = e.id AND x.account_id = ?)'; $p[] = (int)$_GET['account_id']; }
    $q = db()->prepare("SELECT e.id, e.number, e.date, e.description, e.source_type, e.status, e.total FROM gl_entries e WHERE $w ORDER BY e.date DESC, e.id DESC LIMIT 300");
    $q->execute($p);
    json_response(array_map(function ($r) { $r['total'] = (float)$r['total']; return $r; }, $q->fetchAll()));
}
if ($method === 'POST' && !$id) json_response(gl_manual($u, $body), 201);
if ($method === 'POST' && $id && $action === 'reverse') json_response(gl_reverse($u, (int)$id, $body), 201);
error_response('Not found', 404);
