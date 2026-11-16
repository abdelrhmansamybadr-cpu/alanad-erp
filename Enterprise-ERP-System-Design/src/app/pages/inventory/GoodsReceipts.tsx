import { useEffect, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router";
import { toast } from "sonner";
import { Plus, Clock } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { useApi, post } from "../../hooks/useApi";
import { InventoryNav } from "../../components/inventory/InventoryNav";
import { ErrorBox, Field, fmtKg, selectClass } from "../../components/inventory/common";
import { PO, StatusBadge } from "../../components/procurement/common";

interface Row { id: number; number: string; status: string; driver_name: string; vehicle_plate: string | null; arrived_at: string; net_kg: number | null; po_number: string | null; supplier_name: string | null; minutes_waiting: number | null; waiting_too_long: boolean }
interface Rating { supplier_id: number; supplier_name: string; deliveries: number; rejected_deliveries: number; received_kg: number; rejected_kg: number; reject_pct: number; grade_a_pct: number }
const STATUSES = ["Arrived", "Temp_Hold", "Checking", "Ready", "To_Sorting", "Stored", "Rejected"];

export const fmtWait = (m: number, isRTL: boolean) => (m >= 60 ? (isRTL ? `${Math.floor(m / 60)} س ${m % 60} د` : `${Math.floor(m / 60)}h ${m % 60}m`) : isRTL ? `${m} د` : `${m} min`);

/** Receiving: every truck from the gate to the cold room, and how each supplier performs. */
export function GoodsReceipts() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const [status, setStatus] = useState("");
  const list = useApi<Row[]>(`/deliveries${status ? `?status=${status}` : ""}`);
  const approved = useApi<PO[]>("/purchase-orders?status=Approved");
  const rating = useApi<Rating[]>("/deliveries/supplier-rating");
  const [dlg, setDlg] = useState<{ po_id: string; driver_name: string; driver_id_no: string; vehicle_plate: string; gross_kg: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const align = isRTL ? "text-right" : "text-left";

  // coming from "Receive goods" on a purchase order
  useEffect(() => {
    const po = params.get("po");
    if (po) { setDlg({ po_id: po, driver_name: "", driver_id_no: "", vehicle_plate: "", gross_kg: "" }); setParams({}, { replace: true }); }
  }, [params, setParams]);

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    setBusy(true); setError("");
    try {
      const d = await post<{ id: number }>("/deliveries", {
        po_id: Number(dlg.po_id), driver_name: dlg.driver_name, driver_id_no: dlg.driver_id_no || undefined, vehicle_plate: dlg.vehicle_plate || undefined,
        gross_kg: dlg.gross_kg === "" ? undefined : Number(dlg.gross_kg),
      });
      toast.success(isRTL ? "تم تسجيل وصول الشاحنة" : "Truck registered");
      navigate(`/inventory/receiving/${d.id}`);
    } catch (err: any) {
      setError(err.message);
    } finally { setBusy(false); }
  };
  const set = (k: string, v: string) => setDlg((s) => s && { ...s, [k]: v });

  return (
    <div className="space-y-6">
      <InventoryNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "الاستلام والجودة" : "Receiving & Quality"}</h1>
          <p className="text-muted-foreground mt-1">
            {isRTL ? "الشاحنة من البوابة إلى غرفة التبريد: الوزن والحرارة وفحص الجودة والدرجات ثم التخزين." : "A truck from the gate to the cold room: weights, temperature, quality grades, then storage."}
          </p>
        </div>
        {can("deliveries", "create") && (
          <Button className="gap-2" onClick={() => setDlg({ po_id: "", driver_name: "", driver_id_no: "", vehicle_plate: "", gross_kg: "" })}>
            <Plus className="h-4 w-4" />
            {isRTL ? "وصول شاحنة" : "New arrival"}
          </Button>
        )}
      </div>
      <ErrorBox message={list.error || approved.error} />

      <Card className="p-6 space-y-4">
        <select aria-label="status" className={`${selectClass} max-w-[220px]`} value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">{isRTL ? "كل الحالات" : "All statuses"}</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className={align}>{isRTL ? "الرقم" : "Number"}</TableHead>
                <TableHead className={align}>{isRTL ? "أمر الشراء" : "Order"}</TableHead>
                <TableHead className={align}>{isRTL ? "المورد" : "Supplier"}</TableHead>
                <TableHead className={align}>{isRTL ? "السائق / اللوحة" : "Driver / plate"}</TableHead>
                <TableHead className={align}>{isRTL ? "وصلت" : "Arrived"}</TableHead>
                <TableHead className={align}>{isRTL ? "الانتظار" : "Waiting"}</TableHead>
                <TableHead className={align}>{isRTL ? "الصافي كجم" : "Net kg"}</TableHead>
                <TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data?.map((r) => (
                <TableRow key={r.id}>
                  <TableCell className="font-mono"><Link className="text-primary hover:underline" to={`/inventory/receiving/${r.id}`}>{r.number}</Link></TableCell>
                  <TableCell className="font-mono text-sm">{r.po_number}</TableCell>
                  <TableCell>{r.supplier_name}</TableCell>
                  <TableCell className="text-sm">{r.driver_name}{r.vehicle_plate ? ` · ${r.vehicle_plate}` : ""}</TableCell>
                  <TableCell dir="ltr" className="text-xs font-mono whitespace-nowrap">{r.arrived_at}</TableCell>
                  <TableCell>
                    {r.minutes_waiting == null ? "—" : (
                      <span className={`inline-flex items-center gap-1 text-sm ${r.waiting_too_long ? "text-red-600 font-semibold" : ""}`}><Clock className="h-3.5 w-3.5" />{fmtWait(Number(r.minutes_waiting), isRTL)}</span>
                    )}
                  </TableCell>
                  <TableCell className="font-mono">{r.net_kg != null ? fmtKg(Number(r.net_kg)) : "—"}</TableCell>
                  <TableCell><StatusBadge status={r.status} isRTL={isRTL} /></TableCell>
                </TableRow>
              ))}
              {list.data && list.data.length === 0 && (
                <TableRow><TableCell colSpan={8} className="text-center text-muted-foreground">{isRTL ? "لا توجد شاحنات" : "No deliveries"}</TableCell></TableRow>
              )}
            </TableBody>
          </Table>
        </div>
      </Card>

      {(rating.data?.length ?? 0) > 0 && (
        <Card>
          <CardHeader className="pb-2"><CardTitle className="text-base">{isRTL ? "تقييم الموردين من نتائج الجودة" : "Supplier rating from the quality results"}</CardTitle></CardHeader>
          <CardContent className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className={align}>{isRTL ? "المورد" : "Supplier"}</TableHead>
                  <TableHead className={align}>{isRTL ? "الشحنات" : "Deliveries"}</TableHead>
                  <TableHead className={align}>{isRTL ? "مرفوضة كاملة" : "Fully rejected"}</TableHead>
                  <TableHead className={align}>{isRTL ? "الكمية كجم" : "Received kg"}</TableHead>
                  <TableHead className={align}>{isRTL ? "نسبة الرفض" : "Reject %"}</TableHead>
                  <TableHead className={align}>{isRTL ? "درجة A" : "Grade A %"}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rating.data!.map((r) => (
                  <TableRow key={r.supplier_id}>
                    <TableCell className="font-medium">{r.supplier_name}</TableCell>
                    <TableCell>{r.deliveries}</TableCell>
                    <TableCell>{r.rejected_deliveries}</TableCell>
                    <TableCell className="font-mono">{fmtKg(r.received_kg)}</TableCell>
                    <TableCell><Badge variant={r.reject_pct > 10 ? "destructive" : "secondary"}>{r.reject_pct}%</Badge></TableCell>
                    <TableCell className="font-mono">{r.grade_a_pct}%</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent>
          <form onSubmit={create} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "وصول شاحنة" : "Truck arrival"}</DialogTitle></DialogHeader>
            <ErrorBox message={error} />
            <Field id="ar_po" label={isRTL ? "أمر الشراء المعتمد" : "Approved purchase order"}>
              <select id="ar_po" className={selectClass} required value={dlg?.po_id ?? ""} onChange={(e) => set("po_id", e.target.value)}>
                <option value="">{isRTL ? "اختر…" : "Choose…"}</option>
                {approved.data?.map((p) => <option key={p.id} value={p.id}>{p.number} · {p.supplier_name}</option>)}
              </select>
            </Field>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <Field id="ar_driver" label={isRTL ? "اسم السائق" : "Driver name"}><Input id="ar_driver" required value={dlg?.driver_name ?? ""} onChange={(e) => set("driver_name", e.target.value)} /></Field>
              <Field id="ar_idno" label={isRTL ? "رقم هوية السائق" : "Driver ID no."}><Input id="ar_idno" dir="ltr" value={dlg?.driver_id_no ?? ""} onChange={(e) => set("driver_id_no", e.target.value)} /></Field>
              <Field id="ar_plate" label={isRTL ? "رقم اللوحة" : "Plate"}><Input id="ar_plate" dir="ltr" value={dlg?.vehicle_plate ?? ""} onChange={(e) => set("vehicle_plate", e.target.value)} /></Field>
              <Field id="ar_gross" label={isRTL ? "الوزن القائم كجم (اختياري الآن)" : "Gross weight kg (optional now)"}><Input id="ar_gross" type="number" step="any" min="0" dir="ltr" value={dlg?.gross_kg ?? ""} onChange={(e) => set("gross_kg", e.target.value)} /></Field>
            </div>
            <DialogFooter><Button type="submit" disabled={busy}>{busy ? "…" : isRTL ? "تسجيل الوصول" : "Register arrival"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
