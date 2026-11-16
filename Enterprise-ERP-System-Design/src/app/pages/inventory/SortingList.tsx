import { useState } from "react";
import { Link, useNavigate } from "react-router";
import { toast } from "sonner";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";
import { useApi, post } from "../../hooks/useApi";
import { InventoryNav } from "../../components/inventory/InventoryNav";
import { ErrorBox, Field, fmtKg } from "../../components/inventory/common";

interface Waiting { id: number; number: string; vehicle_plate: string | null; supplier_name: string | null; accepted_kg: number; run_id: number | null }
interface Run { id: number; number: string; status: string; delivery_number: string; supplier_name: string | null; input_kg: number; class1_kg: number; class1_pct: number | null; has_incident: boolean }
interface Incident { id: number; run_id: number; run_number: string; supplier_name: string | null; reason_code: string; note: string | null; filed_by_name: string; status: "Open" | "Reviewed"; claim_supplier: number; review_note: string | null }

const STATUS: Record<string, [string, string, string]> = {
  In_Progress: ["Being sorted", "قيد الفرز", "bg-blue-600 hover:bg-blue-600"],
  Awaiting_Signoff: ["Awaiting quality sign-off", "بانتظار اعتماد الجودة", "bg-amber-500 hover:bg-amber-500"],
  Completed: ["Completed", "مكتمل", "bg-teal-700 hover:bg-teal-700"],
  Cancelled: ["Cancelled", "ملغى", "bg-slate-400 hover:bg-slate-400"],
};
export const SortStatus = ({ s, isRTL }: { s: string; isRTL: boolean }) => { const [en, ar, c] = STATUS[s] ?? [s, s, ""]; return <Badge className={c}>{isRTL ? ar : en}</Badge>; };

