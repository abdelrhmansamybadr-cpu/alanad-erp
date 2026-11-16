import { Link } from "react-router";
import { ClipboardList, ShoppingCart, Truck, Lightbulb } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";
import { ErrorBox } from "../../components/inventory/common";
import { ProcurementNav, PR, PO, StatusBadge, money } from "../../components/procurement/common";

export function ProcurementDashboard() {
  const { isRTL } = useI18n();
  const prs = useApi<PR[]>("/purchase-requests");
  const pos = useApi<PO[]>("/purchase-orders");
  const sugg = useApi<unknown[]>("/purchase-requests/suggestions");

  const n = (rows: { status: string }[] | null, s: string) => (rows ? rows.filter((r) => r.status === s).length : "…");
  const kpis: [string, string, string | number, React.ComponentType<{ className?: string }>, string][] = [
    ["Requests awaiting approval", "طلبات بانتظار الاعتماد", n(prs.data, "Pending"), ClipboardList, "/procurement/requests?status=Pending"],
    ["Approved, no order yet", "معتمدة بلا أمر شراء", n(prs.data, "Approved"), ClipboardList, "/procurement/requests"],
    ["Orders awaiting approval", "أوامر بانتظار الاعتماد", n(pos.data, "Submitted"), ShoppingCart, "/procurement/purchase-orders"],
    ["Orders to receive", "أوامر بانتظار الاستلام", n(pos.data, "Approved"), Truck, "/inventory/receiving"],
    ["Below reorder level", "تحت حد إعادة الطلب", sugg.data ? sugg.data.length : "…", Lightbulb, "/procurement/requests"],
  ];
  const waitingPR = prs.data?.filter((p) => p.status === "Pending").slice(0, 5) ?? [];
  const waitingPO = pos.data?.filter((p) => p.status === "Submitted").slice(0, 5) ?? [];

  return (
    <div className="space-y-6">
      <ProcurementNav />
      <div>
        <h1 className="text-3xl font-bold">{isRTL ? "المشتريات" : "Purchasing"}</h1>
        <p className="text-muted-foreground mt-1">{isRTL ? "من الطلب إلى أمر الشراء إلى الاستلام، بأرقام حقيقية." : "From request to order to receipt, with live numbers."}</p>
      </div>
      <ErrorBox message={prs.error || pos.error} />
      <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
        {kpis.map(([en, ar, v, Icon, href]) => (
          <Link key={en} to={href}>
            <Card className="hover:shadow-md transition-shadow h-full">
              <CardContent className="pt-6 flex items-center justify-between gap-2">
                <div>
                  <p className="text-xs text-muted-foreground">{isRTL ? ar : en}</p>
                  <p className="text-2xl font-bold mt-1" dir="ltr">{v}</p>
                </div>
                <Icon className="h-7 w-7 text-primary/60 shrink-0" />
              </CardContent>
            </Card>
          </Link>
        ))}
      </div>
      <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <Card>
          <CardHeader className="pb-2"><CardTitle className="text-base">{isRTL ? "طلبات تنتظر قرارك" : "Requests waiting for a decision"}</CardTitle></CardHeader>
          <CardContent className="space-y-2 text-sm">
            {waitingPR.map((p) => (
              <Link key={p.id} to={`/procurement/requests/${p.id}`} className="flex justify-between items-center hover:bg-muted rounded px-2 py-1">
                <span><b dir="ltr">{p.number}</b> · {p.requested_by_name}</span><StatusBadge status={p.status} isRTL={isRTL} />
              </Link>
            ))}
            {prs.data && waitingPR.length === 0 && <p className="text-muted-foreground">{isRTL ? "لا شيء معلق" : "Nothing waiting"}</p>}
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2"><CardTitle className="text-base">{isRTL ? "أوامر شراء تنتظر الاعتماد" : "Orders waiting for approval"}</CardTitle></CardHeader>
          <CardContent className="space-y-2 text-sm">
            {waitingPO.map((p) => (
              <Link key={p.id} to={`/procurement/purchase-orders/${p.id}`} className="flex justify-between items-center hover:bg-muted rounded px-2 py-1">
                <span><b dir="ltr">{p.number}</b> · {p.supplier_name}</span><span dir="ltr" className="font-mono">{money(p.total, p.currency)}</span>
              </Link>
            ))}
            {pos.data && waitingPO.length === 0 && <p className="text-muted-foreground">{isRTL ? "لا شيء معلق" : "Nothing waiting"}</p>}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
