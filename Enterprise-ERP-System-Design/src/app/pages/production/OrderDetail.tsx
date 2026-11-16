import { useEffect, useState } from "react";
import { Link, useParams } from "react-router";
import { toast } from "sonner";
import { ArrowLeft, ArrowRight, Check, Clock, Plus, Trash2 } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { useApi, post } from "../../hooks/useApi";
import { ErrorBox, Field, fmtKg, selectClass, ColdRoom, nm } from "../../components/inventory/common";
import { StatusBadge, money } from "../../components/procurement/common";
import { ProductionNav, Spec, WasteReason, DECISION_AR, DECISION_EN } from "../../components/production/common";
import { fmtWait } from "../inventory/GoodsReceipts";

interface Detail {
  id: number; number: string; status: string; planned_input_kg: number; input_kg: number | null; issued_cost: number | null; output_kg: number | null; waste_kg: number | null;
  yield_pct: number | null; waste_pct: number | null; result: string | null; minutes_outside: number | null; over_time: number | null; overtime_note: string | null; yield_reason: string | null;
  created_by: number; results_by: number | null; inspected_by: number | null; unit_cost: number | null; notes: string | null; cancel_reason: string | null;
  available_kg: number; shortage_kg: number; minutes_running: number | null; over_time_now: boolean;
  spec: Spec & { input_item_name: string; input_item_name_ar: string | null; output_item_name: string; output_item_name_ar: string | null };
  inputs: { id: number; batch_no: string; expiry_date: string; cold_room_name: string | null; supplier_name: string | null; kg: number; unit_cost: number; cost: number }[];
  outputs: { id: number; grade: string; kg: number; batch_no: string | null; cold_room_name: string | null; value: number | null }[];
  waste: { id: number; reason_code: string; reason_name_en: string | null; reason_name_ar: string | null; waste_class: string; kg: number; decision: string; scrap_income: number | null; status: string; cost: number | null; decision_note: string | null }[];
  logs: { id: number; kind: string; value: number | null; person: string | null; ok: number | boolean; corrective_action: string | null; created_at: string }[];
}
interface ORow { grade: string; kg: string }
interface WRow { reason_code: string; kg: string; decision: string; scrap_income: string }
const KIND_EN: Record<string, string> = { wash_water: "Wash water °C", sanitiser: "Sanitiser ppm", hygiene: "Hygiene check" };
const KIND_AR: Record<string, string> = { wash_water: "مياه الغسيل °م", sanitiser: "المعقّم ppm", hygiene: "فحص النظافة" };

function Step({ n, title, done, children }: { n: number; title: string; done: boolean; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-base flex items-center gap-2">
          <span className={`h-6 w-6 rounded-full text-xs flex items-center justify-center ${done ? "bg-emerald-600 text-white" : "bg-muted text-muted-foreground"}`}>{done ? <Check className="h-3.5 w-3.5" /> : n}</span>
          {title}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">{children}</CardContent>
    </Card>
  );
}

