import { useAuth } from "./context/AuthContext";

/**
 * Two layers of tabs.
 *  1. FIXED for every signed-in person (the "My services" section): my day, my leave, my IT tickets, my profile, settings.
 *     They need no permission and only ever show the person's OWN data.
 *  2. BY ROLE (the "My work" section): a page is open only to a person who may VIEW the list it reads. This is the same
 *     rule the server applies, so a hidden tab can never be reached by typing its address (the layout redirects home).
 * The longest matching prefix wins. A path that is not listed is open to everybody (home, /my/*, settings, my tickets).
 */
const PATH_RESOURCE: [string, string, string?][] = [
  ["/inventory/products", "items"], ["/inventory/warehouses", "cold-rooms"], ["/inventory/use-first", "stock"], ["/inventory/adjustments", "stock"],
  ["/inventory/movements", "stock"], ["/inventory/receiving", "deliveries"], ["/inventory/sorting", "sorting-runs"], ["/inventory", "stock"],
  ["/procurement/requests", "purchase-requests"], ["/procurement/purchase-orders", "purchase-orders"], ["/procurement/market", "market-purchases"],
  ["/procurement/entry-permits", "entry-permits"], ["/procurement/fleet", "vehicles"], ["/procurement/suppliers", "suppliers"],
  ["/procurement/prices", "supplier-prices"], ["/procurement", "purchase-orders"],
  ["/production/specs", "prep-specs"], ["/production/packing", "pack-orders"], ["/production/food-safety", "haccp-logs"],
  ["/production/reports", "production-reports"], ["/production", "prep-orders"],
  ["/accounting/invoices", "ap-invoices"], ["/accounting/payments", "ap-payments"], ["/accounting/ledger", "gl-entries"],
  ["/accounting/reports", "finance-reports"], ["/accounting", "ap-invoices"],
  ["/sales/shipments", "shipments"], ["/sales/invoices", "sales-invoices"], ["/sales/prices", "sales-prices"], ["/sales/recalls", "recalls"], ["/sales", "client-orders"],
  ["/hr/employees", "employees"], ["/hr/leave", "hr-leaves"], ["/hr/expiry", "hr-expiry"], ["/hr", "hr-expiry"],
  ["/it/assets", "it-assets"],
  ["/reports", "factory-reports"], ["/projects", "projects"],
  ["/admin", "admin", "manage"], ["/downloads", "admin", "manage"], ["/design-system", "admin", "manage"], ["/client-proposal-ar", "admin", "manage"],
];

function rule(path: string): [string, string] | null {
  let best: [string, string, string?] | null = null;
  for (const e of PATH_RESOURCE) {
    if ((path === e[0] || path.startsWith(e[0] + "/")) && (!best || e[0].length > best[0].length)) best = e;
  }
  return best ? [best[1], best[2] ?? "view"] : null;
}

/** The tabs of each work module, in the order of the module's tab bar. The sidebar opens the first one the person may see. */
export const MODULE_TABS: Record<string, string[]> = {
  inventory: ["/inventory", "/inventory/products", "/inventory/warehouses", "/inventory/use-first", "/inventory/adjustments", "/inventory/movements", "/inventory/receiving", "/inventory/sorting"],
  procurement: ["/procurement", "/procurement/requests", "/procurement/purchase-orders", "/procurement/market", "/procurement/entry-permits", "/procurement/fleet", "/procurement/suppliers", "/procurement/prices"],
  production: ["/production", "/production/specs", "/production/packing", "/production/food-safety", "/production/reports"],
  accounting: ["/accounting", "/accounting/invoices", "/accounting/payments", "/accounting/ledger", "/accounting/reports"],
  sales: ["/sales", "/sales/shipments", "/sales/invoices", "/sales/prices", "/sales/recalls"],
  hr: ["/hr", "/hr/employees", "/hr/leave", "/hr/expiry"],
  reports: ["/reports"],
};

export function useAccess() {
  const { can, me } = useAuth();
  /** may this person open this page? (the same check as the server, on the list the page reads) */
  const canOpen = (path: string): boolean => {
    const r = rule(path);
    return r ? can(r[0], r[1]) : true;
  };
  /** the first tab of a module this person may open, or null when the module is not theirs */
  const firstTab = (module: string): string | null => (MODULE_TABS[module] ?? []).find(canOpen) ?? null;
  /** IT staff work the whole queue; everybody else only has "my tickets" in the fixed section */
  const itStaff = can("it-tickets", "work") || can("it-tickets", "manage");
  return { canOpen, firstTab, itStaff, hasEmployee: !!me?.has_employee };
}
