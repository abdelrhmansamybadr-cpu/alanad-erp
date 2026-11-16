import { createBrowserRouter, Outlet } from "react-router";
import { MainLayout } from "./layouts/MainLayout";
import { AuthLayout } from "./layouts/AuthLayout";
import { ModuleProvider } from "./context/ModuleContext";
import { UserProvider } from "./context/UserContext";
import { I18nProvider } from "./context/I18nContext";
import { AuthProvider } from "./context/AuthContext";
import { RequireAuth } from "./components/RequireAuth";

// Auth Pages
import { LoginPage } from "./pages/auth/LoginPage";
import { ChangePasswordPage } from "./pages/auth/ChangePasswordPage";

// Main Pages
import { DashboardPage } from "./pages/DashboardPage";

// Inventory Module
import { InventoryDashboard } from "./pages/inventory/InventoryDashboard";
import { ProductList } from "./pages/inventory/ProductList";
import { ProductDetail } from "./pages/inventory/ProductDetail";
import { WarehouseList } from "./pages/inventory/WarehouseList";
import { StockMovements } from "./pages/inventory/StockMovements";
import { StockAdjustments } from "./pages/inventory/StockAdjustments";
import { GoodsReceipts } from "./pages/inventory/GoodsReceipts";
import { UseFirst } from "./pages/inventory/UseFirst";
import { DeliveryDetail } from "./pages/inventory/DeliveryDetail";

// Procurement Module
import { ProcurementDashboard } from "./pages/procurement/ProcurementDashboard";
import { PurchaseRequestList } from "./pages/procurement/PurchaseRequestList";
import { PurchaseRequestDetail } from "./pages/procurement/PurchaseRequestDetail";
import { SupplierPrices } from "./pages/procurement/SupplierPrices";
import { PurchaseOrderList } from "./pages/procurement/PurchaseOrderList";
import { PurchaseOrderDetail } from "./pages/procurement/PurchaseOrderDetail";
import { SupplierList } from "./pages/procurement/SupplierList";
import { EntryPermits } from "./pages/procurement/EntryPermits";
import { FleetGates } from "./pages/procurement/FleetGates";
import { SortingList } from "./pages/inventory/SortingList";
import { SortingDetail } from "./pages/inventory/SortingDetail";
import { PackOrders } from "./pages/production/PackOrders";
import { PackOrderDetail } from "./pages/production/PackOrderDetail";
import { Shipments } from "./pages/sales/Shipments";
import { ShipmentDetail } from "./pages/sales/ShipmentDetail";
import { Invoices as SalesInvoices, InvoiceDetail as SalesInvoiceDetail } from "./pages/sales/Invoices";
import { SalesPrices, Recalls } from "./pages/sales/PricesRecalls";
import { HrHome } from "./pages/hr/HrHome";
import { Employees as HrEmployees } from "./pages/hr/Employees";
import { Leave as HrLeave } from "./pages/hr/Leave";
import { MyLeave } from "./pages/my/MyLeave";
import { MyProfile } from "./pages/my/MyProfile";
import { Expiry as HrExpiry } from "./pages/hr/Expiry";
import { ItHome } from "./pages/it/ItHome";
import { Tickets as ItTickets } from "./pages/it/Tickets";
import { TicketDetail as ItTicketDetail } from "./pages/it/TicketDetail";
import { Assets as ItAssets } from "./pages/it/Assets";
import { FactoryReports } from "./pages/reports/FactoryReports";
import { ErpSettings } from "./pages/admin/ErpSettings";
import { MarketPurchases } from "./pages/procurement/MarketPurchases";
import { MarketPurchaseDetail } from "./pages/procurement/MarketPurchaseDetail";

// Sales Module

// Projects Module

// HR Module

// Accounting Module

// Reports

// Settings
import { SettingsPage } from "./pages/SettingsPage";

// Admin
import { AdminDashboard } from "./pages/admin/AdminDashboard";
import { ModuleManagement } from "./pages/admin/ModuleManagement";
import { TenantManagement } from "./pages/admin/TenantManagement";
import { RoleManagement } from "./pages/admin/RoleManagement";
import { AuditLogs } from "./pages/admin/AuditLogs";
import { BranchManagement } from "./pages/admin/BranchManagement";
import { ProcessDesigner } from "./pages/admin/ProcessDesigner";
import { ClientOrders } from "./pages/sales/ClientOrders";
import { ClientOrderDetail } from "./pages/sales/ClientOrderDetail";

// Download Center
import { DownloadCenter } from "./pages/DownloadCenter";

// Design System
import { DesignSystem } from "./pages/DesignSystem";

// Mobile

// Client Proposal (Arabic)
import { ClientProposalAR } from "./pages/ClientProposalAR";

// 404
import { NotFound } from "./pages/NotFound";
import { ComingSoon } from "./pages/ComingSoon";
import { FinanceDashboard } from "./pages/finance/FinanceDashboard";
import { InvoiceList } from "./pages/finance/InvoiceList";
import { InvoiceDetail } from "./pages/finance/InvoiceDetail";
import { PaymentList } from "./pages/finance/PaymentList";
import { LedgerPage } from "./pages/finance/LedgerPage";
import { FinanceReports } from "./pages/finance/FinanceReports";
import { ProductionDashboard } from "./pages/production/ProductionDashboard";
import { OrderDetail } from "./pages/production/OrderDetail";
import { SpecList } from "./pages/production/SpecList";
import { FoodSafety } from "./pages/production/FoodSafety";
import { ProductionReports } from "./pages/production/ProductionReports";

