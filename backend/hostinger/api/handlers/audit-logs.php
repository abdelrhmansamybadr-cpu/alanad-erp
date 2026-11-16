<?php
// Audit log: read-only (the table also refuses UPDATE/DELETE at database level where the host allows triggers).
if ($GLOBALS['method'] !== 'GET') error_response('Audit log is read-only', 405);
$cid = (int)$GLOBALS['company_id'];
$w = 'company_id = ?'; $p = [$cid];
foreach (['resource', 'action', 'user_id', 'resource_id'] as $f) if (!empty($_GET[$f])) { $w .= " AND $f = ?"; $p[] = $_GET[$f]; }
if (!empty($_GET['from'])) { $w .= ' AND created_at >= ?'; $p[] = $_GET['from']; }
if (!empty($_GET['to'])) { $w .= ' AND created_at <= ?'; $p[] = $_GET['to']; }
$page = max(1, (int)($_GET['page'] ?? 1)); $per = min(200, max(1, (int)($_GET['per_page'] ?? 50)));
json_response(paginate("SELECT * FROM audit_log WHERE $w ORDER BY id DESC", $p, $page, $per));
