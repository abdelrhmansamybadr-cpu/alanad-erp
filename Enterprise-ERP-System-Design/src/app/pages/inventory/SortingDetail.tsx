import { useEffect, useState } from "react";
import { Link, useParams } from "react-router";
import { toast } from "sonner";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";
import { useApi, post } from "../../hooks/useApi";
import { InventoryNav } from "../../components/inventory/InventoryNav";
import { ErrorBox, Field, fmtKg, selectClass, ColdRoom, nm } from "../../components/inventory/common";
import { SortStatus } from "./SortingList";

interface Line { id: number; item_name: string; item_name_ar: string | null; input_kg: number; class1_kg: number | null; class2_kg: number | null; class3_kg: number | null; shrink_kg: number | null; class1_pct: number | null; min_pct: number; below_min: boolean }
interface Run {
  id: number; number: string; status: string; lines: Line[]; below_min: boolean; delivery: { number: string; supplier_name: string | null } | null;
  incident: { reason_code: string; note: string | null; status: string; filed_by_name: string } | null;
  outputs: { class_no: number; kg: number; batch_no: string; grade: string; cold_room_id: number; unit_cost: number | null }[];
  limits: { min_class1_pct: number; balance_tolerance_pct: number };
}
interface Reason { code: string; gate: string; name_en: string; name_ar: string | null }

