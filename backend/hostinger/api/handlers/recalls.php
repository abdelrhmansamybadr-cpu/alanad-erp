<?php
// Recalls: a batch is found bad → its remaining stock is put on hold, and the system lists every pack order, shipment and client it may have reached.
//   GET /recalls    GET /recalls/{id}    GET /recalls/trace?batch_no=...  (the same picture without opening a recall)
//   POST /recalls {batch_id | batch_no, reason}     POST /recalls/{id}/close {note}   (stock.approve, not the person who opened it)
require_once __DIR__ . '/../core/sales.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$seg = $GLOBALS['parts'][1] ?? ''; $action = $GLOBALS['parts'][2] ?? '';
$id = ctype_digit((string)$seg) ? (int)$seg : 0;
if ($method === 'GET') {
    if ($id) json_response(rc_detail($cid, $id));
    if ($seg === 'trace') {
        $q = db()->prepare('SELECT id FROM item_batches WHERE company_id = ? AND batch_no = ?'); $q->execute([$cid, (string)($_GET['batch_no'] ?? '')]);
        $b = $q->fetchColumn(); if (!$b) error_response('Batch not found', 404);
        $tmp = db()->prepare("SELECT id FROM recalls WHERE company_id = ? AND batch_id = ? ORDER BY id DESC LIMIT 1"); $tmp->execute([$cid, $b]);
        if ($rid = $tmp->fetchColumn()) json_response(rc_detail($cid, (int)$rid));
        // no recall yet: build the trace on the fly
        $fake = ['id' => 0, 'number' => null, 'batch_id' => (int)$b, 'status' => null];
        $s = db()->prepare('SELECT COALESCE(SUM(qty),0) FROM stock_ledger WHERE company_id = ? AND batch_id = ?'); $s->execute([$cid, $b]);
        $fake['in_stock_kg'] = round((float)$s->fetchColumn(), 3);
        $o = db()->prepare('SELECT o.id, o.number, o.customer_name, p.kg, o.status FROM pack_order_batches p JOIN pack_orders o ON o.id = p.order_id WHERE p.batch_id = ? AND o.company_id = ? ORDER BY o.id'); $o->execute([$b, $cid]);
        $fake['pack_orders'] = $o->fetchAll(); $ids = array_column($fake['pack_orders'], 'id'); $fake['shipments'] = []; $fake['customers'] = [];
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $sh = db()->query("SELECT sh.id, sh.number, sh.customer_name, sh.status, sh.dispatched_at FROM shipments sh JOIN shipment_units su ON su.shipment_id = sh.id JOIN pack_units u ON u.id = su.unit_id WHERE u.order_id IN ($in) AND sh.status <> 'Cancelled' AND sh.company_id = " . (int)$cid . ' GROUP BY sh.id, sh.number, sh.customer_name, sh.status, sh.dispatched_at');
            $fake['shipments'] = $sh->fetchAll();
            $fake['customers'] = array_values(array_unique(array_column(array_filter($fake['shipments'], fn($x) => in_array($x['status'], ['Dispatched', 'Delivered'], true)), 'customer_name')));
        }
        json_response($fake);
    }
    $q = db()->prepare('SELECT r.id, r.number, r.status, r.reason, r.created_at, b.batch_no, i.name AS item_name FROM recalls r JOIN item_batches b ON b.id = r.batch_id JOIN items i ON i.id = b.item_id WHERE r.company_id = ? ORDER BY r.id DESC LIMIT 200');
    $q->execute([$cid]);
    json_response($q->fetchAll());
}
if ($method === 'POST' && !$id && $seg === '') json_response(rc_create($u, $body), 201);
if ($method === 'POST' && $id && $action === 'close') json_response(rc_close($u, $id, $body));
error_response('Not found', 404);
