import { useState } from "react";
import { toast } from "sonner";
import { Plus, Trash2 } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { apiRequest } from "../../services/api";
import { useApi, post } from "../../hooks/useApi";
import { ErrorBox, Field, selectClass, Item, nm } from "../../components/inventory/common";
import { ProcurementNav, Supplier } from "../../components/procurement/common";

interface Price { id: number; supplier_id: number; item_id: number; price: number; valid_from: string; supplier_name: string; item_name: string; item_name_ar: string | null }
const today = () => new Date().toISOString().slice(0, 10);

export function SupplierPrices() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const [supplier, setSupplier] = useState("");
  const [item, setItem] = useState("");
  const q = new URLSearchParams();
  if (supplier) q.set("supplier_id", supplier);
  if (item) q.set("item_id", item);
  const prices = useApi<Price[]>(`/supplier-prices${q.toString() ? `?${q}` : ""}`);
  const sup = useApi<Supplier[]>("/suppliers");
  const items = useApi<Item[]>("/items");
  const [dlg, setDlg] = useState<{ supplier_id: string; item_id: string; price: string; valid_from: string } | null>(null);
  const align = isRTL ? "text-right" : "text-left";
  const now = today();

  // for each supplier+item, the row that applies today (latest valid_from not in the future)
  const current = new Set<number>();
  const seen = new Set<string>();
  [...(prices.data ?? [])].sort((a, b) => (a.valid_from < b.valid_from ? 1 : -1)).forEach((p) => {
    const k = `${p.supplier_id}-${p.item_id}`;
    if (p.valid_from <= now && !seen.has(k)) { seen.add(k); current.add(p.id); }
  });

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    try {
      await post("/supplier-prices", { supplier_id: Number(dlg.supplier_id), item_id: Number(dlg.item_id), price: Number(dlg.price), valid_from: dlg.valid_from });
      toast.success(isRTL ? "تم حفظ السعر" : "Price saved");
      setDlg(null);
      prices.reload();
    } catch (err: any) { toast.error(err.message); }
  };
  const del = async (id: number) => {
    try { await apiRequest(`/supplier-prices/${id}`, { method: "DELETE" }); prices.reload(); } catch (err: any) { toast.error(err.message); }
  };
  const set = (k: string, v: string) => setDlg((s) => s && { ...s, [k]: v });

  return (
    <div className="space-y-6">
      <ProcurementNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "أسعار الموردين" : "Supplier Prices"}</h1>
          <p className="text-muted-foreground mt-1">{isRTL ? "سعر الكيلو بتاريخ سريانه. السعر الجديد يسري من تاريخه ويبقى القديم في السجل." : "Price per kg from a date. A new price applies from its date; older ones stay as history."}</p>
        </div>
        {can("supplier-prices", "create") && (
          <Button className="gap-2" onClick={() => setDlg({ supplier_id: supplier, item_id: item, price: "", valid_from: now })}><Plus className="h-4 w-4" />{isRTL ? "سعر جديد" : "New price"}</Button>
        )}
      </div>
      <ErrorBox message={prices.error} />
      <Card className="p-6 space-y-4">
        <div className="flex flex-wrap gap-3">
          <select aria-label="supplier" className={`${selectClass} max-w-xs`} value={supplier} onChange={(e) => setSupplier(e.target.value)}>
            <option value="">{isRTL ? "كل الموردين" : "All suppliers"}</option>
            {sup.data?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
          <select aria-label="item" className={`${selectClass} max-w-xs`} value={item} onChange={(e) => setItem(e.target.value)}>
            <option value="">{isRTL ? "كل الأصناف" : "All items"}</option>
            {items.data?.map((i) => <option key={i.id} value={i.id}>{nm(isRTL, i.name, i.name_ar)}</option>)}
          </select>
        </div>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className={align}>{isRTL ? "المورد" : "Supplier"}</TableHead>
                <TableHead className={align}>{isRTL ? "الصنف" : "Item"}</TableHead>
                <TableHead className={align}>{isRTL ? "السعر/كجم" : "Price/kg"}</TableHead>
                <TableHead className={align}>{isRTL ? "يسري من" : "Valid from"}</TableHead>
                <TableHead className={align}>{isRTL ? "الحالة" : "State"}</TableHead>
                <TableHead />
              </TableRow>
            </TableHeader>
            <TableBody>
              {prices.data?.map((p) => (
                <TableRow key={p.id}>
                  <TableCell>{p.supplier_name}</TableCell>
                  <TableCell className="font-medium">{nm(isRTL, p.item_name, p.item_name_ar)}</TableCell>
                  <TableCell dir="ltr" className="font-mono">{Number(p.price).toFixed(2)}</TableCell>
                  <TableCell dir="ltr" className="text-sm">{p.valid_from}</TableCell>
                  <TableCell className="text-sm">
                    {current.has(p.id) ? <b className="text-emerald-700">{isRTL ? "ساري الآن" : "Current"}</b> : p.valid_from > now ? (isRTL ? "قادم" : "Upcoming") : <span className="text-muted-foreground">{isRTL ? "سجل" : "History"}</span>}
                  </TableCell>
                  <TableCell>
                    {can("supplier-prices", "delete") && <Button variant="ghost" size="sm" onClick={() => del(p.id)} aria-label="delete"><Trash2 className="h-4 w-4" /></Button>}
                  </TableCell>
                </TableRow>
              ))}
              {prices.data && prices.data.length === 0 && (
                <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">{isRTL ? "لا توجد أسعار" : "No prices"}</TableCell></TableRow>
              )}
            </TableBody>
          </Table>
        </div>
      </Card>

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent>
          <form onSubmit={save} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "سعر جديد" : "New price"}</DialogTitle></DialogHeader>
            <Field id="sp_sup" label={isRTL ? "المورد" : "Supplier"}>
              <select id="sp_sup" className={selectClass} required value={dlg?.supplier_id ?? ""} onChange={(e) => set("supplier_id", e.target.value)}>
                <option value="">{isRTL ? "اختر…" : "Choose…"}</option>
                {sup.data?.filter((s) => Number(s.is_active) === 1).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </Field>
            <Field id="sp_item" label={isRTL ? "الصنف" : "Item"}>
              <select id="sp_item" className={selectClass} required value={dlg?.item_id ?? ""} onChange={(e) => set("item_id", e.target.value)}>
                <option value="">{isRTL ? "اختر…" : "Choose…"}</option>
                {items.data?.map((i) => <option key={i.id} value={i.id}>{nm(isRTL, i.name, i.name_ar)}</option>)}
              </select>
            </Field>
            <Field id="sp_price" label={isRTL ? "السعر للكيلو" : "Price per kg"}><Input id="sp_price" type="number" step="any" min="0" dir="ltr" required value={dlg?.price ?? ""} onChange={(e) => set("price", e.target.value)} /></Field>
            <Field id="sp_from" label={isRTL ? "يسري من" : "Valid from"}><Input id="sp_from" type="date" required value={dlg?.valid_from ?? ""} onChange={(e) => set("valid_from", e.target.value)} /></Field>
            <DialogFooter><Button type="submit">{isRTL ? "حفظ" : "Save"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
