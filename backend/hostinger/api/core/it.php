<?php
// ─── IT core: helpdesk tickets (SLA, assignment, comments) and a summary; assets are plain company-scoped CRUD ───
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/crud.php';

const IT_NEXT = [
    'New'         => ['Assigned', 'In_Progress', 'Cancelled'],
    'Assigned'    => ['In_Progress', 'Waiting', 'Cancelled'],
    'In_Progress' => ['Waiting', 'Resolved'],
    'Waiting'     => ['In_Progress', 'Resolved'],
    'Resolved'    => ['In_Progress', 'Closed'],        // reopened or closed
];

function it_can(array $u, string $act): bool { return permission_allows($u['permissions'], 'it-tickets', $act); }
function it_text(mixed $v, int $max = 500): ?string {
    $v = trim((string)($v ?? ''));
    return $v === '' ? null : mb_substr($v, 0, $max);
}
function it_staff(array $u): bool { return it_can($u, 'work') || it_can($u, 'manage'); }

function it_row(array $u, int $id): array {
    $q = db()->prepare('SELECT t.*, r.name AS requester_name, a.name AS assignee_name FROM it_tickets t JOIN users r ON r.id = t.requester_id LEFT JOIN users a ON a.id = t.assignee_id WHERE t.id = ? AND t.company_id = ?');
    $q->execute([$id, (int)$u['company_id']]);
    $t = $q->fetch();
    if (!$t || !branch_in_scope($t['branch_id'] !== null ? (int)$t['branch_id'] : null)) error_response('Not found', 404);
    if (!it_staff($u) && (int)$t['requester_id'] !== (int)$u['user_id']) error_response('Not found', 404);   // people see only their own tickets
    return $t;
}

function it_detail(array $u, int $id): array {
    $t = it_row($u, $id);
    $o = db()->prepare('SELECT due_at IS NOT NULL AND due_at < NOW() FROM it_tickets WHERE id = ?'); $o->execute([$id]);   // the database clock, the same one that stamped due_at
    $t['overdue'] = !in_array($t['status'], ['Resolved', 'Closed', 'Cancelled'], true) && (bool)$o->fetchColumn();
    $c = db()->prepare('SELECT c.id, c.body, c.is_internal, c.created_at, u.name AS user_name, c.user_id FROM it_ticket_comments c JOIN users u ON u.id = c.user_id WHERE c.ticket_id = ?' . (it_staff($u) ? '' : ' AND c.is_internal = 0') . ' ORDER BY c.id');
    $c->execute([$id]);
    $t['comments'] = $c->fetchAll();
    if ($t['asset_id']) { $a = db()->prepare('SELECT id, code, name, type FROM it_assets WHERE id = ?'); $a->execute([$t['asset_id']]); $t['asset'] = $a->fetch() ?: null; } else $t['asset'] = null;
    $t['next'] = IT_NEXT[$t['status']] ?? [];
    return $t;
}

/** a resolved ticket nobody reopened closes itself after the configured days */
function it_auto_close(int $cid): void {
    $d = (int)wf_setting($cid, 'it.auto_close_days', '7');
    if ($d <= 0) return;
    db()->prepare("UPDATE it_tickets SET status = 'Closed', closed_at = NOW() WHERE company_id = ? AND status = 'Resolved' AND resolved_at < DATE_SUB(NOW(), INTERVAL ? DAY)")->execute([$cid, $d]);
}

function it_create(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $subject = it_text($b['subject'] ?? null, 200);
    if (!$subject) error_response('A subject is required', 422);
    $cat = $b['category'] ?? 'other'; $pri = $b['priority'] ?? 'medium';
    if (!in_array($cat, ['hardware', 'software', 'network', 'account', 'erp', 'other'], true)) error_response('Unknown category', 422);
    if (!in_array($pri, ['low', 'medium', 'high', 'critical'], true)) error_response('Unknown priority', 422);
    $asset = null;
    if (!empty($b['asset_id'])) { $a = db()->prepare('SELECT id FROM it_assets WHERE id = ? AND company_id = ?'); $a->execute([(int)$b['asset_id'], $cid]); if (!$a->fetchColumn()) error_response('Asset not found', 422); $asset = (int)$b['asset_id']; }
    $hours = (int)wf_setting($cid, 'it.sla_hours_' . $pri, ['critical' => '4', 'high' => '8', 'medium' => '24', 'low' => '72'][$pri]);
    $branch = !empty($b['branch_id']) ? branch_validate($cid, (int)$b['branch_id']) : branch_default($cid);
    $number = crud_next_number('IT', 'it_tickets', $cid);
    db()->prepare('INSERT INTO it_tickets (company_id, branch_id, number, requester_id, category, priority, subject, description, asset_id, due_at) VALUES (?,?,?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? HOUR))')
        ->execute([$cid, $branch, $number, $u['user_id'], $cat, $pri, $subject, it_text($b['description'] ?? null, 5000), $asset, $hours]);
    return it_detail($u, (int)db()->lastInsertId());
}

