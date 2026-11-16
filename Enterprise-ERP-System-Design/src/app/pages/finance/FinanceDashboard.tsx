import { Link } from "react-router";
import { Wallet, AlertTriangle, FileCheck2, Hourglass, PackageOpen, Receipt } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";
import { ErrorBox } from "../../components/inventory/common";
import { StatusBadge, money } from "../../components/procurement/common";
import { FinanceNav, Invoice } from "../../components/finance/common";

interface Summary { payables_open: number; overdue_amount: number; invoices_pending: number; invoices_on_hold: number; invoices_draft: number; payments_pending: number; goods_not_invoiced: number; vat_input_month: number; closed_until: string | null }

export function FinanceDashboard() {
  const { isRTL } = useI18n();
  const sm = useApi<Summary>("/finance-reports/summary");
  const inv = useApi<Invoice[]>("/ap-invoices");
  const s = sm.data;
  const waiting = inv.data?.filter((i) => ["Pending", "On_Hold"].includes(i.status)).slice(0, 6) ?? [];
  const kpis: [string, string, string | number, React.ComponentType<{ className?: string }>, string, boolean][] = [
    ["Payables open", "ذمم الموردين المفتوحة", s ? money(s.payables_open) : "…", Wallet, "/accounting/invoices?status=Approved", false],
    ["Overdue", "متأخرة السداد", s ? money(s.overdue_amount) : "…", AlertTriangle, "/accounting/reports", (s?.overdue_amount ?? 0) > 0],
    ["Invoices to approve", "فواتير بانتظار الاعتماد", s ? s.invoices_pending : "…", FileCheck2, "/accounting/invoices?status=Pending", false],
    ["On hold (match differs)", "فواتير معلّقة (فروقات)", s ? s.invoices_on_hold : "…", Hourglass, "/accounting/invoices?status=On_Hold", (s?.invoices_on_hold ?? 0) > 0],
    ["Payments to approve", "مدفوعات بانتظار الاعتماد", s ? s.payments_pending : "…", Receipt, "/accounting/payments", false],
    ["Goods received, not invoiced", "بضاعة مستلمة لم تُفوتَر", s ? money(s.goods_not_invoiced) : "…", PackageOpen, "/accounting/ledger", false],
  ];
  return (
    <div className="space-y-6">
      <FinanceNav />
      <div>
        <h1 className="text-3xl font-bold">{isRTL ? "المالية" : "Finance"}</h1>
        <p className="text-muted-foreground mt-1">
          {isRTL ? "المشتريات إلى الدفع: الاستلام يُرحَّل تلقائياً، والفاتورة تُطابَق مع الأمر والكمية المستلمة." : "Purchase to pay: receipts post automatically, invoices are matched to the order and the net weight received."}
          {s?.closed_until ? (isRTL ? ` الفترة مقفلة حتى ${s.closed_until}.` : ` Period closed up to ${s.closed_until}.`) : ""}
        </p>
      </div>
      <ErrorBox message={sm.error || inv.error} />
      <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
        {kpis.map(([en, ar, v, Icon, href, warn]) => (
          <Link key={en} to={href}>
            <Card className={`hover:shadow-md transition-shadow h-full ${warn ? "border-amber-300" : ""}`}>
              <CardContent className="pt-6 flex items-center justify-between gap-2">
                <div><p className="text-xs text-muted-foreground">{isRTL ? ar : en}</p><p className="text-xl font-bold mt-1" dir="ltr">{v}</p></div>
                <Icon className={`h-7 w-7 shrink-0 ${warn ? "text-amber-500" : "text-primary/60"}`} />
              </CardContent>
            </Card>
          </Link>
        ))}
      </div>
      <Card>
        <CardHeader className="pb-2"><CardTitle className="text-base">{isRTL ? "فواتير تنتظر قرارك" : "Invoices waiting for a decision"}</CardTitle></CardHeader>
        <CardContent className="space-y-2 text-sm">
          {waiting.map((i) => (
            <Link key={i.id} to={`/accounting/invoices/${i.id}`} className="flex justify-between items-center hover:bg-muted rounded px-2 py-1">
              <span><b dir="ltr">{i.number}</b> · {i.supplier_name}</span>
              <span className="flex items-center gap-3"><span dir="ltr" className="font-mono">{money(i.total)}</span><StatusBadge status={i.status} isRTL={isRTL} /></span>
            </Link>
          ))}
          {inv.data && waiting.length === 0 && <p className="text-muted-foreground">{isRTL ? "لا شيء معلق" : "Nothing waiting"}</p>}
        </CardContent>
      </Card>
    </div>
  );
}
