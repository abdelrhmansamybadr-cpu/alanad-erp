import { useState } from "react";
import { toast } from "sonner";
import { Plus } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useApi, post } from "../../hooks/useApi";
import { apiRequest } from "../../services/api";
import { ErrorBox, Field, selectClass } from "../../components/inventory/common";

interface LeaveType { id: number; name: string; kind: string | null; is_paid: number; max_days_allowed: number; description: string | null }
interface Bal { leave_type_id: number; name: string; kind: string | null; entitlement: number; taken: number; pending: number; remaining: number; unlimited: boolean }
interface Req { id: number; number: string; leave_type: string; from_date: string; to_date: string; days: number; status: string; reason: string | null; reject_reason: string | null }
interface Mine { types: LeaveType[]; balances: Bal[]; requests: Req[] }
interface Detail extends Req { flow?: { status: string; current_step: { position: number; name_en: string; name_ar: string | null } | null; actions: { id: number; action: string; note: string | null; created_at: string }[] } }
const FLOW_STATE: Record<string, [string, string]> = { open: ["Waiting for a decision", "بانتظار قرار"], approved: ["Approved", "معتمد"], cancelled: ["Closed", "مُغلق"] };
const ACT: Record<string, [string, string]> = { approve: ["Approved", "وافق"], reject: ["Rejected", "رفض"], cancel: ["Cancelled", "أُلغي"] };

const LEAVE_AR: Record<string, string> = { "Annual leave": "إجازة سنوية", "Sick leave": "إجازة مرضية", "Marriage leave": "إجازة زواج", "Bereavement leave": "إجازة وفاة", "Maternity leave": "إجازة وضع", "Paternity leave": "إجازة أبوة", "Hajj leave": "إجازة حج", "Unpaid leave": "إجازة بدون أجر", "Emergency leave": "إجازة طارئة" };
const STATUS: Record<string, [string, string, string]> = {
  Pending: ["Waiting for approval", "بانتظار الموافقة", "bg-amber-500 hover:bg-amber-500"], Approved: ["Approved", "معتمدة", "bg-emerald-600 hover:bg-emerald-600"],
  Rejected: ["Rejected", "مرفوضة", "bg-red-600 hover:bg-red-600"], Cancelled: ["Cancelled", "ملغاة", "bg-slate-400 hover:bg-slate-400"],
};

