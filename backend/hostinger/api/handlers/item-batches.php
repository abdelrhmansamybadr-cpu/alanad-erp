<?php
// Batches are created by /stock/receive; this endpoint is read-only: GET /item-batches[?item_id=&expiring_days=] and /item-batches/{id}.
$cid = (int)$GLOBALS['company_id']; $id = $GLOBALS['id'];
if ($GLOBALS['method'] !== 'GET') error_response('Batches are created by receiving stock', 405);
$sel = "SELECT b.*, i.name AS item_name, i.name_ar AS item_name_ar, DATEDIFF(b.expiry_date, CURDATE()) AS days_left,
               COALESCE((SELECT ROUND(SUM(l.qty),3) FROM stock_ledger l WHERE l.batch_id = b.id AND l.company_id = b.company_id), 0) AS on_hand
        FROM item_batches b JOIN items i ON i.id = b.item_id WHERE b.company_id = ?";
if ($id) {
    $q = db()->prepare("$sel AND b.id = ?"); $q->execute([$cid, $id]); $r = $q->fetch();
    if (!$r) error_response('Not found', 404);
    $l = db()->prepare('SELECT id, movement, qty, cold_room_id, rack, position, reason, user_id, created_at FROM stock_ledger WHERE batch_id = ? AND company_id = ? ORDER BY id'); $l->execute([$id, $cid]);
    $r['movements'] = $l->fetchAll(); json_response($r);
}
$p = [$cid];
if (!empty($_GET['item_id'])) { $sel .= ' AND b.item_id = ?'; $p[] = (int)$_GET['item_id']; }
if (isset($_GET['expiring_days'])) { $sel .= ' AND b.expiry_date <= (CURDATE() + INTERVAL ? DAY)'; $p[] = (int)$_GET['expiring_days']; }
$q = db()->prepare("$sel ORDER BY b.expiry_date ASC LIMIT 500"); $q->execute($p);
json_response($q->fetchAll());
