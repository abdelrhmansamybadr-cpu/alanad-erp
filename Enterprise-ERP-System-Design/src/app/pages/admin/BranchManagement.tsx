import { useState } from "react";
import { toast } from "sonner";
import { Plus, Pencil } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";
import { useApi, post, put } from "../../hooks/useApi";

interface Branch {
  id: number;
  code: string;
  name_en: string;
  name_ar: string | null;
  city: string | null;
  address: string | null;
  latitude: string | null;
  longitude: string | null;
  phone: string | null;
  is_main: number | boolean;
  is_active: number | boolean;
  warehouses: number;
  cold_rooms: number;
  restricted_users: number;
}
interface Access { id: number; name: string; email: string; role: string; branch_ids: number[] }

const EMPTY: Record<string, string> = { code: "", name_en: "", name_ar: "", city: "", address: "", latitude: "", longitude: "", phone: "" };
const FIELDS: [string, string, string][] = [
  ["code", "Code (e.g. JED)", "الرمز (مثل JED)"],
  ["name_en", "Name (English)", "الاسم (إنجليزي)"],
  ["name_ar", "Name (Arabic)", "الاسم (عربي)"],
  ["city", "City", "المدينة"],
  ["phone", "Phone", "الهاتف"],
  ["address", "Address", "العنوان"],
  ["latitude", "Latitude", "خط العرض"],
  ["longitude", "Longitude", "خط الطول"],
];

