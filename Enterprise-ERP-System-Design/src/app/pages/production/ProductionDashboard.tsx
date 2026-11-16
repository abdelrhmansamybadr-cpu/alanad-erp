import { useState } from "react";
import { Link, useNavigate } from "react-router";
import { toast } from "sonner";
import { Plus, Clock, Play, ClipboardCheck, Trash2, ShieldAlert } from "lucide-react";
import { Card, CardContent } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { useApi, post } from "../../hooks/useApi";
import { ErrorBox, Field, fmtKg, selectClass, nm } from "../../components/inventory/common";
import { StatusBadge } from "../../components/procurement/common";
import { ProductionNav, OrderRow, Spec } from "../../components/production/common";
import { fmtWait } from "../inventory/GoodsReceipts";

interface Summary { draft: number; approved: number; in_progress: number; awaiting_inspection: number; waste_pending: number; over_time_running: number; unsafe_logs_week: number; month_input_kg: number; month_output_kg: number; month_waste_kg: number; month_yield_pct: number | null }
const STATUSES = ["Draft", "Approved", "In_Progress", "Awaiting_Inspection", "Completed", "Cancelled"];

export function ProductionDashboard() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const navigate = useNavigate();
  const [status, setStatus] = useState("");
  const orders = useApi<OrderRow[]>(`/prep-orders${status ? `?status=${status}` : ""}`);
  const sm = useApi<Summary>("/production-reports/summary");
  const specs = useApi<Spec[]>("/prep-specs");
  const [dlg, setDlg] = useState<{ spec_id: string; kg: string; notes: string } | null>(null);
  const [error, setError] = useState("");
  const s = sm.data;
  const align = isRTL ? "text-right" : "text-left";

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    setError("");
    try {
      const o = await post<{ id: number }>("/prep-orders", { spec_id: Number(dlg.spec_id), planned_input_kg: Number(dlg.kg), notes: dlg.notes || undefined });
      toast.success(isRTL ? "تم تخطيط الأمر" : "Order planned");
      navigate(`/production/orders/${o.id}`);
    } catch (err: any) { setError(err.message); }
  };

  const kpis: [string, string, string | number, React.ComponentType<{ className?: string }>, boolean][] = [
    ["Running now", "قيد التنفيذ", s?.in_progress ?? "…", Play, (s?.over_time_running ?? 0) > 0],
    ["Awaiting inspection", "بانتظار الفحص النهائي", s?.awaiting_inspection ?? "…", ClipboardCheck, false],
    ["Waste awaiting Finance", "هالك بانتظار المالية", s?.waste_pending ?? "…", Trash2, (s?.waste_pending ?? 0) > 0],
    ["Unsafe food-safety logs (7 d)", "قراءات غير آمنة (٧ أيام)", s?.unsafe_logs_week ?? "…", ShieldAlert, (s?.unsafe_logs_week ?? 0) > 0],
    ["Month yield", "إنتاجية الشهر", s?.month_yield_pct != null ? `${s.month_yield_pct}%` : "—", Clock, false],
  ];

  return (
    <div className="space-y-6">
      <ProductionNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "الإنتاج والتجهيز" : "Production & Preparation"}</h1>
          <p className="text-muted-foreground mt-1">{isRTL ? "من الخامة في غرفة التبريد إلى المنتج الجاهز: الغسيل والفرز والتعبئة، مع الوقت خارج التبريد والهالك." : "From raw material in the cold room to finished product: wash, sort, pack, with the time outside the cold and the waste."}</p>
        </div>
        {can("prep-orders", "create") && (
          <Button className="gap-2" onClick={() => setDlg({ spec_id: "", kg: "", notes: "" })}><Plus className="h-4 w-4" />{isRTL ? "أمر تجهيز جديد" : "New order"}</Button>
        )}
      </div>
      <ErrorBox message={orders.error || sm.error || error} />
      <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
        {kpis.map(([en, ar, v, Icon, warn]) => (
          <Card key={en} className={warn ? "border-amber-300" : ""}>
            <CardContent className="pt-6 flex items-center justify-between gap-2">
              <div><p className="text-xs text-muted-foreground">{isRTL ? ar : en}</p><p className="text-2xl font-bold mt-1" dir="ltr">{v}</p></div>
              <Icon className={`h-6 w-6 shrink-0 ${warn ? "text-amber-500" : "text-primary/60"}`} />
            </CardContent>
          </Card>
        ))}
      </div>
      <Card className="p-6 space-y-4">
        <select aria-label="status" className={`${selectClass} max-w-[240px]`} value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">{isRTL ? "كل الحالات" : "All statuses"}</option>
          {STATUSES.map((x) => <option key={x} value={x}>{x}</option>)}
        </select>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className={align}>{isRTL ? "الرقم" : "Number"}</TableHead>
                <TableHead className={align}>{isRTL ? "المواصفة" : "Specification"}</TableHead>
                <TableHead className={align}>{isRTL ? "المخطط كجم" : "Planned kg"}</TableHead>
                <TableHead className={align}>{isRTL ? "المؤقت" : "Timer"}</TableHead>
                <TableHead className={align}>{isRTL ? "الإنتاجية" : "Yield"}</TableHead>
                <TableHead className={align}>{isRTL ? "الهالك" : "Waste"}</TableHead>
                <TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {orders.data?.map((o) => (
                <TableRow key={o.id}>
                  <TableCell className="font-mono"><Link className="text-primary hover:underline" to={`/production/orders/${o.id}`}>{o.number}</Link></TableCell>
                  <TableCell>{nm(isRTL, o.spec_name, o.spec_name_ar)}</TableCell>
                  <TableCell className="font-mono">{fmtKg(o.planned_input_kg)}</TableCell>
                  <TableCell>
                    {o.minutes_running != null ? <span className={`inline-flex items-center gap-1 text-sm ${o.over_time_now ? "text-red-600 font-semibold" : ""}`}><Clock className="h-3.5 w-3.5" />{fmtWait(Number(o.minutes_running), isRTL)} / {o.max_minutes_outside}</span> : Number(o.over_time) === 1 ? <Badge variant="destructive">{isRTL ? "تجاوز الوقت" : "over time"}</Badge> : "—"}
                  </TableCell>
                  <TableCell className="font-mono">{o.yield_pct != null ? `${o.yield_pct}%` : "—"}</TableCell>
                  <TableCell className="font-mono">{o.waste_pct != null ? `${o.waste_pct}%` : "—"}{o.result === "waste_high" && <Badge variant="destructive" className="ms-2">{isRTL ? "هالك مرتفع" : "high"}</Badge>}{o.waste_pending > 0 && <Badge className="ms-2 bg-amber-500 hover:bg-amber-500">{isRTL ? `${o.waste_pending} بانتظار المالية` : `${o.waste_pending} pending`}</Badge>}</TableCell>
                  <TableCell><StatusBadge status={o.status} isRTL={isRTL} /></TableCell>
                </TableRow>
              ))}
              {orders.data && orders.data.length === 0 && <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground">{isRTL ? "لا توجد أوامر" : "No orders"}</TableCell></TableRow>}
            </TableBody>
          </Table>
        </div>
      </Card>

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent>
          <form onSubmit={create} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "أمر تجهيز جديد" : "New preparation order"}</DialogTitle></DialogHeader>
            <ErrorBox message={error} />
            <Field id="po_spec" label={isRTL ? "المواصفة" : "Specification"}>
              <select id="po_spec" className={selectClass} required value={dlg?.spec_id ?? ""} onChange={(e) => setDlg((d) => d && { ...d, spec_id: e.target.value })}>
                <option value="">{isRTL ? "اختر…" : "Choose…"}</option>
                {specs.data?.filter((x) => Number(x.is_active) === 1).map((x) => <option key={x.id} value={x.id}>{x.code} · {nm(isRTL, x.name_en, x.name_ar)}</option>)}
              </select>
            </Field>
            <Field id="po_kg" label={isRTL ? "الكمية المخططة من الخامة (كجم)" : "Planned raw material (kg)"}><Input id="po_kg" type="number" step="any" min="0" dir="ltr" required value={dlg?.kg ?? ""} onChange={(e) => setDlg((d) => d && { ...d, kg: e.target.value })} /></Field>
            <Field id="po_notes" label={isRTL ? "ملاحظات" : "Notes"}><Input id="po_notes" value={dlg?.notes ?? ""} onChange={(e) => setDlg((d) => d && { ...d, notes: e.target.value })} /></Field>
            <DialogFooter><Button type="submit">{isRTL ? "تخطيط" : "Plan order"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