function it_comment(array $u, int $id, array $b): array {
    $t = it_row($u, $id);
    if (in_array($t['status'], ['Closed', 'Cancelled'], true)) error_response('This ticket is ' . $t['status'] . ': reopen is not possible, open a new ticket', 409);
    $body = it_text($b['body'] ?? null, 4000);
    if (!$body) error_response('Write the comment', 422);
    $internal = !empty($b['internal']);
    if ($internal && !it_staff($u)) error_response('Only IT can write internal notes', 403);
    db()->prepare('INSERT INTO it_ticket_comments (ticket_id, user_id, body, is_internal) VALUES (?,?,?,?)')->execute([$id, $u['user_id'], $body, $internal ? 1 : 0]);
    if (it_staff($u) && !$t['first_response_at'] && !$internal) db()->prepare('UPDATE it_tickets SET first_response_at = NOW() WHERE id = ?')->execute([$id]);
    return it_detail($u, $id);
}

function it_assign(array $u, int $id, array $b): array {
    if (!it_can($u, 'manage')) error_response('Forbidden: only the IT manager assigns tickets', 403);
    $t = it_row($u, $id);
    if (in_array($t['status'], ['Resolved', 'Closed', 'Cancelled'], true)) error_response('This ticket is ' . $t['status'], 409);
    $who = db()->prepare("SELECT 1 FROM users u WHERE u.id = ? AND u.company_id = ? AND u.is_active = 1 AND EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = u.role_id AND rp.permission IN ('*','it-tickets.work','it-tickets.*','it-tickets.manage'))"); $who->execute([(int)($b['assignee_id'] ?? 0), (int)$u['company_id']]);
    if (!$who->fetchColumn()) error_response('Choose a person from the IT team', 422);
    db()->prepare("UPDATE it_tickets SET assignee_id = ?, status = IF(status = 'New', 'Assigned', status), first_response_at = COALESCE(first_response_at, NOW()) WHERE id = ?")->execute([(int)$b['assignee_id'], $id]);
    return it_detail($u, $id);
}

/** the assignee (or the IT manager) moves the ticket along: In_Progress, Waiting, Resolved (needs the resolution), back to In_Progress */
function it_status(array $u, int $id, array $b): array {
    $t = it_row($u, $id);
    if (!it_can($u, 'manage') && !(it_can($u, 'work') && (int)$t['assignee_id'] === (int)$u['user_id'])) error_response('Only the person assigned (or the IT manager) can change the status', 403);
    $to = $b['status'] ?? '';
    if (!in_array($to, ['In_Progress', 'Waiting', 'Resolved'], true)) error_response("status must be In_Progress, Waiting or Resolved", 422);
    if (!in_array($to, IT_NEXT[$t['status']] ?? [], true)) error_response('A ticket that is ' . $t['status'] . ' cannot go to ' . $to, 409);
    if ($to === 'In_Progress' && !$t['assignee_id']) { db()->prepare('UPDATE it_tickets SET assignee_id = ? WHERE id = ?')->execute([$u['user_id'], $id]); }
    $res = it_text($b['resolution'] ?? null, 2000);
    if ($to === 'Resolved' && !$res) error_response('Write what was done (resolution)', 422);
    if ($to === 'Resolved') {
        db()->prepare('UPDATE it_tickets SET status = ?, resolved_at = NOW(), resolution = ?, first_response_at = COALESCE(first_response_at, NOW()) WHERE id = ?')->execute([$to, $res, $id]);
    } else {
        db()->prepare('UPDATE it_tickets SET status = ?, first_response_at = COALESCE(first_response_at, NOW()) WHERE id = ?')->execute([$to, $id]);
    }
    return it_detail($u, $id);
}

/** the requester closes a resolved ticket (or reopens it); the IT manager can close any */
function it_close(array $u, int $id, array $b, bool $reopen): array {
    $t = it_row($u, $id);
    if ($t['status'] !== 'Resolved') error_response('Only a resolved ticket can be ' . ($reopen ? 'reopened' : 'closed') . ' (this one is ' . $t['status'] . ')', 409);
    if ((int)$t['requester_id'] !== (int)$u['user_id'] && !it_can($u, 'manage')) error_response('Only the requester or the IT manager can do this', 403);
    if ($reopen) {
        if (!it_text($b['reason'] ?? null)) error_response('Say why it is not solved', 422);
        db()->prepare("UPDATE it_tickets SET status = 'In_Progress', resolved_at = NULL WHERE id = ?")->execute([$id]);
        db()->prepare('INSERT INTO it_ticket_comments (ticket_id, user_id, body) VALUES (?,?,?)')->execute([$id, $u['user_id'], 'Reopened: ' . it_text($b['reason'], 500)]);
    } else db()->prepare("UPDATE it_tickets SET status = 'Closed', closed_at = NOW() WHERE id = ?")->execute([$id]);
    return it_detail($u, $id);
}

