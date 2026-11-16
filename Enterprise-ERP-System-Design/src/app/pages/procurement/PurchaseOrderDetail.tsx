import { useState } from "react";
import { Link, useParams } from "react-router";
import { toast } from "sonner";
import { ArrowLeft, ArrowRight, Truck } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { useApi, post } from "../../hooks/useApi";
import { ErrorBox, Field, fmtKg, selectClass, nm } from "../../components/inventory/common";
import { ProcurementNav, PO, StatusBadge, money } from "../../components/procurement/common";

type Full = PO & { created_by?: number | null; purchase_request_id?: number | null };
interface Reason { code: string; name_en: string; name_ar: string | null }

export function PurchaseOrderDetail() {
  const { id } = useParams();
  const { isRTL } = useI18n();
  const { me, can } = useAuth();
  const po = useApi<Full>(`/purchase-orders/${id}`);
  const reasons = useApi<Reason[]>("/reject-reasons");
  const [rej, setRej] = useState<{ code: string; note: string } | null>(null);
  const [error, setError] = useState("");
  const Back = isRTL ? ArrowRight : ArrowLeft;
  const align = isRTL ? "text-right" : "text-left";
  const p = po.data;
  const mine = !!p && p.created_by === me?.id;

  const run = async (fn: () => Promise<any>, ok: string) => {
    setError("");
    try {
      await fn();
      toast.success(ok);
      setRej(null);
      await po.reload();
    } catch (e: any) {
      setError(e.message);
    }
  };

  return (
    <div className="space-y-6">
      <ProcurementNav />
      <Link to="/procurement/purchase-orders" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline">
        <Back className="h-4 w-4" />
        {isRTL ? "كل أوامر الشراء" : "All purchase orders"}
      </Link>
      <ErrorBox message={po.error || error} />

      {p && (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h1 className="text-3xl font-bold flex items-center gap-3"><span dir="ltr">{p.number}</span> <StatusBadge status={p.status} isRTL={isRTL} /></h1>
              <p className="text-muted-foreground mt-1">
                {p.supplier_name} · <span dir="ltr">{p.date}</span>
                {p.purchase_request_id && (<> · <Link className="text-primary hover:underline" to={`/procurement/requests/${p.purchase_request_id}`}>{isRTL ? "الطلب الأصلي" : "source request"}</Link></>)}
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              {p.status === "Draft" && can("purchase-orders", "create") && (
                <Button onClick={() => run(() => post(`/purchase-orders/${id}/submit`, {}), isRTL ? "تم إرسال الأمر" : "Order sent")}>{isRTL ? "إرسال الأمر" : "Send order"}</Button>
              )}
              {p.status === "Submitted" && !mine && (
                <>
                  <Button onClick={() => run(() => post(`/purchase-orders/${id}/decide`, { action: "approve" }), isRTL ? "تم الاعتماد" : "Approved")}>{isRTL ? "اعتماد" : "Approve"}</Button>
                  <Button variant="outline" onClick={() => setRej({ code: reasons.data?.[0]?.code ?? "", note: "" })}>{isRTL ? "رفض" : "Reject"}</Button>
                </>
              )}
              {["Approved", "Received"].includes(p.status) && can("ap-invoices", "create") && (
                <Button asChild variant="outline"><Link to={`/accounting/invoices?po=${p.id}`}>{isRTL ? "إدخال فاتورة المورد" : "Enter supplier invoice"}</Link></Button>
              )}
              {p.status === "Approved" && (
                <Button asChild className="gap-2">
                  <Link to={`/inventory/receiving?po=${p.id}`}><Truck className="h-4 w-4" />{isRTL ? "استلام البضائع" : "Receive goods"}</Link>
                </Button>
              )}
            </div>
          </div>
          {p.status === "Submitted" && mine && (
            <p className="text-sm text-muted-foreground">{isRTL ? "بانتظار اعتماد المالية. لا يمكنك اعتماد أمر أنشأته بنفسك." : "Waiting for finance approval. You cannot approve an order you created."}</p>
          )}

          <Card>
            <CardHeader><CardTitle className="text-base">{isRTL ? "البنود" : "Lines"}</CardTitle></CardHeader>
            <CardContent className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className={align}>{isRTL ? "الصنف" : "Item"}</TableHead>
                    <TableHead className={align}>{isRTL ? "الكمية (كجم)" : "Qty (kg)"}</TableHead>
                    <TableHead className={align}>{isRTL ? "السعر/كجم" : "Price/kg"}</TableHead>
                    <TableHead className={align}>{isRTL ? "المبلغ" : "Amount"}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {p.lines?.map((l) => (
                    <TableRow key={l.id}>
                      <TableCell className="font-medium">{l.item_name}</TableCell>
                      <TableCell className="font-mono">{fmtKg(Number(l.qty))}</TableCell>
                      <TableCell dir="ltr" className="font-mono">{Number(l.rate).toFixed(2)}</TableCell>
                      <TableCell dir="ltr" className="font-mono">{money(l.amount, p.currency)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              <div className="mt-4 ms-auto max-w-xs text-sm space-y-1" dir="ltr">
                <div className="flex justify-between"><span>{isRTL ? "قبل الضريبة" : "Subtotal"}</span><span className="font-mono">{money(p.subtotal, p.currency)}</span></div>
                <div className="flex justify-between"><span>{isRTL ? "الضريبة" : "VAT"}</span><span className="font-mono">{money(p.tax_amount, p.currency)}</span></div>
                <div className="flex justify-between font-bold text-base border-t pt-1"><span>{isRTL ? "الإجمالي" : "Total"}</span><span className="font-mono">{money(p.total, p.currency)}</span></div>
              </div>
            </CardContent>
          </Card>
          {p.notes && <p className="text-sm text-muted-foreground">{p.notes}</p>}
        </>
      )}

      <Dialog open={!!rej} onOpenChange={(o) => !o && setRej(null)}>
        <DialogContent>
          <form onSubmit={(e) => { e.preventDefault(); run(() => post(`/purchase-orders/${id}/decide`, { action: "reject", reason_code: rej?.code, note: rej?.note }), isRTL ? "تم الرفض" : "Rejected"); }} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "سبب الرفض" : "Reason for rejecting"}</DialogTitle></DialogHeader>
            <Field id="po_rj_code" label={isRTL ? "السبب" : "Reason"}>
              <select id="po_rj_code" className={selectClass} required value={rej?.code ?? ""} onChange={(e) => setRej((s) => s && { ...s, code: e.target.value })}>
                {reasons.data?.map((r) => <option key={r.code} value={r.code}>{nm(isRTL, r.name_en, r.name_ar)}</option>)}
              </select>
            </Field>
            <Field id="po_rj_note" label={isRTL ? "ملاحظة" : "Note"}>
              <Input id="po_rj_note" value={rej?.note ?? ""} onChange={(e) => setRej((s) => s && { ...s, note: e.target.value })} />
            </Field>
            <DialogFooter><Button type="submit">{isRTL ? "رفض الأمر" : "Reject order"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
