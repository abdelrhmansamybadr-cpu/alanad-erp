import { NavLink } from "react-router";
import { useI18n } from "../../context/I18nContext";
import { useAccess } from "../../access";

const TABS: [string, string, string, boolean][] = [
  ["/inventory", "Overview", "نظرة عامة", true],
  ["/inventory/products", "Items", "الأصناف", false],
  ["/inventory/warehouses", "Cold rooms", "المستودعات والتلاجات", false],
  ["/inventory/use-first", "Use first", "استخدم أولاً", false],
  ["/inventory/adjustments", "Operations", "عمليات المخزون", false],
  ["/inventory/movements", "Ledger", "سجل الحركة", false],
  ["/inventory/receiving", "Receiving", "الاستلام والجودة", false],
  ["/inventory/sorting", "Sorting", "الفرز", false],
];

export function InventoryNav() {
  const { isRTL } = useI18n();
  const { canOpen } = useAccess();
  return (
    <nav className="flex gap-1 overflow-x-auto border-b pb-3" aria-label="inventory">
      {TABS.filter(([to]) => canOpen(to)).map(([to, en, ar, end]) => (
        <NavLink
          key={to}
          to={to}
          end={end}
          className={({ isActive }) =>
            `px-3 py-1.5 rounded-md text-sm whitespace-nowrap ${isActive ? "bg-primary text-primary-foreground font-semibold" : "text-muted-foreground hover:bg-muted"}`
          }
        >
          {isRTL ? ar : en}
        </NavLink>
      ))}
    </nav>
  );
}
