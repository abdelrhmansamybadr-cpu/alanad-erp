import { useState } from "react";
import { toast } from "sonner";
import { Plus, Pencil } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";
import { useApi, post, put } from "../../hooks/useApi";
import { HrNav } from "../../components/hr/HrNav";
import { ErrorBox, Field, selectClass } from "../../components/inventory/common";

interface Emp {
  id: number; employee_id: string; first_name: string; last_name: string; email: string | null; mobile: string | null; department: string | null; designation: string | null; status: "Active" | "Inactive" | "Left";
  date_of_joining: string | null; national_id: string | null; nationality: string | null; gender: "M" | "F" | null; iban: string | null; iqama_expiry: string | null; passport_expiry: string | null;
  health_card_expiry: string | null; contract_end: string | null; branch_id: number | null;
}
const FIELDS: [keyof Emp, string, string, "text" | "date"][] = [
  ["employee_id", "Employee no.", "الرقم الوظيفي", "text"], ["first_name", "First name", "الاسم الأول", "text"], ["last_name", "Last name", "اسم العائلة", "text"], ["email", "E-mail (same as his login e-mail, so My leave and My profile work for him)", "البريد (نفس بريد دخوله ليعمل له «إجازاتي» و«بياناتي»)", "text"], ["mobile", "Mobile", "الجوال", "text"],
  ["department", "Department", "القسم", "text"], ["designation", "Job title", "المسمى الوظيفي", "text"], ["date_of_joining", "Joined on", "تاريخ التعيين", "date"], ["national_id", "ID / iqama no. (10 digits)", "رقم الهوية / الإقامة", "text"],
  ["nationality", "Nationality", "الجنسية", "text"], ["iban", "IBAN (SA…)", "الآيبان", "text"], ["iqama_expiry", "Iqama expires", "انتهاء الإقامة", "date"], ["passport_expiry", "Passport expires", "انتهاء الجواز", "date"],
  ["health_card_expiry", "Health card expires", "انتهاء الشهادة الصحية", "date"], ["contract_end", "Contract ends", "انتهاء العقد", "date"],
];