// Root provider wrapper — lives inside the router tree so HMR keeps
// the context instance in sync with every consumer (MainLayout, pages, etc.)
function ProvidersLayout() {
  return (
    <I18nProvider>
      <UserProvider>
        <AuthProvider>
        <ModuleProvider>
          <Outlet />
        </ModuleProvider>
        </AuthProvider>
      </UserProvider>
    </I18nProvider>
  );
}

export const router = createBrowserRouter([
  {
    // Invisible wrapper that injects contexts for every route
    Component: ProvidersLayout,
    children: [
      {
        path: "/auth",
        Component: AuthLayout,
        children: [
          { path: "login", Component: LoginPage },
          { path: "change-password", Component: ChangePasswordPage },
        ],
      },
      {
        // everything below needs a signed-in user (confirmed by the server)
        Component: RequireAuth,
        children: [
      {
        path: "/",
        Component: MainLayout,
        children: [
          { index: true, Component: DashboardPage },
          
          // Inventory
          { path: "inventory", Component: InventoryDashboard },
          { path: "inventory/products", Component: ProductList },
          { path: "inventory/products/:id", Component: ProductDetail },
          { path: "inventory/warehouses", Component: WarehouseList },
          { path: "inventory/movements", Component: StockMovements },
          { path: "inventory/adjustments", Component: StockAdjustments },
          { path: "inventory/receiving", Component: GoodsReceipts },
          { path: "inventory/receiving/:id", Component: DeliveryDetail },
          { path: "inventory/sorting", Component: SortingList },
          { path: "inventory/sorting/:id", Component: SortingDetail },
          { path: "inventory/use-first", Component: UseFirst },
          
          // Procurement
          { path: "procurement", Component: ProcurementDashboard },
          { path: "procurement/requests", Component: PurchaseRequestList },
          { path: "procurement/requests/:id", Component: PurchaseRequestDetail },
          { path: "procurement/prices", Component: SupplierPrices },
          { path: "procurement/market", Component: MarketPurchases },
          { path: "procurement/entry-permits", Component: EntryPermits },
          { path: "procurement/fleet", Component: FleetGates },
          { path: "procurement/market/:id", Component: MarketPurchaseDetail },
          { path: "procurement/purchase-orders", Component: PurchaseOrderList },
          { path: "procurement/purchase-orders/:id", Component: PurchaseOrderDetail },
          { path: "procurement/suppliers", Component: SupplierList },
          
          // Modules whose backend is built in a later phase of the plan
          { path: "sales", Component: ClientOrders },
          { path: "sales/orders/:id", Component: ClientOrderDetail },
          { path: "sales/shipments", Component: Shipments },
          { path: "sales/shipments/:id", Component: ShipmentDetail },
          { path: "sales/invoices", Component: SalesInvoices },
          { path: "sales/invoices/:id", Component: SalesInvoiceDetail },
          { path: "sales/prices", Component: SalesPrices },
          { path: "sales/recalls", Component: Recalls },
          { path: "sales/*", Component: ComingSoon },
          { path: "projects/*", Component: ComingSoon },
          { path: "hr", Component: HrHome },
          { path: "hr/employees", Component: HrEmployees },
          { path: "my/leave", Component: MyLeave },
          { path: "my/profile", Component: MyProfile },
          { path: "hr/leave", Component: HrLeave },
          { path: "hr/expiry", Component: HrExpiry },
          { path: "hr/*", Component: ComingSoon },
          { path: "it", Component: ItHome },
          { path: "it/tickets", Component: ItTickets },
          { path: "it/tickets/:id", Component: ItTicketDetail },
          { path: "it/assets", Component: ItAssets },
          // Production and cold chain (phase 5)
          { path: "production", Component: ProductionDashboard },
          { path: "production/packing", Component: PackOrders },
          { path: "production/packing/:id", Component: PackOrderDetail },
          { path: "production/orders/:id", Component: OrderDetail },
          { path: "production/specs", Component: SpecList },
          { path: "production/food-safety", Component: FoodSafety },
          { path: "production/reports", Component: ProductionReports },

          // Finance (phase 4)
          { path: "accounting", Component: FinanceDashboard },
          { path: "accounting/invoices", Component: InvoiceList },
          { path: "accounting/invoices/:id", Component: InvoiceDetail },
          { path: "accounting/payments", Component: PaymentList },
          { path: "accounting/ledger", Component: LedgerPage },
          { path: "accounting/reports", Component: FinanceReports },
          { path: "reports", Component: FactoryReports },
          { path: "reports/*", Component: ComingSoon },

          // Settings
          { path: "settings", Component: SettingsPage },
          
          // Admin
          { path: "admin", Component: AdminDashboard },
          { path: "admin/modules", Component: ModuleManagement },
          { path: "admin/tenants", Component: TenantManagement },
          { path: "admin/branches", Component: BranchManagement },
          { path: "admin/designer", Component: ProcessDesigner },
          { path: "admin/settings", Component: ErpSettings },
          { path: "admin/roles", Component: RoleManagement },
          { path: "admin/audit-logs", Component: AuditLogs },
          
          // Download Center
          { path: "downloads", Component: DownloadCenter },
          
          // Design System
          { path: "design-system", Component: DesignSystem },
          
          // Client Proposal Arabic
          { path: "client-proposal-ar", Component: ClientProposalAR },
          
          // 404
          { path: "*", Component: NotFound },
        ],
      },
        ],
      },
    ],
  },
], { basename: import.meta.env.BASE_URL.replace(/\/$/, "") || undefined });