/** Sorting (الفرز): trucks waiting for it, the runs, and the incident reports of loads that came out below the class-1 minimum. */
export function SortingList() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const nav = useNavigate();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const queue = useApi<Waiting[]>("/sorting-runs/queue");
  const runs = useApi<Run[]>("/sorting-runs");
  const incidents = useApi<Incident[]>("/sorting-runs/incidents");
  const [busy, setBusy] = useState(false);
  const [review, setReview] = useState<{ id: number; note: string; claim: boolean } | null>(null);
  const align = isRTL ? "text-right" : "text-left";

  const start = async (deliveryId: number) => {
    setBusy(true);
    try { const r = await post("/sorting-runs", { delivery_id: deliveryId }); toast.success(T("Sorting started", "بدأ الفرز")); nav(`/inventory/sorting/${r.id}`); }
    catch (e: any) { toast.error(e.message); } finally { setBusy(false); }
  };
  const doReview = async () => {
    if (!review) return;
    try { await post(`/sorting-runs/incidents/${review.id}/review`, { note: review.note, claim_supplier: review.claim }); toast.success(T("Reviewed", "تمت المراجعة")); setReview(null); await incidents.reload(); }
    catch (e: any) { toast.error(e.message); }
  };

  return (
    <div className="space-y-6">
      <InventoryNav />
      <div>
        <h1 className="text-3xl font-bold">{T("Sorting", "الفرز")}</h1>
        <p className="text-muted-foreground mt-1">{T("A truck routed to sorting is split into three sellable classes: class 1 (for the client), class 2 (normal-quality surplus) and class 3 (lower quality). Class 1 should be at least 80 % of the load, otherwise the quality person files an incident report.", "تُقسَّم الشاحنة الموجَّهة للفرز إلى ثلاث درجات قابلة للبيع: الأولى (للعميل) والثانية (فائض بجودة عادية) والثالثة (جودة أقل). يجب ألا تقل الأولى عن ٨٠٪ من الحمولة وإلا يكتب مسؤول الجودة تقرير حادثة.")}</p>
      </div>
      {(queue.error || runs.error || incidents.error) && <ErrorBox message={queue.error || runs.error || incidents.error} />}

      <Card className="p-6" data-testid="queue">
        <h2 className="font-semibold mb-3">{T("Trucks waiting for sorting", "شاحنات بانتظار الفرز")}</h2>
        <Table>
          <TableHeader><TableRow>
            <TableHead className={align}>{T("Truck", "الشاحنة")}</TableHead><TableHead className={align}>{T("Supplier", "المورد")}</TableHead><TableHead className={align}>{T("Accepted kg", "الكمية المقبولة")}</TableHead><TableHead />
          </TableRow></TableHeader>
          <TableBody>
            {queue.data?.map((d) => (
              <TableRow key={d.id}>
                <TableCell><Link className="font-mono text-primary underline" to={`/inventory/receiving/${d.id}`}>{d.number}</Link><div className="text-xs text-muted-foreground" dir="ltr">{d.vehicle_plate}</div></TableCell>
                <TableCell>{d.supplier_name ?? "—"}</TableCell><TableCell>{fmtKg(d.accepted_kg)} kg</TableCell>
                <TableCell>{d.run_id ? <Link className="text-primary underline" to={`/inventory/sorting/${d.run_id}`}>{T("Open the run", "فتح الفرز")}</Link> : can("sorting-runs", "create") && <Button size="sm" disabled={busy} data-testid={`start-${d.id}`} onClick={() => start(d.id)}>{T("Start sorting", "بدء الفرز")}</Button>}</TableCell>
              </TableRow>
            ))}
            {queue.data && queue.data.length === 0 && <TableRow><TableCell colSpan={4} className="text-center text-muted-foreground">{T("No truck is waiting", "لا توجد شاحنة بالانتظار")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      <Card className="p-6" data-testid="runs">
        <h2 className="font-semibold mb-3">{T("Sorting runs", "عمليات الفرز")}</h2>
        <Table>
          <TableHeader><TableRow>
            <TableHead className={align}>{T("No.", "الرقم")}</TableHead><TableHead className={align}>{T("Truck / supplier", "الشاحنة / المورد")}</TableHead><TableHead className={align}>{T("Input kg", "الداخل")}</TableHead>
            <TableHead className={align}>{T("Class 1", "الدرجة ١")}</TableHead><TableHead className={align}>{T("Status", "الحالة")}</TableHead>
          </TableRow></TableHeader>
          <TableBody>
            {runs.data?.map((r) => (
              <TableRow key={r.id}>
                <TableCell><Link className="font-mono text-primary underline" to={`/inventory/sorting/${r.id}`}>{r.number}</Link></TableCell>
                <TableCell>{r.delivery_number}<div className="text-xs text-muted-foreground">{r.supplier_name}</div></TableCell>
                <TableCell>{fmtKg(r.input_kg)}</TableCell>
                <TableCell>{r.class1_pct != null ? <span className={r.class1_pct < 80 ? "text-red-700 font-semibold" : ""} dir="ltr">{r.class1_pct}%</span> : "—"}{r.has_incident && <Badge variant="secondary" className="ms-2">{T("incident", "حادثة")}</Badge>}</TableCell>
                <TableCell><SortStatus s={r.status} isRTL={isRTL} /></TableCell>
              </TableRow>
            ))}
            {runs.data && runs.data.length === 0 && <TableRow><TableCell colSpan={5} className="text-center text-muted-foreground">{T("No runs yet", "لا توجد عمليات فرز")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      {incidents.data && incidents.data.length > 0 && (
        <Card className="p-6" data-testid="incidents">
          <h2 className="font-semibold mb-3">{T("Incident reports", "تقارير الحوادث")}</h2>
          <Table>
            <TableHeader><TableRow>
              <TableHead className={align}>{T("Run", "الفرز")}</TableHead><TableHead className={align}>{T("Supplier", "المورد")}</TableHead><TableHead className={align}>{T("Reason", "السبب")}</TableHead>
              <TableHead className={align}>{T("Filed by", "كتبه")}</TableHead><TableHead className={align}>{T("Status", "الحالة")}</TableHead><TableHead />
            </TableRow></TableHeader>
            <TableBody>
              {incidents.data.map((i) => (
                <TableRow key={i.id}>
                  <TableCell className="font-mono">{i.run_number}</TableCell><TableCell>{i.supplier_name ?? "—"}</TableCell>
                  <TableCell>{i.reason_code}<div className="text-xs text-muted-foreground">{i.note}</div></TableCell><TableCell>{i.filed_by_name}</TableCell>
                  <TableCell>{i.status === "Open" ? <Badge className="bg-amber-500 hover:bg-amber-500">{T("Open", "مفتوح")}</Badge> : <Badge variant="secondary">{T("Reviewed", "روجع")}{i.claim_supplier ? ` · ${T("claim", "مطالبة")}` : ""}</Badge>}</TableCell>
                  <TableCell>{i.status === "Open" && can("sorting-runs", "approve") && <Button size="sm" variant="outline" data-testid={`review-${i.id}`} onClick={() => setReview({ id: i.id, note: "", claim: false })}>{T("Review", "مراجعة")}</Button>}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      <Dialog open={!!review} onOpenChange={(o) => !o && setReview(null)}>
        <DialogContent>
          <DialogHeader><DialogTitle>{T("Review the incident", "مراجعة الحادثة")}</DialogTitle></DialogHeader>
          <Field id="rv_note" label={T("Review note", "ملاحظة المراجعة")}><Input id="rv_note" value={review?.note ?? ""} onChange={(e) => setReview((s) => s && { ...s, note: e.target.value })} /></Field>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!review?.claim} onChange={(e) => setReview((s) => s && { ...s, claim: e.target.checked })} />{T("Count it as a claim against the supplier", "اعتبرها مطالبة على المورد")}</label>
          <DialogFooter><Button variant="outline" onClick={() => setReview(null)}>{T("Back", "رجوع")}</Button><Button disabled={!review?.note.trim()} data-testid="review-confirm" onClick={doReview}>{T("Save review", "حفظ المراجعة")}</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
