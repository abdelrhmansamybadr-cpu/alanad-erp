<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'invoices',
    'search' => ['number', 'customer_name'],
    'filters' => ['status'],
    'order' => 'created_at DESC',
    'dates' => true,
    'prefix' => 'INV',
    'child' => [
        'key' => 'lines',
        'table' => 'invoice_lines',
        'fk' => 'invoice_id',
    ],
]);
