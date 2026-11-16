import { useEffect, useState } from "react";
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
import { HrNav } from "../../components/hr/HrNav";
import { ErrorBox, Field, selectClass } from "../../components/inventory/common";

interface Emp { id: number; employee_id: string; first_name: string; last_name: string; status: string; user_id?: number | null }
interface Bal { leave_type_id: number; name: string; kind: string | null; entitlement: number; taken: number; pending: number; remaining: number; unlimited: boolean }
interface Leave { id: number; number: string; employee_id: number; employee_name: string; leave_type: string; from_date: string; to_date: string; days: number; status: string; reason: string | null; reject_reason: string | null; requested_by: number | null }
interface Reason { code: string; gate: string; name_en: string; name_ar: string | null }
const STATUS: Record<string, [string, string, string]> = {
  Pending: ["Waiting for approval", "بانتظار الموافقة", "bg-amber-500 hover:bg-amber-500"], Approved: ["Approved", "معتمدة", "bg-emerald-600 hover:bg-emerald-600"],
  Rejected: ["Rejected", "مرفوضة", "bg-red-600 hover:bg-red-600"], Cancelled: ["Cancelled", "ملغاة", "bg-slate-400 hover:bg-slate-400"],
};

/** Leave: balances (Saudi entitlements), requests, and approval through the designer-controlled flow (team head, then HR). */
export function Leave() {
  const { isRTL } = useI18n();
  const { can, me } = useAuth();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const isHr = can("hr-leaves", "manage");
  const emps = useApi<Emp[]>("/employees?status=Active");
  const [emp, setEmp] = useState("");
  const mine = emps.data?.find((e) => e.user_id === me?.id);
  useEffect(() => { if (!emp && emps.data?.length) setEmp(String((isHr ? emps.data[0] : mine)?.id ?? "")); }, [emps.data]);   // eslint-disable-line react-hooks/exhaustive-deps
  const bal = useApi<Bal[]>(emp ? `/hr-leaves/balances?employee_id=${emp}` : null);
  const leaves = useApi<Leave[]>("/hr-leaves");
  const reasons = useApi<Reason[]>("/reject-reasons");
  const [dlg, setDlg] = useState<{ type: string; from: string; to: string; reason: string } | null>(null);
  const [ask, setAsk] = useState<{ id: number; kind: "reject" | "cancel"; code: string; note: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const align = isRTL ? "text-right" : "text-left";

  const reload = () => Promise.all([bal.reload(), leaves.reload()]);
  const request = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    setBusy(true);
    try { await post("/hr-leaves", { employee_id: Number(emp), leave_type_id: Number(dlg.type), from_date: dlg.from, to_date: dlg.to, reason: dlg.reason || undefined }); toast.success(T("Leave requested", "تم طلب الإجازة")); setDlg(null); await reload(); }
    catch (err: any) { toast.error(err.message); } finally { setBusy(false); }
  };
  const decide = async (id: number, action: "approve" | "reject", code?: string, note?: string) => {
    setBusy(true);
    try { await post(`/hr-leaves/${id}/decide`, { action, reason_code: code, note }); toast.success(action === "approve" ? T("Approved at your step", "تمت الموافقة في خطوتك") : T("Rejected", "رُفضت")); setAsk(null); await reload(); }
    catch (err: any) { toast.error(err.message); } finally { setBusy(false); }
  };
  const cancel = async () => {
    if (!ask) return;
    setBusy(true);
    try { await post(`/hr-leaves/${ask.id}/cancel`, { reason: ask.note }); toast.success(T("Cancelled", "أُلغيت")); setAsk(null); await reload(); } catch (err: any) { toast.error(err.message); } finally { setBusy(false); }
  };

  return (
    <div className="space-y-6">
      <HrNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div><h1 className="text-3xl font-bold">{T("Leave", "الإجازات")}</h1><p className="text-muted-foreground mt-1">{T("Annual leave is 21 days, 30 after five years of service; the weekend (Friday and Saturday) is not counted. Approval goes to the team head, then HR; the Process designer can change that.", "الإجازة السنوية ٢١ يوماً و٣٠ بعد خمس سنوات خدمة، ولا تُحسب الجمعة والسبت. الموافقة لرئيس الفريق ثم الموارد البشرية، ويمكن تغييرها من مصمم العمليات.")}</p></div>
        {emp && <Button className="gap-2" data-testid="new-leave" onClick={() => setDlg({ type: "", from: "", to: "", reason: "" })}><Plus className="h-4 w-4" />{T("Request leave", "طلب إجازة")}</Button>}
      </div>
      {(emps.error || leaves.error || bal.error) && <ErrorBox message={emps.error || leaves.error || bal.error} />}
      {isHr && (
        <Field id="lv_emp" label={T("Employee", "الموظف")} className="max-w-sm">
          <select id="lv_emp" className={selectClass} value={emp} onChange={(e) => setEmp(e.target.value)}>{emps.data?.map((e) => <option key={e.id} value={e.id}>{e.employee_id} · {e.first_name} {e.last_name}</option>)}</select>
        </Field>
      )}
      {!isHr && !mine && emps.data && <p className="text-sm text-amber-700">{T("Your account is not linked to an employee record. Ask HR to link it to request leave.", "حسابك غير مربوط ببطاقة موظف. اطلب من الموارد البشرية ربطه لتتمكن من طلب الإجازة.")}</p>}

      <Card className="p-6" data-testid="balances">
        <h2 className="font-semibold mb-3">{T("Balances this year", "الأرصدة هذه السنة")}</h2>
        <Table>
          <TableHeader><TableRow><TableHead className={align}>{T("Type", "النوع")}</TableHead><TableHead className={align}>{T("Entitlement", "الاستحقاق")}</TableHead><TableHead className={align}>{T("Taken", "المستخدم")}</TableHead><TableHead className={align}>{T("Waiting", "بالانتظار")}</TableHead><TableHead className={align}>{T("Left", "المتبقي")}</TableHead></TableRow></TableHeader>
          <TableBody>{bal.data?.map((b) => <TableRow key={b.leave_type_id}><TableCell>{b.name}</TableCell><TableCell>{b.unlimited ? "—" : b.entitlement}</TableCell><TableCell>{b.taken}</TableCell><TableCell>{b.pending}</TableCell><TableCell className="font-semibold">{b.unlimited ? "∞" : b.remaining}</TableCell></TableRow>)}</TableBody>
        </Table>
      </Card>

      <Card className="p-6" data-testid="leave-list">
        <h2 className="font-semibold mb-3">{T("Requests", "الطلبات")}</h2>
        <Table>
          <TableHeader><TableRow><TableHead className={align}>{T("No.", "الرقم")}</TableHead><TableHead className={align}>{T("Employee", "الموظف")}</TableHead><TableHead className={align}>{T("Type", "النوع")}</TableHead><TableHead className={align}>{T("Dates", "التواريخ")}</TableHead><TableHead className={align}>{T("Days", "أيام")}</TableHead><TableHead className={align}>{T("Status", "الحالة")}</TableHead><TableHead /></TableRow></TableHeader>
          <TableBody>
            {leaves.data?.map((l) => {
              const [en, ar, c] = STATUS[l.status] ?? [l.status, l.status, ""];
              return (
                <TableRow key={l.id}>
                  <TableCell className="font-mono">{l.number}</TableCell><TableCell>{l.employee_name}</TableCell><TableCell>{l.leave_type}</TableCell><TableCell dir="ltr">{l.from_date} → {l.to_date}</TableCell><TableCell>{l.days}</TableCell>
                  <TableCell><Badge className={c}>{isRTL ? ar : en}</Badge>{l.reject_reason && <div className="text-xs text-muted-foreground">{l.reject_reason}</div>}</TableCell>
                  <TableCell className="whitespace-nowrap">
                    {l.status === "Pending" && <>
                      <Button size="sm" disabled={busy} data-testid={`approve-${l.id}`} onClick={() => decide(l.id, "approve")}>{T("Approve", "موافقة")}</Button>
                      <Button size="sm" variant="ghost" className="text-red-700" onClick={() => setAsk({ id: l.id, kind: "reject", code: "", note: "" })}>{T("Reject", "رفض")}</Button>
                      <Button size="sm" variant="ghost" onClick={() => setAsk({ id: l.id, kind: "cancel", code: "", note: "" })}>{T("Cancel", "إلغاء")}</Button>
                    </>}
                  </TableCell>
                </TableRow>
              );
            })}
            {leaves.data && leaves.data.length === 0 && <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground">{T("No leave requests", "لا توجد طلبات إجازة")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent>
          <form onSubmit={request} className="space-y-3">
            <DialogHeader><DialogTitle>{T("Request leave", "طلب إجازة")}</DialogTitle></DialogHeader>
            <Field id="lv_type" label={T("Type", "النوع")}><select id="lv_type" className={selectClass} required value={dlg?.type} onChange={(e) => setDlg((s) => s && { ...s, type: e.target.value })}><option value="">—</option>{bal.data?.map((b) => <option key={b.leave_type_id} value={b.leave_type_id}>{b.name}{b.unlimited ? "" : ` (${b.remaining})`}</option>)}</select></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field id="lv_from" label={T("From", "من")}><Input id="lv_from" type="date" dir="ltr" required value={dlg?.from ?? ""} onChange={(e) => setDlg((s) => s && { ...s, from: e.target.value })} /></Field>
              <Field id="lv_to" label={T("To", "إلى")}><Input id="lv_to" type="date" dir="ltr" required value={dlg?.to ?? ""} onChange={(e) => setDlg((s) => s && { ...s, to: e.target.value })} /></Field>
            </div>
            <Field id="lv_reason" label={T("Reason", "السبب")}><Input id="lv_reason" value={dlg?.reason ?? ""} onChange={(e) => setDlg((s) => s && { ...s, reason: e.target.value })} /></Field>
            <DialogFooter><Button type="button" variant="outline" onClick={() => setDlg(null)}>{T("Cancel", "إلغاء")}</Button><Button type="submit" disabled={busy} data-testid="send-leave">{T("Send", "إرسال")}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!ask} onOpenChange={(o) => !o && setAsk(null)}>
        <DialogContent>
          <DialogHeader><DialogTitle>{ask?.kind === "reject" ? T("Why reject?", "سبب الرفض") : T("Why cancel?", "سبب الإلغاء")}</DialogTitle></DialogHeader>
          {ask?.kind === "reject" && <Field id="lv_code" label={T("Reason", "السبب")}><select id="lv_code" className={selectClass} value={ask.code} onChange={(e) => setAsk((s) => s && { ...s, code: e.target.value })}><option value="">—</option>{reasons.data?.filter((r) => ["general", "hr"].includes(r.gate)).map((r) => <option key={r.code} value={r.code}>{isRTL && r.name_ar ? r.name_ar : r.name_en}</option>)}</select></Field>}
          <Field id="lv_note" label={T("Note", "ملاحظة")}><Input id="lv_note" value={ask?.note ?? ""} onChange={(e) => setAsk((s) => s && { ...s, note: e.target.value })} /></Field>
          <DialogFooter><Button variant="outline" onClick={() => setAsk(null)}>{T("Back", "رجوع")}</Button><Button disabled={busy || (ask?.kind === "reject" ? !ask.code : !ask?.note.trim())} data-testid="ask-confirm" onClick={() => (ask?.kind === "reject" ? decide(ask.id, "reject", ask.code, ask.note) : cancel())}>{T("Confirm", "تأكيد")}</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
