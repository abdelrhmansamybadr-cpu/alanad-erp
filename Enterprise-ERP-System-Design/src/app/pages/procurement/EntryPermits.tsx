import { useState } from "react";
import { toast } from "sonner";
import { Plus } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";
import { useApi, post } from "../../hooks/useApi";
import { ProcurementNav, StatusBadge, PO } from "../../components/procurement/common";
import { ErrorBox, Field, selectClass, fmtKg } from "../../components/inventory/common";

interface Permit {
  id: number; number: string; status: string; vehicle_plate: string; driver_name: string; expected_kg: number; po_number: string; supplier_name: string | null;
  gate_code: string | null; delivery_id: number | null; branch_id: number | null;
}
interface Vehicle { id: number; plate: string; kind: "own" | "rented"; busy: boolean; is_active: number | boolean; capacity_kg: number | null }
interface Gate { id: number; code: string; name: string; name_ar: string | null; branch_id: number | null; is_active: number | boolean }
interface Space { free_kg: number; committed_kg: number; rooms_with_capacity: number; rooms_without_capacity: number }
type Ask = { kind: "reject" | "cancel" | "approve-note" | "issue"; id: number } | null;

/** Entry permit (إذن دخول): request → the store pre-approves space → the gate issues it on a gate → the truck enters. */
export function EntryPermits() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const [status, setStatus] = useState("");
  const rows = useApi<Permit[]>(`/entry-permits${status ? `?status=${status}` : ""}`);
  const space = useApi<Space>("/entry-permits/space");
  const pos = useApi<PO[]>("/purchase-orders?status=Approved");
  const vehicles = useApi<Vehicle[]>("/vehicles");
  const gates = useApi<Gate[]>("/gates");
  const [dlg, setDlg] = useState<{ po_id: string; vehicle_id: string; plate: string; driver: string; kg: string } | null>(null);
  const [ask, setAsk] = useState<Ask>(null);
  const [text, setText] = useState("");
  const [gate, setGate] = useState("");
  const [busy, setBusy] = useState(false);
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const align = isRTL ? "text-right" : "text-left";

  const run = async (path: string, ok: string, body: object = {}) => {
    setBusy(true);
    try { await post(path, body); toast.success(ok); await Promise.all([rows.reload(), space.reload(), vehicles.reload()]); return true; }
    catch (err: any) { toast.error(err.message); return false; }
    finally { setBusy(false); }
  };
  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    const ok = await run("/entry-permits", T("Entry permit requested", "تم طلب إذن الدخول"), {
      po_id: Number(dlg.po_id), ...(dlg.vehicle_id ? { vehicle_id: Number(dlg.vehicle_id) } : { vehicle_plate: dlg.plate }),
      ...(dlg.driver ? { driver_name: dlg.driver } : {}), ...(dlg.kg ? { expected_kg: Number(dlg.kg) } : {}),
    });
    if (ok) setDlg(null);
  };
  const close = () => { setAsk(null); setText(""); setGate(""); };
  const STATUSES = ["Requested", "Store_Approved", "Issued", "Entered", "Rejected", "Cancelled"];

  return (
    <div className="space-y-6">
      <ProcurementNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{T("Entry permits", "أذونات الدخول")}</h1>
          <p className="text-muted-foreground mt-1">{T("A truck is requested for an approved order, the store confirms there is space, the gate issues the permit, and the truck enters.", "تُطلب الشاحنة لأمر شراء معتمد، يؤكد المخزن وجود مساحة، تصدر البوابة الإذن، ثم تدخل الشاحنة.")}</p>
        </div>
        {can("entry-permits", "create") && (
          <Button className="gap-2" data-testid="new-permit" onClick={() => setDlg({ po_id: "", vehicle_id: "", plate: "", driver: "", kg: "" })}>
            <Plus className="h-4 w-4" />{T("Request a truck", "طلب شاحنة")}
          </Button>
        )}
      </div>
      {rows.error && <ErrorBox message={rows.error} />}
      {space.data && (
        <Card className="p-4 text-sm" data-testid="space-card">
          {T("Free cold-room space now: ", "المساحة الحرة في غرف التبريد الآن: ")}<b>{fmtKg(space.data.free_kg)} kg</b>
          {space.data.committed_kg > 0 && <> · {T("promised to permits: ", "محجوزة لأذونات: ")}{fmtKg(space.data.committed_kg)} kg</>}
          {space.data.rooms_without_capacity > 0 && <span className="text-muted-foreground"> · {space.data.rooms_without_capacity} {T("rooms have no capacity set (not counted)", "غرفة بلا سعة محددة (غير محسوبة)")}</span>}
        </Card>
      )}
      <div className="flex gap-2 flex-wrap">
        {["", ...STATUSES].map((s) => (
          <Button key={s || "all"} size="sm" variant={status === s ? "default" : "outline"} onClick={() => setStatus(s)}>
            {s ? <StatusBadge status={s} isRTL={isRTL} /> : T("All", "الكل")}
          </Button>
        ))}
      </div>
      <Card className="p-6">
        <Table>
          <TableHeader><TableRow>
            <TableHead className={align}>{T("No.", "الرقم")}</TableHead>
            <TableHead className={align}>{T("Truck / driver", "الشاحنة / السائق")}</TableHead>
            <TableHead className={align}>{T("Order", "أمر الشراء")}</TableHead>
            <TableHead className={align}>{T("Expected", "المتوقع")}</TableHead>
            <TableHead className={align}>{T("Gate", "البوابة")}</TableHead>
            <TableHead className={align}>{T("Status", "الحالة")}</TableHead>
            <TableHead />
          </TableRow></TableHeader>
          <TableBody>
            {rows.data?.map((r) => (
              <TableRow key={r.id}>
                <TableCell className="font-mono">{r.number}</TableCell>
                <TableCell><span dir="ltr" className="font-medium">{r.vehicle_plate}</span><div className="text-xs text-muted-foreground">{r.driver_name}</div></TableCell>
                <TableCell>{r.po_number}<div className="text-xs text-muted-foreground">{r.supplier_name}</div></TableCell>
                <TableCell>{fmtKg(r.expected_kg)} kg</TableCell>
                <TableCell>{r.gate_code ?? "—"}</TableCell>
                <TableCell><StatusBadge status={r.status} isRTL={isRTL} /></TableCell>
                <TableCell className="whitespace-nowrap">
                  {r.status === "Requested" && can("entry-permits", "store") && (
                    <>
                      <Button size="sm" disabled={busy} data-testid={`store-approve-${r.id}`} onClick={async () => { if (!(await run(`/entry-permits/${r.id}/store-approve`, T("Space approved", "تمت الموافقة على المساحة")))) setAsk({ kind: "approve-note", id: r.id }); }}>{T("Space OK", "المساحة متوفرة")}</Button>
                      <Button size="sm" variant="ghost" className="text-red-700" disabled={busy} onClick={() => setAsk({ kind: "reject", id: r.id })}>{T("Refuse", "رفض")}</Button>
                    </>
                  )}
                  {r.status === "Store_Approved" && can("entry-permits", "gate") && <Button size="sm" disabled={busy} data-testid={`issue-${r.id}`} onClick={() => setAsk({ kind: "issue", id: r.id })}>{T("Issue permit", "إصدار الإذن")}</Button>}
                  {r.status === "Issued" && can("entry-permits", "gate") && <Button size="sm" disabled={busy} data-testid={`enter-${r.id}`} onClick={() => run(`/entry-permits/${r.id}/enter`, T("Truck registered at the gate", "سُجّلت الشاحنة عند البوابة"))}>{T("Truck enters", "دخول الشاحنة")}</Button>}
                  {["Requested", "Store_Approved", "Issued"].includes(r.status) && <Button size="sm" variant="ghost" disabled={busy} onClick={() => setAsk({ kind: "cancel", id: r.id })}>{T("Cancel", "إلغاء")}</Button>}
                </TableCell>
              </TableRow>
            ))}
            {rows.data && rows.data.length === 0 && <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground">{T("No permits", "لا توجد أذونات")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent>
          <form onSubmit={create} className="space-y-4">
            <DialogHeader><DialogTitle>{T("Request a truck", "طلب شاحنة")}</DialogTitle></DialogHeader>
            <Field id="ep_po" label={T("Approved purchase order", "أمر الشراء المعتمد")}>
              <select id="ep_po" className={selectClass} required value={dlg?.po_id} onChange={(e) => setDlg((s) => s && { ...s, po_id: e.target.value })}>
                <option value="">—</option>
                {pos.data?.map((p) => <option key={p.id} value={p.id}>{p.number} · {p.supplier_name}</option>)}
              </select>
            </Field>
            <Field id="ep_vehicle" label={T("Truck", "الشاحنة")}>
              <select id="ep_vehicle" className={selectClass} value={dlg?.vehicle_id} onChange={(e) => setDlg((s) => s && { ...s, vehicle_id: e.target.value })}>
                <option value="">{T("— another truck (type the plate) —", "— شاحنة أخرى (اكتب اللوحة) —")}</option>
                {vehicles.data?.filter((v) => Number(v.is_active) === 1).map((v) => (
                  <option key={v.id} value={v.id} disabled={v.busy}>{v.plate} · {v.kind === "own" ? T("own", "ملك") : T("rented", "مستأجرة")}{v.busy ? ` · ${T("busy", "مشغولة")}` : ""}</option>
                ))}
              </select>
            </Field>
            {!dlg?.vehicle_id && <Field id="ep_plate" label={T("Plate", "رقم اللوحة")}><Input id="ep_plate" dir="ltr" required value={dlg?.plate ?? ""} onChange={(e) => setDlg((s) => s && { ...s, plate: e.target.value })} /></Field>}
            <div className="grid grid-cols-2 gap-3">
              <Field id="ep_driver" label={T("Driver", "السائق")}><Input id="ep_driver" value={dlg?.driver ?? ""} onChange={(e) => setDlg((s) => s && { ...s, driver: e.target.value })} /></Field>
              <Field id="ep_kg" label={T("Expected load (kg)", "الحمولة المتوقعة (كجم)")}><Input id="ep_kg" type="number" dir="ltr" placeholder={T("order total", "إجمالي الأمر")} value={dlg?.kg ?? ""} onChange={(e) => setDlg((s) => s && { ...s, kg: e.target.value })} /></Field>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setDlg(null)}>{T("Cancel", "إلغاء")}</Button>
              <Button type="submit" disabled={busy}>{T("Request", "طلب")}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!ask} onOpenChange={(o) => !o && close()}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{ask?.kind === "issue" ? T("Issue the permit on a gate", "إصدار الإذن على بوابة") : ask?.kind === "reject" ? T("Why is there no space?", "سبب الرفض") : ask?.kind === "approve-note" ? T("More than the free space: why approve?", "أكبر من المساحة الحرة: لماذا توافق؟") : T("Why cancel?", "سبب الإلغاء")}</DialogTitle>
          </DialogHeader>
          {ask?.kind === "issue" ? (
            <Field id="ep_gate" label={T("Gate", "البوابة")}>
              <select id="ep_gate" className={selectClass} value={gate} onChange={(e) => setGate(e.target.value)}>
                <option value="">—</option>
                {gates.data?.filter((g) => Number(g.is_active) === 1).map((g) => <option key={g.id} value={g.id}>{g.code} · {isRTL && g.name_ar ? g.name_ar : g.name}</option>)}
              </select>
            </Field>
          ) : (
            <Field id="ep_text" label={T("Reason", "السبب")}><Input id="ep_text" value={text} onChange={(e) => setText(e.target.value)} /></Field>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={close}>{T("Back", "رجوع")}</Button>
            <Button data-testid="ask-confirm" disabled={busy || (ask?.kind === "issue" ? !gate : !text.trim())} onClick={async () => {
              if (!ask) return;
              const base = `/entry-permits/${ask.id}`;
              const ok = ask.kind === "issue" ? await run(`${base}/issue`, T("Permit issued", "صدر الإذن"), { gate_id: Number(gate) })
                : ask.kind === "reject" ? await run(`${base}/store-reject`, T("Refused", "تم الرفض"), { reason: text })
                : ask.kind === "approve-note" ? await run(`${base}/store-approve`, T("Space approved", "تمت الموافقة على المساحة"), { note: text })
                : await run(`${base}/cancel`, T("Cancelled", "أُلغي"), { reason: text });
              if (ok) close();
            }}>{T("Confirm", "تأكيد")}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
