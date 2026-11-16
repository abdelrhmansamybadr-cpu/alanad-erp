import { useState } from "react";
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { Card } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";
import { ErrorBox } from "../../components/inventory/common";

interface R {
  period: { from: string; to: string };
  receiving: { trucks: number; rejected_trucks: number; received_kg: number; rejected_kg: number; reject_pct: number; avg_minutes_to_accept: number | null };
  sorting: { runs: number; input_kg: number; class1_pct: number; class2_pct: number; class3_pct: number; shrink_pct: number; incidents: number; by_supplier: { supplier: string; runs: number; input_kg: number; class1_pct: number; class2_pct: number; class3_pct: number }[] };
  production: { prep_orders: number; avg_yield_pct: number | null; avg_waste_pct: number | null };
  packing: { orders: number; boxes: number; cartons: number; pallets: number; kg: number; loss_kg: number; loss_pct: number };
  sales: { invoices: number; shipments: number; subtotal: number; vat: number; total: number; cogs: number; gross_margin: number; gross_margin_pct: number; by_grade: { grade: string; kg: number; amount: number; avg_price: number }[]; top_customers: { customer: string; subtotal: number; invoices: number }[] };
  stock: { by_grade: { grade: string; kg: number }[]; total_kg: number; expiring_kg: number; expiring_days: number; open_alarms: number; active_holds: number };
  market: { settled_purchases: number; spent: number; open_custody: number; overdue_custody: number };
  hr: { active_employees: number; on_leave_today: number; pending_leaves: number; expired: number; expiring_soon: number };
}
const n = (v: number, d = 0) => Number(v).toLocaleString("en-US", { minimumFractionDigits: d, maximumFractionDigits: d });
const iso = (d: Date) => d.toISOString().slice(0, 10);

function Kpi({ label, value, sub, tone }: { label: string; value: string; sub?: string; tone?: "bad" | "good" }) {
  return (
    <Card className="p-4">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className={`text-2xl font-bold mt-1 ${tone === "bad" ? "text-red-700" : tone === "good" ? "text-emerald-700" : ""}`} dir="ltr">{value}</div>
      {sub && <div className="text-xs text-muted-foreground mt-1">{sub}</div>}
    </Card>
  );
}
function Section({ title, children, id }: { title: string; children: React.ReactNode; id: string }) {
  return <section data-testid={id} className="space-y-3"><h2 className="text-xl font-semibold">{title}</h2>{children}</section>;
}

