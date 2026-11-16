import { useState } from "react";
import { Link } from "react-router";
import { toast } from "sonner";
import { Plus } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { useApi, post } from "../../hooks/useApi";
import { ErrorBox, Field, selectClass } from "../../components/inventory/common";
import { ProductionNav } from "../../components/production/common";

interface Log { id: number; kind: string; prep_order_id: number | null; order_number: string | null; value: number | null; person: string | null; ok: boolean; note: string | null; corrective_action: string | null; by_name: string | null; created_at: string }
const KIND_EN: Record<string, string> = { wash_water: "Wash water (°C)", sanitiser: "Sanitiser (ppm)", hygiene: "Hygiene check" };
const KIND_AR: Record<string, string> = { wash_water: "مياه الغسيل (°م)", sanitiser: "المعقّم (ppm)", hygiene: "فحص النظافة" };

export function FoodSafety() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const [kind, setKind] = useState("");
  const [ok, setOk] = useState("");
  const q = new URLSearchParams();
  if (kind) q.set("kind", kind);
  if (ok) q.set("ok", ok);
  const logs = useApi<Log[]>(`/haccp-logs${q.toString() ? `?${q}` : ""}`);
  const [dlg, setDlg] = useState<{ kind: string; value: string; person: string; passed: string; note: string; action: string } | null>(null);
  const [error, setError] = useState("");
  const align = isRTL ? "text-right" : "text-left";

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    setError("");
    const body: Record<string, unknown> = { kind: dlg.kind, note: dlg.note || undefined, corrective_action: dlg.action || undefined };
    if (dlg.kind === "hygiene") { body.person = dlg.person; body.passed = dlg.passed === "1"; } else body.value = Number(dlg.value);
    try { await post("/haccp-logs", body); toast.success(isRTL ? "تم التسجيل" : "Recorded"); setDlg(null); logs.reload(); } catch (err: any) { setError(err.message); }
  };

  return (
    <div className="space-y-6">
      <ProductionNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "سلامة الغذاء" : "Food Safety Log"}</h1>
          <p className="text-muted-foreground mt-1">{isRTL ? "حرارة مياه الغسيل ومستوى المعقّم وفحص نظافة العاملين. القراءة خارج النطاق تحتاج إجراءً تصحيحياً." : "Wash-water temperature, sanitiser level and staff hygiene checks. An out-of-range reading needs a corrective action."}</p>
        </div>
        {can("haccp-logs", "create") && <Button className="gap-2" onClick={() => setDlg({ kind: "wash_water", value: "", person: "", passed: "1", note: "", action: "" })}><Plus className="h-4 w-4" />{isRTL ? "تسجيل قراءة" : "Record a reading"}</Button>}
      </div>
      <ErrorBox message={logs.error} />
      <Card className="p-6 space-y-4">
        <div className="flex flex-wrap gap-3">
          <select aria-label="kind" className={`${selectClass} max-w-[220px]`} value={kind} onChange={(e) => setKind(e.target.value)}>
            <option value="">{isRTL ? "كل الأنواع" : "All kinds"}</option>
            {Object.keys(KIND_EN).map((k) => <option key={k} value={k}>{isRTL ? KIND_AR[k] : KIND_EN[k]}</option>)}
          </select>
          <select aria-label="ok" className={`${selectClass} max-w-[220px]`} value={ok} onChange={(e) => setOk(e.target.value)}>
            <option value="">{isRTL ? "الكل" : "All"}</option>
            <option value="0">{isRTL ? "خارج النطاق فقط" : "Out of range only"}</option>
            <option value="1">{isRTL ? "سليمة فقط" : "OK only"}</option>
          </select>
        </div>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className={align}>{isRTL ? "الوقت" : "Time"}</TableHead>
                <TableHead className={align}>{isRTL ? "النوع" : "Kind"}</TableHead>
                <TableHead className={align}>{isRTL ? "القراءة / الشخص" : "Reading / person"}</TableHead>
                <TableHead className={align}>{isRTL ? "النتيجة" : "Result"}</TableHead>
                <TableHead className={align}>{isRTL ? "الأمر" : "Order"}</TableHead>
                <TableHead className={align}>{isRTL ? "الإجراء التصحيحي" : "Corrective action"}</TableHead>
                <TableHead className={align}>{isRTL ? "سجّلها" : "By"}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {logs.data?.map((l) => (
                <TableRow key={l.id} className={l.ok ? "" : "bg-red-50/60"}>
                  <TableCell dir="ltr" className="text-xs font-mono whitespace-nowrap">{l.created_at}</TableCell>
                  <TableCell>{(isRTL ? KIND_AR : KIND_EN)[l.kind]}</TableCell>
                  <TableCell dir="ltr" className="font-mono text-sm">{l.value ?? l.person}</TableCell>
                  <TableCell>{l.ok ? <Badge className="bg-emerald-600 hover:bg-emerald-600">OK</Badge> : <Badge variant="destructive">{isRTL ? "خارج النطاق" : "Out of range"}</Badge>}</TableCell>
                  <TableCell className="font-mono text-sm">{l.prep_order_id ? <Link className="text-primary hover:underline" to={`/production/orders/${l.prep_order_id}`}>{l.order_number}</Link> : "—"}</TableCell>
                  <TableCell className="text-sm">{l.corrective_action ?? ""}</TableCell>
                  <TableCell className="text-sm">{l.by_name}</TableCell>
                </TableRow>
              ))}
              {logs.data && logs.data.length === 0 && <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground">{isRTL ? "لا توجد سجلات" : "No logs"}</TableCell></TableRow>}
            </TableBody>
          </Table>
        </div>
      </Card>

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent>
          <form onSubmit={save} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "تسجيل قراءة" : "Record a reading"}</DialogTitle></DialogHeader>
            <ErrorBox message={error} />
            <Field id="fs_kind" label={isRTL ? "النوع" : "Kind"}>
              <select id="fs_kind" className={selectClass} value={dlg?.kind ?? ""} onChange={(e) => setDlg((d) => d && { ...d, kind: e.target.value })}>
                {Object.keys(KIND_EN).map((k) => <option key={k} value={k}>{isRTL ? KIND_AR[k] : KIND_EN[k]}</option>)}
              </select>
            </Field>
            {dlg?.kind === "hygiene" ? (
              <div className="grid grid-cols-2 gap-3">
                <Field id="fs_person" label={isRTL ? "اسم الموظف" : "Staff member"}><Input id="fs_person" required value={dlg.person} onChange={(e) => setDlg((d) => d && { ...d, person: e.target.value })} /></Field>
                <Field id="fs_passed" label={isRTL ? "النتيجة" : "Result"}><select id="fs_passed" className={selectClass} value={dlg.passed} onChange={(e) => setDlg((d) => d && { ...d, passed: e.target.value })}><option value="1">{isRTL ? "سليم" : "Pass"}</option><option value="0">{isRTL ? "غير سليم" : "Fail"}</option></select></Field>
              </div>
            ) : (
              <Field id="fs_value" label={dlg?.kind === "wash_water" ? (isRTL ? "الحرارة °م" : "Temperature °C") : isRTL ? "التركيز ppm" : "Level ppm"}><Input id="fs_value" type="number" step="any" dir="ltr" required value={dlg?.value ?? ""} onChange={(e) => setDlg((d) => d && { ...d, value: e.target.value })} /></Field>
            )}
            <Field id="fs_action" label={isRTL ? "إجراء تصحيحي (إلزامي إن كانت القراءة خارج النطاق)" : "Corrective action (required if out of range)"}><Input id="fs_action" value={dlg?.action ?? ""} onChange={(e) => setDlg((d) => d && { ...d, action: e.target.value })} /></Field>
            <Field id="fs_note" label={isRTL ? "ملاحظة" : "Note"}><Input id="fs_note" value={dlg?.note ?? ""} onChange={(e) => setDlg((d) => d && { ...d, note: e.target.value })} /></Field>
            <DialogFooter><Button type="submit">{isRTL ? "حفظ" : "Save"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