export function OrderDetail() {
  const { id } = useParams();
  const { isRTL } = useI18n();
  const { me, can } = useAuth();
  const q = useApi<Detail>(`/prep-orders/${id}`);
  const rooms = useApi<ColdRoom[]>("/cold-rooms");
  const reasons = useApi<WasteReason[]>("/production-reports/waste-reasons");
  const d = q.data;
  const [error, setError] = useState("");
  const [issueKg, setIssueKg] = useState("");
  const [outs, setOuts] = useState<ORow[]>([]);
  const [wastes, setWastes] = useState<WRow[]>([]);
  const [yieldReason, setYieldReason] = useState("");
  const [overNote, setOverNote] = useState("");
  const [storeRoom, setStoreRoom] = useState<Record<string, { room: string; rack: string }>>({});
  const [wasteRoom, setWasteRoom] = useState("");
  const [inspNote, setInspNote] = useState("");
  const [log, setLog] = useState({ wash: "", san: "", person: "", passed: "1", action: "" });
  const [cancel, setCancel] = useState<string | null>(null);
  const [wd, setWd] = useState<{ id: number; action: "approve" | "reject" | "redecide"; note: string; decision: string; income: string; room: string } | null>(null);
  const Back = isRTL ? ArrowRight : ArrowLeft;
  const align = isRTL ? "text-right" : "text-left";
  const reasonName = (c: string) => { const r = reasons.data?.find((x) => x.code === c); return r ? nm(isRTL, r.name_en, r.name_ar) : c; };
  const decName = (x: string) => (isRTL ? DECISION_AR[x] : DECISION_EN[x]) ?? x;

  useEffect(() => {
    if (!d) return;
    setIssueKg(String(d.planned_input_kg));
    setOuts(d.outputs.length ? d.outputs.map((o) => ({ grade: o.grade, kg: String(o.kg) })) : [{ grade: "A", kg: "" }]);
    setWastes(d.waste.map((w) => ({ reason_code: w.reason_code, kg: String(w.kg), decision: w.decision, scrap_income: w.scrap_income != null ? String(w.scrap_income) : "" })));
    setYieldReason(d.yield_reason ?? ""); setOverNote(d.overtime_note ?? "");
    setStoreRoom(Object.fromEntries(d.outputs.map((o) => [o.grade, { room: "", rack: "" }])));
    // the editors start from the server only when the order or its status changes: a log, a reload or an error must not wipe what is being typed
  }, [d?.id, d?.status]);

  // the running timer ticks without reloading everything
  useEffect(() => {
    if (d?.status !== "In_Progress") return;
    const t = setInterval(() => q.reload(), 30000);
    return () => clearInterval(t);
  }, [d?.status]);

  const run = async (fn: () => Promise<unknown>, ok: string) => {
    setError("");
    try { await fn(); toast.success(ok); await q.reload(); } catch (e: any) { setError(e.message); }
  };
  const act = (path: string, body: object, ok: string) => run(() => post(`/prep-orders/${id}/${path}`, body), ok);

  const mine = !!d && d.created_by === me?.id;
  const iEntered = !!d && d.results_by === me?.id;
  const inputKg = d?.input_kg ?? 0;
  const outSum = outs.reduce((s, r) => s + (Number(r.kg) || 0), 0);
  const wasteSum = wastes.reduce((s, r) => s + (Number(r.kg) || 0), 0);
  const unacc = inputKg - outSum - wasteSum;
  const yieldPct = inputKg > 0 ? (outSum / inputKg) * 100 : 0;
  const wastePct = inputKg > 0 ? (wasteSum / inputKg) * 100 : 0;
  const running = d?.status === "In_Progress";
  const editResults = running || d?.status === "Awaiting_Inspection";
  const needWasteRoom = d?.waste.some((w) => ["rework", "by_product"].includes(w.decision)) ?? false;

  const saveResults = () =>
    act("results", {
      outputs: outs.filter((o) => o.kg !== "").map((o) => ({ grade: o.grade, kg: Number(o.kg) })),
      waste: wastes.filter((w) => w.kg !== "").map((w) => ({ reason_code: w.reason_code, kg: Number(w.kg), decision: w.decision, scrap_income: w.decision === "scrap_sale" ? Number(w.scrap_income || 0) : undefined })),
      yield_reason: yieldReason || undefined, overtime_note: overNote || undefined,
    }, isRTL ? "تم حفظ النتائج وإيقاف المؤقت" : "Results saved, timer stopped");

  const addLog = async (kind: "wash_water" | "sanitiser" | "hygiene") => {
    const body: Record<string, unknown> = { kind, prep_order_id: Number(id), corrective_action: log.action || undefined };
    if (kind === "wash_water") body.value = Number(log.wash);
    if (kind === "sanitiser") body.value = Number(log.san);
    if (kind === "hygiene") { body.person = log.person; body.passed = log.passed === "1"; }
    await run(() => post("/haccp-logs", body), isRTL ? "تم تسجيل القراءة" : "Reading recorded");
  };

  const wasteAction = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!wd) return;
    const path = wd.action === "redecide" ? "redecide" : "decide";
    const body = wd.action === "redecide" ? { decision: wd.decision, scrap_income: wd.decision === "scrap_sale" ? Number(wd.income || 0) : undefined, cold_room_id: wd.room ? Number(wd.room) : undefined, note: wd.note || undefined } : { action: wd.action, note: wd.note || undefined };
    await run(() => post(`/prep-orders/${id}/waste/${wd.id}/${path}`, body), isRTL ? "تم" : "Done");
    setWd(null);
  };

  return (
    <div className="space-y-6">
      <ProductionNav />
      <Link to="/production" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline"><Back className="h-4 w-4" />{isRTL ? "كل الأوامر" : "All orders"}</Link>
      <ErrorBox message={q.error || error} />

      {d && (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h1 className="text-3xl font-bold flex items-center gap-3"><span dir="ltr">{d.number}</span><StatusBadge status={d.status} isRTL={isRTL} />{d.result === "waste_high" && <Badge variant="destructive">{isRTL ? "هالك مرتفع" : "High waste"}</Badge>}</h1>
              <p className="text-muted-foreground mt-1">{nm(isRTL, d.spec.input_item_name, d.spec.input_item_name_ar)} → {nm(isRTL, d.spec.output_item_name, d.spec.output_item_name_ar)} · {isRTL ? "مخطط" : "planned"} <span dir="ltr">{fmtKg(d.planned_input_kg)} kg</span></p>
            </div>
            {d.minutes_running != null && (
              <div className={`flex items-center gap-2 rounded-lg border px-3 py-2 ${d.over_time_now ? "border-red-300 text-red-700 bg-red-50" : ""}`}>
                <Clock className="h-4 w-4" />
                <div><p className="text-[11px] text-muted-foreground">{isRTL ? `خارج التبريد (الحد ${d.spec.max_minutes_outside} د)` : `Outside the cold (limit ${d.spec.max_minutes_outside} min)`}</p><p className="font-semibold">{fmtWait(d.minutes_running, isRTL)}</p></div>
              </div>
            )}
          </div>
          {d.status === "Cancelled" && <div className="p-3 rounded-lg bg-slate-100 text-sm">{isRTL ? "أُلغي الأمر: " : "Cancelled: "}{d.cancel_reason}</div>}

          <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <Step n={1} title={isRTL ? "التخطيط والاعتماد" : "Plan & approval"} done={!["Draft", "Cancelled"].includes(d.status)}>
              <p className="text-sm">{isRTL ? "المتاح من الخامة (قابل للاستخدام):" : "Usable raw material in stock:"} <b dir="ltr">{fmtKg(d.available_kg)} kg</b>{d.shortage_kg > 0 && <span className="text-red-600" dir="ltr"> · {isRTL ? "نقص" : "short"} {fmtKg(d.shortage_kg)} kg</span>}</p>
              <div className="flex flex-wrap gap-2">
                {d.status === "Draft" && can("prep-orders", "approve") && !mine && <Button onClick={() => act("approve", {}, isRTL ? "تم الاعتماد" : "Approved")}>{isRTL ? "اعتماد الأمر" : "Approve order"}</Button>}
                {d.status === "Draft" && mine && <p className="text-xs text-muted-foreground">{isRTL ? "يعتمده مدير آخر. لا يمكنك اعتماد أمر خططته بنفسك." : "A manager approves it. You cannot approve an order you planned yourself."}</p>}
                {["Draft", "Approved"].includes(d.status) && d.shortage_kg > 0 && can("purchase-requests", "create") && (
                  <Button variant="outline" onClick={() => run(async () => { const r: any = await post(`/prep-orders/${id}/request-materials`, {}); toast.success(`${r.number}`); }, isRTL ? "أُنشئ طلب شراء" : "Purchase request created")}>{isRTL ? "طلب شراء للنقص" : "Request the shortage"}</Button>
                )}
                {["Draft", "Approved"].includes(d.status) && <Button variant="outline" onClick={() => setCancel("")}>{isRTL ? "إلغاء" : "Cancel"}</Button>}
              </div>
            </Step>

            <Step n={2} title={isRTL ? "صرف الخامة من غرفة التبريد (الأقدم صلاحية أولاً)" : "Issue material from the cold room (earliest expiry first)"} done={!!d.input_kg}>
              {d.status === "Approved" && can("prep-orders", "create") && (
                <div className="flex items-end gap-2">
                  <Field id="is_kg" label={isRTL ? "كجم" : "kg"}><Input id="is_kg" type="number" step="any" min="0" dir="ltr" className="w-32" value={issueKg} onChange={(e) => setIssueKg(e.target.value)} /></Field>
                  <Button onClick={() => act("start", { kg: Number(issueKg) }, isRTL ? "تم صرف الخامة وبدأ المؤقت" : "Material issued, timer started")}>{isRTL ? "صرف وبدء المؤقت" : "Issue & start timer"}</Button>
                </div>
              )}
              {d.inputs.length > 0 && (
                <div className="overflow-x-auto">
                  <Table>
                    <TableHeader><TableRow><TableHead className={align}>{isRTL ? "الدفعة" : "Batch"}</TableHead><TableHead className={align}>{isRTL ? "الغرفة" : "Room"}</TableHead><TableHead className={align}>{isRTL ? "كجم" : "kg"}</TableHead><TableHead className={align}>{isRTL ? "سعر/كجم" : "Cost/kg"}</TableHead><TableHead className={align}>{isRTL ? "التكلفة" : "Cost"}</TableHead></TableRow></TableHeader>
                    <TableBody>
                      {d.inputs.map((i) => (
                        <TableRow key={i.id}><TableCell className="font-mono text-xs">{i.batch_no}<span className="text-muted-foreground"> · {i.supplier_name ?? "—"}</span></TableCell><TableCell className="text-sm">{i.cold_room_name ?? "—"}</TableCell><TableCell className="font-mono">{fmtKg(i.kg)}</TableCell><TableCell dir="ltr" className="font-mono">{Number(i.unit_cost).toFixed(2)}</TableCell><TableCell dir="ltr" className="font-mono">{money(i.cost)}</TableCell></TableRow>
                      ))}
                    </TableBody>
                  </Table>
                  <p className="text-sm mt-2" dir="ltr">{isRTL ? "إجمالي الصرف" : "Issued"}: <b>{fmtKg(d.input_kg ?? 0)} kg</b> · <b>{money(d.issued_cost ?? 0)}</b> · {d.unit_cost?.toFixed(2)} /kg</p>
                </div>
              )}
            </Step>
          </div>

          <Step n={3} title={isRTL ? "سلامة الغذاء: مياه الغسيل والمعقّم والنظافة" : "Food safety: wash water, sanitiser, hygiene"} done={d.logs.some((l) => l.kind === "wash_water") && d.logs.some((l) => l.kind === "sanitiser")}>
            {["Approved", "In_Progress", "Awaiting_Inspection"].includes(d.status) && can("haccp-logs", "create") && (
              <div className="flex flex-wrap items-end gap-3">
                <Field id="lg_w" label={`${isRTL ? "مياه الغسيل °م" : "Wash water °C"}`}><div className="flex gap-1"><Input id="lg_w" type="number" step="0.1" dir="ltr" className="w-24" value={log.wash} onChange={(e) => setLog({ ...log, wash: e.target.value })} /><Button type="button" variant="outline" size="sm" disabled={log.wash === ""} onClick={() => addLog("wash_water")}>{isRTL ? "تسجيل" : "Log"}</Button></div></Field>
                <Field id="lg_s" label={isRTL ? "المعقّم ppm" : "Sanitiser ppm"}><div className="flex gap-1"><Input id="lg_s" type="number" step="1" dir="ltr" className="w-24" value={log.san} onChange={(e) => setLog({ ...log, san: e.target.value })} /><Button type="button" variant="outline" size="sm" disabled={log.san === ""} onClick={() => addLog("sanitiser")}>{isRTL ? "تسجيل" : "Log"}</Button></div></Field>
                <Field id="lg_p" label={isRTL ? "فحص نظافة: الاسم" : "Hygiene check: name"}><div className="flex gap-1"><Input id="lg_p" className="w-32" value={log.person} onChange={(e) => setLog({ ...log, person: e.target.value })} /><select aria-label="hygiene result" className={`${selectClass} w-24`} value={log.passed} onChange={(e) => setLog({ ...log, passed: e.target.value })}><option value="1">{isRTL ? "سليم" : "Pass"}</option><option value="0">{isRTL ? "غير سليم" : "Fail"}</option></select><Button type="button" variant="outline" size="sm" disabled={!log.person} onClick={() => addLog("hygiene")}>{isRTL ? "تسجيل" : "Log"}</Button></div></Field>
                <Field id="lg_a" label={isRTL ? "إجراء تصحيحي (إن كانت القراءة خارج النطاق)" : "Corrective action (if out of range)"}><Input id="lg_a" className="w-64" value={log.action} onChange={(e) => setLog({ ...log, action: e.target.value })} /></Field>
              </div>
            )}
            {d.logs.length > 0 && (
              <Table>
                <TableBody>
                  {d.logs.map((l) => (
                    <TableRow key={l.id}>
                      <TableCell className="text-sm">{(isRTL ? KIND_AR : KIND_EN)[l.kind]}</TableCell>
                      <TableCell dir="ltr" className="font-mono text-sm">{l.value ?? l.person}</TableCell>
                      <TableCell>{Number(l.ok) === 1 ? <Badge className="bg-emerald-600 hover:bg-emerald-600">OK</Badge> : <Badge variant="destructive">{isRTL ? "خارج النطاق" : "Out of range"}</Badge>}</TableCell>
                      <TableCell className="text-xs text-muted-foreground">{l.corrective_action ?? ""}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </Step>

          {(running || d.status === "Awaiting_Inspection" || d.status === "Completed") && (
            <Step n={4} title={isRTL ? "النتائج: المنتج الجاهز بالدرجات والهالك بالأسباب" : "Results: finished product by grade, waste by reason"} done={!["In_Progress"].includes(d.status)}>
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div className="space-y-2">
                  <p className="text-sm font-medium">{isRTL ? "المنتج الجاهز (كجم)" : "Finished product (kg)"}</p>
                  {outs.map((o, i) => (
                    <div key={i} className="flex gap-2 items-center">
                      <select aria-label={`out grade ${i + 1}`} className={`${selectClass} w-20`} disabled={!editResults} value={o.grade} onChange={(e) => setOuts((s) => s.map((r, j) => (j === i ? { ...r, grade: e.target.value } : r)))}><option>A</option><option>B</option><option>C</option></select>
                      <Input aria-label={`out kg ${i + 1}`} className="w-28" type="number" step="any" min="0" dir="ltr" disabled={!editResults} value={o.kg} onChange={(e) => setOuts((s) => s.map((r, j) => (j === i ? { ...r, kg: e.target.value } : r)))} />
                      {editResults && <Button type="button" variant="ghost" size="sm" aria-label="remove" disabled={outs.length === 1} onClick={() => setOuts((s) => s.filter((_, j) => j !== i))}><Trash2 className="h-4 w-4" /></Button>}
                    </div>
                  ))}
                  {editResults && <Button type="button" variant="outline" size="sm" className="gap-1" onClick={() => setOuts((s) => [...s, { grade: "B", kg: "" }])}><Plus className="h-4 w-4" />{isRTL ? "درجة" : "Grade"}</Button>}
                </div>
                <div className="space-y-2">
                  <p className="text-sm font-medium">{isRTL ? "الهالك بالأسباب والقرار" : "Waste by reason, with the decision"}</p>
                  {wastes.map((w, i) => (
                    <div key={i} className="flex flex-wrap gap-2 items-center">
                      <select aria-label={`waste reason ${i + 1}`} className={`${selectClass} w-40`} disabled={!editResults} value={w.reason_code} onChange={(e) => { const r = reasons.data?.find((x) => x.code === e.target.value); setWastes((s) => s.map((x, j) => (j === i ? { ...x, reason_code: e.target.value, decision: r?.default_decision ?? x.decision } : x))); }}>
                        <option value="">{isRTL ? "السبب…" : "Reason…"}</option>
                        {reasons.data?.map((r) => <option key={r.code} value={r.code}>{nm(isRTL, r.name_en, r.name_ar)} ({r.waste_class === "high" ? (isRTL ? "عالية القيمة" : "high") : isRTL ? "منخفضة" : "low"})</option>)}
                      </select>
                      <Input aria-label={`waste kg ${i + 1}`} className="w-24" type="number" step="any" min="0" dir="ltr" disabled={!editResults} value={w.kg} onChange={(e) => setWastes((s) => s.map((x, j) => (j === i ? { ...x, kg: e.target.value } : x)))} />
                      <select aria-label={`waste decision ${i + 1}`} className={`${selectClass} w-44`} disabled={!editResults} value={w.decision} onChange={(e) => setWastes((s) => s.map((x, j) => (j === i ? { ...x, decision: e.target.value } : x)))}>
                        {Object.keys(DECISION_EN).map((k) => <option key={k} value={k}>{decName(k)}</option>)}
                      </select>
                      {w.decision === "scrap_sale" && <Input aria-label={`waste income ${i + 1}`} className="w-24" type="number" step="0.01" min="0" dir="ltr" placeholder={isRTL ? "الدخل" : "Income"} disabled={!editResults} value={w.scrap_income} onChange={(e) => setWastes((s) => s.map((x, j) => (j === i ? { ...x, scrap_income: e.target.value } : x)))} />}
                      {editResults && <Button type="button" variant="ghost" size="sm" aria-label="remove" onClick={() => setWastes((s) => s.filter((_, j) => j !== i))}><Trash2 className="h-4 w-4" /></Button>}
                    </div>
                  ))}
                  {editResults && <Button type="button" variant="outline" size="sm" className="gap-1" onClick={() => setWastes((s) => [...s, { reason_code: reasons.data?.[0]?.code ?? "", kg: "", decision: reasons.data?.[0]?.default_decision ?? "destroy", scrap_income: "" }])}><Plus className="h-4 w-4" />{isRTL ? "هالك" : "Waste"}</Button>}
                </div>
              </div>
              <p className="text-sm" dir="ltr">
                {isRTL ? "المدخل" : "Input"} {fmtKg(inputKg)} kg · {isRTL ? "المنتج" : "Output"} {fmtKg(outSum)} · {isRTL ? "الهالك" : "Waste"} {fmtKg(wasteSum)} · <span className={unacc > inputKg * 0.03 ? "text-red-600" : ""}>{isRTL ? "غير محسوب" : "Unaccounted"} {fmtKg(Math.max(0, unacc))}</span> · {isRTL ? "الإنتاجية" : "Yield"} <b>{yieldPct.toFixed(1)}%</b> ({isRTL ? "المخطط" : "plan"} {d.spec.planned_yield_pct}%) · {isRTL ? "الهالك" : "Waste"} <b>{wastePct.toFixed(1)}%</b> ({isRTL ? "الحد" : "limit"} {d.spec.waste_high_pct}%)
              </p>
              {editResults && (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <Field id="yr" label={isRTL ? "سبب انخفاض الإنتاجية (إلزامي إن كانت أقل من المخطط)" : "Reason for low yield (required below plan)"}><Input id="yr" value={yieldReason} onChange={(e) => setYieldReason(e.target.value)} /></Field>
                  <Field id="on" label={isRTL ? "ملاحظة تجاوز الوقت خارج التبريد (إلزامية عند التجاوز)" : "Note when over the time outside the cold (required)"}><Input id="on" value={overNote} onChange={(e) => setOverNote(e.target.value)} /></Field>
                </div>
              )}
              {editResults && can("prep-orders", "create") && <Button onClick={saveResults}>{running ? (isRTL ? "إيقاف المؤقت وحفظ النتائج" : "Stop timer & save results") : isRTL ? "تعديل النتائج" : "Update results"}</Button>}
              {d.minutes_outside != null && <p className="text-sm text-muted-foreground">{isRTL ? "مدة الوقت خارج التبريد:" : "Time outside the cold:"} <b className={Number(d.over_time) === 1 ? "text-red-600" : ""}>{fmtWait(d.minutes_outside, isRTL)}</b>{d.overtime_note ? ` — ${d.overtime_note}` : ""}</p>}
            </Step>
          )}

          {d.status === "Awaiting_Inspection" && (
            <Step n={5} title={isRTL ? "الفحص النهائي والتخزين" : "Final inspection & put-away"} done={false}>
              {iEntered && <p className="text-sm text-muted-foreground">{isRTL ? "أدخلتَ النتائج بنفسك: يجب أن يفحصها شخص آخر." : "You entered the results: another person must do the final inspection."}</p>}
              {can("prep-orders", "approve") && (
                <>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    {d.outputs.map((o) => (
                      <div key={o.id} className="flex gap-2 items-end">
                        <Field id={`st_${o.grade}`} label={`${isRTL ? "غرفة الدرجة" : "Cold room, grade"} ${o.grade} (${fmtKg(o.kg)} kg)`} className="flex-1">
                          <select id={`st_${o.grade}`} className={selectClass} value={storeRoom[o.grade]?.room ?? ""} onChange={(e) => setStoreRoom((s) => ({ ...s, [o.grade]: { ...(s[o.grade] ?? { rack: "" }), room: e.target.value } }))}>
                            <option value="">{isRTL ? "اختر…" : "Choose…"}</option>
                            {rooms.data?.map((r) => <option key={r.id} value={r.id}>{nm(isRTL, r.name, r.name_ar)}</option>)}
                          </select>
                        </Field>
                        <Input aria-label={`rack ${o.grade}`} className="w-20" dir="ltr" placeholder={isRTL ? "رف" : "Rack"} value={storeRoom[o.grade]?.rack ?? ""} onChange={(e) => setStoreRoom((s) => ({ ...s, [o.grade]: { ...(s[o.grade] ?? { room: "" }), rack: e.target.value } }))} />
                      </div>
                    ))}
                    {needWasteRoom && (
                      <Field id="wr" label={isRTL ? "غرفة الهالك العائد للمخزون (إعادة تصنيع / منتج ثانوي)" : "Cold room for waste going back to stock"}>
                        <select id="wr" className={selectClass} value={wasteRoom} onChange={(e) => setWasteRoom(e.target.value)}>
                          <option value="">{isRTL ? "اختر…" : "Choose…"}</option>
                          {rooms.data?.map((r) => <option key={r.id} value={r.id}>{nm(isRTL, r.name, r.name_ar)}</option>)}
                        </select>
                      </Field>
                    )}
                    <Field id="in" label={isRTL ? "ملاحظة المفتش" : "Inspector's note"} className="sm:col-span-2"><Input id="in" value={inspNote} onChange={(e) => setInspNote(e.target.value)} /></Field>
                  </div>
                  <Button onClick={() => act("sign-off", { store: d.outputs.map((o) => ({ grade: o.grade, cold_room_id: Number(storeRoom[o.grade]?.room || 0) || undefined, rack: storeRoom[o.grade]?.rack || undefined })), waste_room_id: wasteRoom ? Number(wasteRoom) : undefined, note: inspNote || undefined }, isRTL ? "تم الفحص والتخزين" : "Signed off and stored")}>{isRTL ? "اعتماد الفحص وتخزين المنتج" : "Sign off & store"}</Button>
                </>
              )}
            </Step>
          )}

          {d.status === "Completed" && d.outputs.length > 0 && (
            <Card>
              <CardHeader className="pb-2"><CardTitle className="text-base">{isRTL ? "المنتج الجاهز في المخزون" : "Finished goods in stock"}</CardTitle></CardHeader>
              <CardContent className="overflow-x-auto">
                <Table>
                  <TableHeader><TableRow><TableHead className={align}>{isRTL ? "الدرجة" : "Grade"}</TableHead><TableHead className={align}>{isRTL ? "كجم" : "kg"}</TableHead><TableHead className={align}>{isRTL ? "الدفعة" : "Batch"}</TableHead><TableHead className={align}>{isRTL ? "الغرفة" : "Room"}</TableHead><TableHead className={align}>{isRTL ? "القيمة" : "Value"}</TableHead></TableRow></TableHeader>
                  <TableBody>{d.outputs.map((o) => <TableRow key={o.id}><TableCell>{o.grade}</TableCell><TableCell className="font-mono">{fmtKg(o.kg)}</TableCell><TableCell className="font-mono text-xs">{o.batch_no}</TableCell><TableCell>{o.cold_room_name}</TableCell><TableCell dir="ltr" className="font-mono">{o.value != null ? money(o.value) : "—"}</TableCell></TableRow>)}</TableBody>
                </Table>
              </CardContent>
            </Card>
          )}

          {d.waste.length > 0 && ["Awaiting_Inspection", "Completed"].includes(d.status) && (
            <Card>
              <CardHeader className="pb-2"><CardTitle className="text-base">{isRTL ? "قرارات الهالك" : "Waste decisions"}</CardTitle></CardHeader>
              <CardContent className="overflow-x-auto">
                <Table>
                  <TableHeader><TableRow><TableHead className={align}>{isRTL ? "السبب" : "Reason"}</TableHead><TableHead className={align}>{isRTL ? "كجم" : "kg"}</TableHead><TableHead className={align}>{isRTL ? "القرار" : "Decision"}</TableHead><TableHead className={align}>{isRTL ? "التكلفة" : "Cost"}</TableHead><TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead><TableHead /></TableRow></TableHeader>
                  <TableBody>
                    {d.waste.map((w) => (
                      <TableRow key={w.id}>
                        <TableCell>{reasonName(w.reason_code)}</TableCell>
                        <TableCell className="font-mono">{fmtKg(w.kg)}</TableCell>
                        <TableCell className="text-sm">{decName(w.decision)}{w.scrap_income != null ? ` · ${w.scrap_income}` : ""}</TableCell>
                        <TableCell dir="ltr" className="font-mono">{w.cost != null ? money(w.cost) : "—"}</TableCell>
                        <TableCell><StatusBadge status={w.status === "Planned" ? "Draft" : w.status} isRTL={isRTL} />{w.decision_note && <p className="text-xs text-muted-foreground mt-1">{w.decision_note}</p>}</TableCell>
                        <TableCell className="whitespace-nowrap">
                          {w.status === "Pending" && can("gl-entries", "approve") && d.inspected_by !== me?.id && (<><Button size="sm" onClick={() => setWd({ id: w.id, action: "approve", note: "", decision: w.decision, income: "", room: "" })}>{isRTL ? "اعتماد" : "Approve"}</Button>{" "}<Button size="sm" variant="outline" onClick={() => setWd({ id: w.id, action: "reject", note: "", decision: w.decision, income: "", room: "" })}>{isRTL ? "إعادة" : "Return"}</Button></>)}
                          {["Pending", "Returned"].includes(w.status) && can("prep-orders", "approve") && <Button size="sm" variant="ghost" onClick={() => setWd({ id: w.id, action: "redecide", note: "", decision: "rework", income: "", room: "" })}>{isRTL ? "قرار آخر" : "Re-decide"}</Button>}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
                {d.waste.some((w) => w.status === "Pending") && <p className="text-xs text-muted-foreground mt-2">{isRTL ? "الإتلاف والبيع كخردة يعتمدهما شخص من المالية غير مفتش الأمر." : "Destroy and scrap sale are approved by Finance, by someone other than the inspector."}</p>}
              </CardContent>
            </Card>
          )}
        </>
      )}

      <Dialog open={cancel !== null} onOpenChange={(o) => !o && setCancel(null)}>
        <DialogContent>
          <form onSubmit={(e) => { e.preventDefault(); act("cancel", { reason: cancel }, isRTL ? "أُلغي الأمر" : "Order cancelled"); setCancel(null); }} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "إلغاء الأمر" : "Cancel the order"}</DialogTitle></DialogHeader>
            <Field id="cn" label={isRTL ? "السبب (إلزامي)" : "Reason (required)"}><Input id="cn" required value={cancel ?? ""} onChange={(e) => setCancel(e.target.value)} /></Field>
            <DialogFooter><Button type="submit">{isRTL ? "إلغاء الأمر" : "Cancel order"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!wd} onOpenChange={(o) => !o && setWd(null)}>
        <DialogContent>
          <form onSubmit={wasteAction} className="space-y-4">
            <DialogHeader><DialogTitle>{wd?.action === "approve" ? (isRTL ? "اعتماد قرار الهالك" : "Approve the waste decision") : wd?.action === "reject" ? (isRTL ? "إعادة القرار للمفتش" : "Return the decision") : isRTL ? "قرار جديد للهالك" : "New decision for the waste"}</DialogTitle></DialogHeader>
            {wd?.action === "redecide" && (
              <>
                <Field id="wd_dec" label={isRTL ? "القرار" : "Decision"}><select id="wd_dec" className={selectClass} value={wd.decision} onChange={(e) => setWd((s) => s && { ...s, decision: e.target.value })}>{Object.keys(DECISION_EN).map((k) => <option key={k} value={k}>{decName(k)}</option>)}</select></Field>
                {wd.decision === "scrap_sale" && <Field id="wd_inc" label={isRTL ? "الدخل من البيع" : "Sale income"}><Input id="wd_inc" type="number" step="0.01" min="0" dir="ltr" value={wd.income} onChange={(e) => setWd((s) => s && { ...s, income: e.target.value })} /></Field>}
                {["rework", "by_product"].includes(wd.decision) && <Field id="wd_room" label={isRTL ? "غرفة التبريد" : "Cold room"}><select id="wd_room" className={selectClass} required value={wd.room} onChange={(e) => setWd((s) => s && { ...s, room: e.target.value })}><option value="">{isRTL ? "اختر…" : "Choose…"}</option>{rooms.data?.map((r) => <option key={r.id} value={r.id}>{nm(isRTL, r.name, r.name_ar)}</option>)}</select></Field>}
              </>
            )}
            <Field id="wd_note" label={wd?.action === "reject" ? (isRTL ? "ملاحظة (إلزامية)" : "Note (required)") : isRTL ? "ملاحظة" : "Note"}><Input id="wd_note" required={wd?.action === "reject"} value={wd?.note ?? ""} onChange={(e) => setWd((s) => s && { ...s, note: e.target.value })} /></Field>
            <DialogFooter><Button type="submit">{isRTL ? "تأكيد" : "Confirm"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
