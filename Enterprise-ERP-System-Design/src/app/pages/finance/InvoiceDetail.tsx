import { useState } from "react";
import { Link, useNavigate, useParams } from "react-router";
import { toast } from "sonner";
import { ArrowLeft, ArrowRight, AlertTriangle, CheckCircle2 } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { apiRequest } from "../../services/api";
import { useApi, post } from "../../hooks/useApi";
import { ErrorBox, Field, fmtKg, selectClass, nm } from "../../components/inventory/common";
import { StatusBadge, money } from "../../components/procurement/common";
import { FinanceNav, InvoiceDetailData, Reason, financeReasons } from "../../components/finance/common";
import { InvoiceFormDialog } from "../../components/finance/InvoiceFormDialog";

export function InvoiceDetail() {
  const { id } = useParams();
  const { isRTL } = useI18n();
  const { me, can } = useAuth();
  const navigate = useNavigate();
  const q = useApi<InvoiceDetailData>(`/ap-invoices/${id}`);
  const reasonsQ = useApi<Reason[]>("/reject-reasons");
  const reasons = financeReasons(reasonsQ.data);
  const [error, setError] = useState("");
  const [edit, setEdit] = useState(false);
  const [dec, setDec] = useState<{ action: "approve" | "reject"; code: string; note: string } | null>(null);
  const Back = isRTL ? ArrowRight : ArrowLeft;
  const align = isRTL ? "text-right" : "text-left";
  const d = q.data;
  const mine = !!d && d.created_by === me?.id;

  const run = async (fn: () => Promise<unknown>, ok: string) => {
    setError("");
    try { await fn(); toast.success(ok); await q.reload(); } catch (e: any) { setError(e.message); q.reload(); }
  };

  return (
    <div className="space-y-6">
      <FinanceNav />
      <Link to="/accounting/invoices" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline"><Back className="h-4 w-4" />{isRTL ? "كل الفواتير" : "All invoices"}</Link>
      <ErrorBox message={q.error || error} />

      {d && (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h1 className="text-3xl font-bold flex items-center gap-3"><span dir="ltr">{d.number}</span><StatusBadge status={d.status} isRTL={isRTL} />{d.overdue_days > 0 && <Badge variant="destructive">{isRTL ? `متأخرة ${d.overdue_days} يوم` : `${d.overdue_days} days overdue`}</Badge>}</h1>
              <p className="text-muted-foreground mt-1">
                {d.supplier_name} · <span dir="ltr">{d.supplier_invoice_no}</span> · <Link className="text-primary hover:underline" to={`/procurement/purchase-orders/${d.purchase_order_id}`} dir="ltr">{d.po_number}</Link> · <span dir="ltr">{d.invoice_date} → {d.due_date}</span>
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              {["Draft", "Rejected"].includes(d.status) && mine && <Button variant="outline" onClick={() => setEdit(true)}>{isRTL ? "تعديل" : "Edit"}</Button>}
              {d.status === "Draft" && mine && <Button onClick={() => run(() => post(`/ap-invoices/${id}/submit`, {}), isRTL ? "تمت المطابقة" : "Match done")}>{isRTL ? "إرسال ومطابقة" : "Submit & match"}</Button>}
              {["Draft", "Rejected"].includes(d.status) && mine && (
                <Button variant="outline" onClick={async () => { try { await apiRequest(`/ap-invoices/${id}`, { method: "DELETE" }); navigate("/accounting/invoices"); } catch (e: any) { setError(e.message); } }}>{isRTL ? "حذف" : "Delete"}</Button>
              )}
              {["Pending", "On_Hold"].includes(d.status) && !mine && can("ap-invoices", "approve") && (
                <>
                  <Button onClick={() => (d.status === "On_Hold" ? setDec({ action: "approve", code: "", note: "" }) : run(() => post(`/ap-invoices/${id}/decide`, { action: "approve" }), isRTL ? "تم الاعتماد" : "Approved"))}>{d.status === "On_Hold" ? (isRTL ? "اعتماد رغم الفروقات" : "Approve anyway") : isRTL ? "اعتماد" : "Approve"}</Button>
                  <Button variant="outline" onClick={() => setDec({ action: "reject", code: reasons[0]?.code ?? "", note: "" })}>{isRTL ? "رفض" : "Reject"}</Button>
                </>
              )}
              {d.status === "Approved" && d.open_amount > 0.004 && can("ap-payments", "create") && (
                <Button asChild><Link to={`/accounting/payments?supplier=${d.supplier_id}`}>{isRTL ? "سداد" : "Pay"}</Link></Button>
              )}
            </div>
          </div>

          {["Pending", "On_Hold"].includes(d.status) && mine && <p className="text-sm text-muted-foreground">{isRTL ? "بانتظار اعتماد شخص آخر. لا يمكنك اعتماد فاتورة أدخلتها بنفسك." : "Waiting for another person. You cannot approve an invoice you entered."}</p>}
          {d.reject_reason_code && <div className="p-3 rounded-lg bg-red-50 text-red-700 text-sm">{isRTL ? "رُفضت. السبب: " : "Rejected. Reason: "}<b>{nm(isRTL, reasons.find((r) => r.code === d.reject_reason_code)?.name_en, reasons.find((r) => r.code === d.reject_reason_code)?.name_ar) || d.reject_reason_code}</b>{d.decision_note ? ` — ${d.decision_note}` : ""}</div>}

          {d.match_status && (
            <Card className={d.match_status === "matched" ? "border-emerald-300" : "border-red-300"}>
              <CardHeader className="pb-2">
                <CardTitle className="text-base flex items-center gap-2">
                  {d.match_status === "matched" ? <CheckCircle2 className="h-5 w-5 text-emerald-600" /> : <AlertTriangle className="h-5 w-5 text-red-600" />}
                  {d.match_status === "matched" ? (isRTL ? "المطابقة الثلاثية: مطابقة" : "3-way match: matched") : isRTL ? "المطابقة الثلاثية: توجد فروقات" : "3-way match: differences found"}
                </CardTitle>
              </CardHeader>
              {d.match_note && <CardContent className="text-sm whitespace-pre-line text-red-700">{d.match_note}</CardContent>}
            </Card>
          )}

          <Card>
            <CardHeader><CardTitle className="text-base">{isRTL ? "البنود" : "Lines"}</CardTitle></CardHeader>
            <CardContent className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className={align}>{isRTL ? "الصنف" : "Item"}</TableHead>
                    <TableHead className={align}>{isRTL ? "الفاتورة كجم" : "Invoiced kg"}</TableHead>
                    <TableHead className={align}>{isRTL ? "السعر" : "Price"}</TableHead>
                    <TableHead className={align}>{isRTL ? "المبلغ" : "Amount"}</TableHead>
                    <TableHead className={align}>{isRTL ? "سعر الأمر" : "Order price"}</TableHead>
                    <TableHead className={align}>{isRTL ? "المستلم (صافي) كجم" : "Received (net) kg"}</TableHead>
                    <TableHead className={align}>{isRTL ? "القابل للفوترة" : "Billable kg"}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {d.lines.map((l) => (
                    <TableRow key={l.id} className={l.issue ? "bg-red-50/60" : ""}>
                      <TableCell className="font-medium">{nm(isRTL, l.item_name, l.item_name_ar)}{l.issue && <p className="text-xs text-red-600 font-normal">{l.issue}</p>}</TableCell>
                      <TableCell className="font-mono">{fmtKg(l.qty_kg)}</TableCell>
                      <TableCell dir="ltr" className="font-mono">{l.price}</TableCell>
                      <TableCell dir="ltr" className="font-mono">{money(l.amount)}</TableCell>
                      <TableCell dir="ltr" className="font-mono">{l.po_price ?? "—"}</TableCell>
                      <TableCell className="font-mono">{l.received_kg != null ? fmtKg(l.received_kg) : "—"}</TableCell>
                      <TableCell className="font-mono">{l.billable_kg != null ? fmtKg(l.billable_kg) : "—"}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              <div className="mt-4 ms-auto max-w-xs text-sm space-y-1" dir="ltr">
                <div className="flex justify-between"><span>{isRTL ? "قبل الضريبة" : "Subtotal"}</span><span className="font-mono">{money(d.subtotal)}</span></div>
                <div className="flex justify-between"><span>VAT {d.vat_rate_pct}%</span><span className="font-mono">{money(d.vat_amount)}</span></div>
                <div className="flex justify-between font-bold text-base border-t pt-1"><span>{isRTL ? "الإجمالي" : "Total"}</span><span className="font-mono">{money(d.total)}</span></div>
                {d.status === "Approved" && <div className="flex justify-between text-muted-foreground"><span>{isRTL ? "المدفوع" : "Paid"}</span><span className="font-mono">{money(d.paid_amount)}</span></div>}
                {d.status === "Approved" && <div className="flex justify-between font-semibold"><span>{isRTL ? "المتبقي" : "Open"}</span><span className="font-mono">{money(d.open_amount)}</span></div>}
              </div>
              {d.entry_number && <p className="text-xs text-muted-foreground mt-3">{isRTL ? "القيد المحاسبي: " : "Journal entry: "}<Link className="text-primary hover:underline" to="/accounting/ledger">{d.entry_number}</Link></p>}
            </CardContent>
          </Card>
        </>
      )}

      <InvoiceFormDialog open={edit} edit={d} onClose={() => setEdit(false)} onSaved={() => { setEdit(false); q.reload(); }} />

      <Dialog open={!!dec} onOpenChange={(o) => !o && setDec(null)}>
        <DialogContent>
          <form onSubmit={(e) => { e.preventDefault(); run(() => post(`/ap-invoices/${id}/decide`, { action: dec!.action, reason_code: dec!.action === "reject" ? dec!.code : undefined, note: dec!.note || undefined }), dec!.action === "approve" ? (isRTL ? "تم الاعتماد" : "Approved") : isRTL ? "تم الرفض" : "Rejected"); setDec(null); }} className="space-y-4">
            <DialogHeader><DialogTitle>{dec?.action === "approve" ? (isRTL ? "اعتماد رغم الفروقات" : "Approve despite the differences") : isRTL ? "رفض الفاتورة" : "Reject the invoice"}</DialogTitle></DialogHeader>
            {dec?.action === "reject" && (
              <Field id="dec_code" label={isRTL ? "السبب" : "Reason"}>
                <select id="dec_code" className={selectClass} required value={dec.code} onChange={(e) => setDec((s) => s && { ...s, code: e.target.value })}>
                  {reasons.map((r) => <option key={r.code} value={r.code}>{nm(isRTL, r.name_en, r.name_ar)}</option>)}
                </select>
              </Field>
            )}
            <Field id="dec_note" label={dec?.action === "approve" ? (isRTL ? "ملاحظة (إلزامية)" : "Note (required)") : isRTL ? "ملاحظة" : "Note"}>
              <Input id="dec_note" required={dec?.action === "approve"} value={dec?.note ?? ""} onChange={(e) => setDec((s) => s && { ...s, note: e.target.value })} />
            </Field>
            <DialogFooter><Button type="submit">{isRTL ? "تأكيد" : "Confirm"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
