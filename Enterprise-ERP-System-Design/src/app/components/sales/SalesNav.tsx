import { NavLink } from "react-router";
import { useI18n } from "../../context/I18nContext";
import { useAccess } from "../../access";

const TABS: [string, string, string, boolean][] = [
  ["/sales", "Client orders", "طلبات العملاء", true],
  ["/sales/shipments", "Shipments", "الشحنات", false],
  ["/sales/invoices", "Tax invoices", "الفواتير الضريبية", false],
  ["/sales/prices", "Prices", "الأسعار", false],
  ["/sales/recalls", "Recalls", "الاستدعاء", false],
];

export function SalesNav() {
  const { isRTL } = useI18n();
  const { canOpen } = useAccess();
  return (
    <nav className="flex gap-1 overflow-x-auto border-b pb-3" aria-label="sales">
      {TABS.filter(([to]) => canOpen(to)).map(([to, en, ar, end]) => (
        <NavLink key={to} to={to} end={end} className={({ isActive }) => `px-3 py-1.5 rounded-md text-sm whitespace-nowrap ${isActive ? "bg-primary text-primary-foreground font-semibold" : "text-muted-foreground hover:bg-muted"}`}>
          {isRTL ? ar : en}
        </NavLink>
      ))}
    </nav>
  );
}

const SH: Record<string, [string, string, string]> = {
  Draft: ["Being prepared", "قيد التجهيز", "bg-slate-500 hover:bg-slate-500"],
  Loaded: ["Loaded", "تم التحميل", "bg-blue-600 hover:bg-blue-600"],
  Permit_Issued: ["Exit permit issued", "إذن الخروج صادر", "bg-violet-600 hover:bg-violet-600"],
  Dispatched: ["Dispatched", "خرجت الشحنة", "bg-teal-700 hover:bg-teal-700"],
  Delivered: ["Delivered", "تم التسليم", "bg-emerald-700 hover:bg-emerald-700"],
  Cancelled: ["Cancelled", "ملغاة", "bg-slate-400 hover:bg-slate-400"],
};
export const shipmentStatus = (s: string, isRTL: boolean) => { const [en, ar] = SH[s] ?? [s, s]; return isRTL ? ar : en; };
export const shipmentClass = (s: string) => (SH[s] ?? ["", "", ""])[2];
