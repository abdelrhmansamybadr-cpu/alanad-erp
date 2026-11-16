<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'items',
    'search' => ['name', 'code', 'name_ar', 'barcode'],
    'filters' => ['item_group'],
    'order' => 'name',
    'related' => [
        'invoice_usage' => 'SELECT i.id, i.number, i.date, il.qty, il.rate, il.amount FROM invoice_lines il JOIN invoices i ON i.id = il.invoice_id WHERE il.item_id = ? AND i.company_id = ? ORDER BY i.date DESC LIMIT 20',
        'po_usage' => 'SELECT p.id, p.number, p.date, pl.qty, pl.rate, pl.amount FROM purchase_order_lines pl JOIN purchase_orders p ON p.id = pl.purchase_order_id WHERE pl.item_id = ? AND p.company_id = ? ORDER BY p.date DESC LIMIT 20',
    ],
]);
