import { useMemo, useState } from "react";
import { Link } from "react-router";
import { toast } from "sonner";
import { Plus, Search, Pencil } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { useApi, post, put } from "../../hooks/useApi";
import { InventoryNav } from "../../components/inventory/InventoryNav";
import { ErrorBox, Field, fmtKg, Item, Balance, nm } from "../../components/inventory/common";

const FIELDS: [string, string, string, "text" | "number"][] = [
  ["code", "Code", "الكود", "text"],
  ["barcode", "Barcode", "الباركود", "text"],
  ["name", "Name (English)", "الاسم (إنجليزي)", "text"],
  ["name_ar", "Name (Arabic)", "الاسم (عربي)", "text"],
  ["item_group", "Group", "المجموعة", "text"],
  ["uom", "Unit", "الوحدة", "text"],
  ["shelf_life_days", "Shelf life (days)", "مدة الصلاحية (أيام)", "number"],
  ["storage_temp_min", "Storage temp min °C", "أقل حرارة تخزين °م", "number"],
  ["storage_temp_max", "Storage temp max °C", "أعلى حرارة تخزين °م", "number"],
  ["compat_group", "Compatibility group", "مجموعة التوافق", "text"],
  ["reorder_level", "Reorder level (kg)", "حد إعادة الطلب (كجم)", "number"],
];

export function ProductList() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const items = useApi<Item[]>("/items");
  const bal = useApi<Balance[]>("/stock/balance");
  const [search, setSearch] = useState("");
  const [edit, setEdit] = useState<{ id: number | null; form: Record<string, string> } | null>(null);
  const [saving, setSaving] = useState(false);

  const totals = useMemo(() => {
    const m = new Map<number, number>();
    bal.data?.forEach((b) => m.set(b.item_id, (m.get(b.item_id) ?? 0) + b.qty));
    return m;
  }, [bal.data]);

  const rows = (items.data ?? []).filter((i) => {
    const s = search.trim().toLowerCase();
    return !s || [i.name, i.name_ar, i.code, i.barcode].some((v) => (v ?? "").toLowerCase().includes(s));
  });

  const open = (i?: Item) =>
    setEdit({
      id: i?.id ?? null,
      form: Object.fromEntries(FIELDS.map(([k]) => [k, String((i as any)?.[k] ?? (k === "uom" ? "kg" : ""))])),
    });

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!edit) return;
    setSaving(true);
    try {
      // empty box -> null (unset), except the columns the database never allows to be null
      const body: Record<string, unknown> = {};
      FIELDS.forEach(([k]) => {
        const v = edit.form[k].trim();
        body[k] = v !== "" ? v : k === "reorder_level" ? 0 : k === "name" || k === "uom" ? v : null;
      });
      if (edit.id) await put(`/items/${edit.id}`, body);
      else await post("/items", body);
      toast.success(isRTL ? "تم الحفظ" : "Saved");
      setEdit(null);
      items.reload();
    } catch (err: any) {
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  const align = isRTL ? "text-right" : "text-left";
  return (
    <div className="space-y-6">
      <InventoryNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "الأصناف" : "Items"}</h1>
          <p className="text-muted-foreground mt-1">
            {isRTL ? "الخضار والفاكهة وقواعد تخزينها: الصلاحية والحرارة ومجموعة التوافق." : "Produce and its storage rules: shelf life, temperature and compatibility group."}
          </p>
        </div>
        {can("items", "create") && (
          <Button className="gap-2" onClick={() => open()}>
            <Plus className="h-4 w-4" />
            {isRTL ? "صنف جديد" : "New item"}
          </Button>
        )}
      </div>

      <ErrorBox message={items.error} />

      <Card className="p-6 space-y-4">
        <div className="relative max-w-sm">
          <Search className="absolute top-2.5 start-3 h-4 w-4 text-muted-foreground" />
          <Input className="ps-9" placeholder={isRTL ? "بحث بالاسم أو الكود أو الباركود" : "Search name, code or barcode"} value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className={align}>{isRTL ? "الكود" : "Code"}</TableHead>
                <TableHead className={align}>{isRTL ? "الصنف" : "Item"}</TableHead>
                <TableHead className={align}>{isRTL ? "المجموعة" : "Group"}</TableHead>
                <TableHead className={align}>{isRTL ? "الصلاحية" : "Shelf life"}</TableHead>
                <TableHead className={align}>{isRTL ? "الحرارة" : "Temp"}</TableHead>
                <TableHead className={align}>{isRTL ? "المخزون (كجم)" : "In stock (kg)"}</TableHead>
                <TableHead />
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((i) => {
                const qty = totals.get(i.id) ?? 0;
                const low = i.reorder_level != null && Number(i.reorder_level) > 0 && qty < Number(i.reorder_level);
                return (
                  <TableRow key={i.id}>
                    <TableCell className="font-mono text-sm">{i.code || "—"}</TableCell>
                    <TableCell className="font-medium">
                      <Link to={`/inventory/products/${i.id}`} className="hover:underline text-primary">
                        {nm(isRTL, i.name, i.name_ar)}
                      </Link>
                    </TableCell>
                    <TableCell>{i.item_group || "—"}</TableCell>
                    <TableCell>{i.shelf_life_days ? (isRTL ? `${i.shelf_life_days} يوم` : `${i.shelf_life_days} d`) : "—"}</TableCell>
                    <TableCell dir="ltr" className="text-sm">
                      {i.storage_temp_min != null && i.storage_temp_max != null ? `${i.storage_temp_min}…${i.storage_temp_max} °C` : "—"}
                    </TableCell>
                    <TableCell className="font-mono">
                      {fmtKg(qty)} {low && <Badge variant="destructive" className="ms-2">{isRTL ? "منخفض" : "Low"}</Badge>}
                    </TableCell>
                    <TableCell>
                      {can("items", "edit") && (
                        <Button variant="ghost" size="sm" onClick={() => open(i)} aria-label="edit">
                          <Pencil className="h-4 w-4" />
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
              {items.data && rows.length === 0 && (
                <TableRow>
                  <TableCell colSpan={7} className="text-center text-muted-foreground">{isRTL ? "لا توجد أصناف" : "No items"}</TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </div>
      </Card>

      <Dialog open={!!edit} onOpenChange={(o) => !o && setEdit(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto">
          <form onSubmit={save} className="space-y-4">
            <DialogHeader>
              <DialogTitle>{edit?.id ? (isRTL ? "تعديل صنف" : "Edit item") : isRTL ? "صنف جديد" : "New item"}</DialogTitle>
            </DialogHeader>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              {FIELDS.map(([k, en, ar, type]) => (
                <Field key={k} id={`it_${k}`} label={isRTL ? ar : en}>
                  <Input
                    id={`it_${k}`}
                    type={type}
                    step={type === "number" ? "any" : undefined}
                    required={k === "name"}
                    dir={type === "number" || k === "code" || k === "barcode" ? "ltr" : undefined}
                    value={edit?.form[k] ?? ""}
                    onChange={(e) => setEdit((s) => (s ? { ...s, form: { ...s.form, [k]: e.target.value } } : s))}
                  />
                </Field>
              ))}
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setEdit(null)}>{isRTL ? "إلغاء" : "Cancel"}</Button>
              <Button type="submit" disabled={saving}>{saving ? "…" : isRTL ? "حفظ" : "Save"}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
