<?php
// Tax invoices (read-only; they are made from a shipment).
//   GET /sales-invoices[?search=]    GET /sales-invoices/{id}  (lines, QR as base64, hash chain)    GET /sales-invoices/{id}/xml  (the UBL 2.1 document, unsigned)
//   POST /sales-invoices/{id}/zatca-submit    clearance / reporting with ZATCA: refused until the company is onboarded (needs the compliance + production CSID)
require_once __DIR__ . '/../core/sales.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $method = $GLOBALS['method'];
$seg = $GLOBALS['parts'][1] ?? ''; $action = $GLOBALS['parts'][2] ?? '';
$id = ctype_digit((string)$seg) ? (int)$seg : 0;
function si_row(int $cid, int $id): array {
    $q = db()->prepare('SELECT * FROM sales_invoices WHERE id = ? AND company_id = ?'); $q->execute([$id, $cid]);
    $r = $q->fetch();
    if (!$r || !branch_in_scope($r['branch_id'] !== null ? (int)$r['branch_id'] : null)) error_response('Not found', 404);
    return $r;
}
if ($method === 'GET' && $id && $action === 'xml') {
    $r = si_row($cid, $id);
    header('Content-Type: application/xml; charset=utf-8'); header('Content-Disposition: inline; filename="' . $r['number'] . '.xml"');
    echo $r['xml']; exit;
}
if ($method === 'GET' && $id) {
    $r = si_row($cid, $id); unset($r['xml']);
    $l = db()->prepare('SELECT * FROM sales_invoice_lines WHERE invoice_id = ? ORDER BY id'); $l->execute([$id]);
    $r['lines'] = array_map(function ($x) { foreach (['kg', 'price_per_kg', 'amount'] as $f) $x[$f] = (float)$x[$f]; return $x; }, $l->fetchAll());
    foreach (['subtotal', 'vat_rate', 'vat_amount', 'total', 'cogs'] as $f) $r[$f] = (float)$r[$f];
    $s = db()->prepare('SELECT number, exit_permit_no FROM shipments WHERE id = ?'); $s->execute([$r['shipment_id']]); $r['shipment'] = $s->fetch() ?: null;
    $c = db()->prepare('SELECT name_en, legal_name, vat_number, cr_number, address FROM companies WHERE id = ?'); $c->execute([$cid]); $r['seller'] = $c->fetch() ?: null;
    json_response($r);
}
if ($method === 'POST' && $id && $action === 'zatca-submit') {
    si_row($cid, $id);
    error_response('This company is not onboarded with ZATCA yet: clearance / reporting needs the compliance and production certificates (CSID) from the Fatoora portal, and the invoice must be signed first. Nothing was sent.', 409);
}
if ($method === 'GET') {
    $w = 'i.company_id = ?' . branch_scope_sql('i.branch_id'); $p = [$cid];
    if (!empty($_GET['search'])) { $w .= ' AND (i.number LIKE ? OR i.customer_name LIKE ?)'; $p[] = '%' . $_GET['search'] . '%'; $p[] = '%' . $_GET['search'] . '%'; }
    $q = db()->prepare("SELECT i.id, i.number, i.customer_name, i.invoice_type, i.issued_at, i.subtotal, i.vat_amount, i.total, i.zatca_status, i.icv, s.number AS shipment_number
                        FROM sales_invoices i JOIN shipments s ON s.id = i.shipment_id WHERE $w ORDER BY i.id DESC LIMIT 300");
    $q->execute($p);
    json_response(array_map(function ($r) { foreach (['subtotal', 'vat_amount', 'total'] as $f) $r[$f] = (float)$r[$f]; return $r; }, $q->fetchAll()));
}
error_response('Not found', 404);
