<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'sales_orders',
    'search' => ['number', 'customer_name'],
    'filters' => ['status'],
    'order' => 'created_at DESC',
    'dates' => true,
    'prefix' => 'SO',
    'child' => [
        'key' => 'lines',
        'table' => 'sales_order_lines',
        'fk' => 'sales_order_id',
    ],
]);
