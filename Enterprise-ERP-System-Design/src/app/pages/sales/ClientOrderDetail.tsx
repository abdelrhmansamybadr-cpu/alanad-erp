import { useState } from "react";
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
import { SalesNav } from "../../components/sales/SalesNav";
import { StatusBadge } from "../../components/procurement/common";
import { ErrorBox, Field, fmtKg, nm } from "../../components/inventory/common";

interface Line { id: number; item_id: number; item_name: string; item_name_ar: string | null; qty_kg: number; reserved_kg: number; shortage_kg: number; note: string | null }
interface Resv { id: number; line_id: number; batch_no: string; expiry_date: string; qty: number }
interface Order {
  id: number; number: string; customer_name: string; channel: string; order_date: string; due_date: string | null; status: string; notes: string | null;
  purchase_request_id: number | null; needs_approval?: boolean; lines: Line[]; reservations: Resv[]; total_kg: number; reserved_kg: number;
}
interface CheckLine { line_id: number; item_name: string; needed_kg: number; free_kg: number; shortage_kg: number }
interface Check { can_fulfil: boolean; lines: CheckLine[] }

export function ClientOrderDetail() {
  const { id } = useParams();
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const o = useApi<Order>(`/client-orders/${id}`);
  const [check, setCheck] = useState<Check | null>(null);
  const [busy, setBusy] = useState(false);
  const [cancel, setCancel] = useState<string | null>(null);
  const d = o.data;
  const editable = can("client-orders", "create");

  const run = async (path: string, ok: string, body: object = {}) => {
    setBusy(true);
    try {
      const r = await post(`/client-orders/${id}/${path}`, body);
      toast.success(ok);
      setCheck(null);
      await o.reload();
      return r;
    } catch (err: any) {
      toast.error(err.message);
    } finally {
      setBusy(false);
    }
  };
  const doCheck = async () => {
    setBusy(true);
    try {
      const { apiRequest } = await import("../../services/api");
      setCheck(await apiRequest<Check>(`/client-orders/${id}/check`));
    } catch (err: any) {
      toast.error(err.message);
    } finally {
      setBusy(false);
    }
  };

  if (o.error) return <ErrorBox message={o.error} />;
  if (!d) return <p className="text-muted-foreground">…</p>;
  const open = !["Cancelled", "Closed"].includes(d.status);
  const align = isRTL ? "text-right" : "text-left";

  return (
    <div className="space-y-6">
      <SalesNav />
      <div>
        <Link to="/sales" className="text-sm text-primary underline">{isRTL ? "← طلبات العملاء" : "← Client orders"}</Link>
        <div className="flex flex-wrap items-center gap-3 mt-1">
          <h1 className="text-3xl font-bold" dir="ltr">{d.number}</h1>
          <StatusBadge status={d.status} isRTL={isRTL} />
        </div>
        <p className="text-muted-foreground mt-1">
          {d.customer_name} · {isRTL ? "التسليم" : "Due"} <span dir="ltr">{d.due_date ?? "—"}</span> · {fmtKg(d.reserved_kg)} / {fmtKg(d.total_kg)} kg {isRTL ? "محجوز" : "reserved"}
        </p>
        {d.notes && <p className="text-sm mt-1 whitespace-pre-line">{d.notes}</p>}
      </div>

      {editable && open && (
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" disabled={busy} onClick={doCheck} data-testid="check-stock">{isRTL ? "فحص المخزون" : "Check stock"}</Button>
          {d.needs_approval && can("client-orders", "approve") && (
            <Button disabled={busy} onClick={() => run("approve", isRTL ? "تمت الموافقة" : "Approved")} data-testid="approve">{isRTL ? "موافقة على الطلب" : "Approve order"}</Button>
          )}
          <Button disabled={busy || !!d.needs_approval} onClick={() => run("reserve", isRTL ? "تم الحجز" : "Reserved")} data-testid="reserve">
            {d.status === "Draft" ? (isRTL ? "حجز المخزون" : "Reserve stock") : isRTL ? "إعادة الحجز" : "Re-reserve"}
          </Button>
          {d.status === "Short" && !d.purchase_request_id && (
            <Button variant="secondary" disabled={busy} onClick={() => run("request-purchase", isRTL ? "تم إنشاء طلب شراء للنقص" : "Purchase request created")} data-testid="request-purchase">
              {isRTL ? "طلب شراء للنقص" : "Request purchase for the shortage"}
            </Button>
          )}
          {["Reserved", "Short"].includes(d.status) && (
            <Button variant="outline" disabled={busy} onClick={() => run("release", isRTL ? "أُلغي الحجز" : "Reservation released")}>{isRTL ? "إلغاء الحجز" : "Release"}</Button>
          )}
          <Button variant="ghost" className="text-red-700" disabled={busy} onClick={() => setCancel("")}>{isRTL ? "إلغاء الطلب" : "Cancel order"}</Button>
        </div>
      )}
      {d.needs_approval && open && (
        <div className="p-3 rounded-lg bg-amber-50 text-amber-800 text-sm" data-testid="needs-approval">
          {isRTL ? "هذا الطلب أكبر من حد الموافقة: يلزم أن يوافق عليه شخص آخر قبل حجز المخزون." : "This order is above the approval limit: another person must approve it before stock is reserved."}
        </div>
      )}
      {d.purchase_request_id && <p className="text-sm text-muted-foreground">{isRTL ? "أُنشئ طلب شراء للنقص (راجع المشتريات ← طلبات الشراء)." : "A purchase request was created for the shortage (see Purchases → Requests)."}</p>}

      {check && (
        <Card className="p-6" data-testid="check-result">
          <h2 className="font-semibold mb-3">
            {check.can_fulfil ? (isRTL ? "المخزون يكفي الطلب كله" : "Stock covers the whole order") : isRTL ? "المخزون لا يكفي" : "Stock is not enough"}
          </h2>
          <Table>
            <TableHeader><TableRow>
              <TableHead className={align}>{isRTL ? "الصنف" : "Item"}</TableHead>
              <TableHead className={align}>{isRTL ? "المطلوب" : "Needed"}</TableHead>
              <TableHead className={align}>{isRTL ? "المتاح الحر" : "Free"}</TableHead>
              <TableHead className={align}>{isRTL ? "النقص" : "Shortage"}</TableHead>
            </TableRow></TableHeader>
            <TableBody>
              {check.lines.map((l) => (
                <TableRow key={l.line_id}>
                  <TableCell>{l.item_name}</TableCell><TableCell>{fmtKg(l.needed_kg)}</TableCell><TableCell>{fmtKg(l.free_kg)}</TableCell>
                  <TableCell className={l.shortage_kg > 0 ? "text-red-700 font-semibold" : ""}>{fmtKg(l.shortage_kg)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      <Card className="p-6">
        <h2 className="font-semibold mb-3">{isRTL ? "بنود الطلب" : "Order lines"}</h2>
        <Table>
          <TableHeader><TableRow>
            <TableHead className={align}>{isRTL ? "الصنف" : "Item"}</TableHead>
            <TableHead className={align}>{isRTL ? "المطلوب (كجم)" : "Ordered (kg)"}</TableHead>
            <TableHead className={align}>{isRTL ? "المحجوز (كجم)" : "Reserved (kg)"}</TableHead>
            <TableHead className={align}>{isRTL ? "النقص (كجم)" : "Shortage (kg)"}</TableHead>
            <TableHead className={align}>{isRTL ? "الدُفعات المحجوزة (الأقرب صلاحية أولاً)" : "Held batches (earliest expiry first)"}</TableHead>
          </TableRow></TableHeader>
          <TableBody>
            {d.lines.map((l) => (
              <TableRow key={l.id}>
                <TableCell className="font-medium">{nm(isRTL, l.item_name, l.item_name_ar)}{l.note ? <div className="text-xs text-muted-foreground">{l.note}</div> : null}</TableCell>
                <TableCell>{fmtKg(l.qty_kg)}</TableCell>
                <TableCell>{fmtKg(l.reserved_kg)}</TableCell>
                <TableCell className={l.shortage_kg > 0 && d.status !== "Draft" ? "text-red-700 font-semibold" : ""}>{d.status === "Draft" ? "—" : fmtKg(l.shortage_kg)}</TableCell>
                <TableCell className="text-sm" dir="ltr">
                  {d.reservations.filter((r) => r.line_id === l.id).map((r) => `${r.batch_no} (${r.expiry_date}): ${fmtKg(r.qty)}`).join(" · ") || "—"}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={cancel !== null} onOpenChange={(x) => !x && setCancel(null)}>
        <DialogContent>
          <DialogHeader><DialogTitle>{isRTL ? "إلغاء الطلب" : "Cancel order"}</DialogTitle></DialogHeader>
          <Field id="co_cancel" label={isRTL ? "السبب" : "Reason"}>
            <Input id="co_cancel" value={cancel ?? ""} onChange={(e) => setCancel(e.target.value)} />
          </Field>
          <DialogFooter>
            <Button variant="outline" onClick={() => setCancel(null)}>{isRTL ? "رجوع" : "Back"}</Button>
            <Button disabled={!cancel?.trim() || busy} onClick={async () => { await run("cancel", isRTL ? "أُلغي الطلب" : "Order cancelled", { reason: cancel }); setCancel(null); }}>
              {isRTL ? "تأكيد الإلغاء" : "Confirm cancel"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