/** Branches (فروع) of the selected company: locations, and which users may work in which branch. */
export function BranchManagement() {
  const { isRTL } = useI18n();
  const { can, reload } = useAuth();
  const branches = useApi<Branch[]>("/company-branches");
  const access = useApi<Access[]>("/branch-access");
  const [tab, setTab] = useState<"branches" | "access">("branches");
  const [edit, setEdit] = useState<{ id: number | null; form: Record<string, string> } | null>(null);
  const [acc, setAcc] = useState<{ user: Access; ids: number[] } | null>(null);
  const [saving, setSaving] = useState(false);
  const canManage = can("admin", "manage");
  const align = isRTL ? "text-right" : "text-left";
  const name = (b: { name_en: string; name_ar: string | null }) => (isRTL && b.name_ar ? b.name_ar : b.name_en);

  const open = (b?: Branch) =>
    setEdit({ id: b?.id ?? null, form: b ? Object.fromEntries(Object.keys(EMPTY).map((k) => [k, String((b as any)[k] ?? "")])) : { ...EMPTY } });

  const refresh = async () => { await Promise.all([branches.reload(), access.reload(), reload()]); };

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!edit) return;
    setSaving(true);
    try {
      const form: Record<string, unknown> = { ...edit.form };
      for (const k of ["latitude", "longitude"]) form[k] = edit.form[k] === "" ? null : Number(edit.form[k]);
      if (edit.id) await put(`/company-branches/${edit.id}`, form);
      else await post("/company-branches", form);
      toast.success(isRTL ? "تم الحفظ" : "Saved");
      setEdit(null);
      await refresh();
    } catch (err: any) {
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  const setActive = async (b: Branch, on: boolean) => {
    try { await put(`/company-branches/${b.id}`, { is_active: on }); await refresh(); } catch (err: any) { toast.error(err.message); }
  };
  const makeMain = async (b: Branch) => {
    try { await put(`/company-branches/${b.id}`, { is_main: true }); await refresh(); } catch (err: any) { toast.error(err.message); }
  };
  const saveAccess = async () => {
    if (!acc) return;
    setSaving(true);
    try {
      await put(`/branch-access/${acc.user.id}`, { branch_ids: acc.ids });
      toast.success(isRTL ? "تم الحفظ" : "Saved");
      setAcc(null);
      await refresh();
    } catch (err: any) {
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  const active = (branches.data ?? []).filter((b) => Number(b.is_active) === 1);
  const error = branches.error || access.error;

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "الفروع" : "Branches"}</h1>
          <p className="text-muted-foreground mt-1">
            {isRTL
              ? "لكل شركة فرع رئيسي وفروع أخرى في مواقع مختلفة. المخازن وغرف التبريد والطلبات والإنتاج تتبع فرعاً."
              : "Each company has a main branch and others in different places. Warehouses, cold rooms, orders and production belong to a branch."}
          </p>
        </div>
        {canManage && tab === "branches" && (
          <Button className="gap-2" onClick={() => open()} data-testid="add-branch">
            <Plus className="h-4 w-4" />
            {isRTL ? "إضافة فرع" : "Add branch"}
          </Button>
        )}
      </div>

      {error && <div className="p-3 rounded-lg bg-red-50 text-red-700 text-sm" role="alert">{error}</div>}

      <div className="flex gap-2" role="tablist">
        {(["branches", "access"] as const).map((t) => (
          <Button key={t} role="tab" aria-selected={tab === t} variant={tab === t ? "default" : "outline"} size="sm" onClick={() => setTab(t)}>
            {t === "branches" ? (isRTL ? "الفروع" : "Branches") : isRTL ? "وصول المستخدمين" : "User access"}
          </Button>
        ))}
      </div>

      {tab === "branches" && (
        <Card className="p-6">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className={align}>{isRTL ? "الرمز" : "Code"}</TableHead>
                <TableHead className={align}>{isRTL ? "الفرع" : "Branch"}</TableHead>
                <TableHead className={align}>{isRTL ? "المدينة" : "City"}</TableHead>
                <TableHead className={align}>{isRTL ? "المخازن" : "Warehouses"}</TableHead>
                <TableHead className={align}>{isRTL ? "غرف التبريد" : "Cold rooms"}</TableHead>
                <TableHead className={align}>{isRTL ? "مستخدمون مقيّدون" : "Restricted users"}</TableHead>
                <TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead>
                <TableHead />
              </TableRow>
            </TableHeader>
            <TableBody>
              {branches.data?.map((b) => {
                const on = Number(b.is_active) === 1;
                const main = Number(b.is_main) === 1;
                return (
                  <TableRow key={b.id}>
                    <TableCell className="font-mono text-sm">{b.code}</TableCell>
                    <TableCell className="font-medium">
                      {name(b)} {main && <Badge className="ms-2">{isRTL ? "رئيسي" : "Main"}</Badge>}
                    </TableCell>
                    <TableCell>{b.city || "—"}</TableCell>
                    <TableCell>{b.warehouses}</TableCell>
                    <TableCell>{b.cold_rooms}</TableCell>
                    <TableCell>{b.restricted_users}</TableCell>
                    <TableCell>
                      <Badge variant={on ? "default" : "secondary"}>{on ? (isRTL ? "نشط" : "Active") : isRTL ? "موقوف" : "Inactive"}</Badge>
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      {canManage && (
                        <>
                          <Button variant="ghost" size="sm" onClick={() => open(b)} aria-label="edit"><Pencil className="h-4 w-4" /></Button>
                          {!main && on && <Button variant="ghost" size="sm" onClick={() => makeMain(b)}>{isRTL ? "اجعله رئيسياً" : "Make main"}</Button>}
                          {!main && <Button variant="ghost" size="sm" onClick={() => setActive(b, !on)}>{on ? (isRTL ? "إيقاف" : "Disable") : isRTL ? "تفعيل" : "Enable"}</Button>}
                        </>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
          <p className="text-xs text-muted-foreground mt-4">
            {isRTL ? "لا يُحذف الفرع. لا يمكن إيقاف الفرع الرئيسي أو فرع فيه مخازن نشطة." : "Branches are never deleted. The main branch, or a branch with active warehouses, cannot be disabled."}
          </p>
        </Card>
      )}

      {tab === "access" && (
        <Card className="p-6">
          <p className="text-sm text-muted-foreground mb-4">
            {isRTL ? "بدون تحديد فرع = يعمل المستخدم في كل الفروع. المدير يرى كل الفروع دائماً." : "No branch selected = the user works in every branch. The administrator always sees all."}
          </p>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className={align}>{isRTL ? "المستخدم" : "User"}</TableHead>
                <TableHead className={align}>{isRTL ? "الدور" : "Role"}</TableHead>
                <TableHead className={align}>{isRTL ? "الفروع المسموحة" : "Allowed branches"}</TableHead>
                <TableHead />
              </TableRow>
            </TableHeader>
            <TableBody>
              {access.data?.map((u) => (
                <TableRow key={u.id}>
                  <TableCell className="font-medium">{u.name}<div className="text-xs text-muted-foreground" dir="ltr">{u.email}</div></TableCell>
                  <TableCell>{u.role}</TableCell>
                  <TableCell>
                    {u.branch_ids.length === 0
                      ? isRTL ? "كل الفروع" : "All branches"
                      : u.branch_ids.map((id) => { const b = branches.data?.find((x) => x.id === id); return b ? name(b) : id; }).join("، ")}
                  </TableCell>
                  <TableCell>
                    {canManage && (
                      <Button variant="ghost" size="sm" onClick={() => setAcc({ user: u, ids: [...u.branch_ids] })}>
                        {isRTL ? "تعديل" : "Edit"}
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      <Dialog open={!!edit} onOpenChange={(o) => !o && setEdit(null)}>
        <DialogContent>
          <form onSubmit={save} className="space-y-4">
            <DialogHeader>
              <DialogTitle>{edit?.id ? (isRTL ? "تعديل فرع" : "Edit branch") : isRTL ? "فرع جديد" : "New branch"}</DialogTitle>
            </DialogHeader>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              {FIELDS.map(([k, en, ar]) => (
                <div key={k} className={`space-y-1 ${k === "address" ? "sm:col-span-2" : ""}`}>
                  <Label htmlFor={`br_${k}`}>{isRTL ? ar : en}</Label>
                  <Input
                    id={`br_${k}`}
                    value={edit?.form[k] ?? ""}
                    required={k === "code" || k === "name_en"}
                    dir={["code", "latitude", "longitude", "phone"].includes(k) ? "ltr" : undefined}
                    onChange={(e) => setEdit((s) => (s ? { ...s, form: { ...s.form, [k]: e.target.value } } : s))}
                  />
                </div>
              ))}
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setEdit(null)}>{isRTL ? "إلغاء" : "Cancel"}</Button>
              <Button type="submit" disabled={saving}>{saving ? "…" : isRTL ? "حفظ" : "Save"}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!acc} onOpenChange={(o) => !o && setAcc(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{acc?.user.name}</DialogTitle>
          </DialogHeader>
          <div className="space-y-2">
            {active.map((b) => (
              <label key={b.id} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={!!acc?.ids.includes(b.id)}
                  onChange={(e) => setAcc((s) => (s ? { ...s, ids: e.target.checked ? [...s.ids, b.id] : s.ids.filter((x) => x !== b.id) } : s))}
                />
                {name(b)} <span className="text-muted-foreground">({b.code})</span>
              </label>
            ))}
            <p className="text-xs text-muted-foreground">{isRTL ? "اتركها فارغة ليعمل في كل الفروع." : "Leave empty for all branches."}</p>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setAcc(null)}>{isRTL ? "إلغاء" : "Cancel"}</Button>
            <Button onClick={saveAccess} disabled={saving}>{saving ? "…" : isRTL ? "حفظ" : "Save"}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
