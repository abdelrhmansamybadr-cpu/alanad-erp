import { useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { Badge } from "../../components/ui/badge";
import { Input } from "../../components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";
import { ErrorBox, Field, fmtKg, nm } from "../../components/inventory/common";
import { ProductionNav } from "../../components/production/common";

interface Loss { id: number | null; name: string; input_kg: number; waste_kg: number; loss_pct: number | null }
interface Rep {
  from: string; to: string;
  by_spec: { spec_id: number; code: string; name_en: string; name_ar: string | null; orders: number; input_kg: number; output_kg: number; waste_kg: number; yield_pct: number | null; planned_yield_pct: number; over_time_orders: number }[];
  by_supplier: Loss[]; by_cold_room: Loss[];
  by_waste_reason: { reason_code: string; name_en: string | null; name_ar: string | null; waste_class: string; kg: number }[];
}
const monthStart = () => new Date(new Date().getFullYear(), new Date().getMonth(), 1).toLocaleDateString("sv");
const monthEnd = () => new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).toLocaleDateString("sv");

export function ProductionReports() {
  const { isRTL } = useI18n();
  const [from, setFrom] = useState(monthStart());
  const [to, setTo] = useState(monthEnd());
  const rep = useApi<Rep>(`/production-reports/yield?from=${from}&to=${to}`);
  const align = isRTL ? "text-right" : "text-left";
  const r = rep.data;

  const lossTable = (title: string, rows: Loss[] | undefined, label: string) => (
    <Card>
      <CardHeader className="pb-2"><CardTitle className="text-base">{title}</CardTitle></CardHeader>
      <CardContent className="overflow-x-auto">
        <Table>
          <TableHeader><TableRow><TableHead className={align}>{label}</TableHead><TableHead className={align}>{isRTL ? "المدخل كجم" : "Input kg"}</TableHead><TableHead className={align}>{isRTL ? "الهالك كجم" : "Waste kg"}</TableHead><TableHead className={align}>{isRTL ? "نسبة الفقد" : "Loss"}</TableHead></TableRow></TableHeader>
          <TableBody>
            {rows?.map((x, i) => (
              <TableRow key={i}><TableCell className="font-medium">{x.name}</TableCell><TableCell className="font-mono">{fmtKg(x.input_kg)}</TableCell><TableCell className="font-mono">{fmtKg(x.waste_kg)}</TableCell><TableCell>{x.loss_pct != null ? <Badge variant={x.loss_pct > 25 ? "destructive" : "secondary"}>{x.loss_pct}%</Badge> : "—"}</TableCell></TableRow>
            ))}
            {rows && rows.length === 0 && <TableRow><TableCell colSpan={4} className="text-center text-muted-foreground">{isRTL ? "لا توجد بيانات" : "No data"}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  );

  return (
    <div className="space-y-6">
      <ProductionNav />
      <div>
        <h1 className="text-3xl font-bold">{isRTL ? "الإنتاجية والهالك" : "Yield & Waste"}</h1>
        <p className="text-muted-foreground mt-1">{isRTL ? "الأوامر المكتملة فقط. الفقد يتبع الخامة: لكل مورد ولكل غرفة تبريد حصته من الهالك." : "Completed orders only. Loss follows the material: each supplier and each cold room carries its share of the waste."}</p>
      </div>
      <ErrorBox message={rep.error} />
      <div className="flex flex-wrap gap-3">
        <Field id="rp_from" label={isRTL ? "من" : "From"}><Input id="rp_from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
        <Field id="rp_to" label={isRTL ? "إلى" : "To"}><Input id="rp_to" type="date" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
      </div>
      <Card>
        <CardHeader className="pb-2"><CardTitle className="text-base">{isRTL ? "الإنتاجية لكل منتج" : "Yield by product"}</CardTitle></CardHeader>
        <CardContent className="overflow-x-auto">
          <Table>
            <TableHeader><TableRow><TableHead className={align}>{isRTL ? "المواصفة" : "Specification"}</TableHead><TableHead className={align}>{isRTL ? "الأوامر" : "Orders"}</TableHead><TableHead className={align}>{isRTL ? "المدخل" : "Input kg"}</TableHead><TableHead className={align}>{isRTL ? "المخرج" : "Output kg"}</TableHead><TableHead className={align}>{isRTL ? "الهالك" : "Waste kg"}</TableHead><TableHead className={align}>{isRTL ? "الإنتاجية" : "Yield"}</TableHead><TableHead className={align}>{isRTL ? "المخطط" : "Plan"}</TableHead><TableHead className={align}>{isRTL ? "تجاوز الوقت" : "Over time"}</TableHead></TableRow></TableHeader>
            <TableBody>
              {r?.by_spec.map((s) => (
                <TableRow key={s.spec_id}>
                  <TableCell className="font-medium">{nm(isRTL, s.name_en, s.name_ar)}</TableCell><TableCell>{s.orders}</TableCell><TableCell className="font-mono">{fmtKg(s.input_kg)}</TableCell><TableCell className="font-mono">{fmtKg(s.output_kg)}</TableCell><TableCell className="font-mono">{fmtKg(s.waste_kg)}</TableCell>
                  <TableCell>{s.yield_pct != null ? <Badge variant={s.yield_pct < s.planned_yield_pct ? "destructive" : "secondary"}>{s.yield_pct}%</Badge> : "—"}</TableCell><TableCell className="font-mono">{s.planned_yield_pct}%</TableCell><TableCell>{s.over_time_orders}</TableCell>
                </TableRow>
              ))}
              {r && r.by_spec.length === 0 && <TableRow><TableCell colSpan={8} className="text-center text-muted-foreground">{isRTL ? "لا توجد أوامر مكتملة في هذه الفترة" : "No completed orders in this period"}</TableCell></TableRow>}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
      <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">
        {lossTable(isRTL ? "الفقد حسب المورد" : "Loss by supplier", r?.by_supplier, isRTL ? "المورد" : "Supplier")}
        {lossTable(isRTL ? "الفقد حسب غرفة التبريد" : "Loss by cold room", r?.by_cold_room, isRTL ? "الغرفة" : "Cold room")}
      </div>
      <Card>
        <CardHeader className="pb-2"><CardTitle className="text-base">{isRTL ? "الهالك حسب السبب" : "Waste by reason"}</CardTitle></CardHeader>
        <CardContent className="overflow-x-auto">
          <Table>
            <TableHeader><TableRow><TableHead className={align}>{isRTL ? "السبب" : "Reason"}</TableHead><TableHead className={align}>{isRTL ? "الفئة" : "Class"}</TableHead><TableHead className={align}>{isRTL ? "كجم" : "kg"}</TableHead></TableRow></TableHeader>
            <TableBody>
              {r?.by_waste_reason.map((w) => <TableRow key={w.reason_code}><TableCell>{nm(isRTL, w.name_en, w.name_ar)}</TableCell><TableCell>{w.waste_class === "high" ? (isRTL ? "عالية القيمة" : "high value") : isRTL ? "منخفضة" : "low value"}</TableCell><TableCell className="font-mono">{fmtKg(w.kg)}</TableCell></TableRow>)}
              {r && r.by_waste_reason.length === 0 && <TableRow><TableCell colSpan={3} className="text-center text-muted-foreground">{isRTL ? "لا توجد بيانات" : "No data"}</TableCell></TableRow>}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}
