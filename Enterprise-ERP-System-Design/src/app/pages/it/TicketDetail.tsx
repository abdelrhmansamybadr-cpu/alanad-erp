import { useState } from "react";
import { useParams } from "react-router";
import { toast } from "sonner";
import { Card } from "../../components/ui/card";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { Textarea } from "../../components/ui/textarea";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";
import { useApi, post } from "../../hooks/useApi";
import { ItNav, TICKET_STATUS, PRIORITY, CATEGORY } from "../../components/it/ItNav";
import { ErrorBox, selectClass } from "../../components/inventory/common";

interface Comment { id: number; body: string; is_internal: number | boolean; created_at: string; user_name: string }
interface Ticket {
  id: number; number: string; subject: string; description: string | null; category: string; priority: string; status: string; due_at: string | null; created_at: string; resolved_at: string | null; resolution: string | null;
  requester_id: number; requester_name: string; assignee_id: number | null; assignee_name: string | null; overdue: boolean; asset: { id: number; code: string; name: string } | null; comments: Comment[]; next: string[];
}
interface Member { id: number; name: string }

export function TicketDetail() {
  const { id } = useParams();
  const { isRTL } = useI18n();
  const { can, me } = useAuth();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const q = useApi<Ticket>(`/it-tickets/${id}`);
  const isManager = can("it-tickets", "manage");
  const staff = isManager || can("it-tickets", "work");
  const team = useApi<Member[]>(isManager ? "/it-tickets/team" : null);
  const [text, setText] = useState(""); const [internal, setInternal] = useState(false);
  const [who, setWho] = useState(""); const [note, setNote] = useState(""); const [busy, setBusy] = useState(false);
  const t = q.data;
  const run = async (path: string, body: object, ok: string) => {
    setBusy(true);
    try { await post(`/it-tickets/${id}/${path}`, body); toast.success(ok); setText(""); setNote(""); setInternal(false); await q.reload(); }
    catch (e: any) { toast.error(e.message); } finally { setBusy(false); }
  };
  const mineToWork = !!t && staff && (isManager || t.assignee_id === me?.id);
  const isRequester = !!t && t.requester_id === me?.id;
  const open = !!t && !["Closed", "Cancelled"].includes(t.status);

  return (
    <div className="space-y-6">
      <ItNav />
      {q.error && <ErrorBox message={q.error} />}
      {t && (
        <>
          <div>
            <div className="flex flex-wrap items-center gap-2"><h1 className="text-2xl font-bold" dir="ltr">{t.number}</h1>
              <Badge data-testid="t-status" className={TICKET_STATUS[t.status]?.[2]}>{TICKET_STATUS[t.status]?.[isRTL ? 1 : 0]}</Badge>
              <Badge className={PRIORITY[t.priority]?.[2]}>{PRIORITY[t.priority]?.[isRTL ? 1 : 0]}</Badge>
              {t.overdue && <Badge className="bg-red-600 hover:bg-red-600">{T("Overdue", "متأخرة")}</Badge>}</div>
            <p className="text-lg mt-1">{t.subject}</p>
            <p className="text-sm text-muted-foreground mt-1">{t.requester_name} · {CATEGORY[t.category]?.[isRTL ? 1 : 0]} · {t.created_at.slice(0, 16)} · {T("handled by", "المسؤول")}: {t.assignee_name ?? "—"} · {T("due", "الموعد")}: <span dir="ltr">{t.due_at?.slice(0, 16)}</span>{t.asset && <> · {T("device", "الجهاز")}: {t.asset.code} {t.asset.name}</>}</p>
            {t.description && <Card className="p-4 mt-3 text-sm whitespace-pre-wrap">{t.description}</Card>}
            {t.resolution && <Card className="p-4 mt-3 text-sm border-emerald-300 bg-emerald-50 dark:bg-emerald-950/30" data-testid="t-resolution"><b>{T("Solution", "الحل")}:</b> {t.resolution}</Card>}
          </div>

          {isManager && open && !["Resolved"].includes(t.status) && (
            <Card className="p-4 flex flex-wrap items-end gap-3" data-testid="assign-box">
              <div><div className="text-sm mb-1">{T("Assign to", "إسناد إلى")}</div><select data-testid="assign-select" className={selectClass} value={who} onChange={(e) => setWho(e.target.value)}><option value="">—</option>{(Array.isArray(team.data) ? team.data : []).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}</select></div>
              <Button disabled={busy || !who} data-testid="assign-btn" onClick={() => run("assign", { assignee_id: Number(who) }, T("Assigned", "تم الإسناد"))}>{T("Assign", "إسناد")}</Button>
            </Card>
          )}

          {mineToWork && open && t.next.length > 0 && t.status !== "Resolved" && (
            <Card className="p-4 space-y-3" data-testid="work-box">
              <div className="text-sm font-semibold">{T("Work on this ticket", "العمل على التذكرة")}</div>
              <Textarea rows={2} placeholder={T("What was done? (required to resolve)", "ما الذي تم؟ (مطلوب عند الحل)")} value={note} onChange={(e) => setNote(e.target.value)} data-testid="resolution-text" />
              <div className="flex flex-wrap gap-2">
                {t.next.includes("In_Progress") && <Button variant="outline" disabled={busy} data-testid="st-In_Progress" onClick={() => run("status", { status: "In_Progress" }, T("Started", "بدأ العمل"))}>{T("Start work", "بدء العمل")}</Button>}
                {t.next.includes("Waiting") && <Button variant="outline" disabled={busy} data-testid="st-Waiting" onClick={() => run("status", { status: "Waiting" }, T("Waiting", "بانتظار رد"))}>{T("Waiting for the requester", "بانتظار رد صاحب الطلب")}</Button>}
                {t.next.includes("Resolved") && <Button disabled={busy || !note.trim()} data-testid="st-Resolved" onClick={() => run("status", { status: "Resolved", resolution: note }, T("Resolved", "تم الحل"))}>{T("Mark as solved", "تم الحل")}</Button>}
              </div>
            </Card>
          )}

          {t.status === "Resolved" && (isRequester || isManager) && (
            <Card className="p-4 space-y-3" data-testid="confirm-box">
              <div className="text-sm font-semibold">{T("Is it solved for you?", "هل تم حلها عندك؟")}</div>
              <Textarea rows={2} placeholder={T("If not, say what is still wrong", "إن لم تُحل، اكتب ما بقي من المشكلة")} value={note} onChange={(e) => setNote(e.target.value)} />
              <div className="flex gap-2"><Button disabled={busy} data-testid="close-btn" onClick={() => run("close", {}, T("Closed", "أُغلقت"))}>{T("Yes, close it", "نعم، أغلقها")}</Button><Button variant="outline" disabled={busy || !note.trim()} data-testid="reopen-btn" onClick={() => run("reopen", { reason: note }, T("Reopened", "أُعيد فتحها"))}>{T("No, reopen", "لا، أعد فتحها")}</Button></div>
            </Card>
          )}

          {["New", "Assigned"].includes(t.status) && (isRequester || isManager) && (
            <div><Button variant="outline" size="sm" data-testid="cancel-btn" disabled={busy || !note.trim()} onClick={() => run("cancel", { reason: note }, T("Cancelled", "أُلغيت"))}>{T("Cancel this ticket (write the reason above)", "إلغاء التذكرة (اكتب السبب أعلاه)")}</Button></div>
          )}

          <Card className="p-6 space-y-4" data-testid="comments">
            <h2 className="font-semibold">{T("Conversation", "المحادثة")}</h2>
            {t.comments.length === 0 && <p className="text-sm text-muted-foreground">{T("No messages yet", "لا توجد رسائل بعد")}</p>}
            {t.comments.map((c) => (
              <div key={c.id} className={`rounded-md p-3 text-sm ${c.is_internal ? "bg-amber-50 dark:bg-amber-950/30 border border-amber-300" : "bg-muted"}`}>
                <div className="text-xs text-muted-foreground mb-1">{c.user_name} · {c.created_at.slice(0, 16)}{c.is_internal ? ` · ${T("internal note (IT only)", "ملاحظة داخلية (للتقنية فقط)")}` : ""}</div>
                <div className="whitespace-pre-wrap">{c.body}</div>
              </div>
            ))}
            {open && (
              <div className="space-y-2">
                <Textarea rows={2} value={text} onChange={(e) => setText(e.target.value)} placeholder={T("Write a message", "اكتب رسالة")} data-testid="comment-text" />
                <div className="flex items-center gap-3">
                  <Button disabled={busy || !text.trim()} data-testid="comment-send" onClick={() => run("comment", { body: text, internal }, T("Sent", "أُرسلت"))}>{T("Send", "إرسال")}</Button>
                  {staff && <label className="inline-flex items-center gap-2 text-sm"><input type="checkbox" checked={internal} onChange={(e) => setInternal(e.target.checked)} data-testid="comment-internal" />{T("Internal note (the requester will not see it)", "ملاحظة داخلية (لا يراها صاحب الطلب)")}</label>}
                </div>
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  );
}