/** One sorting run: the sorter types the kilos of each class, the screen shows class 1 % live, the quality person files the report and signs off. */
export function SortingDetail() {
  const { id } = useParams();
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const q = useApi<Run>(`/sorting-runs/${id}`);
  const rooms = useApi<ColdRoom[]>("/cold-rooms");
  const reasons = useApi<Reason[]>("/reject-reasons");
  const [vals, setVals] = useState<Record<number, { c1: string; c2: string; c3: string; sh: string }>>({});
  const [roomSel, setRoomSel] = useState<Record<string, string>>({});
  const [inc, setInc] = useState({ code: "", note: "" });
  const [busy, setBusy] = useState(false);
  const [cancel, setCancel] = useState<string | null>(null);
  const r = q.data;
  const align = isRTL ? "text-right" : "text-left";

  useEffect(() => {
    if (!r) return;
    setVals(Object.fromEntries(r.lines.map((l) => [l.id, { c1: l.class1_kg != null ? String(l.class1_kg) : "", c2: l.class2_kg != null ? String(l.class2_kg) : "", c3: l.class3_kg != null ? String(l.class3_kg) : "", sh: l.shrink_kg != null ? String(l.shrink_kg) : "" }])));
  }, [r?.id, r?.status, r?.lines.length]);   // eslint-disable-line react-hooks/exhaustive-deps

  const run = async (path: string, ok: string, body: object = {}) => {
    setBusy(true);
    try { await post(`/sorting-runs/${id}/${path}`, body); toast.success(ok); await q.reload(); return true; }
    catch (e: any) { toast.error(e.message); return false; } finally { setBusy(false); }
  };
  if (q.error) return <ErrorBox message={q.error} />;
  if (!r) return <p className="text-muted-foreground">…</p>;

  const num = (s: string | undefined) => (s === undefined || s === "" ? 0 : Number(s));
  const live = (l: Line) => { const v = vals[l.id] ?? { c1: "", c2: "", c3: "", sh: "" }; const sum = num(v.c1) + num(v.c2) + num(v.c3) + num(v.sh); return { sum, pct: l.input_kg > 0 ? (num(v.c1) / l.input_kg) * 100 : 0, gap: l.input_kg - sum }; };
  const setV = (lid: number, k: "c1" | "c2" | "c3" | "sh", v: string) => setVals((s) => ({ ...s, [lid]: { ...s[lid], [k]: v } }));
  const editable = ["In_Progress", "Awaiting_Signoff"].includes(r.status) && can("sorting-runs", "edit");
  const canSign = r.status === "Awaiting_Signoff" && can("sorting-runs", "signoff");
  const sortReasons = reasons.data?.filter((x) => x.gate === "sorting") ?? [];
  const classNames = [T("Class 1 · for the client (grade A)", "الدرجة ١ · للعميل (A)"), T("Class 2 · normal-quality surplus (B)", "الدرجة ٢ · فائض جودة عادية (B)"), T("Class 3 · lower quality (C)", "الدرجة ٣ · جودة أقل (C)")];
  const usedClasses = [1, 2, 3].filter((c) => r.lines.some((l) => ((c === 1 ? l.class1_kg : c === 2 ? l.class2_kg : l.class3_kg) ?? 0) > 0));

  return (
    <div className="space-y-6">
      <InventoryNav />
      <div>
        <Link to="/inventory/sorting" className="text-sm text-primary underline">{T("← Sorting", "← الفرز")}</Link>
        <div className="flex flex-wrap items-center gap-3 mt-1"><h1 className="text-3xl font-bold" dir="ltr">{r.number}</h1><SortStatus s={r.status} isRTL={isRTL} /></div>
        <p className="text-muted-foreground mt-1">{r.delivery?.number} · {r.delivery?.supplier_name} · {T("class 1 must be at least", "الدرجة ١ لا تقل عن")} {r.limits.min_class1_pct}%</p>
      </div>

      <Card className="p-6" data-testid="lines">
        <Table>
          <TableHeader><TableRow>
            <TableHead className={align}>{T("Item", "الصنف")}</TableHead><TableHead className={align}>{T("In (kg)", "الداخل (كجم)")}</TableHead>
            {classNames.map((c, i) => <TableHead key={i} className={align}>{c}</TableHead>)}
            <TableHead className={align}>{T("Shrink (kg)", "الفاقد (كجم)")}</TableHead><TableHead className={align}>{T("Class 1 %", "الدرجة ١ %")}</TableHead>
          </TableRow></TableHeader>
          <TableBody>
            {r.lines.map((l) => {
              const lv = live(l); const v = vals[l.id] ?? { c1: "", c2: "", c3: "", sh: "" };
              const below = lv.pct + 0.0001 < l.min_pct && (r.status !== "In_Progress" || v.c1 !== "");
              return (
                <TableRow key={l.id}>
                  <TableCell className="font-medium">{nm(isRTL, l.item_name, l.item_name_ar)}</TableCell>
                  <TableCell>{fmtKg(l.input_kg)}</TableCell>
                  {(["c1", "c2", "c3", "sh"] as const).map((k) => (
                    <TableCell key={k}>{editable ? <Input data-testid={`${k}-${l.id}`} className="w-28" type="number" step="0.001" min="0" dir="ltr" value={v[k]} onChange={(e) => setV(l.id, k, e.target.value)} /> : <span>{fmtKg(Number({ c1: l.class1_kg, c2: l.class2_kg, c3: l.class3_kg, sh: l.shrink_kg }[k] ?? 0))}</span>}</TableCell>
                  ))}
                  <TableCell data-testid={`pct-${l.id}`} className={below ? "text-red-700 font-bold" : "text-emerald-700 font-semibold"}>
                    <span dir="ltr">{lv.pct.toFixed(1)}%</span>
                    {below && <div className="text-xs font-normal">{T("below the limit: an incident report is required", "أقل من الحد: يلزم تقرير حادثة")}</div>}
                    {editable && Math.abs(lv.gap) > l.input_kg * r.limits.balance_tolerance_pct / 100 + 0.0005 && <div className="text-xs font-normal text-amber-700">{T("does not add up to", "لا يساوي")} {fmtKg(l.input_kg)}</div>}
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
        {editable && <div className="mt-4"><Button disabled={busy} data-testid="save-results" onClick={() => run("results", T("Results saved", "حُفظت النتائج"), { lines: r.lines.map((l) => ({ line_id: l.id, class1_kg: num(vals[l.id]?.c1), class2_kg: num(vals[l.id]?.c2), class3_kg: num(vals[l.id]?.c3), shrink_kg: num(vals[l.id]?.sh) })) })}>{T("Save results", "حفظ النتائج")}</Button></div>}
      </Card>

      {r.below_min && r.status === "Awaiting_Signoff" && (
        <Card className="p-6 space-y-3 border-red-300" data-testid="incident-box">
          <h2 className="font-semibold text-red-700">{T("Incident report: class 1 is below the limit", "تقرير الحادثة: الدرجة ١ أقل من الحد")}</h2>
          {r.incident ? <p className="text-sm" data-testid="incident-filed">{T("Filed by", "كتبه")} {r.incident.filed_by_name}: <b>{r.incident.reason_code}</b> {r.incident.note}</p> : null}
          {canSign && (
            <div className="grid sm:grid-cols-[1fr_2fr_auto] gap-3 items-end">
              <Field id="inc_reason" label={T("Reason", "السبب")}>
                <select id="inc_reason" className={selectClass} value={inc.code} onChange={(e) => setInc({ ...inc, code: e.target.value })}>
                  <option value="">—</option>{sortReasons.map((x) => <option key={x.code} value={x.code}>{isRTL && x.name_ar ? x.name_ar : x.name_en}</option>)}
                </select>
              </Field>
              <Field id="inc_note" label={T("Note", "ملاحظة")}><Input id="inc_note" value={inc.note} onChange={(e) => setInc({ ...inc, note: e.target.value })} /></Field>
              <Button disabled={busy || !inc.code} data-testid="file-incident" onClick={() => run("incident", T("Incident report filed", "سُجّل تقرير الحادثة"), { reason_code: inc.code, note: inc.note })}>{r.incident ? T("Update report", "تحديث التقرير") : T("File report", "تسجيل التقرير")}</Button>
            </div>
          )}
        </Card>
      )}

      {canSign && (
        <Card className="p-6 space-y-3" data-testid="signoff-box">
          <h2 className="font-semibold">{T("Quality sign-off: where does each class go?", "اعتماد الجودة: أين تذهب كل درجة؟")}</h2>
          <div className="grid sm:grid-cols-3 gap-3">
            {usedClasses.map((c) => (
              <Field key={c} id={`room_${c}`} label={classNames[c - 1]}>
                <select id={`room_${c}`} className={selectClass} value={roomSel[String(c)] ?? ""} onChange={(e) => setRoomSel((s) => ({ ...s, [String(c)]: e.target.value }))}>
                  <option value="">—</option>{rooms.data?.map((x) => <option key={x.id} value={x.id}>{nm(isRTL, x.name, x.name_ar)}</option>)}
                </select>
              </Field>
            ))}
          </div>
          <Button disabled={busy || usedClasses.some((c) => !roomSel[String(c)])} data-testid="sign-off" onClick={() => run("sign-off", T("Signed off: the classes are in stock", "تم الاعتماد: الدرجات دخلت المخزون"), { rooms: Object.fromEntries(usedClasses.map((c) => [String(c), Number(roomSel[String(c)])])) })}>{T("Sign off and store", "اعتماد وتخزين")}</Button>
        </Card>
      )}

      {r.outputs.length > 0 && (
        <Card className="p-6" data-testid="outputs">
          <h2 className="font-semibold mb-3">{T("What went into stock", "ما دخل المخزون")}</h2>
          <ul className="text-sm space-y-1">{r.outputs.map((o, i) => <li key={i}>{classNames[o.class_no - 1]}: <b>{fmtKg(o.kg)} kg</b> · {T("batch", "الدفعة")} <span dir="ltr">{o.batch_no}</span> · {T("grade", "الدرجة")} {o.grade}{o.unit_cost != null ? ` · ${Number(o.unit_cost).toFixed(2)} SAR/kg` : ""}</li>)}</ul>
        </Card>
      )}

      {["In_Progress", "Awaiting_Signoff"].includes(r.status) && <Button variant="ghost" className="text-red-700" onClick={() => setCancel("")}>{T("Cancel this run", "إلغاء هذا الفرز")}</Button>}
      <Dialog open={cancel !== null} onOpenChange={(o) => !o && setCancel(null)}>
        <DialogContent>
          <DialogHeader><DialogTitle>{T("Cancel the run", "إلغاء الفرز")}</DialogTitle></DialogHeader>
          <Field id="so_cancel" label={T("Reason", "السبب")}><Input id="so_cancel" value={cancel ?? ""} onChange={(e) => setCancel(e.target.value)} /></Field>
          <DialogFooter><Button variant="outline" onClick={() => setCancel(null)}>{T("Back", "رجوع")}</Button><Button disabled={!cancel?.trim() || busy} onClick={async () => { if (await run("cancel", T("Cancelled", "أُلغي"), { reason: cancel })) setCancel(null); }}>{T("Confirm", "تأكيد")}</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
