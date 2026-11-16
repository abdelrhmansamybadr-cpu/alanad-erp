import { useEffect, useState } from "react";
import { useSearchParams } from "react-router";
import { toast } from "sonner";
import { Plus } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { useApi, post } from "../../hooks/useApi";
import { ErrorBox, Field, selectClass, nm } from "../../components/inventory/common";
import { StatusBadge, Supplier, money } from "../../components/procurement/common";
import { FinanceNav, Invoice, Reason, financeReasons } from "../../components/finance/common";

interface Pay { id: number; number: string; date: string; amount: number; method: string; reference: string | null; status: string; created_by: number; supplier_name: string }
const METHOD_AR: Record<string, string> = { bank_transfer: "تحويل بنكي", cheque: "شيك", cash: "نقداً" };

export function PaymentList() {
  const { isRTL } = useI18n();
  const { me, can } = useAuth();
  const [params, setParams] = useSearchParams();
  const list = useApi<Pay[]>("/ap-payments");
  const suppliers = useApi<Supplier[]>("/suppliers");
  const reasonsQ = useApi<Reason[]>("/reject-reasons");
  const reasons = financeReasons(reasonsQ.data);
  const [dlg, setDlg] = useState<{ supplier_id: string; date: string; method: string; reference: string; alloc: Record<number, string> } | null>(null);
  const [rej, setRej] = useState<{ id: number; code: string; note: string } | null>(null);
  const [error, setError] = useState("");
  const open = useApi<Invoice[]>(dlg?.supplier_id ? `/ap-invoices?supplier_id=${dlg.supplier_id}&status=Approved` : null);
  const align = isRTL ? "text-right" : "text-left";
  const openInv = (open.data ?? []).filter((i) => i.open_amount > 0.004);
  const total = Object.values(dlg?.alloc ?? {}).reduce((s, v) => s + (Number(v) || 0), 0);

  useEffect(() => {
    const s = params.get("supplier");
    if (s) { setDlg({ supplier_id: s, date: new Date().toISOString().slice(0, 10), method: "bank_transfer", reference: "", alloc: {} }); setParams({}, { replace: true }); }
  }, [params, setParams]);

  const run = async (fn: () => Promise<unknown>, ok: string) => {
    setError("");
    try { await fn(); toast.success(ok); await list.reload(); } catch (e: any) { setError(e.message); list.reload(); }
  };

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    const allocations = Object.entries(dlg.alloc).filter(([, v]) => Number(v) > 0).map(([id, v]) => ({ invoice_id: Number(id), amount: Number(v) }));
    setError("");
    try {
      await post("/ap-payments", { supplier_id: Number(dlg.supplier_id), date: dlg.date, method: dlg.method, reference: dlg.reference || undefined, amount: Math.round(total * 100) / 100, allocations });
      toast.success(isRTL ? "تم إدخال الدفعة وبانتظار الاعتماد" : "Payment entered, waiting for approval");
      setDlg(null);
      list.reload();
    } catch (err: any) { setError(err.message); }
  };

  return (
    <div className="space-y-6">
      <FinanceNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "مدفوعات الموردين" : "Supplier Payments"}</h1>
          <p className="text-muted-foreground mt-1">{isRTL ? "الدفعة تُدخل ثم يعتمدها شخص آخر، وعندها فقط تُرحَّل إلى الحسابات." : "A payment is entered, then approved by another person. Only then is it posted to the accounts."}</p>
        </div>
        {can("ap-payments", "create") && (
          <Button className="gap-2" onClick={() => setDlg({ supplier_id: "", date: new Date().toISOString().slice(0, 10), method: "bank_transfer", reference: "", alloc: {} })}><Plus className="h-4 w-4" />{isRTL ? "دفعة جديدة" : "New payment"}</Button>
        )}
      </div>
      <ErrorBox message={list.error || error} />
      <Card className="p-6 overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className={align}>{isRTL ? "الرقم" : "Number"}</TableHead>
              <TableHead className={align}>{isRTL ? "المورد" : "Supplier"}</TableHead>
              <TableHead className={align}>{isRTL ? "التاريخ" : "Date"}</TableHead>
              <TableHead className={align}>{isRTL ? "الطريقة" : "Method"}</TableHead>
              <TableHead className={align}>{isRTL ? "المبلغ" : "Amount"}</TableHead>
              <TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.data?.map((p) => (
              <TableRow key={p.id}>
                <TableCell className="font-mono">{p.number}</TableCell>
                <TableCell>{p.supplier_name}</TableCell>
                <TableCell dir="ltr" className="text-sm">{p.date}</TableCell>
                <TableCell className="text-sm">{isRTL ? METHOD_AR[p.method] ?? p.method : p.method.replace("_", " ")}{p.reference ? ` · ${p.reference}` : ""}</TableCell>
                <TableCell dir="ltr" className="font-mono text-sm">{money(p.amount)}</TableCell>
                <TableCell><StatusBadge status={p.status} isRTL={isRTL} /></TableCell>
                <TableCell className="whitespace-nowrap">
                  {p.status === "Pending" && p.created_by !== me?.id && can("ap-payments", "approve") && (
                    <>
                      <Button size="sm" onClick={() => run(() => post(`/ap-payments/${p.id}/decide`, { action: "approve" }), isRTL ? "تم الاعتماد والترحيل" : "Approved and posted")}>{isRTL ? "اعتماد" : "Approve"}</Button>{" "}
                      <Button size="sm" variant="outline" onClick={() => setRej({ id: p.id, code: reasons[0]?.code ?? "", note: "" })}>{isRTL ? "رفض" : "Reject"}</Button>
                    </>
                  )}
                  {p.status === "Pending" && p.created_by === me?.id && <span className="text-xs text-muted-foreground">{isRTL ? "بانتظار شخص آخر" : "waiting for another person"}</span>}
                </TableCell>
              </TableRow>
            ))}
            {list.data && list.data.length === 0 && <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground">{isRTL ? "لا توجد مدفوعات" : "No payments"}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
          <form onSubmit={create} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "دفعة جديدة لمورد" : "New supplier payment"}</DialogTitle></DialogHeader>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <Field id="py_sup" label={isRTL ? "المورد" : "Supplier"} className="sm:col-span-2">
                <select id="py_sup" className={selectClass} required value={dlg?.supplier_id ?? ""} onChange={(e) => setDlg((s) => s && { ...s, supplier_id: e.target.value, alloc: {} })}>
                  <option value="">{isRTL ? "اختر…" : "Choose…"}</option>
                  {suppliers.data?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
              </Field>
              <Field id="py_date" label={isRTL ? "التاريخ" : "Date"}><Input id="py_date" type="date" required value={dlg?.date ?? ""} onChange={(e) => setDlg((s) => s && { ...s, date: e.target.value })} /></Field>
              <Field id="py_method" label={isRTL ? "الطريقة" : "Method"}>
                <select id="py_method" className={selectClass} value={dlg?.method ?? ""} onChange={(e) => setDlg((s) => s && { ...s, method: e.target.value })}>
                  <option value="bank_transfer">{isRTL ? "تحويل بنكي" : "Bank transfer"}</option><option value="cheque">{isRTL ? "شيك" : "Cheque"}</option><option value="cash">{isRTL ? "نقداً" : "Cash"}</option>
                </select>
              </Field>
              <Field id="py_ref" label={isRTL ? "المرجع" : "Reference"} className="sm:col-span-2"><Input id="py_ref" dir="ltr" value={dlg?.reference ?? ""} onChange={(e) => setDlg((s) => s && { ...s, reference: e.target.value })} /></Field>
            </div>
            {dlg?.supplier_id && (
              <div className="space-y-2">
                <p className="text-sm font-medium">{isRTL ? "الفواتير المفتوحة (اكتب المبلغ المسدد لكل فاتورة)" : "Open invoices (enter the amount paid on each)"}</p>
                {openInv.map((i) => (
                  <div key={i.id} className="flex items-center gap-3 text-sm">
                    <span className="flex-1" dir="ltr">{i.number} · {i.supplier_invoice_no} · due {i.due_date}{i.overdue ? " ⚠" : ""}</span>
                    <span className="text-muted-foreground" dir="ltr">open {i.open_amount.toFixed(2)}</span>
                    <Input aria-label={`pay ${i.number}`} className="w-32" type="number" step="0.01" min="0" max={i.open_amount} dir="ltr" value={dlg.alloc[i.id] ?? ""} onChange={(e) => setDlg((s) => s && { ...s, alloc: { ...s.alloc, [i.id]: e.target.value } })} />
                  </div>
                ))}
                {open.data && openInv.length === 0 && <p className="text-sm text-muted-foreground">{isRTL ? "لا توجد فواتير معتمدة مفتوحة لهذا المورد." : "No open approved invoices for this supplier."}</p>}
                <p className="text-sm text-end" dir="ltr">{isRTL ? "إجمالي الدفعة" : "Payment total"}: <b>{total.toFixed(2)}</b> SAR</p>
              </div>
            )}
            <DialogFooter><Button type="submit" disabled={total <= 0}>{isRTL ? "إدخال الدفعة" : "Enter payment"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!rej} onOpenChange={(o) => !o && setRej(null)}>
        <DialogContent>
          <form onSubmit={(e) => { e.preventDefault(); run(() => post(`/ap-payments/${rej!.id}/decide`, { action: "reject", reason_code: rej!.code, note: rej!.note || undefined }), isRTL ? "تم الرفض" : "Rejected"); setRej(null); }} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "رفض الدفعة" : "Reject the payment"}</DialogTitle></DialogHeader>
            <Field id="pj_code" label={isRTL ? "السبب" : "Reason"}>
              <select id="pj_code" className={selectClass} required value={rej?.code ?? ""} onChange={(e) => setRej((s) => s && { ...s, code: e.target.value })}>
                {reasons.map((r) => <option key={r.code} value={r.code}>{nm(isRTL, r.name_en, r.name_ar)}</option>)}
              </select>
            </Field>
            <Field id="pj_note" label={isRTL ? "ملاحظة" : "Note"}><Input id="pj_note" value={rej?.note ?? ""} onChange={(e) => setRej((s) => s && { ...s, note: e.target.value })} /></Field>
            <DialogFooter><Button type="submit">{isRTL ? "رفض" : "Reject"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
