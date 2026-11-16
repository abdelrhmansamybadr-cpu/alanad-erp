<?php
require_once getenv('ALANAD_CONFIG') ?: __DIR__ . '/config.php';   // tests point this at a throw-away DB
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/core/pipeline.php';

cors();

// Never show PHP errors or SQL to clients: log them and answer with a clean JSON 500.
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e) {
    error_log('[alanad-api] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['error' => 'Server error']);
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}

// Parse URI: strip /api prefix, split into parts
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = preg_replace('#^.*?/api#', '', $uri);
$uri    = trim($uri, '/');
$parts  = explode('/', $uri);

$resource = $parts[0] ?? '';
$id       = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
$method   = $_SERVER['REQUEST_METHOD'];
$body     = json_decode(file_get_contents('php://input'), true) ?? [];

$GLOBALS['id']     = $id;
$GLOBALS['method'] = $method;
$GLOBALS['body']   = $body;
$GLOBALS['parts']  = $parts;

$map = [
    'auth'               => 'handlers/auth.php',
    'dashboard'          => 'handlers/dashboard.php',
    'customers'          => 'handlers/customers.php',
    'suppliers'          => 'handlers/suppliers.php',
    'items'              => 'handlers/items.php',
    'invoices'           => 'handlers/invoices.php',
    'quotations'         => 'handlers/quotations.php',
    'sales-orders'       => 'handlers/sales-orders.php',
    'purchase-orders'    => 'handlers/purchase-orders.php',
    'payments'           => 'handlers/payments.php',
    'leads'              => 'handlers/leads.php',
    'deals'              => 'handlers/deals.php',
    'contacts'           => 'handlers/contacts.php',
    'organizations'      => 'handlers/organizations.php',
    'accounts'           => 'handlers/accounts.php',
    'journal-entries'    => 'handlers/journal-entries.php',
    'bank-accounts'      => 'handlers/bank-accounts.php',
    'currencies'         => 'handlers/currencies.php',
    'cost-centers'       => 'handlers/cost-centers.php',
    'fiscal-years'       => 'handlers/fiscal-years.php',
    'tax-templates'      => 'handlers/tax-templates.php',
    'payment-orders'     => 'handlers/payment-orders.php',
    'employees'          => 'handlers/employees.php',
    'departments'        => 'handlers/departments.php',
    'designations'       => 'handlers/designations.php',
    'employment-types'   => 'handlers/employment-types.php',
    'salary-components'  => 'handlers/salary-components.php',
    'salary-structures'  => 'handlers/salary-structures.php',
    'salary-slips'       => 'handlers/salary-slips.php',
    'leave-types'        => 'handlers/leave-types.php',
    'leave-requests'     => 'handlers/leave-requests.php',
    'holiday-lists'      => 'handlers/holiday-lists.php',
    'appraisals'         => 'handlers/appraisals.php',
    'promotions'         => 'handlers/promotions.php',
    'transfers'          => 'handlers/transfers.php',
    'separations'        => 'handlers/separations.php',
    'shift-types'        => 'handlers/shift-types.php',
    'job-applicants'     => 'handlers/job-applicants.php',
    'branches'           => 'handlers/branches.php',
    'item-groups'        => 'handlers/item-groups.php',
    'customer-groups'    => 'handlers/customer-groups.php',
    'supplier-groups'    => 'handlers/supplier-groups.php',
    'territories'        => 'handlers/territories.php',
    'payment-terms'      => 'handlers/payment-terms.php',
    'price-lists'        => 'handlers/price-lists.php',
    'uom'                => 'handlers/uom.php',
    'brands'             => 'handlers/brands.php',
    'sales-persons'      => 'handlers/sales-persons.php',
    'addresses'          => 'handlers/addresses.php',
    'terms-conditions'   => 'handlers/terms-conditions.php',
    'bom'                => 'handlers/bom.php',
    'work-orders'        => 'handlers/work-orders.php',
    'job-cards'          => 'handlers/job-cards.php',
    'assets'             => 'handlers/assets.php',
    'asset-movements'    => 'handlers/asset-movements.php',
    'projects'           => 'handlers/projects.php',
    'tasks'              => 'handlers/tasks.php',
    'timesheets'         => 'handlers/timesheets.php',
    'quality-procedures' => 'handlers/quality-procedures.php',
    'pos-profiles'       => 'handlers/pos-profiles.php',
    'pos-sessions'       => 'handlers/pos-sessions.php',
    'pos-invoices'       => 'handlers/pos-invoices.php',
    'website-pages'      => 'handlers/website-pages.php',
    'blog-posts'         => 'handlers/blog-posts.php',
    'users'              => 'handlers/users.php',
    // Phase 0 foundation
    'me'                 => 'handlers/me.php',
    'companies'          => 'handlers/companies.php',
    'roles'              => 'handlers/roles.php',
    'settings'           => 'handlers/settings.php',
    'modules'            => 'handlers/modules.php',
    'audit-logs'         => 'handlers/audit-logs.php',
    'workflows'          => 'handlers/workflows.php',
    'workflow-instances' => 'handlers/workflow-instances.php',
    'reject-reasons'     => 'handlers/reject-reasons.php',
    'notifications'      => 'handlers/notifications.php',
    // Phase 1: inventory and cold rooms
    'warehouses'         => 'handlers/warehouses.php',
    'cold-rooms'         => 'handlers/cold-rooms.php',
    'stock'              => 'handlers/stock.php',
    'item-batches'       => 'handlers/item-batches.php',
    'unit-conversions'   => 'handlers/unit-conversions.php',
    'alarms'             => 'handlers/alarms.php',
    'quality-holds'      => 'handlers/quality-holds.php',
    // Phase 2: purchasing
    'purchase-requests'  => 'handlers/purchase-requests.php',
    'supplier-prices'    => 'handlers/supplier-prices.php',
    'deliveries'         => 'handlers/deliveries.php',
    'ap-invoices'        => 'handlers/ap-invoices.php',
    'ap-payments'        => 'handlers/ap-payments.php',
    'gl-accounts'        => 'handlers/gl-accounts.php',
    'gl-entries'         => 'handlers/gl-entries.php',
    'finance-reports'    => 'handlers/finance-reports.php',
    'prep-specs'         => 'handlers/prep-specs.php',
    'prep-orders'        => 'handlers/prep-orders.php',
    'client-orders'      => 'handlers/client-orders.php',
    'market-purchases'   => 'handlers/market-purchases.php',
    'vehicles'           => 'handlers/vehicles.php',
    'gates'              => 'handlers/gates.php',
    'entry-permits'      => 'handlers/entry-permits.php',
    'sorting-runs'       => 'handlers/sorting-runs.php',
    'pack-specs'         => 'handlers/pack-specs.php',
    'pack-orders'        => 'handlers/pack-orders.php',
    'shipments'          => 'handlers/shipments.php',
    'sales-invoices'     => 'handlers/sales-invoices.php',
    'sales-prices'       => 'handlers/sales-prices.php',
    'recalls'            => 'handlers/recalls.php',
    'hr-leaves'          => 'handlers/hr-leaves.php',
    'my'                 => 'handlers/my.php',
    'review-notes'       => 'handlers/review-notes.php',
    'hr-expiry'          => 'handlers/hr-expiry.php',
    'it-tickets'         => 'handlers/it-tickets.php',
    'it-assets'          => 'handlers/it-assets.php',
    'factory-reports'    => 'handlers/factory-reports.php',
    'haccp-logs'         => 'handlers/haccp-logs.php',
    'production-reports' => 'handlers/production-reports.php',
    'company-branches'   => 'handlers/company-branches.php',
    'branch-access'      => 'handlers/branch-access.php',
];

if (isset($map[$resource])) {
    pipeline_run($resource, $method, $id, $parts);   // safety, auth, module switch, permissions, audit
    $file = __DIR__ . '/' . $map[$resource];
    if (file_exists($file)) {
        require $file;
    } else {
        error_response("Handler not found: $resource", 404);
    }
} else {
    error_response('Unknown resource', 404);
}
