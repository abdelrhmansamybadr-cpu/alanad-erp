import { NavLink } from "react-router";
import { useI18n } from "../../context/I18nContext";
import { useAccess } from "../../access";

const TABS: [string, string, string, boolean][] = [
  ["/accounting", "Overview", "نظرة عامة", true],
  ["/accounting/invoices", "Invoices", "فواتير الموردين", false],
  ["/accounting/payments", "Payments", "المدفوعات", false],
  ["/accounting/ledger", "Journal & accounts", "القيود والحسابات", false],
  ["/accounting/reports", "Reports", "التقارير", false],
];

export function FinanceNav() {
  const { isRTL } = useI18n();
  const { canOpen } = useAccess();
  return (
    <nav className="flex gap-1 overflow-x-auto border-b pb-3" aria-label="finance">
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

export interface Invoice {
  id: number; number: string; supplier_invoice_no: string; invoice_date: string; due_date: string; status: string; match_status: string | null;
  subtotal: number; vat_amount: number; total: number; paid_amount: number; supplier_name: string; supplier_id: number; po_number: string; purchase_order_id: number;
  open_amount: number; overdue: boolean;
}
export interface InvoiceLine {
  id: number; item_id: number; item_name: string; item_name_ar: string | null; qty_kg: number; price: number; amount: number;
  po_price: number | null; received_kg: number | null; billable_kg: number | null; issue: string | null;
}
export interface InvoiceDetailData extends Invoice {
  vat_rate_pct: number; match_note: string | null; decision_note: string | null; reject_reason_code: string | null; entry_number: string | null;
  created_by: number; notes: string | null; overdue_days: number; lines: InvoiceLine[];
}
export interface Reason { code: string; gate: string; name_en: string; name_ar: string | null }

/** reject reasons of the finance gate (finance first, then the general ones), one per code */
export function financeReasons(all: Reason[] | null): Reason[] {
  const m = new Map<string, Reason>();
  [...(all ?? [])].sort((a) => (a.gate === "finance" ? -1 : 1)).forEach((r) => { if ((r.gate === "finance" || r.gate === "general") && !m.has(r.code)) m.set(r.code, r); });
  return [...m.values()];
}