/** MY LEAVE — a fixed tab for every employee. Only my own balances and requests; nobody else's, and nothing about HR. */
export function MyLeave() {
  const { isRTL } = useI18n();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const mine = useApi<Mine>("/my/leave");
  const [dlg, setDlg] = useState<{ type: string; from: string; to: string; reason: string } | null>(null);
  const [view, setView] = useState<Detail | null>(null);
  const [cancel, setCancel] = useState<{ id: number; note: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const align = isRTL ? "text-right" : "text-left";
  const lt = (n: string) => (isRTL ? LEAVE_AR[n] ?? n : n);

  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    setBusy(true);
    try {
      await post("/my/leave", { leave_type_id: Number(dlg.type), from_date: dlg.from, to_date: dlg.to, reason: dlg.reason || undefined });
      toast.success(T("Leave requested. Your team head and HR will decide.", "تم إرسال طلب الإجازة. سيقرر رئيس الفريق ثم الموارد البشرية."));
      setDlg(null);
      await mine.reload();
    } catch (err: any) { toast.error(err.message); } finally { setBusy(false); }
  };
  const open = async (id: number) => {
    try { setView(await apiRequest<Detail>(`/my/leave/${id}`)); } catch (err: any) { toast.error(err.message); }
  };
  const doCancel = async () => {
    if (!cancel) return;
    setBusy(true);
    try { await post(`/my/leave/${cancel.id}/cancel`, { reason: cancel.note }); toast.success(T("Cancelled", "أُلغي الطلب")); setCancel(null); await mine.reload(); }
    catch (err: any) { toast.error(err.message); } finally { setBusy(false); }
  };

  const noCard = /not linked/i.test(mine.error);
  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{T("My leave", "إجازاتي")}</h1>
          <p className="text-muted-foreground mt-1">{T("Your balances and your own requests. A request goes to your team head, then to HR.", "أرصدتك وطلباتك أنت فقط. يذهب الطلب إلى رئيس الفريق ثم إلى الموارد البشرية.")}</p>
        </div>
        {mine.data && <Button className="gap-2" data-testid="new-leave" onClick={() => setDlg({ type: "", from: "", to: "", reason: "" })}><Plus className="h-4 w-4" />{T("Request leave", "طلب إجازة")}</Button>}
      </div>
      {noCard && <p className="text-sm text-amber-700" data-testid="no-card">{T("Your account is not linked to an employee record yet. Ask HR to link it, then you can request leave here.", "حسابك غير مربوط ببطاقة موظف بعد. اطلب من الموارد البشرية ربطه ثم اطلب إجازتك من هنا.")}</p>}
      {mine.error && !noCard && <ErrorBox message={mine.error} />}

      {mine.data && (
        <>
          <Card className="p-6" data-testid="balances">
            <h2 className="font-semibold mb-3">{T("My balances this year", "أرصدتي هذه السنة")}</h2>
            <Table>
              <TableHeader><TableRow>
                <TableHead className={align}>{T("Type", "النوع")}</TableHead><TableHead className={align}>{T("Entitlement", "الاستحقاق")}</TableHead>
                <TableHead className={align}>{T("Taken", "المأخوذ")}</TableHead><TableHead className={align}>{T("Waiting", "بانتظار")}</TableHead><TableHead className={align}>{T("Remaining", "المتبقي")}</TableHead>
              </TableRow></TableHeader>
              <TableBody>
                {mine.data.balances.map((b) => (
                  <TableRow key={b.leave_type_id}><TableCell>{lt(b.name)}</TableCell><TableCell>{b.unlimited ? "—" : b.entitlement}</TableCell><TableCell>{b.taken}</TableCell><TableCell>{b.pending}</TableCell><TableCell className="font-semibold">{b.unlimited ? "—" : b.remaining}</TableCell></TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>

          <Card className="p-6" data-testid="my-requests">
            <h2 className="font-semibold mb-3">{T("My requests", "طلباتي")}</h2>
            <Table>
              <TableHeader><TableRow>
                <TableHead className={align}>{T("No.", "الرقم")}</TableHead><TableHead className={align}>{T("Type", "النوع")}</TableHead><TableHead className={align}>{T("Dates", "التواريخ")}</TableHead>
                <TableHead className={align}>{T("Days", "الأيام")}</TableHead><TableHead className={align}>{T("State", "الحالة")}</TableHead><TableHead />
              </TableRow></TableHeader>
              <TableBody>
                {mine.data.requests.map((l) => {
                  const [en, ar, c] = STATUS[l.status] ?? [l.status, l.status, ""];
                  return (
                    <TableRow key={l.id}>
                      <TableCell className="font-mono">{l.number}</TableCell><TableCell>{lt(l.leave_type)}</TableCell>
                      <TableCell dir="ltr">{l.from_date} → {l.to_date}</TableCell><TableCell>{l.days}</TableCell>
                      <TableCell><Badge className={c}>{isRTL ? ar : en}</Badge>{l.reject_reason && <div className="text-xs text-muted-foreground">{l.reject_reason}</div>}</TableCell>
                      <TableCell className="whitespace-nowrap">
                        <Button size="sm" variant="ghost" onClick={() => open(l.id)}>{T("Where is it?", "أين وصل؟")}</Button>
                        {l.status === "Pending" && <Button size="sm" variant="ghost" onClick={() => setCancel({ id: l.id, note: "" })}>{T("Cancel", "إلغاء")}</Button>}
                      </TableCell>
                    </TableRow>
                  );
                })}
                {mine.data.requests.length === 0 && <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">{T("You have no leave requests", "ليس لديك طلبات إجازة")}</TableCell></TableRow>}
              </TableBody>
            </Table>
          </Card>
        </>
      )}

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent>
          <form onSubmit={send} className="space-y-3">
            <DialogHeader><DialogTitle>{T("Request leave", "طلب إجازة")}</DialogTitle></DialogHeader>
            <Field id="ml_type" label={T("Type", "النوع")}>
              <select id="ml_type" className={selectClass} required value={dlg?.type} onChange={(e) => setDlg((s) => s && { ...s, type: e.target.value })}>
                <option value="">{T("Choose…", "اختر…")}</option>
                {mine.data?.types.map((t) => <option key={t.id} value={t.id}>{lt(t.name)}</option>)}
              </select>
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field id="ml_from" label={T("From", "من")}><Input id="ml_from" type="date" dir="ltr" required value={dlg?.from ?? ""} onChange={(e) => setDlg((s) => s && { ...s, from: e.target.value })} /></Field>
              <Field id="ml_to" label={T("To", "إلى")}><Input id="ml_to" type="date" dir="ltr" required value={dlg?.to ?? ""} onChange={(e) => setDlg((s) => s && { ...s, to: e.target.value })} /></Field>
            </div>
            <Field id="ml_reason" label={T("Reason (optional)", "السبب (اختياري)")}><Input id="ml_reason" value={dlg?.reason ?? ""} onChange={(e) => setDlg((s) => s && { ...s, reason: e.target.value })} /></Field>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setDlg(null)}>{T("Cancel", "إلغاء")}</Button>
              <Button type="submit" disabled={busy} data-testid="send-leave">{T("Send", "إرسال")}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!view} onOpenChange={(o) => !o && setView(null)}>
        <DialogContent>
          <DialogHeader><DialogTitle>{view?.number} · {view ? lt(view.leave_type) : ""}</DialogTitle></DialogHeader>
          <p className="text-sm" dir="ltr">{view?.from_date} → {view?.to_date} ({view?.days} {T("days", "يوم")})</p>
          {view?.flow && (
            <div className="space-y-2 text-sm" data-testid="flow">
              <p><b>{T("Now:", "الآن:")}</b> {isRTL ? FLOW_STATE[view.flow.status]?.[1] : FLOW_STATE[view.flow.status]?.[0]}
                {view.flow.status === "open" && view.flow.current_step && <> — {T("waiting at", "عند")} <b>{isRTL ? view.flow.current_step.name_ar || view.flow.current_step.name_en : view.flow.current_step.name_en}</b></>}</p>
              {view.flow.actions.length > 0 && (
                <ul className="list-disc ps-5">{view.flow.actions.map((a) => <li key={a.id}>{isRTL ? ACT[a.action]?.[1] ?? a.action : ACT[a.action]?.[0] ?? a.action} · <span dir="ltr">{a.created_at}</span>{a.note ? ` — ${a.note}` : ""}</li>)}</ul>
              )}
            </div>
          )}
          <DialogFooter><Button variant="outline" onClick={() => setView(null)}>{T("Close", "إغلاق")}</Button></DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={!!cancel} onOpenChange={(o) => !o && setCancel(null)}>
        <DialogContent>
          <DialogHeader><DialogTitle>{T("Why cancel?", "سبب الإلغاء")}</DialogTitle></DialogHeader>
          <Field id="ml_note" label={T("Reason", "السبب")}><Input id="ml_note" value={cancel?.note ?? ""} onChange={(e) => setCancel((s) => s && { ...s, note: e.target.value })} /></Field>
          <DialogFooter>
            <Button variant="outline" onClick={() => setCancel(null)}>{T("Back", "رجوع")}</Button>
            <Button disabled={busy || !cancel?.note.trim()} onClick={doCancel}>{T("Cancel the request", "إلغاء الطلب")}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
