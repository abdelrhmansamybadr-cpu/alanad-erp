import { useEffect, useState } from "react";
import { Link, useParams } from "react-router";
import { toast } from "sonner";
import { ArrowLeft, ArrowRight, Check, Clock, Plus, Trash2, Thermometer } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { useApi, post } from "../../hooks/useApi";
import { InventoryNav } from "../../components/inventory/InventoryNav";
import { ErrorBox, Field, fmtKg, selectClass, ColdRoom, nm } from "../../components/inventory/common";
import { StatusBadge } from "../../components/procurement/common";
import { fmtWait } from "./GoodsReceipts";
import { ThreePeople, ThreeDelivery } from "../../components/inventory/ThreePeople";

interface DLine {
  id: number; item_id: number; item_name: string; item_name_ar: string | null; grade: string; received_kg: number; rejected_kg: number;
  reject_reason_code: string | null; sample_kg: number | null; note: string | null; accepted_kg: number; batch_id: number | null;
  cold_room_id: number | null; stored_at: string | null; shelf_life_days: number | null;
}
interface POLine { item_id: number; item_name: string; item_name_ar: string | null; ordered: number; received: number; remaining: number }
interface Delivery extends Partial<ThreeDelivery> {
  id: number; number: string; status: string; driver_name: string; driver_id_no: string | null; vehicle_plate: string | null; arrived_at: string; purchase_order_id: number;
  gross_kg: number | null; tare_kg: number | null; net_kg: number | null; truck_air_temp: number | null; product_temp: number | null; temp_ok: number | null;
  temp_decision_note: string | null; reject_reason_code: string | null; reject_note: string | null; lines: DLine[];
  po: { id: number; number: string; supplier_name: string | null; lines: POLine[] };
  limits: { truck_air_max: number; product_temp_max: number; product_temp_tolerance: number; net_tolerance_pct: number; max_wait_minutes: number };
  minutes_waiting: number | null; waiting_too_long: boolean;
}
interface Reason { code: string; gate: string; name_en: string; name_ar: string | null }
interface QRow { item_id: string; grade: string; received_kg: string; rejected_kg: string; reason: string; sample_kg: string }
interface PRow { cold_room_id: string; rack: string; position: string; expiry_date: string }

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