/** Factory reports: the numbers of every stage for a period, from the real data. */
export function FactoryReports() {
  const { isRTL } = useI18n();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const today = new Date();
  const [from, setFrom] = useState(iso(new Date(today.getTime() - 29 * 86400000)));
  const [to, setTo] = useState(iso(today));
  const q = useApi<R>(`/factory-reports?from=${from}&to=${to}`);
  const r = q.data;
  const preset = (days: number) => { setTo(iso(today)); setFrom(iso(new Date(today.getTime() - (days - 1) * 86400000))); };
  const classData = r?.sorting.by_supplier.map((s) => ({ name: s.supplier, [T("Class 1", "الدرجة ١")]: s.class1_pct, [T("Class 2", "الدرجة ٢")]: s.class2_pct, [T("Class 3", "الدرجة ٣")]: s.class3_pct })) ?? [];
  const gradeData = r?.sales.by_grade.map((g) => ({ name: g.grade, kg: g.kg, amount: g.amount })) ?? [];
  const stockData = r?.stock.by_grade.map((g) => ({ name: g.grade, kg: g.kg })) ?? [];

  return (
    <div className="space-y-8">
      <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 print:hidden">
        <div><h1 className="text-3xl font-bold">{T("Factory reports", "تقارير المصنع")}</h1><p className="text-muted-foreground mt-1">{T("The numbers of every stage, from receiving to sales, for the period you choose and the branch you work in.", "أرقام كل مرحلة من الاستلام إلى المبيعات، للفترة التي تختارها وللفرع الذي تعمل فيه.")}</p></div>
        <div className="flex flex-wrap items-end gap-2">
          {[7, 30, 90].map((d) => <Button key={d} size="sm" variant="outline" onClick={() => preset(d)}>{d} {T("days", "يوم")}</Button>)}
          <Input type="date" dir="ltr" className="w-40" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="from" data-testid="from" />
          <Input type="date" dir="ltr" className="w-40" value={to} onChange={(e) => setTo(e.target.value)} aria-label="to" data-testid="to" />
          <Button size="sm" onClick={() => window.print()}>{T("Print", "طباعة")}</Button>
        </div>
      </div>
      {q.error && <ErrorBox message={q.error} />}
      {!r && !q.error && <p className="text-muted-foreground">…</p>}
      {r && (
        <>
          <Section id="rp-receiving" title={T("Receiving", "الاستلام")}>
            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
              <Kpi label={T("Trucks", "الشاحنات")} value={n(r.receiving.trucks)} sub={`${r.receiving.rejected_trucks} ${T("rejected", "مرفوضة")}`} />
              <Kpi label={T("Received (kg)", "المستلم (كجم)")} value={n(r.receiving.received_kg)} />
              <Kpi label={T("Rejected (kg)", "المرفوض (كجم)")} value={n(r.receiving.rejected_kg)} sub={`${r.receiving.reject_pct}%`} tone={r.receiving.reject_pct > 5 ? "bad" : undefined} />
              <Kpi label={T("Time to accept", "زمن القبول")} value={r.receiving.avg_minutes_to_accept != null ? `${r.receiving.avg_minutes_to_accept} ${T("min", "د")}` : "—"} />
              <Kpi label={T("Held on quality", "محجوز للجودة")} value={n(r.stock.active_holds)} sub={`${r.stock.open_alarms} ${T("temperature alarms", "إنذار حرارة")}`} tone={r.stock.open_alarms > 0 ? "bad" : undefined} />
            </div>
          </Section>

          <Section id="rp-sorting" title={T("Sorting: how the loads split", "الفرز: كيف تنقسم الحمولات")}>
            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
              <Kpi label={T("Class 1 (for the client)", "الدرجة ١ (للعميل)")} value={`${r.sorting.class1_pct}%`} tone={r.sorting.runs > 0 && r.sorting.class1_pct < 80 ? "bad" : "good"} sub={`${T("limit", "الحد")} 80%`} />
              <Kpi label={T("Class 2", "الدرجة ٢")} value={`${r.sorting.class2_pct}%`} />
              <Kpi label={T("Class 3", "الدرجة ٣")} value={`${r.sorting.class3_pct}%`} />
              <Kpi label={T("Shrink", "الفاقد")} value={`${r.sorting.shrink_pct}%`} />
              <Kpi label={T("Runs / incidents", "عمليات / حوادث")} value={`${r.sorting.runs} / ${r.sorting.incidents}`} sub={`${n(r.sorting.input_kg)} kg`} />
            </div>
            {classData.length > 0 && (
              <Card className="p-4" style={{ height: 280 }} data-testid="chart-classes"><ResponsiveContainer width="100%" height="100%"><BarChart data={classData}><CartesianGrid strokeDasharray="3 3" /><XAxis dataKey="name" /><YAxis unit="%" /><Tooltip /><Legend />
                <Bar dataKey={T("Class 1", "الدرجة ١")} stackId="a" fill="#059669" /><Bar dataKey={T("Class 2", "الدرجة ٢")} stackId="a" fill="#f59e0b" /><Bar dataKey={T("Class 3", "الدرجة ٣")} stackId="a" fill="#dc2626" /></BarChart></ResponsiveContainer></Card>
            )}
          </Section>

          <Section id="rp-production" title={T("Preparation and packing", "التجهيز والتعبئة")}>
            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
              <Kpi label={T("Preparation yield", "إنتاجية التجهيز")} value={r.production.avg_yield_pct != null ? `${r.production.avg_yield_pct}%` : "—"} sub={`${r.production.prep_orders} ${T("orders", "أمر")}`} />
              <Kpi label={T("Preparation waste", "هالك التجهيز")} value={r.production.avg_waste_pct != null ? `${r.production.avg_waste_pct}%` : "—"} />
              <Kpi label={T("Boxes packed", "صناديق معبأة")} value={n(r.packing.boxes)} sub={`${n(r.packing.kg)} kg`} />
              <Kpi label={T("Cartons / pallets", "كراتين / طبليات")} value={`${n(r.packing.cartons)} / ${n(r.packing.pallets)}`} />
              <Kpi label={T("Packing loss", "فاقد التعبئة")} value={`${r.packing.loss_pct}%`} sub={`${r.packing.loss_kg} kg`} />
            </div>
          </Section>

          <Section id="rp-sales" title={T("Sales", "المبيعات")}>
            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
              <Kpi label={T("Invoiced (before VAT)", "المفوتر (قبل الضريبة)")} value={n(r.sales.subtotal, 2)} sub={`${r.sales.invoices} ${T("invoices", "فاتورة")}`} />
              <Kpi label={T("VAT", "الضريبة")} value={n(r.sales.vat, 2)} sub={`${T("total", "الإجمالي")} ${n(r.sales.total, 2)}`} />
              <Kpi label={T("Cost of goods sold", "تكلفة البضاعة المباعة")} value={n(r.sales.cogs, 2)} />
              <Kpi label={T("Gross margin", "هامش الربح الإجمالي")} value={n(r.sales.gross_margin, 2)} sub={`${r.sales.gross_margin_pct}%`} tone={r.sales.gross_margin < 0 ? "bad" : "good"} />
              <Kpi label={T("Shipments sent", "شحنات خرجت")} value={n(r.sales.shipments)} />
            </div>
            <div className="grid lg:grid-cols-2 gap-3">
              {gradeData.length > 0 && <Card className="p-4" style={{ height: 260 }} data-testid="chart-grades"><div className="text-sm font-medium mb-2">{T("kg sold by grade", "الكيلوات المباعة بحسب الدرجة")}</div><ResponsiveContainer width="100%" height="90%"><BarChart data={gradeData}><CartesianGrid strokeDasharray="3 3" /><XAxis dataKey="name" /><YAxis /><Tooltip /><Bar dataKey="kg" fill="#0f766e" /></BarChart></ResponsiveContainer></Card>}
              <Card className="p-4" data-testid="top-customers"><div className="text-sm font-medium mb-2">{T("Top clients", "أكبر العملاء")}</div>
                {r.sales.top_customers.length === 0 ? <p className="text-sm text-muted-foreground">—</p> : <ul className="text-sm space-y-1">{r.sales.top_customers.map((c) => <li key={c.customer} className="flex justify-between"><span>{c.customer}</span><b dir="ltr">{n(c.subtotal, 2)}</b></li>)}</ul>}
              </Card>
            </div>
          </Section>

          <Section id="rp-stock" title={T("Stock now, market buying and people", "المخزون الآن والشراء من السوق والموظفون")}>
            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
              <Kpi label={T("Stock (kg)", "المخزون (كجم)")} value={n(r.stock.total_kg)} sub={r.stock.by_grade.map((g) => `${g.grade}: ${n(g.kg)}`).join(" · ")} />
              <Kpi label={T(`Expiring within ${r.stock.expiring_days} days (kg)`, `ينتهي خلال ${r.stock.expiring_days} أيام (كجم)`)} value={n(r.stock.expiring_kg)} tone={r.stock.expiring_kg > 0 ? "bad" : undefined} />
              <Kpi label={T("Market purchases settled", "مشتريات السوق المسوّاة")} value={n(r.market.spent, 2)} sub={`${r.market.settled_purchases} ${T("purchases", "عملية")}`} />
              <Kpi label={T("Cash with buyers", "نقد مع المشترين")} value={n(r.market.open_custody, 2)} sub={`${r.market.overdue_custody} ${T("overdue", "متأخرة")}`} tone={r.market.overdue_custody > 0 ? "bad" : undefined} />
              <Kpi label={T("Documents expired / soon", "وثائق منتهية / قريباً")} value={`${r.hr.expired} / ${r.hr.expiring_soon}`} sub={`${r.hr.active_employees} ${T("employees", "موظف")}`} tone={r.hr.expired > 0 ? "bad" : undefined} />
            </div>
            {stockData.length > 0 && <Card className="p-4" style={{ height: 240 }}><div className="text-sm font-medium mb-2">{T("Stock by grade (kg)", "المخزون بحسب الدرجة (كجم)")}</div><ResponsiveContainer width="100%" height="88%"><BarChart data={stockData}><CartesianGrid strokeDasharray="3 3" /><XAxis dataKey="name" /><YAxis /><Tooltip /><Bar dataKey="kg" fill="#2563eb" /></BarChart></ResponsiveContainer></Card>}
          </Section>
        </>
      )}
    </div>
  );
}
