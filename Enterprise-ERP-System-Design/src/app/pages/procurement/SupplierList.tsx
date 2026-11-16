import { useState } from "react";
import { toast } from "sonner";
import { Plus, Pencil, Search } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { useApi, post, put } from "../../hooks/useApi";
import { ErrorBox, Field } from "../../components/inventory/common";
import { ProcurementNav, Supplier } from "../../components/procurement/common";

const FIELDS: [string, string, string][] = [
  ["code", "Code", "الكود"],
  ["name", "Name", "الاسم"],
  ["phone", "Phone", "الهاتف"],
  ["mobile", "Mobile", "الجوال"],
  ["email", "Email", "البريد"],
  ["tax_id", "VAT number", "الرقم الضريبي"],
  ["supplier_group", "Group", "المجموعة"],
  ["currency", "Currency", "العملة"],
  ["payment_terms", "Payment terms", "شروط الدفع"],
  ["city", "City", "المدينة"],
  ["address", "Address", "العنوان"],
];

export function SupplierList() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const sup = useApi<Supplier[]>("/suppliers");
  const [search, setSearch] = useState("");
  const [edit, setEdit] = useState<{ id: number | null; form: Record<string, string> } | null>(null);
  const align = isRTL ? "text-right" : "text-left";

  const rows = (sup.data ?? []).filter((s) => {
    const q = search.trim().toLowerCase();
    return !q || [s.name, s.code, s.phone, s.mobile, s.city].some((v) => (v ?? "").toLowerCase().includes(q));
  });

  const open = (s?: Supplier) =>
    setEdit({ id: s?.id ?? null, form: Object.fromEntries(FIELDS.map(([k]) => [k, String((s as any)?.[k] ?? (k === "currency" ? "SAR" : ""))])) });

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!edit) return;
    try {
      const body: Record<string, unknown> = {};
      FIELDS.forEach(([k]) => { const v = edit.form[k].trim(); body[k] = v !== "" ? v : k === "name" || k === "currency" ? v : null; });
      if (edit.id) await put(`/suppliers/${edit.id}`, body);
      else await post("/suppliers", body);
      toast.success(isRTL ? "تم الحفظ" : "Saved");
      setEdit(null);
      sup.reload();
    } catch (err: any) {
      toast.error(err.message);
    }
  };

  const toggle = async (s: Supplier) => {
    try {
      await put(`/suppliers/${s.id}`, { is_active: Number(s.is_active) === 1 ? 0 : 1 });
      sup.reload();
    } catch (err: any) {
      toast.error(err.message);
    }
  };

  return (
    <div className="space-y-6">
      <ProcurementNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "الموردون" : "Suppliers"}</h1>
          <p className="text-muted-foreground mt-1">{isRTL ? "المزارع والموردون الذين تشتري منهم." : "The farms and vendors you buy from."}</p>
        </div>
        {can("suppliers", "create") && (
          <Button className="gap-2" onClick={() => open()}><Plus className="h-4 w-4" />{isRTL ? "مورد جديد" : "New supplier"}</Button>
        )}
      </div>
      <ErrorBox message={sup.error} />
      <Card className="p-6 space-y-4">
        <div className="relative max-w-sm">
          <Search className="absolute top-2.5 start-3 h-4 w-4 text-muted-foreground" />
          <Input className="ps-9" placeholder={isRTL ? "بحث" : "Search"} value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className={align}>{isRTL ? "الكود" : "Code"}</TableHead>
                <TableHead className={align}>{isRTL ? "المورد" : "Supplier"}</TableHead>
                <TableHead className={align}>{isRTL ? "الاتصال" : "Contact"}</TableHead>
                <TableHead className={align}>{isRTL ? "المدينة" : "City"}</TableHead>
                <TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead>
                <TableHead />
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((s) => {
                const on = Number(s.is_active) === 1;
                return (
                  <TableRow key={s.id}>
                    <TableCell className="font-mono text-sm">{s.code || "—"}</TableCell>
                    <TableCell className="font-medium">{s.name}</TableCell>
                    <TableCell dir="ltr" className="text-sm">{s.mobile || s.phone || s.email || "—"}</TableCell>
                    <TableCell>{s.city || "—"}</TableCell>
                    <TableCell><Badge variant={on ? "default" : "secondary"}>{on ? (isRTL ? "نشط" : "Active") : isRTL ? "موقوف" : "Inactive"}</Badge></TableCell>
                    <TableCell className="whitespace-nowrap">
                      {can("suppliers", "edit") && (
                        <>
                          <Button variant="ghost" size="sm" onClick={() => open(s)} aria-label="edit"><Pencil className="h-4 w-4" /></Button>
                          <Button variant="ghost" size="sm" onClick={() => toggle(s)}>{on ? (isRTL ? "إيقاف" : "Disable") : isRTL ? "تفعيل" : "Enable"}</Button>
                        </>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
              {sup.data && rows.length === 0 && (
                <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">{isRTL ? "لا يوجد موردون" : "No suppliers"}</TableCell></TableRow>
              )}
            </TableBody>
          </Table>
        </div>
      </Card>

      <Dialog open={!!edit} onOpenChange={(o) => !o && setEdit(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto">
          <form onSubmit={save} className="space-y-4">
            <DialogHeader><DialogTitle>{edit?.id ? (isRTL ? "تعديل مورد" : "Edit supplier") : isRTL ? "مورد جديد" : "New supplier"}</DialogTitle></DialogHeader>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              {FIELDS.map(([k, en, ar]) => (
                <Field key={k} id={`su_${k}`} label={isRTL ? ar : en} className={k === "address" ? "sm:col-span-2" : ""}>
                  <Input id={`su_${k}`} required={k === "name"} dir={["code", "phone", "mobile", "email", "tax_id"].includes(k) ? "ltr" : undefined} value={edit?.form[k] ?? ""} onChange={(e) => setEdit((s) => s && { ...s, form: { ...s.form, [k]: e.target.value } })} />
                </Field>
              ))}
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setEdit(null)}>{isRTL ? "إلغاء" : "Cancel"}</Button>
              <Button type="submit">{isRTL ? "حفظ" : "Save"}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
