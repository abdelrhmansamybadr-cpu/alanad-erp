<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'bom',
    'search' => ['number', 'item_name'],
    'filters' => [],
    'order' => 'created_at DESC',
    'prefix' => 'BOM',
    'child' => [
        'key' => 'items',
        'table' => 'bom_items',
        'fk' => 'bom_id',
    ],
]);
