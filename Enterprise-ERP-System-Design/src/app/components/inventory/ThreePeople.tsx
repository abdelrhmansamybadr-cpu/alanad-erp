import { useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "../ui/card";
import { Button } from "../ui/button";
import { Input } from "../ui/input";
import { Badge } from "../ui/badge";
import { Field, selectClass } from "./common";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";

export interface ThreeInfo {
  rule_on: boolean; suggested_route: "store" | "sorting"; count_diff_pct: number | null; count_tolerance_pct: number; people: Record<string, string | null>;
}
export interface ThreeDelivery {
  id: number; status: string; counted_units: number | null; count_unit: string | null; finance_status: "Passed" | "Disputed" | null;
  delivery_note_no: string | null; note_units: number | null; papers_ok: number | null; goods_ok: number | null; finance_note: string | null;
  route: "store" | "sorting" | null; three?: ThreeInfo;
}

/** The three people at the truck: quality (the steps above), the counter, then Finance (papers + goods); then the route. */
export function ThreePeople({ d, act }: { d: ThreeDelivery; act: (path: string, body: object, ok: string) => Promise<void> }) {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const [units, setUnits] = useState("");
  const [unit, setUnit] = useState("crate");
  const [fin, setFin] = useState({ papers: true, goods: true, no: "", noteUnits: "", note: "" });
  const t = d.three;
  if (!t || !["Arrived", "Temp_Hold", "Checking", "Ready", "To_Sorting"].includes(d.status)) return null;
  const who = (k: string) => t.people[k] ?? "—";
  const canCount = can("deliveries", "count") && d.finance_status !== "Passed";
  const canFin = can("deliveries", "finance");
  const readyForFinance = d.status === "Ready";

  return (
    <Card data-testid="three-people">
      <CardHeader className="pb-2"><CardTitle className="text-base">{T("Three people at the truck", "ثلاثة أشخاص عند الشاحنة")}{t.rule_on ? <Badge className="ms-2">{T("rule on", "القاعدة مفعّلة")}</Badge> : <Badge variant="secondary" className="ms-2">{T("optional", "اختياري")}</Badge>}</CardTitle></CardHeader>
      <CardContent className="space-y-4 text-sm">
        <div className="grid sm:grid-cols-3 gap-3">
          <div className="p-3 rounded-lg border"><div className="font-semibold">{T("1. Quality", "١. الجودة")}</div><div>{who("quality_by")}</div><div className="text-xs text-muted-foreground">{T("signed off by", "اعتمدها")}: {who("accepted_by")}</div></div>
          <div className="p-3 rounded-lg border"><div className="font-semibold">{T("2. Counter", "٢. العدّاد")}</div><div>{who("counted_by")}</div><div className="text-xs">{d.counted_units != null ? `${d.counted_units} ${d.count_unit}` : T("not counted yet", "لم يُعدّ بعد")}</div></div>
          <div className="p-3 rounded-lg border"><div className="font-semibold">{T("3. Finance", "٣. المالية")}</div><div>{who("finance_by")}</div><div className="text-xs">{d.finance_status === "Passed" ? T("papers and goods confirmed", "الأوراق والبضاعة مؤكدة") : d.finance_status === "Disputed" ? T("disputed", "فيها خلاف") : T("waiting", "بالانتظار")}</div></div>
        </div>

        {canCount && (
          <div className="flex flex-wrap items-end gap-2">
            <Field id="cnt_units" label={T("Units counted", "العدد المُعدود")} className="w-32"><Input id="cnt_units" type="number" dir="ltr" value={units} onChange={(e) => setUnits(e.target.value)} /></Field>
            <Field id="cnt_unit" label={T("Unit", "الوحدة")} className="w-32">
              <select id="cnt_unit" className={selectClass} value={unit} onChange={(e) => setUnit(e.target.value)}>
                <option value="crate">{T("crate", "صندوق")}</option><option value="box">{T("box", "كرتونة")}</option><option value="bag">{T("bag", "كيس")}</option><option value="pallet">{T("pallet", "طبلية")}</option>
              </select>
            </Field>
            <Button data-testid="count-btn" disabled={!units} onClick={() => act("count", { units: Number(units), unit }, T("Counted", "تم العدّ"))}>{T("Save count", "حفظ العدّ")}</Button>
          </div>
        )}

        {canFin && readyForFinance && d.finance_status !== "Passed" && (
          <div className="space-y-3 border-t pt-3">
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <Field id="fn_no" label={T("Delivery note no.", "رقم إشعار التسليم")}><Input id="fn_no" dir="ltr" value={fin.no} onChange={(e) => setFin({ ...fin, no: e.target.value })} /></Field>
              <Field id="fn_units" label={T("Units on the note", "العدد في الإشعار")}><Input id="fn_units" type="number" dir="ltr" value={fin.noteUnits} onChange={(e) => setFin({ ...fin, noteUnits: e.target.value })} /></Field>
              <label className="flex items-center gap-2 pt-6"><input type="checkbox" checked={fin.papers} onChange={(e) => setFin({ ...fin, papers: e.target.checked })} />{T("Papers correct", "الأوراق سليمة")}</label>
              <label className="flex items-center gap-2 pt-6"><input type="checkbox" checked={fin.goods} onChange={(e) => setFin({ ...fin, goods: e.target.checked })} />{T("Goods match", "البضاعة مطابقة")}</label>
            </div>
            <Field id="fn_note" label={T("Note (required if anything is wrong or the count differs)", "ملاحظة (مطلوبة إن وُجد خلل أو فرق في العدد)")}><Input id="fn_note" value={fin.note} onChange={(e) => setFin({ ...fin, note: e.target.value })} /></Field>
            <Button data-testid="finance-btn" onClick={() => act("finance-check", { papers_ok: fin.papers, goods_ok: fin.goods, delivery_note_no: fin.no || undefined, note_units: fin.noteUnits ? Number(fin.noteUnits) : undefined, note: fin.note || undefined }, T("Finance check saved", "تم حفظ فحص المالية"))}>{T("Confirm for Finance", "تأكيد المالية")}</Button>
          </div>
        )}
        {d.finance_note && <p className="text-xs text-muted-foreground">{d.delivery_note_no ? `${T("Note", "الإشعار")} ${d.delivery_note_no} · ` : ""}{d.finance_note}</p>}
        {t.count_diff_pct != null && Math.abs(t.count_diff_pct) > 0 && <p className="text-xs">{T("Count vs note: ", "العدّ مقابل الإشعار: ")}<span dir="ltr">{t.count_diff_pct}%</span></p>}

        {canFin && (d.finance_status === "Passed" || !t.rule_on) && ["Ready", "To_Sorting"].includes(d.status) && (
          <div className="flex flex-wrap items-center gap-2 border-t pt-3" data-testid="route-box">
            <span>{T("Route: ", "التوجيه: ")}<b>{d.route ? (d.route === "sorting" ? T("sorting", "الفرز") : T("store", "المخزن")) : "—"}</b> · {T("suggested: ", "المقترح: ")}{t.suggested_route === "sorting" ? T("sorting", "الفرز") : T("store", "المخزن")}</span>
            <Button size="sm" data-testid="route-sorting" variant={t.suggested_route === "sorting" ? "default" : "outline"} onClick={() => act("route", { to: "sorting" }, T("Sent to sorting", "حُوّلت للفرز"))}>{T("To sorting", "إلى الفرز")}</Button>
            <Button size="sm" data-testid="route-store" variant={t.suggested_route === "store" ? "default" : "outline"} onClick={() => act("route", { to: "store" }, T("Sent to the store", "حُوّلت للمخزن"))}>{T("To the store", "إلى المخزن")}</Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
