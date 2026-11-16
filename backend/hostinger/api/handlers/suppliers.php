<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'suppliers',
    'search' => ['name', 'email', 'code'],
    'filters' => [],
    'order' => 'name',
    'related' => [
        'purchase_orders' => 'SELECT id, number, date, total, status FROM purchase_orders WHERE supplier_id = ? AND company_id = ? ORDER BY date DESC LIMIT 20',
    ],
]);