export function Employees() {
  const { isRTL } = useI18n();
  const { can, me } = useAuth();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("Active");
  const list = useApi<Emp[]>(`/employees?status=${status}${search ? `&search=${encodeURIComponent(search)}` : ""}`);
  const [dlg, setDlg] = useState<{ id: number | null; form: Record<string, string> } | null>(null);
  const [saving, setSaving] = useState(false);
  const align = isRTL ? "text-right" : "text-left";
  const open = (e?: Emp) => setDlg({ id: e?.id ?? null, form: Object.fromEntries([...FIELDS.map(([k]) => [k as string, String((e as any)?.[k] ?? "")]), ["gender", e?.gender ?? ""], ["status", e?.status ?? "Active"], ["branch_id", String(e?.branch_id ?? "")]]) });
  const save = async (ev: React.FormEvent) => {
    ev.preventDefault();
    if (!dlg) return;
    setSaving(true);
    try {
      const body: Record<string, unknown> = {};
      for (const [k, v] of Object.entries(dlg.form)) body[k] = v === "" ? null : k === "branch_id" ? Number(v) : v;
      if (dlg.id) await put(`/employees/${dlg.id}`, body); else await post("/employees", body);
      toast.success(T("Saved", "تم الحفظ")); setDlg(null); await list.reload();
    } catch (err: any) { toast.error(err.message); } finally { setSaving(false); }
  };
  const dayClass = (d: string | null) => { if (!d) return ""; const left = Math.round((new Date(d).getTime() - Date.now()) / 86400000); return left < 0 ? "text-red-700 font-semibold" : left <= 30 ? "text-orange-600 font-semibold" : ""; };

  return (
    <div className="space-y-6">
      <HrNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <h1 className="text-3xl font-bold">{T("Employees", "الموظفون")}</h1>
        {can("employees", "create") && <Button className="gap-2" data-testid="new-employee" onClick={() => open()}><Plus className="h-4 w-4" />{T("Add employee", "إضافة موظف")}</Button>}
      </div>
      {list.error && <ErrorBox message={list.error} />}
      <div className="flex flex-wrap gap-2 items-center">
        <Input className="max-w-xs" placeholder={T("Search name, number or ID…", "بحث بالاسم أو الرقم أو الهوية…")} value={search} onChange={(e) => setSearch(e.target.value)} aria-label="search" />
        {["Active", "Inactive", "Left"].map((s) => <Button key={s} size="sm" variant={status === s ? "default" : "outline"} onClick={() => setStatus(s)}>{s === "Active" ? T("Active", "على رأس العمل") : s === "Inactive" ? T("Inactive", "موقوف") : T("Left", "غادر")}</Button>)}
      </div>
      <Card className="p-6" data-testid="employee-list">
        <Table>
          <TableHeader><TableRow>
            <TableHead className={align}>{T("No.", "الرقم")}</TableHead><TableHead className={align}>{T("Name", "الاسم")}</TableHead><TableHead className={align}>{T("Department", "القسم")}</TableHead>
            <TableHead className={align}>{T("Iqama", "الإقامة")}</TableHead><TableHead className={align}>{T("Health card", "الشهادة الصحية")}</TableHead><TableHead className={align}>{T("Status", "الحالة")}</TableHead><TableHead />
          </TableRow></TableHeader>
          <TableBody>
            {list.data?.map((e) => (
              <TableRow key={e.id}>
                <TableCell className="font-mono">{e.employee_id}</TableCell><TableCell className="font-medium">{e.first_name} {e.last_name}<div className="text-xs text-muted-foreground">{e.designation}</div></TableCell><TableCell>{e.department ?? "—"}</TableCell>
                <TableCell dir="ltr" className={dayClass(e.iqama_expiry)}>{e.iqama_expiry ?? "—"}</TableCell><TableCell dir="ltr" className={dayClass(e.health_card_expiry)}>{e.health_card_expiry ?? "—"}</TableCell>
                <TableCell><Badge variant={e.status === "Active" ? "default" : "secondary"}>{e.status}</Badge></TableCell>
                <TableCell>{can("employees", "edit") && <Button variant="ghost" size="sm" aria-label="edit" onClick={() => open(e)}><Pencil className="h-4 w-4" /></Button>}</TableCell>
              </TableRow>
            ))}
            {list.data && list.data.length === 0 && <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground">{T("No employees", "لا يوجد موظفون")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
          <form onSubmit={save} className="space-y-3">
            <DialogHeader><DialogTitle>{dlg?.id ? T("Edit employee", "تعديل موظف") : T("New employee", "موظف جديد")}</DialogTitle></DialogHeader>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              {FIELDS.map(([k, en, ar, type]) => (
                <Field key={k} id={`em_${k}`} label={T(en, ar)}><Input id={`em_${k}`} type={type} dir={type === "date" || k === "iban" || k === "national_id" ? "ltr" : undefined} required={k === "employee_id" || k === "first_name" || k === "last_name"} value={dlg?.form[k] ?? ""} onChange={(e) => setDlg((s) => s && { ...s, form: { ...s.form, [k]: e.target.value } })} /></Field>
              ))}
              <Field id="em_gender" label={T("Gender", "الجنس")}><select id="em_gender" className={selectClass} value={dlg?.form.gender} onChange={(e) => setDlg((s) => s && { ...s, form: { ...s.form, gender: e.target.value } })}><option value="">—</option><option value="M">{T("Male", "ذكر")}</option><option value="F">{T("Female", "أنثى")}</option></select></Field>
              <Field id="em_branch" label={T("Branch", "الفرع")}><select id="em_branch" className={selectClass} value={dlg?.form.branch_id} onChange={(e) => setDlg((s) => s && { ...s, form: { ...s.form, branch_id: e.target.value } })}><option value="">—</option>{me?.branches?.map((b) => <option key={b.id} value={b.id}>{isRTL && b.name_ar ? b.name_ar : b.name_en}</option>)}</select></Field>
              <Field id="em_status" label={T("Status", "الحالة")}><select id="em_status" className={selectClass} value={dlg?.form.status} onChange={(e) => setDlg((s) => s && { ...s, form: { ...s.form, status: e.target.value } })}><option value="Active">{T("Active", "على رأس العمل")}</option><option value="Inactive">{T("Inactive", "موقوف")}</option><option value="Left">{T("Left", "غادر")}</option></select></Field>
            </div>
            <DialogFooter><Button type="button" variant="outline" onClick={() => setDlg(null)}>{T("Cancel", "إلغاء")}</Button><Button type="submit" disabled={saving} data-testid="save-employee">{T("Save", "حفظ")}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
