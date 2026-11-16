import { useState } from "react";
import { Link, useParams } from "react-router";
import { toast } from "sonner";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";
import { useApi, post } from "../../hooks/useApi";
import { SalesNav, shipmentClass, shipmentStatus } from "../../components/sales/SalesNav";
import { ErrorBox, Field, fmtKg } from "../../components/inventory/common";

interface Unit { id: number; kind: "pallet" | "carton"; code: string; boxes: number; kg: number; pack_order: string; item_name: string; grade: string }
interface Line { item_id: number; item_name: string; grade: string; kg: number; boxes: number }
interface Shipment {
  id: number; number: string; status: string; customer_name: string; vehicle_plate: string | null; driver_name: string | null; exit_permit_no: string | null; received_by: string | null;
  units: Unit[]; lines: Line[]; total_kg: number; total_boxes: number; invoice_id: number | null; invoice: { id: number; number: string; total: number; subtotal: number; vat_amount: number } | null;
  dispatched_at: string | null; notes: string | null;
}

export function ShipmentDetail() {
  const { id } = useParams();
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const q = useApi<Shipment>(`/shipments/${id}`);
  const [codes, setCodes] = useState("");
  const [busy, setBusy] = useState(false);
  const [ask, setAsk] = useState<"cancel" | "deliver" | null>(null);
  const [text, setText] = useState("");
  const s = q.data;
  const run = async (path: string, ok: string, body: object = {}) => {
    setBusy(true);
    try { await post(`/shipments/${id}/${path}`, body); toast.success(ok); await q.reload(); return true; } catch (e: any) { toast.error(e.message); return false; } finally { setBusy(false); }
  };
  if (q.error) return <ErrorBox message={q.error} />;
  if (!s) return <p className="text-muted-foreground">…</p>;
  const align = isRTL ? "text-right" : "text-left";

  return (
    <div className="space-y-6">
      <div className="print:hidden"><SalesNav /></div>
      <div>
        <Link to="/sales/shipments" className="text-sm text-primary underline print:hidden">{T("← Shipments", "← الشحنات")}</Link>
        <div className="flex flex-wrap items-center gap-3 mt-1"><h1 className="text-3xl font-bold" dir="ltr">{s.number}</h1><Badge className={shipmentClass(s.status)}>{shipmentStatus(s.status, isRTL)}</Badge></div>
        <p className="text-muted-foreground mt-1">{s.customer_name} · <span dir="ltr">{s.vehicle_plate ?? "—"}</span> · {s.driver_name ?? "—"}{s.exit_permit_no ? ` · ${T("exit permit", "إذن الخروج")} ${s.exit_permit_no}` : ""}</p>
      </div>

      <div className="flex flex-wrap gap-2 print:hidden">
        {s.status === "Draft" && can("shipments", "load") && (
          <div className="w-full space-y-2" data-testid="load-box">
            <Field id="sh_codes" label={T("Scan every pallet / carton code (one per line)", "امسح كل كود طبلية/كرتونة (كود في كل سطر)")}><textarea id="sh_codes" dir="ltr" className="w-full border rounded-md p-2 text-sm font-mono h-24 bg-background" value={codes} onChange={(e) => setCodes(e.target.value)} /></Field>
            <div className="flex gap-2">
              <Button variant="outline" size="sm" data-testid="fill-codes" onClick={() => setCodes(s.units.map((u) => u.code).join("\n"))}>{T("Fill with the expected codes", "تعبئة بالأكواد المتوقعة")}</Button>
              <Button disabled={busy} data-testid="load" onClick={() => run("load", T("Loaded: every code matches", "تم التحميل: كل الأكواد مطابقة"), { codes: codes.split(/\s+/).filter(Boolean) })}>{T("Confirm loading", "تأكيد التحميل")}</Button>
            </div>
          </div>
        )}
        {["Loaded", "Permit_Issued", "Dispatched", "Delivered"].includes(s.status) && !s.invoice_id && can("shipments", "invoice") && <Button disabled={busy} data-testid="invoice" onClick={() => run("invoice", T("Tax invoice issued", "صدرت الفاتورة الضريبية"))}>{T("Issue the tax invoice", "إصدار الفاتورة الضريبية")}</Button>}
        {s.status === "Loaded" && can("shipments", "gate") && <Button disabled={busy} data-testid="permit" onClick={() => run("exit-permit", T("Exit permit issued", "صدر إذن الخروج"))}>{T("Issue the exit permit", "إصدار إذن الخروج")}</Button>}
        {s.status === "Permit_Issued" && can("shipments", "gate") && <Button disabled={busy} data-testid="dispatch" onClick={() => run("dispatch", T("The truck has left", "خرجت الشاحنة"))}>{T("The truck leaves", "خروج الشاحنة")}</Button>}
        {s.status === "Dispatched" && can("shipments", "edit") && <Button disabled={busy} data-testid="deliver" onClick={() => { setText(""); setAsk("deliver"); }}>{T("Mark as delivered", "تسجيل التسليم")}</Button>}
        {["Draft", "Loaded"].includes(s.status) && !s.invoice_id && can("shipments", "edit") && <Button variant="ghost" className="text-red-700" onClick={() => { setText(""); setAsk("cancel"); }}>{T("Cancel", "إلغاء")}</Button>}
        <Button variant="outline" onClick={() => window.print()}>{T("Print the delivery note", "طباعة إشعار التسليم")}</Button>
      </div>

      {s.invoice && <Card className="p-4 text-sm" data-testid="invoice-box">{T("Tax invoice", "الفاتورة الضريبية")}: <Link className="font-mono text-primary underline" to={`/sales/invoices/${s.invoice.id}`}>{s.invoice.number}</Link> · {T("total", "الإجمالي")} <b>{Number(s.invoice.total).toFixed(2)} SAR</b> ({T("VAT", "الضريبة")} {Number(s.invoice.vat_amount).toFixed(2)})</Card>}

      <Card className="p-6" data-testid="note">
        <h2 className="font-semibold mb-3">{T("Delivery note", "إشعار التسليم")} {s.number}</h2>
        <Table>
          <TableHeader><TableRow><TableHead className={align}>{T("Item", "الصنف")}</TableHead><TableHead className={align}>{T("Grade", "الدرجة")}</TableHead><TableHead className={align}>{T("Boxes", "صناديق")}</TableHead><TableHead className={align}>{T("kg", "كجم")}</TableHead></TableRow></TableHeader>
          <TableBody>
            {s.lines.map((l, i) => <TableRow key={i}><TableCell>{l.item_name}</TableCell><TableCell>{l.grade}</TableCell><TableCell>{l.boxes}</TableCell><TableCell>{fmtKg(l.kg)}</TableCell></TableRow>)}
            <TableRow className="font-semibold"><TableCell colSpan={2}>{T("Total", "الإجمالي")}</TableCell><TableCell>{s.total_boxes}</TableCell><TableCell>{fmtKg(s.total_kg)}</TableCell></TableRow>
          </TableBody>
        </Table>
        <h3 className="font-semibold mt-4 mb-2 text-sm">{T("Pallets and cartons", "الطبليات والكراتين")}</h3>
        <div className="flex flex-wrap gap-2">{s.units.map((u) => <Badge key={u.id} variant="outline" className="font-mono" dir="ltr">{u.code} · {u.boxes}</Badge>)}</div>
        <div className="grid grid-cols-2 gap-8 mt-8 text-sm text-muted-foreground"><div className="border-t pt-2">{T("Driver's signature", "توقيع السائق")}</div><div className="border-t pt-2">{T("Receiver's signature", "توقيع المستلم")}{s.received_by ? `: ${s.received_by}` : ""}</div></div>
      </Card>

      <Dialog open={!!ask} onOpenChange={(o) => !o && setAsk(null)}>
        <DialogContent>
          <DialogHeader><DialogTitle>{ask === "deliver" ? T("Who received the goods?", "من استلم البضاعة؟") : T("Why cancel?", "سبب الإلغاء")}</DialogTitle></DialogHeader>
          <Field id="sh_ask" label={ask === "deliver" ? T("Receiver's name", "اسم المستلم") : T("Reason", "السبب")}><Input id="sh_ask" value={text} onChange={(e) => setText(e.target.value)} /></Field>
          <DialogFooter><Button variant="outline" onClick={() => setAsk(null)}>{T("Back", "رجوع")}</Button><Button disabled={busy || !text.trim()} data-testid="ask-confirm" onClick={async () => { if (await run(ask === "deliver" ? "deliver" : "cancel", ask === "deliver" ? T("Delivered", "تم التسليم") : T("Cancelled", "أُلغيت"), ask === "deliver" ? { received_by: text } : { reason: text })) setAsk(null); }}>{T("Confirm", "تأكيد")}</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
