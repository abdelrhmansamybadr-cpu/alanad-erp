<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'quotations',
    'search' => ['number', 'customer_name'],
    'filters' => ['status'],
    'order' => 'created_at DESC',
    'dates' => true,
    'prefix' => 'QUO',
    'child' => [
        'key' => 'lines',
        'table' => 'quotation_lines',
        'fk' => 'quotation_id',
    ],
]);
