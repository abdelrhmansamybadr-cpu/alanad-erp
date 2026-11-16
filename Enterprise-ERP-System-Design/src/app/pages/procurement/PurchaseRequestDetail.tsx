import { useState } from "react";
import { Link, useNavigate, useParams } from "react-router";
import { toast } from "sonner";
import { ArrowLeft, ArrowRight } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { apiRequest } from "../../services/api";
import { useApi, post } from "../../hooks/useApi";
import { ErrorBox, Field, fmtKg, selectClass, Item, nm } from "../../components/inventory/common";
import { ProcurementNav, PR, PO, Supplier, StatusBadge } from "../../components/procurement/common";
import { PRFormDialog, PRDraft, draftFromPR } from "../../components/procurement/PRFormDialog";

interface Reason { code: string; gate: string; name_en: string; name_ar: string | null }

export function PurchaseRequestDetail() {
  const { id } = useParams();
  const { isRTL } = useI18n();
  const { me, can } = useAuth();
  const navigate = useNavigate();
  const pr = useApi<PR>(`/purchase-requests/${id}`);
  const items = useApi<Item[]>("/items");
  const suppliers = useApi<Supplier[]>("/suppliers");
  const reasons = useApi<Reason[]>("/reject-reasons");
  const [edit, setEdit] = useState<PRDraft | null>(null);
  const [rej, setRej] = useState<{ code: string; note: string } | null>(null);
  const [conv, setConv] = useState<{ supplier_id: string } | null>(null);
  const [error, setError] = useState("");
  const Back = isRTL ? ArrowRight : ArrowLeft;
  const align = isRTL ? "text-right" : "text-left";
  const p = pr.data;
  const mine = !!p && p.requested_by === me?.id;

  const run = async (fn: () => Promise<any>, ok: string) => {
    setError("");
    try {
      await fn();
      toast.success(ok);
      await pr.reload();
    } catch (e: any) {
      setError(e.message);
    }
  };

  const decide = (action: "approve" | "reject") =>
    run(async () => {
      await post(`/purchase-requests/${id}/decide`, action === "reject" ? { action, reason_code: rej?.code, note: rej?.note } : { action });
      setRej(null);
    }, action === "approve" ? (isRTL ? "تم الاعتماد" : "Approved") : isRTL ? "تم الرفض" : "Rejected");

  const convert = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    try {
      const po = await post<PO>(`/purchase-requests/${id}/to-po`, { supplier_id: Number(conv?.supplier_id) });
      toast.success(isRTL ? "تم إنشاء أمر الشراء" : "Purchase order created");
      navigate(`/procurement/purchase-orders/${po.id}`);
    } catch (err: any) {
      setConv(null);
      setError(err.message);
    }
  };

  return (
    <div className="space-y-6">
      <ProcurementNav />
      <Link to="/procurement/requests" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline">
        <Back className="h-4 w-4" />
        {isRTL ? "كل الطلبات" : "All requests"}
      </Link>
      <ErrorBox message={pr.error || error} />

      {p && (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h1 className="text-3xl font-bold flex items-center gap-3"><span dir="ltr">{p.number}</span> <StatusBadge status={p.status} isRTL={isRTL} /></h1>
              <p className="text-muted-foreground mt-1">
                {p.requested_by_name} · <span dir="ltr">{p.date}</span>{p.needed_by ? (isRTL ? ` · مطلوب قبل ${p.needed_by}` : ` · needed by ${p.needed_by}`) : ""}
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              {(p.status === "Draft" || p.status === "Rejected") && mine && (
                <Button variant="outline" onClick={() => setEdit(draftFromPR(p))}>{isRTL ? "تعديل" : "Edit"}</Button>
              )}
              {p.status === "Draft" && mine && (
                <Button onClick={() => run(() => post(`/purchase-requests/${id}/submit`, {}), isRTL ? "أُرسل للاعتماد" : "Sent for approval")}>{isRTL ? "إرسال للاعتماد" : "Send for approval"}</Button>
              )}
              {(p.status === "Draft" || p.status === "Rejected") && mine && (
                <Button variant="outline" onClick={async () => { try { await apiRequest(`/purchase-requests/${id}`, { method: "DELETE" }); navigate("/procurement/requests"); } catch (e: any) { setError(e.message); } }}>{isRTL ? "حذف" : "Delete"}</Button>
              )}
              {p.status === "Pending" && !mine && (
                <>
                  <Button onClick={() => decide("approve")}>{isRTL ? "اعتماد" : "Approve"}</Button>
                  <Button variant="outline" onClick={() => setRej({ code: reasons.data?.[0]?.code ?? "", note: "" })}>{isRTL ? "رفض" : "Reject"}</Button>
                </>
              )}
              {p.status === "Approved" && can("purchase-orders", "create") && (
                <Button onClick={() => setConv({ supplier_id: "" })}>{isRTL ? "إنشاء أمر شراء" : "Create purchase order"}</Button>
              )}
            </div>
          </div>

          {p.status === "Pending" && mine && (
            <p className="text-sm text-muted-foreground">{isRTL ? "بانتظار اعتماد مشرف آخر. لا يمكنك اعتماد طلبك بنفسك." : "Waiting for another supervisor. You cannot approve your own request."}</p>
          )}
          {p.reject_reason && (
            <div className="p-3 rounded-lg bg-red-50 text-red-700 text-sm">{isRTL ? "سبب الرفض: " : "Rejected: "}{p.reject_reason}</div>
          )}
          {p.notes && <p className="text-sm">{p.notes}</p>}

          <Card>
            <CardHeader><CardTitle className="text-base">{isRTL ? "الأصناف المطلوبة" : "Requested items"}</CardTitle></CardHeader>
            <CardContent className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className={align}>{isRTL ? "الصنف" : "Item"}</TableHead>
                    <TableHead className={align}>{isRTL ? "الكمية (كجم)" : "Qty (kg)"}</TableHead>
                    <TableHead className={align}>{isRTL ? "ملاحظة" : "Note"}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {p.lines?.map((l) => (
                    <TableRow key={l.id}>
                      <TableCell className="font-medium">{nm(isRTL, l.item_name, l.item_name_ar)}</TableCell>
                      <TableCell className="font-mono">{fmtKg(Number(l.qty))}</TableCell>
                      <TableCell>{l.note || "—"}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </>
      )}

      <PRFormDialog draft={edit} items={items.data ?? []} onClose={() => setEdit(null)} onSaved={() => { setEdit(null); pr.reload(); }} />

      <Dialog open={!!rej} onOpenChange={(o) => !o && setRej(null)}>
        <DialogContent>
          <form onSubmit={(e) => { e.preventDefault(); decide("reject"); }} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "سبب الرفض" : "Reason for rejecting"}</DialogTitle></DialogHeader>
            <Field id="rj_code" label={isRTL ? "السبب" : "Reason"}>
              <select id="rj_code" className={selectClass} required value={rej?.code ?? ""} onChange={(e) => setRej((s) => s && { ...s, code: e.target.value })}>
                {reasons.data?.map((r) => <option key={r.code} value={r.code}>{nm(isRTL, r.name_en, r.name_ar)}</option>)}
              </select>
            </Field>
            <Field id="rj_note" label={isRTL ? "ملاحظة" : "Note"}>
              <Input id="rj_note" value={rej?.note ?? ""} onChange={(e) => setRej((s) => s && { ...s, note: e.target.value })} />
            </Field>
            <DialogFooter><Button type="submit">{isRTL ? "رفض الطلب" : "Reject request"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!conv} onOpenChange={(o) => !o && setConv(null)}>
        <DialogContent>
          <form onSubmit={convert} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "إنشاء أمر شراء" : "Create purchase order"}</DialogTitle></DialogHeader>
            <Field id="cv_sup" label={isRTL ? "المورد (الأسعار من قائمة أسعاره الحالية)" : "Supplier (prices come from their current price list)"}>
              <select id="cv_sup" className={selectClass} required value={conv?.supplier_id ?? ""} onChange={(e) => setConv({ supplier_id: e.target.value })}>
                <option value="">{isRTL ? "اختر…" : "Choose…"}</option>
                {suppliers.data?.filter((s) => Number(s.is_active) === 1).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </Field>
            <DialogFooter><Button type="submit">{isRTL ? "إنشاء" : "Create"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
