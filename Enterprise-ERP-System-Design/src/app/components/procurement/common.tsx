import { NavLink } from "react-router";
import { Badge } from "../ui/badge";
import { useI18n } from "../../context/I18nContext";
import { useAccess } from "../../access";

const TABS: [string, string, string, boolean][] = [
  ["/procurement", "Overview", "نظرة عامة", true],
  ["/procurement/requests", "Requests", "طلبات الشراء", false],
  ["/procurement/purchase-orders", "Orders", "أوامر الشراء", false],
  ["/procurement/market", "Market buying", "الشراء من السوق", false],
  ["/procurement/entry-permits", "Entry permits", "أذونات الدخول", false],
  ["/procurement/fleet", "Fleet & gates", "الأسطول والبوابات", false],
  ["/procurement/suppliers", "Suppliers", "الموردون", false],
  ["/procurement/prices", "Prices", "أسعار الموردين", false],
  ["/inventory/receiving", "Receiving", "الاستلام والجودة", false],
];

export function ProcurementNav() {
  const { isRTL } = useI18n();
  const { canOpen } = useAccess();
  return (
    <nav className="flex gap-1 overflow-x-auto border-b pb-3" aria-label="procurement">
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

export interface PRLine { id?: number; item_id: number; item_name?: string; item_name_ar?: string | null; qty: number; note?: string | null }
export interface PR {
  id: number; number: string; date: string; needed_by: string | null; requested_by: number; requested_by_name: string | null;
  status: string; reject_reason: string | null; notes: string | null; line_count?: number; lines?: PRLine[];
}
export interface POLine { id: number; item_id: number | null; item_name: string | null; qty: number; uom: string | null; rate: number; amount: number }
export interface PO {
  id: number; number: string; date: string; expected_date: string | null; supplier_id: number | null; supplier_name: string | null;
  currency: string; subtotal: number; tax_amount: number; total: number; status: string; notes: string | null; lines?: POLine[];
}
export interface Supplier {
  id: number; code: string | null; name: string; email: string | null; phone: string | null; mobile: string | null; tax_id: string | null;
  supplier_group: string | null; currency: string; payment_terms: string | null; address: string | null; city: string | null; is_active: number | boolean;
}

const STATUS: Record<string, [string, string, string]> = {
  Draft: ["Draft", "مسودة", "bg-slate-500 hover:bg-slate-500"],
  Pending: ["Awaiting approval", "بانتظار الاعتماد", "bg-amber-500 hover:bg-amber-500"],
  Submitted: ["Awaiting approval", "بانتظار الاعتماد", "bg-amber-500 hover:bg-amber-500"],
  Approved: ["Approved", "معتمد", "bg-emerald-600 hover:bg-emerald-600"],
  Rejected: ["Rejected", "مرفوض", "bg-red-600 hover:bg-red-600"],
  Converted: ["Converted to order", "حُوّل إلى أمر شراء", "bg-primary hover:bg-primary"],
  Received: ["Received", "تم الاستلام", "bg-teal-700 hover:bg-teal-700"],
  Arrived: ["At the gate", "عند البوابة", "bg-slate-600 hover:bg-slate-600"],
  Temp_Hold: ["Temperature hold", "حجز حرارة", "bg-red-600 hover:bg-red-600"],
  Checking: ["Quality check", "فحص الجودة", "bg-amber-500 hover:bg-amber-500"],
  Ready: ["Ready to store", "جاهز للتخزين", "bg-emerald-600 hover:bg-emerald-600"],
  Stored: ["Stored", "تم التخزين", "bg-teal-700 hover:bg-teal-700"],
  On_Hold: ["On hold (match differs)", "معلّقة (فروقات)", "bg-red-600 hover:bg-red-600"],
  Posted: ["Posted", "مُرحَّل", "bg-teal-700 hover:bg-teal-700"],
  Reversed: ["Reversed", "معكوس", "bg-slate-500 hover:bg-slate-500"],
  In_Progress: ["In progress", "قيد التنفيذ", "bg-blue-600 hover:bg-blue-600"],
  Awaiting_Inspection: ["Awaiting inspection", "بانتظار الفحص النهائي", "bg-amber-500 hover:bg-amber-500"],
  Completed: ["Completed", "مكتمل", "bg-teal-700 hover:bg-teal-700"],
  Done: ["Done", "تم", "bg-teal-700 hover:bg-teal-700"],
  Returned: ["Returned by Finance", "أعادتها المالية", "bg-red-600 hover:bg-red-600"],
  Cash_Issued: ["Cash with buyer", "النقد مع المشتري", "bg-blue-600 hover:bg-blue-600"],
  Settlement_Submitted: ["Settlement awaiting Finance", "التسوية بانتظار المالية", "bg-amber-500 hover:bg-amber-500"],
  Settled: ["Settled", "تمت التسوية", "bg-teal-700 hover:bg-teal-700"],
  Pass: ["Passed", "مقبول", "bg-emerald-600 hover:bg-emerald-600"],
  Fail: ["Failed", "مرفوض", "bg-red-600 hover:bg-red-600"],
  Requested: ["Awaiting the store", "بانتظار المخزن", "bg-amber-500 hover:bg-amber-500"],
  Store_Approved: ["Space approved", "المساحة معتمدة", "bg-emerald-600 hover:bg-emerald-600"],
  Issued: ["Permit issued", "الإذن صادر", "bg-blue-600 hover:bg-blue-600"],
  Entered: ["Truck inside", "الشاحنة بالداخل", "bg-teal-700 hover:bg-teal-700"],
  To_Sorting: ["Waiting for sorting", "بانتظار الفرز", "bg-violet-600 hover:bg-violet-600"],
  Reserved: ["Stock reserved", "تم حجز المخزون", "bg-teal-700 hover:bg-teal-700"],
  Short: ["Short of stock", "ينقصه مخزون", "bg-amber-500 hover:bg-amber-500"],
  Cancelled: ["Cancelled", "ملغى", "bg-slate-400 hover:bg-slate-400"],
};
export function StatusBadge({ status, isRTL }: { status: string; isRTL: boolean }) {
  const [en, ar, cls] = STATUS[status] ?? [status, status, ""];
  return <Badge className={cls}>{isRTL ? ar : en}</Badge>;
}

export const money = (n: number | string | null | undefined, cur = "SAR") =>
  `${Number(n ?? 0).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${cur}`;
