import { useState } from "react";
import { Link, useNavigate } from "react-router";
import { toast } from "sonner";
import { Plus } from "lucide-react";
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
import { ErrorBox, Field, selectClass, fmtKg } from "../../components/inventory/common";

interface Row { id: number; number: string; status: string; customer_name: string; vehicle_plate: string | null; exit_permit_no: string | null; invoice_number: string | null; kg: number }
interface Avail { id: number; kind: "pallet" | "carton"; code: string; boxes: number; kg: number; pack_order: string; customer_name: string | null; client_order_id: number | null; item_name: string; grade: string }
interface CO { id: number; number: string; customer_name: string; status: string }

/** Shipments: the delivery note, loading check, invoice, exit permit and dispatch. */
export function Shipments() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const nav = useNavigate();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const rows = useApi<Row[]>("/shipments");
  const units = useApi<Avail[]>("/shipments/available-units");
  const cos = useApi<CO[]>("/client-orders");
  const [dlg, setDlg] = useState<{ ids: number[]; co: string; customer: string; plate: string; driver: string } | null>(null);
  const [saving, setSaving] = useState(false);
  const align = isRTL ? "text-right" : "text-left";
  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    setSaving(true);
    try {
      const s = await post("/shipments", { unit_ids: dlg.ids, ...(dlg.co ? { client_order_id: Number(dlg.co) } : { customer_name: dlg.customer }), vehicle_plate: dlg.plate || undefined, driver_name: dlg.driver || undefined });
      toast.success(T("Shipment created", "تم إنشاء الشحنة")); setDlg(null); nav(`/sales/shipments/${s.id}`);
    } catch (err: any) { toast.error(err.message); } finally { setSaving(false); }
  };
  const toggle = (id: number) => setDlg((s) => s && { ...s, ids: s.ids.includes(id) ? s.ids.filter((x) => x !== id) : [...s.ids, id] });

  return (
    <div className="space-y-6">
      <SalesNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{T("Shipments", "الشحنات")}</h1>
          <p className="text-muted-foreground mt-1">{T("Choose the packed pallets and cartons, scan them onto the truck, issue the tax invoice and the exit permit, and let the truck leave.", "اختر الطبليات والكراتين المعبأة وامسحها عند التحميل، ثم أصدر الفاتورة الضريبية وإذن الخروج وأخرج الشاحنة.")}</p>
        </div>
        {can("shipments", "create") && <Button className="gap-2" data-testid="new-shipment" onClick={() => setDlg({ ids: [], co: "", customer: "", plate: "", driver: "" })}><Plus className="h-4 w-4" />{T("New shipment", "شحنة جديدة")}</Button>}
      </div>
      {(rows.error || units.error) && <ErrorBox message={rows.error || units.error} />}
      <Card className="p-6" data-testid="shipment-list">
        <Table>
          <TableHeader><TableRow>
            <TableHead className={align}>{T("Delivery note", "إشعار التسليم")}</TableHead><TableHead className={align}>{T("Client", "العميل")}</TableHead><TableHead className={align}>{T("Truck", "الشاحنة")}</TableHead>
            <TableHead className={align}>{T("kg", "كجم")}</TableHead><TableHead className={align}>{T("Invoice", "الفاتورة")}</TableHead><TableHead className={align}>{T("Status", "الحالة")}</TableHead>
          </TableRow></TableHeader>
          <TableBody>
            {rows.data?.map((r) => (
              <TableRow key={r.id}>
                <TableCell><Link className="font-mono text-primary underline" to={`/sales/shipments/${r.id}`}>{r.number}</Link>{r.exit_permit_no && <div className="text-xs text-muted-foreground">{r.exit_permit_no}</div>}</TableCell>
                <TableCell>{r.customer_name}</TableCell><TableCell dir="ltr">{r.vehicle_plate ?? "—"}</TableCell><TableCell>{fmtKg(r.kg)}</TableCell><TableCell className="font-mono">{r.invoice_number ?? "—"}</TableCell>
                <TableCell><Badge className={shipmentClass(r.status)}>{shipmentStatus(r.status, isRTL)}</Badge></TableCell>
              </TableRow>
            ))}
            {rows.data && rows.data.length === 0 && <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">{T("No shipments", "لا توجد شحنات")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent className="max-w-2xl">
          <form onSubmit={create} className="space-y-3">
            <DialogHeader><DialogTitle>{T("New shipment", "شحنة جديدة")}</DialogTitle></DialogHeader>
            <div className="max-h-56 overflow-y-auto border rounded-lg divide-y" data-testid="unit-picker">
              {units.data?.length === 0 && <p className="p-3 text-sm text-muted-foreground">{T("No packed pallets or cartons are waiting.", "لا توجد طبليات أو كراتين معبأة بالانتظار.")}</p>}
              {units.data?.map((u) => (
                <label key={u.id} className="flex items-center gap-3 p-2 text-sm cursor-pointer">
                  <input type="checkbox" data-testid={`unit-${u.id}`} checked={!!dlg?.ids.includes(u.id)} onChange={() => toggle(u.id)} />
                  <span className="font-mono" dir="ltr">{u.code}</span><span className="text-muted-foreground">{u.item_name} · {u.grade} · {u.boxes} {T("boxes", "صندوق")} · {fmtKg(u.kg)} kg · {u.customer_name ?? ""}</span>
                </label>
              ))}
            </div>
            <Field id="sh_co" label={T("For a client order", "لطلب عميل")}>
              <select id="sh_co" className={selectClass} value={dlg?.co} onChange={(e) => setDlg((s) => s && { ...s, co: e.target.value })}>
                <option value="">{T("— no client order —", "— بدون طلب عميل —")}</option>{cos.data?.filter((c) => !["Cancelled", "Closed"].includes(c.status)).map((c) => <option key={c.id} value={c.id}>{c.number} · {c.customer_name}</option>)}
              </select>
            </Field>
            {!dlg?.co && <Field id="sh_cust" label={T("Client name", "اسم العميل")}><Input id="sh_cust" required value={dlg?.customer ?? ""} onChange={(e) => setDlg((s) => s && { ...s, customer: e.target.value })} /></Field>}
            <div className="grid grid-cols-2 gap-3">
              <Field id="sh_plate" label={T("Truck plate", "لوحة الشاحنة")}><Input id="sh_plate" dir="ltr" value={dlg?.plate ?? ""} onChange={(e) => setDlg((s) => s && { ...s, plate: e.target.value })} /></Field>
              <Field id="sh_driver" label={T("Driver", "السائق")}><Input id="sh_driver" value={dlg?.driver ?? ""} onChange={(e) => setDlg((s) => s && { ...s, driver: e.target.value })} /></Field>
            </div>
            <DialogFooter><Button type="button" variant="outline" onClick={() => setDlg(null)}>{T("Cancel", "إلغاء")}</Button><Button type="submit" disabled={saving || !dlg?.ids.length}>{T("Create", "إنشاء")} ({dlg?.ids.length ?? 0})</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
