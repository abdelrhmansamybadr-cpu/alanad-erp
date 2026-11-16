<?php
// Review mode (tester notes). Every signed-in person may leave notes and sees their OWN; administrators see all and close them.
//   GET  /review-notes[?status=Open]          my notes (admin: all notes of the company)
//   POST /review-notes {page_path, selector?, element_text?, kind, new_text?, note}
//   POST /review-notes/{id}/status {status: Open|Done|Rejected, done_note?}     [admin]
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $uid = (int)$u['user_id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$id = (int)($GLOBALS['parts'][1] ?? 0); $sub = $GLOBALS['parts'][2] ?? '';
$isAdmin = ($u['role'] ?? '') === 'admin';
$cut = function ($v, int $n) { $v = trim((string)($v ?? '')); return $v === '' ? null : mb_substr($v, 0, $n); };

if (!$id && $method === 'GET') {
    $w = 'company_id = ?'; $p = [$cid];
    if (!$isAdmin) { $w .= ' AND user_id = ?'; $p[] = $uid; }
    if (in_array($_GET['status'] ?? '', ['Open', 'Done', 'Rejected'], true)) { $w .= ' AND status = ?'; $p[] = $_GET['status']; }
    $q = db()->prepare("SELECT * FROM review_notes WHERE $w ORDER BY id DESC LIMIT 500"); $q->execute($p);
    json_response($q->fetchAll());
}
if (!$id && $method === 'POST') {
    $kind = $body['kind'] ?? 'comment';
    if (!in_array($kind, ['comment', 'rename', 'change', 'bug'], true)) error_response('Bad kind', 422);
    $path = $cut($body['page_path'] ?? null, 255); if (!$path) error_response('page_path is required', 422);
    $note = $cut($body['note'] ?? null, 4000); $new = $cut($body['new_text'] ?? null, 500);
    if (!$note && !$new) error_response('Write a note or the new text', 422);
    db()->prepare('INSERT INTO review_notes (company_id, user_id, user_name, page_path, selector, element_text, kind, new_text, note) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $uid, $cut($u['name'] ?? null, 120), $path, $cut($body['selector'] ?? null, 500), $cut($body['element_text'] ?? null, 300), $kind, $new, $note]);
    json_response(['id' => (int)db()->lastInsertId()], 201);
}
if ($id && $sub === 'status' && $method === 'POST') {
    if (!$isAdmin) error_response('Only an administrator can close notes', 403);
    $st = $body['status'] ?? ''; if (!in_array($st, ['Open', 'Done', 'Rejected'], true)) error_response('Bad status', 422);
    $q = db()->prepare('UPDATE review_notes SET status = ?, done_note = ?, resolved_at = ? WHERE id = ? AND company_id = ?');
    $q->execute([$st, $cut($body['done_note'] ?? null, 500), $st === 'Open' ? null : date('Y-m-d H:i:s'), $id, $cid]);
    json_response(['ok' => true]);
}
error_response('Not found', 404);
