import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Plus, Pencil } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { apiRequest } from "../../services/api";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";

interface Company {
  id: number;
  group_name: string | null;
  name_en: string;
  name_ar: string | null;
  legal_name: string | null;
  cr_number: string | null;
  vat_number: string | null;
  address: string | null;
  currency: string | null;
  is_active: number | boolean;
}

const EMPTY: Record<string, string> = {
  name_en: "", name_ar: "", legal_name: "", group_name: "", cr_number: "", vat_number: "", currency: "SAR", address: "",
};

const FIELDS: [string, string, string][] = [
  ["name_en", "Name (English)", "الاسم (إنجليزي)"],
  ["name_ar", "Name (Arabic)", "الاسم (عربي)"],
  ["legal_name", "Legal name", "الاسم القانوني"],
  ["group_name", "Group", "المجموعة"],
  ["cr_number", "Commercial register no.", "رقم السجل التجاري"],
  ["vat_number", "VAT number", "الرقم الضريبي"],
  ["currency", "Currency", "العملة"],
  ["address", "Address", "العنوان"],
];

export function TenantManagement() {
  const { isRTL } = useI18n();
  const { reload, can } = useAuth();
  const [rows, setRows] = useState<Company[] | null>(null);
  const [error, setError] = useState("");
  const [edit, setEdit] = useState<{ id: number | null; form: Record<string, string> } | null>(null);
  const [saving, setSaving] = useState(false);

  const load = () => apiRequest<Company[]>("/companies").then(setRows).catch((e) => setError(e.message));
  useEffect(() => {
    load();
  }, []);

  const open = (c?: Company) =>
    setEdit({
      id: c?.id ?? null,
      form: c ? Object.fromEntries(Object.keys(EMPTY).map((k) => [k, String((c as any)[k] ?? "")])) : { ...EMPTY },
    });

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!edit) return;
    setSaving(true);
    try {
      const body = JSON.stringify(edit.form);
      if (edit.id) await apiRequest(`/companies/${edit.id}`, { method: "PUT", body });
      else await apiRequest("/companies", { method: "POST", body });
      toast.success(isRTL ? "تم الحفظ" : "Saved");
      setEdit(null);
      await Promise.all([load(), reload()]);
    } catch (err: any) {
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  const setActive = async (c: Company, on: boolean) => {
    try {
      await apiRequest(`/companies/${c.id}`, { method: "PUT", body: JSON.stringify({ is_active: on ? 1 : 0 }) });
      await load();
    } catch (err: any) {
      toast.error(err.message);
    }
  };

  const canManage = can("admin", "manage");
  const align = isRTL ? "text-right" : "text-left";

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "الشركات" : "Companies"}</h1>
          <p className="text-muted-foreground mt-1">
            {isRTL
              ? "شركات المجموعة. لكل شركة بياناتها وإعداداتها ووحداتها المنفصلة."
              : "The companies of the group. Each has its own data, settings and modules."}
          </p>
        </div>
        {canManage && (
          <Button className="gap-2" onClick={() => open()}>
            <Plus className="h-4 w-4" />
            {isRTL ? "إضافة شركة" : "Add company"}
          </Button>
        )}
      </div>

      {error && (
        <div className="p-3 rounded-lg bg-red-50 text-red-700 text-sm" role="alert">
          {error}
        </div>
      )}

      <Card className="p-6">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className={align}>#</TableHead>
              <TableHead className={align}>{isRTL ? "الشركة" : "Company"}</TableHead>
              <TableHead className={align}>{isRTL ? "المجموعة" : "Group"}</TableHead>
              <TableHead className={align}>{isRTL ? "السجل التجاري" : "CR no."}</TableHead>
              <TableHead className={align}>{isRTL ? "الرقم الضريبي" : "VAT no."}</TableHead>
              <TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows?.map((c) => {
              const on = Number(c.is_active) === 1;
              return (
                <TableRow key={c.id}>
                  <TableCell className="font-mono text-sm">{c.id}</TableCell>
                  <TableCell className="font-medium">{isRTL ? c.name_ar || c.name_en : c.name_en}</TableCell>
                  <TableCell>{c.group_name || "—"}</TableCell>
                  <TableCell className="font-mono text-sm">{c.cr_number || "—"}</TableCell>
                  <TableCell className="font-mono text-sm">{c.vat_number || "—"}</TableCell>
                  <TableCell>
                    <Badge variant={on ? "default" : "secondary"}>
                      {on ? (isRTL ? "نشطة" : "Active") : isRTL ? "موقوفة" : "Inactive"}
                    </Badge>
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    {canManage && (
                      <>
                        <Button variant="ghost" size="sm" onClick={() => open(c)} aria-label="edit">
                          <Pencil className="h-4 w-4" />
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => setActive(c, !on)}>
                          {on ? (isRTL ? "إيقاف" : "Disable") : isRTL ? "تفعيل" : "Enable"}
                        </Button>
                      </>
                    )}
                  </TableCell>
                </TableRow>
              );
            })}
            {rows && rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={7} className="text-center text-muted-foreground">
                  {isRTL ? "لا توجد شركات" : "No companies"}
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
        <p className="text-xs text-muted-foreground mt-4">
          {isRTL ? "الشركات لا تُحذف أبداً، يمكن إيقافها فقط." : "Companies are never deleted, only disabled."}
        </p>
      </Card>

      <Dialog open={!!edit} onOpenChange={(o) => !o && setEdit(null)}>
        <DialogContent>
          <form onSubmit={save} className="space-y-4">
            <DialogHeader>
              <DialogTitle>
                {edit?.id ? (isRTL ? "تعديل شركة" : "Edit company") : isRTL ? "شركة جديدة" : "New company"}
              </DialogTitle>
            </DialogHeader>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              {FIELDS.map(([k, en, ar]) => (
                <div key={k} className={`space-y-1 ${k === "address" ? "sm:col-span-2" : ""}`}>
                  <Label htmlFor={`co_${k}`}>{isRTL ? ar : en}</Label>
                  <Input
                    id={`co_${k}`}
                    value={edit?.form[k] ?? ""}
                    required={k === "name_en"}
                    onChange={(e) => setEdit((s) => (s ? { ...s, form: { ...s.form, [k]: e.target.value } } : s))}
                  />
                </div>
              ))}
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setEdit(null)}>
                {isRTL ? "إلغاء" : "Cancel"}
              </Button>
              <Button type="submit" disabled={saving}>
                {saving ? "…" : isRTL ? "حفظ" : "Save"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