function it_cancel(array $u, int $id, array $b): array {
    $t = it_row($u, $id);
    if (!in_array($t['status'], ['New', 'Assigned'], true)) error_response('Only a ticket nobody started can be cancelled (this one is ' . $t['status'] . ')', 409);
    if ((int)$t['requester_id'] !== (int)$u['user_id'] && !it_can($u, 'manage')) error_response('Only the requester or the IT manager can cancel', 403);
    if (!it_text($b['reason'] ?? null)) error_response('A reason is required', 422);
    db()->prepare("UPDATE it_tickets SET status = 'Cancelled', closed_at = NOW() WHERE id = ?")->execute([$id]);
    db()->prepare('INSERT INTO it_ticket_comments (ticket_id, user_id, body) VALUES (?,?,?)')->execute([$id, $u['user_id'], 'Cancelled: ' . it_text($b['reason'], 500)]);
    return it_detail($u, $id);
}

function it_summary(array $u): array {
    $cid = (int)$u['company_id'];
    it_auto_close($cid);
    $own = it_staff($u) ? '' : ' AND requester_id = ' . (int)$u['user_id'];
    $w = 'company_id = ?' . branch_scope_sql('branch_id') . $own;
    $st = db()->prepare("SELECT status, COUNT(*) AS n FROM it_tickets WHERE $w GROUP BY status"); $st->execute([$cid]);
    $by = []; foreach ($st->fetchAll() as $r) $by[$r['status']] = (int)$r['n'];
    $ov = db()->prepare("SELECT COUNT(*) FROM it_tickets WHERE $w AND status IN ('New','Assigned','In_Progress','Waiting') AND due_at < NOW()"); $ov->execute([$cid]);
    $av = db()->prepare("SELECT AVG(GREATEST(0, TIMESTAMPDIFF(MINUTE, created_at, resolved_at))) FROM it_tickets WHERE $w AND resolved_at IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)"); $av->execute([$cid]);
    $fr = db()->prepare("SELECT AVG(GREATEST(0, TIMESTAMPDIFF(MINUTE, created_at, first_response_at))) FROM it_tickets WHERE $w AND first_response_at IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)"); $fr->execute([$cid]);
    $cat = db()->prepare("SELECT category, COUNT(*) AS n FROM it_tickets WHERE $w AND status NOT IN ('Cancelled') GROUP BY category ORDER BY n DESC"); $cat->execute([$cid]);
    $av = $av->fetchColumn(); $fr = $fr->fetchColumn();
    $open = ($by['New'] ?? 0) + ($by['Assigned'] ?? 0) + ($by['In_Progress'] ?? 0) + ($by['Waiting'] ?? 0);
    $aw = db()->prepare("SELECT COUNT(*) FROM it_assets WHERE company_id = ? AND warranty_end IS NOT NULL AND status <> 'Retired' AND warranty_end <= DATE_ADD(CURDATE(), INTERVAL ? DAY)" . branch_scope_sql('branch_id'));
    $aw->execute([$cid, (int)wf_setting($cid, 'it.warranty_warning_days', '60')]);
    $as = db()->prepare("SELECT COUNT(*) FROM it_assets WHERE company_id = ? AND status <> 'Retired'" . branch_scope_sql('branch_id')); $as->execute([$cid]);
    return ['by_status' => $by, 'open' => $open, 'overdue' => (int)$ov->fetchColumn(), 'avg_resolution_hours' => $av !== null ? round((float)$av / 60, 1) : null, 'avg_first_response_minutes' => $fr !== null ? (int)round((float)$fr) : null,
            'by_category' => $cat->fetchAll(), 'assets' => (int)$as->fetchColumn(), 'warranty_expiring' => (int)$aw->fetchColumn(), 'portal_url' => wf_setting($cid, 'it.portal_url', ''), 'is_it' => it_staff($u)];
}

function it_team(int $cid): array {
    $q = db()->prepare("SELECT u.id, u.name FROM users u WHERE u.company_id = ? AND u.is_active = 1 AND EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = u.role_id AND rp.permission IN ('*','it-tickets.work','it-tickets.*','it-tickets.manage')) ORDER BY u.name");
    $q->execute([$cid]);
    return $q->fetchAll();
}
