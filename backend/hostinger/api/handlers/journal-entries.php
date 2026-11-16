<?php
if ($GLOBALS['method'] !== 'GET') error_response('Read only: the ledger is written by the finance engine (gl-entries, ap-invoices, ap-payments)', 405);
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'fin_journal_entries',
    'search' => ['number', 'description'],
    'filters' => ['status'],
    'order' => 'date DESC',
    'prefix' => 'JV',
    'child' => [
        'key' => 'lines',
        'table' => 'fin_journal_lines',
        'fk' => 'journal_entry_id',
    ],
]);
