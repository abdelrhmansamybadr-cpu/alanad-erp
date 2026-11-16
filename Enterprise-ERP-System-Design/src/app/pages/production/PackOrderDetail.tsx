import { useState } from "react";
import { Link, useParams } from "react-router";
import { toast } from "sonner";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { Badge } from "../../components/ui/badge";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";
import { useApi, post } from "../../hooks/useApi";
import { ProductionNav } from "../../components/production/common";
import { ErrorBox, Field, fmtKg } from "../../components/inventory/common";
import { PackStatus } from "./PackOrders";

interface Unit { id: number; kind: "pallet" | "carton"; code: string; parent_id: number | null; boxes: number; kg: number; is_partial: boolean }
interface Order {
  id: number; number: string; status: string; customer_name: string | null; item_name: string; grade: string; required_kg: number; box_kg: number | null; planned_boxes: number | null; issued_kg: number | null;
  boxes_per_carton: number; cartons_per_pallet: number; boxes_packed: number | null; loss_kg: number | null; cartons: number | null; pallets: number | null; due_date: string | null;
  spec: { sizes: number[]; label_note: string | null }; batches: { batch_id: number; batch_no: string; expiry_date: string; kg: number }[]; units: Unit[];
}

/** One pack order: box size (head of preparation), stock issue and packing (packer), then the cartons and pallets with their codes. */
export function PackOrderDetail() {
  const { id } = useParams();
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const q = useApi<Order>(`/pack-orders/${id}`);
  const [box, setBox] = useState("");
  const [boxes, setBoxes] = useState("");
  const [loss, setLoss] = useState("0");
  const [busy, setBusy] = useState(false);
  const [cancel, setCancel] = useState<string | null>(null);
  const o = q.data;
  const run = async (path: string, ok: string, body: object = {}) => {
    setBusy(true);
    try { await post(`/pack-orders/${id}/${path}`, body); toast.success(ok); await q.reload(); return true; } catch (e: any) { toast.error(e.message); return false; } finally { setBusy(false); }
  };
  if (q.error) return <ErrorBox message={q.error} />;
  if (!o) return <p className="text-muted-foreground">…</p>;
  const align = isRTL ? "text-right" : "text-left";
  const pallets = o.units.filter((u) => u.kind === "pallet");
  const cartonsOf = (pid: number) => o.units.filter((u) => u.kind === "carton" && u.parent_id === pid);

  return (
    <div className="space-y-6">
      <ProductionNav />
      <div>
        <Link to="/production/packing" className="text-sm text-primary underline">{T("← Packing", "← التعبئة")}</Link>
        <div className="flex flex-wrap items-center gap-3 mt-1"><h1 className="text-3xl font-bold" dir="ltr">{o.number}</h1><PackStatus s={o.status} isRTL={isRTL} /></div>
        <p className="text-muted-foreground mt-1">{o.customer_name ?? "—"} · {o.item_name} · {T("grade", "الدرجة")} {o.grade} · {fmtKg(o.required_kg)} kg{o.due_date ? ` · ${o.due_date}` : ""}</p>
        <p className="text-xs text-muted-foreground">{o.boxes_per_carton} {T("boxes per carton", "صندوق في الكرتونة")} · {o.cartons_per_pallet} {T("cartons per pallet", "كرتونة على الطبلية")}{o.spec.label_note ? ` · ${o.spec.label_note}` : ""}</p>
      </div>

      {["Draft", "Ready"].includes(o.status) && can("pack-orders", "decide") && (
        <Card className="p-6 space-y-3" data-testid="decide-box">
          <h2 className="font-semibold">{T("Box size (head of preparation)", "حجم الصندوق (رئيس التجهيز)")}</h2>
          {o.planned_boxes != null && <p className="text-sm" data-testid="planned">{T("Decided: ", "المقرر: ")}<b>{o.planned_boxes}</b> {T("boxes of", "صندوق من")} <b>{o.box_kg} kg</b></p>}
          <div className="flex flex-wrap gap-2 items-center">
            {o.spec.sizes.map((s) => <Button key={s} variant={box === String(s) || (!box && o.box_kg === s) ? "default" : "outline"} data-testid={`size-${s}`} onClick={() => setBox(String(s))}>{s} kg</Button>)}
            <Button disabled={busy || !box} data-testid="decide" onClick={() => run("decide", T("Box size decided", "تم تحديد حجم الصندوق"), { box_kg: Number(box) })}>{T("Confirm", "تأكيد")}</Button>
          </div>
        </Card>
      )}

      {o.status === "Ready" && can("pack-orders", "edit") && (
        <Card className="p-6 space-y-2">
          <p className="text-sm">{T("Planned: ", "المخطط: ")}<b>{o.planned_boxes}</b> {T("boxes of", "صندوق من")} <b>{o.box_kg} kg</b> = {fmtKg((o.planned_boxes ?? 0) * (o.box_kg ?? 0))} kg {T("of grade", "من الدرجة")} {o.grade}</p>
          <Button disabled={busy} data-testid="start" onClick={() => run("start", T("Stock issued: packing started", "صُرف المخزون: بدأت التعبئة"))}>{T("Issue the stock and start packing", "صرف المخزون وبدء التعبئة")}</Button>
        </Card>
      )}

      {o.status === "In_Progress" && can("pack-orders", "edit") && (
        <Card className="p-6 space-y-3" data-testid="pack-box">
          <p className="text-sm">{T("Issued: ", "المصروف: ")}<b>{fmtKg(o.issued_kg ?? 0)} kg</b> · {T("boxes x box size + loss must add up to it", "عدد الصناديق × حجمها + الفاقد يجب أن يساوي ذلك")}</p>
          <div className="flex flex-wrap items-end gap-3">
            <Field id="pk_boxes" label={T("Boxes packed", "عدد الصناديق")} className="w-40"><Input id="pk_boxes" type="number" dir="ltr" value={boxes} onChange={(e) => setBoxes(e.target.value)} /></Field>
            <Field id="pk_loss" label={T("Loss (kg)", "الفاقد (كجم)")} className="w-32"><Input id="pk_loss" type="number" step="0.001" dir="ltr" value={loss} onChange={(e) => setLoss(e.target.value)} /></Field>
            <Button disabled={busy || !boxes} data-testid="pack" onClick={() => run("pack", T("Cartons and pallets created", "أُنشئت الكراتين والطبليات"), { boxes_packed: Number(boxes), loss_kg: Number(loss || 0) })}>{T("Finish packing", "إنهاء التعبئة")}</Button>
          </div>
        </Card>
      )}

      {o.units.length > 0 && (
        <Card className="p-6" data-testid="units">
          <h2 className="font-semibold mb-1">{T("Pallets and cartons", "الطبليات والكراتين")}</h2>
          <p className="text-sm text-muted-foreground mb-3">{o.boxes_packed} {T("boxes", "صندوق")} · {o.cartons} {T("cartons", "كرتونة")} · {o.pallets} {T("pallets", "طبلية")}{o.loss_kg ? ` · ${T("loss", "فاقد")} ${fmtKg(o.loss_kg)} kg` : ""}</p>
          {pallets.map((p) => (
            <div key={p.id} className="border rounded-lg p-3 mb-3">
              <div className="font-semibold flex flex-wrap gap-2 items-center"><span dir="ltr" className="font-mono">{p.code}</span><span className="text-sm font-normal text-muted-foreground">{p.boxes} {T("boxes", "صندوق")} · {fmtKg(p.kg)} kg</span></div>
              <div className="mt-2 flex flex-wrap gap-2">{cartonsOf(p.id).map((c) => <Badge key={c.id} variant={c.is_partial ? "secondary" : "outline"} className="font-mono" dir="ltr">{c.code.split("-").slice(-1)[0]} · {c.boxes}{c.is_partial ? " ◐" : ""}</Badge>)}</div>
            </div>
          ))}
        </Card>
      )}

      {o.batches.length > 0 && (
        <Card className="p-6" data-testid="batches">
          <h2 className="font-semibold mb-3">{T("Traceability: the batches behind this packing", "التتبّع: الدفعات التي أُخذت منها هذه التعبئة")}</h2>
          <Table>
            <TableHeader><TableRow><TableHead className={align}>{T("Batch", "الدفعة")}</TableHead><TableHead className={align}>{T("Expiry", "الانتهاء")}</TableHead><TableHead className={align}>{T("kg", "كجم")}</TableHead></TableRow></TableHeader>
            <TableBody>{o.batches.map((b) => <TableRow key={b.batch_id}><TableCell dir="ltr">{b.batch_no}</TableCell><TableCell dir="ltr">{b.expiry_date}</TableCell><TableCell>{fmtKg(b.kg)}</TableCell></TableRow>)}</TableBody>
          </Table>
        </Card>
      )}

      {["Draft", "Ready"].includes(o.status) && <Button variant="ghost" className="text-red-700" onClick={() => setCancel("")}>{T("Cancel", "إلغاء")}</Button>}
      <Dialog open={cancel !== null} onOpenChange={(x) => !x && setCancel(null)}>
        <DialogContent>
          <DialogHeader><DialogTitle>{T("Cancel the pack order", "إلغاء أمر التعبئة")}</DialogTitle></DialogHeader>
          <Field id="pk_cancel" label={T("Reason", "السبب")}><Input id="pk_cancel" value={cancel ?? ""} onChange={(e) => setCancel(e.target.value)} /></Field>
          <DialogFooter><Button variant="outline" onClick={() => setCancel(null)}>{T("Back", "رجوع")}</Button><Button disabled={!cancel?.trim() || busy} onClick={async () => { if (await run("cancel", T("Cancelled", "أُلغي"), { reason: cancel })) setCancel(null); }}>{T("Confirm", "تأكيد")}</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
