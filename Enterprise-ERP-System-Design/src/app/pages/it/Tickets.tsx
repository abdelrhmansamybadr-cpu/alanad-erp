import { useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router";
import { toast } from "sonner";
import { Plus } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Textarea } from "../../components/ui/textarea";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";
import { useApi, post } from "../../hooks/useApi";
import { ItNav, TICKET_STATUS, PRIORITY, CATEGORY } from "../../components/it/ItNav";
import { ErrorBox, Field, selectClass } from "../../components/inventory/common";

interface Ticket { id: number; number: string; subject: string; category: string; priority: string; status: string; due_at: string | null; created_at: string; requester_name: string; assignee_name: string | null; overdue: boolean }
interface Asset { id: number; code: string; name: string }

export function Tickets() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const nav = useNavigate();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const staff = can("it-tickets", "work") || can("it-tickets", "manage");
  const [sp] = useSearchParams();
  const [status, setStatus] = useState("");
  const overdue = sp.get("overdue") === "1";
  const q = useApi<Ticket[]>(`/it-tickets?${status ? `status=${status}&` : ""}${overdue ? "overdue=1" : ""}`);
  const assets = useApi<Asset[]>(staff ? "/it-assets" : null);
  const [dlg, setDlg] = useState<{ subject: string; description: string; category: string; priority: string; asset_id: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const align = isRTL ? "text-right" : "text-left";
  const rows = Array.isArray(q.data) ? q.data : [];

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    setBusy(true);
    try {
      const t = await post("/it-tickets", { subject: dlg.subject, description: dlg.description || undefined, category: dlg.category, priority: dlg.priority, asset_id: dlg.asset_id ? Number(dlg.asset_id) : undefined });
      toast.success(T("Ticket opened", "تم فتح التذكرة")); setDlg(null); nav(`/it/tickets/${t.id}`);
    } catch (err: any) { toast.error(err.message); } finally { setBusy(false); }
  };

  return (
    <div className="space-y-6">
      <ItNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div><h1 className="text-3xl font-bold">{staff ? T("Tickets", "التذاكر") : T("My tickets", "تذاكري")}</h1>{overdue && <p className="text-sm text-red-600 mt-1">{T("Showing only tickets past their time limit", "تظهر فقط التذاكر المتأخرة عن وقتها")}</p>}</div>
        <Button className="gap-2" data-testid="new-ticket" onClick={() => setDlg({ subject: "", description: "", category: "other", priority: "medium", asset_id: "" })}><Plus className="h-4 w-4" />{T("New ticket", "تذكرة جديدة")}</Button>
      </div>
      {q.error && <ErrorBox message={q.error} />}
      <div className="flex gap-2 flex-wrap">
        <Button size="sm" variant={status === "" ? "default" : "outline"} onClick={() => setStatus("")}>{T("All", "الكل")}</Button>
        {["New", "Assigned", "In_Progress", "Waiting", "Resolved", "Closed"].map((s) => <Button key={s} size="sm" variant={status === s ? "default" : "outline"} onClick={() => setStatus(s)}>{T(TICKET_STATUS[s][0], TICKET_STATUS[s][1])}</Button>)}
      </div>
      <Card className="p-6" data-testid="ticket-list">
        <Table>
          <TableHeader><TableRow><TableHead className={align}>{T("No.", "الرقم")}</TableHead><TableHead className={align}>{T("Problem", "المشكلة")}</TableHead><TableHead className={align}>{T("Priority", "الأولوية")}</TableHead><TableHead className={align}>{T("Status", "الحالة")}</TableHead>{staff && <TableHead className={align}>{T("From", "من")}</TableHead>}<TableHead className={align}>{T("Handled by", "المسؤول")}</TableHead><TableHead className={align}>{T("Due", "الموعد")}</TableHead></TableRow></TableHeader>
          <TableBody>
            {rows.map((t) => (
              <TableRow key={t.id} data-testid={`ticket-${t.number}`}>
                <TableCell><Link className="text-primary underline" to={`/it/tickets/${t.id}`} dir="ltr">{t.number}</Link></TableCell>
                <TableCell className="font-medium max-w-[280px]">{t.subject}<div className="text-xs text-muted-foreground">{CATEGORY[t.category]?.[isRTL ? 1 : 0]}</div></TableCell>
                <TableCell><Badge className={PRIORITY[t.priority]?.[2]}>{PRIORITY[t.priority]?.[isRTL ? 1 : 0]}</Badge></TableCell>
                <TableCell><Badge className={TICKET_STATUS[t.status]?.[2]}>{TICKET_STATUS[t.status]?.[isRTL ? 1 : 0] ?? t.status}</Badge></TableCell>
                {staff && <TableCell>{t.requester_name}</TableCell>}
                <TableCell>{t.assignee_name ?? "—"}</TableCell>
                <TableCell dir="ltr" className={t.overdue ? "text-red-600 font-semibold" : ""}>{t.due_at?.slice(0, 16)}{t.overdue ? " ⚠" : ""}</TableCell>
              </TableRow>
            ))}
            {q.data && rows.length === 0 && <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground">{T("No tickets", "لا توجد تذاكر")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent>
          <DialogHeader><DialogTitle>{T("New ticket", "تذكرة جديدة")}</DialogTitle></DialogHeader>
          {dlg && (
            <form onSubmit={create} className="space-y-3" data-testid="ticket-form">
              <Field id="tk_subject" label={T("What is the problem?", "ما المشكلة؟")}><Input id="tk_subject" required maxLength={200} value={dlg.subject} onChange={(e) => setDlg({ ...dlg, subject: e.target.value })} /></Field>
              <Field id="tk_desc" label={T("Details", "التفاصيل")}><Textarea id="tk_desc" rows={3} value={dlg.description} onChange={(e) => setDlg({ ...dlg, description: e.target.value })} /></Field>
              <div className="grid grid-cols-2 gap-3">
                <Field id="tk_cat" label={T("Kind", "النوع")}><select id="tk_cat" className={selectClass} value={dlg.category} onChange={(e) => setDlg({ ...dlg, category: e.target.value })}>{Object.entries(CATEGORY).map(([k, v]) => <option key={k} value={k}>{v[isRTL ? 1 : 0]}</option>)}</select></Field>
                <Field id="tk_pri" label={T("How urgent?", "مدى الاستعجال")}><select id="tk_pri" className={selectClass} value={dlg.priority} onChange={(e) => setDlg({ ...dlg, priority: e.target.value })}>{Object.entries(PRIORITY).map(([k, v]) => <option key={k} value={k}>{v[isRTL ? 1 : 0]}</option>)}</select></Field>
              </div>
              {staff && <Field id="tk_asset" label={T("Device (optional)", "الجهاز (اختياري)")}><select id="tk_asset" className={selectClass} value={dlg.asset_id} onChange={(e) => setDlg({ ...dlg, asset_id: e.target.value })}><option value="">—</option>{(Array.isArray(assets.data) ? assets.data : []).map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}</select></Field>}
              <DialogFooter><Button type="button" variant="outline" onClick={() => setDlg(null)}>{T("Cancel", "إلغاء")}</Button><Button type="submit" disabled={busy || !dlg.subject.trim()} data-testid="ticket-submit">{T("Open ticket", "فتح التذكرة")}</Button></DialogFooter>
            </form>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