export function DeliveryDetail() {
  const { id } = useParams();
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const dq = useApi<Delivery>(`/deliveries/${id}`);
  const rooms = useApi<ColdRoom[]>("/cold-rooms");
  const reasonsQ = useApi<Reason[]>("/reject-reasons");
  const d = dq.data;
  const [error, setError] = useState("");
  const [gross, setGross] = useState("");
  const [truckT, setTruckT] = useState("");
  const [prodT, setProdT] = useState("");
  const [tare, setTare] = useState("");
  const [ql, setQl] = useState<QRow[]>([]);
  const [pa, setPa] = useState<Record<number, PRow>>({});
  const [closeOrder, setCloseOrder] = useState(false);
  const [decision, setDecision] = useState<{ kind: "accept" | "reject"; note: string } | null>(null);
  const [rej, setRej] = useState<{ code: string; note: string } | null>(null);
  const Back = isRTL ? ArrowRight : ArrowLeft;
  const align = isRTL ? "text-right" : "text-left";

  // reject reasons for this gate (receiving first, then the general ones), one per code
  const reasons = (() => {
    const m = new Map<string, Reason>();
    [...(reasonsQ.data ?? [])].sort((a) => (a.gate === "receiving" ? -1 : 1)).forEach((r) => { if ((r.gate === "receiving" || r.gate === "general") && !m.has(r.code)) m.set(r.code, r); });
    return [...m.values()];
  })();

  useEffect(() => {
    if (!d) return;
    setGross(d.gross_kg != null ? String(d.gross_kg) : "");
    setTare(d.tare_kg != null ? String(d.tare_kg) : "");
    setTruckT(d.truck_air_temp != null ? String(d.truck_air_temp) : "");
    setProdT(d.product_temp != null ? String(d.product_temp) : "");
    setQl(
      d.lines.length
        ? d.lines.map((l) => ({ item_id: String(l.item_id), grade: l.grade, received_kg: String(l.received_kg), rejected_kg: l.rejected_kg ? String(l.rejected_kg) : "", reason: l.reject_reason_code ?? "", sample_kg: l.sample_kg != null ? String(l.sample_kg) : "" }))
        : d.po.lines.map((p) => ({ item_id: String(p.item_id), grade: "A", received_kg: "", rejected_kg: "", reason: "", sample_kg: "" }))
    );
    setPa(Object.fromEntries(d.lines.filter((l) => !l.stored_at).map((l) => [l.id, { cold_room_id: "", rack: "", position: "", expiry_date: "" }])));
    setCloseOrder(false);
  }, [d]);

  const run = async (fn: () => Promise<unknown>, ok: string) => {
    setError("");
    try { await fn(); toast.success(ok); await dq.reload(); } catch (e: any) { setError(e.message); dq.reload(); }
  };
  const act = (path: string, body: object, ok: string) => run(() => post(`/deliveries/${id}/${path}`, body), ok);

  const open = d && !["Stored", "Rejected"].includes(d.status);
  const editable = d && ["Arrived", "Temp_Hold", "Checking"].includes(d.status);
  const setQ = (i: number, k: keyof QRow, v: string) => setQl((s) => s.map((r, j) => (j === i ? { ...r, [k]: v } : r)));
  const onceStored = d?.lines.some((l) => l.stored_at);

  const saveQuality = () =>
    act("quality", { lines: ql.filter((r) => r.received_kg !== "").map((r) => ({ item_id: Number(r.item_id), grade: r.grade, received_kg: Number(r.received_kg), rejected_kg: r.rejected_kg === "" ? 0 : Number(r.rejected_kg), reject_reason_code: r.reason || undefined, sample_kg: r.sample_kg === "" ? undefined : Number(r.sample_kg) })) }, isRTL ? "تم حفظ نتائج الفحص" : "Quality results saved");
  const putAway = () => {
    const lines = Object.entries(pa).filter(([, v]) => v.cold_room_id).map(([lid, v]) => ({ line_id: Number(lid), cold_room_id: Number(v.cold_room_id), rack: v.rack || undefined, position: v.position || undefined, expiry_date: v.expiry_date || undefined }));
    return act("put-away", { lines, close_order: closeOrder }, isRTL ? "تم التخزين" : "Stored");
  };

  const itemName = (l: { item_name: string; item_name_ar: string | null }) => nm(isRTL, l.item_name, l.item_name_ar);

  return (
    <div className="space-y-6">
      <InventoryNav />
      <Link to="/inventory/receiving" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline">
        <Back className="h-4 w-4" />{isRTL ? "كل الشاحنات" : "All deliveries"}
      </Link>
      <ErrorBox message={dq.error || error} />

      {d && (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h1 className="text-3xl font-bold flex items-center gap-3"><span dir="ltr">{d.number}</span><StatusBadge status={d.status} isRTL={isRTL} /></h1>
              <p className="text-muted-foreground mt-1">
                {d.po.supplier_name} · <Link className="text-primary hover:underline" to={`/procurement/purchase-orders/${d.purchase_order_id}`} dir="ltr">{d.po.number}</Link> · {d.driver_name}{d.vehicle_plate ? ` · ${d.vehicle_plate}` : ""}
              </p>
            </div>
            {d.minutes_waiting != null && (
              <div className={`flex items-center gap-2 rounded-lg border px-3 py-2 ${d.waiting_too_long ? "border-red-300 text-red-700 bg-red-50" : ""}`}>
                <Clock className="h-4 w-4" />
                <div><p className="text-[11px] text-muted-foreground">{isRTL ? "مدة الانتظار منذ الوصول" : "Time since arrival"}</p><p className="font-semibold">{fmtWait(d.minutes_waiting, isRTL)}</p></div>
              </div>
            )}
          </div>

          {d.status === "Rejected" && (
            <div className="p-3 rounded-lg bg-red-50 text-red-700 text-sm">{isRTL ? "رُفضت الشاحنة. السبب: " : "Truck rejected. Reason: "}<b>{reasons.find((r) => r.code === d.reject_reason_code)?.[isRTL ? "name_ar" : "name_en"] ?? d.reject_reason_code}</b>{d.reject_note ? ` — ${d.reject_note}` : ""}</div>
          )}

          <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <Step n={1} title={isRTL ? "الوزن القائم (الشاحنة محمّلة)" : "Gross weight (truck loaded)"} done={d.gross_kg != null}>
              <div className="flex items-end gap-2">
                <Field id="gw" label={isRTL ? "كجم" : "kg"}><Input id="gw" type="number" step="any" min="0" dir="ltr" disabled={!editable || !can("deliveries", "create")} value={gross} onChange={(e) => setGross(e.target.value)} className="w-40" /></Field>
                {editable && can("deliveries", "create") && <Button variant="outline" onClick={() => act("weigh-in", { gross_kg: Number(gross) }, isRTL ? "تم تسجيل الوزن" : "Weight saved")}>{isRTL ? "حفظ" : "Save"}</Button>}
              </div>
            </Step>

            <Step n={2} title={isRTL ? "فحص الحرارة" : "Temperature check"} done={d.temp_ok === 1 || (d.temp_ok === 0 && d.status !== "Temp_Hold")}>
              <p className="text-xs text-muted-foreground" dir="ltr">
                {isRTL ? "الحد:" : "Limits:"} truck ≤ {d.limits.truck_air_max}° · product ≤ {d.limits.product_temp_max}° (+{d.limits.product_temp_tolerance})
              </p>
              <div className="flex flex-wrap items-end gap-2">
                <Field id="ta" label={isRTL ? "هواء الشاحنة °م" : "Truck air °C"}><Input id="ta" type="number" step="0.1" dir="ltr" disabled={!editable} value={truckT} onChange={(e) => setTruckT(e.target.value)} className="w-32" /></Field>
                <Field id="tp" label={isRTL ? "المنتج (مسبار) °م" : "Product probe °C"}><Input id="tp" type="number" step="0.1" dir="ltr" disabled={!editable} value={prodT} onChange={(e) => setProdT(e.target.value)} className="w-32" /></Field>
                {editable && can("deliveries", "create") && (
                  <Button variant="outline" className="gap-2" onClick={() => act("temperature", { truck_air_temp: Number(truckT), product_temp: Number(prodT) }, isRTL ? "تم تسجيل الحرارة" : "Temperature recorded")}><Thermometer className="h-4 w-4" />{isRTL ? "تسجيل" : "Record"}</Button>
                )}
              </div>
              {d.status === "Temp_Hold" && (
                <div className="p-3 rounded-lg bg-red-50 text-red-700 text-sm space-y-2">
                  <p>{isRTL ? "الحرارة خارج النطاق. القرار لمدير الجودة." : "Temperature out of range. The quality manager decides."}</p>
                  {can("deliveries", "approve") && (
                    <div className="flex gap-2">
                      <Button size="sm" onClick={() => setDecision({ kind: "accept", note: "" })}>{isRTL ? "قبول استثنائي" : "Accept anyway"}</Button>
                      <Button size="sm" variant="outline" onClick={() => setDecision({ kind: "reject", note: "" })}>{isRTL ? "إعادة الشاحنة" : "Send the truck back"}</Button>
                    </div>
                  )}
                </div>
              )}
              {d.temp_decision_note && <p className="text-xs text-muted-foreground">{isRTL ? "قرار المدير: " : "Manager decision: "}{d.temp_decision_note}</p>}
            </Step>
          </div>

          <Step n={3} title={isRTL ? "فحص الجودة والكمية: العينة والدرجة والمرفوض" : "Quality & quantity: sampling, grade, rejects"} done={d.lines.length > 0 && d.status !== "Checking" ? true : d.lines.length > 0}>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className={align}>{isRTL ? "الصنف" : "Item"}</TableHead>
                    <TableHead className={align}>{isRTL ? "الدرجة" : "Grade"}</TableHead>
                    <TableHead className={align}>{isRTL ? "الموزون كجم" : "Weighed kg"}</TableHead>
                    <TableHead className={align}>{isRTL ? "المرفوض كجم" : "Rejected kg"}</TableHead>
                    <TableHead className={align}>{isRTL ? "سبب الرفض" : "Reject reason"}</TableHead>
                    <TableHead className={align}>{isRTL ? "العينة كجم" : "Sample kg"}</TableHead>
                    <TableHead />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {ql.map((r, i) => (
                    <TableRow key={i}>
                      <TableCell>
                        <select aria-label={`q item ${i + 1}`} className={`${selectClass} min-w-[150px]`} disabled={d.status !== "Checking"} value={r.item_id} onChange={(e) => setQ(i, "item_id", e.target.value)}>
                          {d.po.lines.map((p) => <option key={p.item_id} value={p.item_id}>{itemName(p)} ({fmtKg(p.remaining)} kg left)</option>)}
                        </select>
                      </TableCell>
                      <TableCell>
                        <select aria-label={`q grade ${i + 1}`} className={`${selectClass} w-16`} disabled={d.status !== "Checking"} value={r.grade} onChange={(e) => setQ(i, "grade", e.target.value)}><option>A</option><option>B</option><option>C</option></select>
                      </TableCell>
                      <TableCell><Input aria-label={`q weighed ${i + 1}`} className="w-24" type="number" step="any" min="0" dir="ltr" disabled={d.status !== "Checking"} value={r.received_kg} onChange={(e) => setQ(i, "received_kg", e.target.value)} /></TableCell>
                      <TableCell><Input aria-label={`q rejected ${i + 1}`} className="w-24" type="number" step="any" min="0" dir="ltr" disabled={d.status !== "Checking"} value={r.rejected_kg} onChange={(e) => setQ(i, "rejected_kg", e.target.value)} /></TableCell>
                      <TableCell>
                        <select aria-label={`q reason ${i + 1}`} className={`${selectClass} min-w-[140px]`} disabled={d.status !== "Checking"} required={Number(r.rejected_kg) > 0} value={r.reason} onChange={(e) => setQ(i, "reason", e.target.value)}>
                          <option value="">—</option>
                          {reasons.map((x) => <option key={x.code} value={x.code}>{nm(isRTL, x.name_en, x.name_ar)}</option>)}
                        </select>
                      </TableCell>
                      <TableCell><Input aria-label={`q sample ${i + 1}`} className="w-20" type="number" step="any" min="0" dir="ltr" disabled={d.status !== "Checking"} value={r.sample_kg} onChange={(e) => setQ(i, "sample_kg", e.target.value)} /></TableCell>
                      <TableCell>{d.status === "Checking" && <Button variant="ghost" size="sm" aria-label="remove" onClick={() => setQl((s) => s.filter((_, j) => j !== i))}><Trash2 className="h-4 w-4" /></Button>}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
            {d.status === "Checking" && can("deliveries", "create") && (
              <div className="flex gap-2">
                <Button variant="outline" size="sm" className="gap-2" onClick={() => setQl((s) => [...s, { item_id: String(d.po.lines[0]?.item_id ?? ""), grade: "A", received_kg: "", rejected_kg: "", reason: "", sample_kg: "" }])}><Plus className="h-4 w-4" />{isRTL ? "سطر (درجة أخرى)" : "Line (another grade)"}</Button>
                <Button onClick={saveQuality}>{isRTL ? "حفظ نتائج الفحص" : "Save quality results"}</Button>
              </div>
            )}
          </Step>

          <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <Step n={4} title={isRTL ? "الوزن الفارغ والصافي" : "Weigh-out and net weight"} done={d.net_kg != null}>
              <div className="flex items-end gap-2">
                <Field id="tw" label={isRTL ? "وزن الشاحنة فارغة كجم" : "Empty truck (tare) kg"}><Input id="tw" type="number" step="any" min="0" dir="ltr" disabled={d.status !== "Checking" || d.lines.length === 0} value={tare} onChange={(e) => setTare(e.target.value)} className="w-40" /></Field>
                {d.status === "Checking" && d.lines.length > 0 && can("deliveries", "create") && <Button variant="outline" onClick={() => act("weigh-out", { tare_kg: Number(tare) }, isRTL ? "تم حساب الوزن الصافي" : "Net weight calculated")}>{isRTL ? "حساب الصافي" : "Calculate net"}</Button>}
              </div>
              {d.net_kg != null && <p className="text-sm">{isRTL ? "الصافي:" : "Net:"} <b dir="ltr">{fmtKg(d.net_kg)} kg</b> · {isRTL ? "وزن البنود:" : "weighed items:"} <span dir="ltr">{fmtKg(d.lines.reduce((s, l) => s + l.received_kg, 0))} kg</span></p>}
              <p className="text-xs text-muted-foreground">{isRTL ? `الفرق المسموح بين الصافي والبنود ${d.limits.net_tolerance_pct}%.` : `Allowed gap between net and the items: ${d.limits.net_tolerance_pct}%.`}</p>
            </Step>

            <Step n={5} title={isRTL ? "اعتماد الجودة" : "Quality sign-off"} done={["Ready", "Stored"].includes(d.status)}>
              {d.status === "Checking" && (
                <p className="text-sm text-muted-foreground">{isRTL ? "يعتمدها شخص غير من سجّل الوصول." : "Signed off by someone other than the person who registered the arrival."}</p>
              )}
              {["Ready", "Stored"].includes(d.status) && <p className="text-sm text-emerald-700">{isRTL ? "تم الاعتماد." : "Signed off."}</p>}
              {can("deliveries", "approve") && open && (
                <div className="flex gap-2">
                  {d.status === "Checking" && <Button onClick={() => act("accept", {}, isRTL ? "تم اعتماد الجودة" : "Quality signed off")}>{isRTL ? "اعتماد" : "Sign off"}</Button>}
                  <Button variant="outline" onClick={() => setRej({ code: reasons[0]?.code ?? "", note: "" })}>{isRTL ? "رفض الشاحنة كلها" : "Reject the whole truck"}</Button>
                </div>
              )}
            </Step>
          </div>

          <ThreePeople d={d as ThreeDelivery} act={act} />

          {(d.status === "Ready" || d.status === "Stored") && (
            <Step n={6} title={isRTL ? "التخزين: غرفة التبريد والرف" : "Put-away: cold room and rack"} done={d.status === "Stored"}>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className={align}>{isRTL ? "الصنف" : "Item"}</TableHead>
                      <TableHead className={align}>{isRTL ? "الدرجة" : "Grade"}</TableHead>
                      <TableHead className={align}>{isRTL ? "المقبول كجم" : "Accepted kg"}</TableHead>
                      <TableHead className={align}>{isRTL ? "غرفة التبريد" : "Cold room"}</TableHead>
                      <TableHead className={align}>{isRTL ? "الرف" : "Rack"}</TableHead>
                      <TableHead className={align}>{isRTL ? "الموضع" : "Position"}</TableHead>
                      <TableHead className={align}>{isRTL ? "الانتهاء" : "Expiry"}</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {d.lines.map((l) => {
                      const p = pa[l.id];
                      const set = (k: keyof PRow, v: string) => setPa((s) => ({ ...s, [l.id]: { ...s[l.id], [k]: v } }));
                      return (
                        <TableRow key={l.id}>
                          <TableCell className="font-medium">{itemName(l)}</TableCell>
                          <TableCell>{l.grade}</TableCell>
                          <TableCell className="font-mono">{fmtKg(l.accepted_kg)}</TableCell>
                          {l.stored_at || !p ? (
                            <TableCell colSpan={4} className="text-sm text-emerald-700">{isRTL ? "مُخزَّن في " : "Stored in "}{rooms.data?.find((r) => r.id === l.cold_room_id)?.name ?? "—"}</TableCell>
                          ) : (
                            <>
                              <TableCell>
                                <select aria-label={`pa room ${l.id}`} className={`${selectClass} min-w-[140px]`} value={p.cold_room_id} onChange={(e) => set("cold_room_id", e.target.value)}>
                                  <option value="">{isRTL ? "اختر…" : "Choose…"}</option>
                                  {rooms.data?.map((r) => <option key={r.id} value={r.id}>{nm(isRTL, r.name, r.name_ar)}</option>)}
                                </select>
                              </TableCell>
                              <TableCell><Input aria-label={`pa rack ${l.id}`} className="w-20" dir="ltr" value={p.rack} onChange={(e) => set("rack", e.target.value)} /></TableCell>
                              <TableCell><Input aria-label={`pa pos ${l.id}`} className="w-20" dir="ltr" value={p.position} onChange={(e) => set("position", e.target.value)} /></TableCell>
                              <TableCell><Input aria-label={`pa exp ${l.id}`} className="w-36" type="date" required={!l.shelf_life_days && !!p.cold_room_id} value={p.expiry_date} onChange={(e) => set("expiry_date", e.target.value)} /></TableCell>
                            </>
                          )}
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </div>
              {d.status === "Ready" && can("stock", "create") && (
                <div className="flex flex-wrap items-center gap-4">
                  <Button onClick={putAway}>{isRTL ? "تخزين المحدد" : "Store the selected lines"}</Button>
                  <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={closeOrder} onChange={(e) => setCloseOrder(e.target.checked)} />{isRTL ? "إغلاق أمر الشراء بعد التخزين (الباقي لن يصل)" : "Close the purchase order afterwards (the rest will not arrive)"}</label>
                </div>
              )}
              {onceStored && <p className="text-xs text-muted-foreground">{isRTL ? "تاريخ الانتهاء يُحسب من مدة صلاحية الصنف إن تُرك فارغاً." : "Expiry is calculated from the item's shelf life when left empty."}</p>}
            </Step>
          )}
        </>
      )}

      <Dialog open={!!decision} onOpenChange={(o) => !o && setDecision(null)}>
        <DialogContent>
          <form onSubmit={(e) => { e.preventDefault(); act("temperature-decision", { decision: decision!.kind, note: decision!.note }, isRTL ? "تم القرار" : "Decision recorded"); setDecision(null); }} className="space-y-4">
            <DialogHeader><DialogTitle>{decision?.kind === "accept" ? (isRTL ? "قبول استثنائي" : "Accept anyway") : isRTL ? "إعادة الشاحنة" : "Send the truck back"}</DialogTitle></DialogHeader>
            <Field id="td_note" label={isRTL ? "ملاحظة القرار (إلزامية)" : "Decision note (required)"}><Input id="td_note" required value={decision?.note ?? ""} onChange={(e) => setDecision((s) => s && { ...s, note: e.target.value })} /></Field>
            <DialogFooter><Button type="submit">{isRTL ? "تأكيد" : "Confirm"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!rej} onOpenChange={(o) => !o && setRej(null)}>
        <DialogContent>
          <form onSubmit={(e) => { e.preventDefault(); act("reject", { reason_code: rej!.code, note: rej!.note || undefined }, isRTL ? "تم رفض الشاحنة" : "Truck rejected"); setRej(null); }} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "رفض الشاحنة كلها" : "Reject the whole truck"}</DialogTitle></DialogHeader>
            <Field id="rj_reason" label={isRTL ? "السبب" : "Reason"}>
              <select id="rj_reason" className={selectClass} required value={rej?.code ?? ""} onChange={(e) => setRej((s) => s && { ...s, code: e.target.value })}>
                {reasons.map((r) => <option key={r.code} value={r.code}>{nm(isRTL, r.name_en, r.name_ar)}</option>)}
              </select>
            </Field>
            <Field id="rj_note2" label={isRTL ? "ملاحظة" : "Note"}><Input id="rj_note2" value={rej?.note ?? ""} onChange={(e) => setRej((s) => s && { ...s, note: e.target.value })} /></Field>
            <DialogFooter><Button type="submit">{isRTL ? "رفض" : "Reject"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
