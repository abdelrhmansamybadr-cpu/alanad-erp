import { useState } from "react";
import { Link, useNavigate } from "react-router";
import { toast } from "sonner";
import { Plus } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";
import { useApi, post } from "../../hooks/useApi";
import { ProductionNav } from "../../components/production/common";
import { ErrorBox, Field, selectClass, fmtKg, Item, nm } from "../../components/inventory/common";

interface Row { id: number; number: string; status: string; customer_name: string | null; item_name: string; grade: string; required_kg: number; box_kg: number | null; due_date: string | null; boxes_packed: number | null; cartons: number | null; pallets: number | null }
interface ClientOrder { id: number; number: string; customer_name: string; status: string }
interface Spec { id: number; item_id: number; customer_id: number | null; box_kg_options: string; boxes_per_carton: number; cartons_per_pallet: number | null; label_note: string | null; is_active: number | boolean }
interface Customer { id: number; name: string }

const PSTATUS: Record<string, [string, string, string]> = {
  Draft: ["Waiting for the box size", "بانتظار حجم الصندوق", "bg-slate-500 hover:bg-slate-500"],
  Ready: ["Ready to pack", "جاهز للتعبئة", "bg-emerald-600 hover:bg-emerald-600"],
  In_Progress: ["Packing", "قيد التعبئة", "bg-blue-600 hover:bg-blue-600"],
  Packed: ["Packed", "تمت التعبئة", "bg-teal-700 hover:bg-teal-700"],
  Cancelled: ["Cancelled", "ملغى", "bg-slate-400 hover:bg-slate-400"],
};
export const PackStatus = ({ s, isRTL }: { s: string; isRTL: boolean }) => { const [en, ar, c] = PSTATUS[s] ?? [s, s, ""]; return <Badge className={c}>{isRTL ? ar : en}</Badge>; };

/** Packing: the orders (box 1 or 2 kg → carton of 40 → pallet) and the pack specifications per client. */
export function PackOrders() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const nav = useNavigate();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const rows = useApi<Row[]>("/pack-orders");
  const specs = useApi<Spec[]>("/pack-specs");
  const items = useApi<Item[]>("/items");
  const clientOrders = useApi<ClientOrder[]>("/client-orders");
  const customers = useApi<Customer[]>("/customers");
  const [dlg, setDlg] = useState<{ client_order_id: string; item_id: string; required_kg: string; customer_name: string } | null>(null);
  const [sd, setSd] = useState<{ item_id: string; customer_id: string; box_kg_options: string; boxes_per_carton: string; cartons_per_pallet: string; label_note: string } | null>(null);
  const [saving, setSaving] = useState(false);
  const align = isRTL ? "text-right" : "text-left";
  const itemName = (id: number) => { const i = items.data?.find((x) => x.id === id); return i ? nm(isRTL, i.name, i.name_ar) : id; };

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    setSaving(true);
    try {
      const o = await post("/pack-orders", dlg.client_order_id ? { client_order_id: Number(dlg.client_order_id), ...(dlg.required_kg ? { required_kg: Number(dlg.required_kg) } : {}) } : { item_id: Number(dlg.item_id), required_kg: Number(dlg.required_kg), customer_name: dlg.customer_name || undefined });
      toast.success(T("Pack order created", "تم إنشاء أمر التعبئة")); setDlg(null); nav(`/production/packing/${o.id}`);
    } catch (err: any) { toast.error(err.message); } finally { setSaving(false); }
  };
  const saveSpec = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!sd) return;
    setSaving(true);
    try {
      await post("/pack-specs", { item_id: Number(sd.item_id), customer_id: sd.customer_id ? Number(sd.customer_id) : null, box_kg_options: sd.box_kg_options, default_box_kg: Number(sd.box_kg_options.split(",")[0]) || 1, boxes_per_carton: Number(sd.boxes_per_carton), cartons_per_pallet: sd.cartons_per_pallet ? Number(sd.cartons_per_pallet) : null, label_note: sd.label_note || null });
      toast.success(T("Specification saved", "حُفظت المواصفات")); setSd(null); await specs.reload();
    } catch (err: any) { toast.error(err.message); } finally { setSaving(false); }
  };

  return (
    <div className="space-y-6">
      <ProductionNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{T("Packing", "التعبئة")}</h1>
          <p className="text-muted-foreground mt-1">{T("The head of preparation decides the box size (1 or 2 kg). Boxes go in cartons of 40 and cartons on pallets. Every carton and pallet has a code that leads back to the batches.", "يقرر رئيس التجهيز حجم الصندوق (١ أو ٢ كجم). تُوضع الصناديق في كراتين من ٤٠ والكراتين على طبليات، ولكل كرتونة وطبلية رمز يعود إلى الدفعات.")}</p>
        </div>
        {can("pack-orders", "create") && <Button className="gap-2" data-testid="new-pack" onClick={() => setDlg({ client_order_id: "", item_id: "", required_kg: "", customer_name: "" })}><Plus className="h-4 w-4" />{T("New pack order", "أمر تعبئة جديد")}</Button>}
      </div>
      {(rows.error || specs.error) && <ErrorBox message={rows.error || specs.error} />}

      <Card className="p-6" data-testid="pack-list">
        <Table>
          <TableHeader><TableRow>
            <TableHead className={align}>{T("No.", "الرقم")}</TableHead><TableHead className={align}>{T("Client / item", "العميل / الصنف")}</TableHead><TableHead className={align}>{T("Required", "المطلوب")}</TableHead>
            <TableHead className={align}>{T("Box", "الصندوق")}</TableHead><TableHead className={align}>{T("Cartons / pallets", "كراتين / طبليات")}</TableHead><TableHead className={align}>{T("Status", "الحالة")}</TableHead>
          </TableRow></TableHeader>
          <TableBody>
            {rows.data?.map((r) => (
              <TableRow key={r.id}>
                <TableCell><Link className="font-mono text-primary underline" to={`/production/packing/${r.id}`}>{r.number}</Link></TableCell>
                <TableCell>{r.customer_name ?? "—"}<div className="text-xs text-muted-foreground">{r.item_name} · {T("grade", "الدرجة")} {r.grade}</div></TableCell>
                <TableCell>{fmtKg(r.required_kg)} kg{r.due_date && <div className="text-xs text-muted-foreground" dir="ltr">{r.due_date}</div>}</TableCell>
                <TableCell>{r.box_kg != null ? `${r.box_kg} kg` : "—"}</TableCell>
                <TableCell>{r.cartons != null ? `${r.cartons} / ${r.pallets}` : "—"}</TableCell>
                <TableCell><PackStatus s={r.status} isRTL={isRTL} /></TableCell>
              </TableRow>
            ))}
            {rows.data && rows.data.length === 0 && <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">{T("No pack orders", "لا توجد أوامر تعبئة")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      <Card className="p-6" data-testid="specs">
        <div className="flex items-center justify-between mb-3">
          <h2 className="font-semibold">{T("Pack specifications", "مواصفات التعبئة")}</h2>
          {can("pack-specs", "create") && <Button size="sm" variant="outline" className="gap-1" data-testid="new-spec" onClick={() => setSd({ item_id: "", customer_id: "", box_kg_options: "1,2", boxes_per_carton: "40", cartons_per_pallet: "", label_note: "" })}><Plus className="h-4 w-4" />{T("Add", "إضافة")}</Button>}
        </div>
        <p className="text-xs text-muted-foreground mb-3">{T("A client's own specification wins; otherwise the item's general one; otherwise the company defaults (Settings → Packing).", "مواصفات العميل أولاً، ثم العامة للصنف، ثم الافتراضية للشركة (الإعدادات ← التعبئة).")}</p>
        <Table>
          <TableHeader><TableRow>
            <TableHead className={align}>{T("Item", "الصنف")}</TableHead><TableHead className={align}>{T("Client", "العميل")}</TableHead><TableHead className={align}>{T("Box sizes (kg)", "أحجام الصندوق (كجم)")}</TableHead>
            <TableHead className={align}>{T("Boxes / carton", "صناديق/كرتونة")}</TableHead><TableHead className={align}>{T("Cartons / pallet", "كراتين/طبلية")}</TableHead><TableHead className={align}>{T("Label", "الملصق")}</TableHead>
          </TableRow></TableHeader>
          <TableBody>
            {specs.data?.map((s) => (
              <TableRow key={s.id}>
                <TableCell>{itemName(s.item_id)}</TableCell><TableCell>{s.customer_id ? customers.data?.find((c) => c.id === s.customer_id)?.name ?? s.customer_id : T("any client", "أي عميل")}</TableCell>
                <TableCell dir="ltr">{s.box_kg_options}</TableCell><TableCell>{s.boxes_per_carton}</TableCell><TableCell>{s.cartons_per_pallet ?? T("default", "الافتراضي")}</TableCell><TableCell>{s.label_note ?? "—"}</TableCell>
              </TableRow>
            ))}
            {specs.data && specs.data.length === 0 && <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">{T("No specifications: the company defaults apply", "لا توجد مواصفات: تُطبَّق الافتراضات")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent>
          <form onSubmit={create} className="space-y-3">
            <DialogHeader><DialogTitle>{T("New pack order", "أمر تعبئة جديد")}</DialogTitle></DialogHeader>
            <Field id="po_co" label={T("For a client order (fills in the client, kg and date)", "لطلب عميل (يملأ العميل والكمية والتاريخ)")}>
              <select id="po_co" className={selectClass} value={dlg?.client_order_id} onChange={(e) => setDlg((s) => s && { ...s, client_order_id: e.target.value })}>
                <option value="">{T("— no client order —", "— بدون طلب عميل —")}</option>
                {clientOrders.data?.filter((c) => !["Cancelled", "Closed"].includes(c.status)).map((c) => <option key={c.id} value={c.id}>{c.number} · {c.customer_name}</option>)}
              </select>
            </Field>
            {!dlg?.client_order_id && (
              <>
                <Field id="po_item" label={T("Item", "الصنف")}>
                  <select id="po_item" className={selectClass} required value={dlg?.item_id} onChange={(e) => setDlg((s) => s && { ...s, item_id: e.target.value })}>
                    <option value="">—</option>{items.data?.map((i) => <option key={i.id} value={i.id}>{nm(isRTL, i.name, i.name_ar)}</option>)}
                  </select>
                </Field>
                <Field id="po_cust" label={T("Client name (optional)", "اسم العميل (اختياري)")}><Input id="po_cust" value={dlg?.customer_name ?? ""} onChange={(e) => setDlg((s) => s && { ...s, customer_name: e.target.value })} /></Field>
              </>
            )}
            <Field id="po_kg" label={T("Kg to pack", "الكمية المراد تعبئتها (كجم)")}><Input id="po_kg" type="number" dir="ltr" required={!dlg?.client_order_id} value={dlg?.required_kg ?? ""} onChange={(e) => setDlg((s) => s && { ...s, required_kg: e.target.value })} /></Field>
            <DialogFooter><Button type="button" variant="outline" onClick={() => setDlg(null)}>{T("Cancel", "إلغاء")}</Button><Button type="submit" disabled={saving}>{T("Create", "إنشاء")}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!sd} onOpenChange={(o) => !o && setSd(null)}>
        <DialogContent>
          <form onSubmit={saveSpec} className="space-y-3">
            <DialogHeader><DialogTitle>{T("New pack specification", "مواصفات تعبئة جديدة")}</DialogTitle></DialogHeader>
            <div className="grid grid-cols-2 gap-3">
              <Field id="sp_item" label={T("Item", "الصنف")}>
                <select id="sp_item" className={selectClass} required value={sd?.item_id} onChange={(e) => setSd((s) => s && { ...s, item_id: e.target.value })}>
                  <option value="">—</option>{items.data?.map((i) => <option key={i.id} value={i.id}>{nm(isRTL, i.name, i.name_ar)}</option>)}
                </select>
              </Field>
              <Field id="sp_cust" label={T("Client", "العميل")}>
                <select id="sp_cust" className={selectClass} value={sd?.customer_id} onChange={(e) => setSd((s) => s && { ...s, customer_id: e.target.value })}>
                  <option value="">{T("Any client", "أي عميل")}</option>{customers.data?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </Field>
              <Field id="sp_sizes" label={T("Box sizes (kg, comma)", "أحجام الصندوق (كجم)")}><Input id="sp_sizes" dir="ltr" required value={sd?.box_kg_options ?? ""} onChange={(e) => setSd((s) => s && { ...s, box_kg_options: e.target.value })} /></Field>
              <Field id="sp_ctn" label={T("Boxes per carton", "صناديق في الكرتونة")}><Input id="sp_ctn" type="number" dir="ltr" required value={sd?.boxes_per_carton ?? ""} onChange={(e) => setSd((s) => s && { ...s, boxes_per_carton: e.target.value })} /></Field>
              <Field id="sp_pal" label={T("Cartons per pallet", "كراتين على الطبلية")}><Input id="sp_pal" type="number" dir="ltr" placeholder={T("company default", "الافتراضي")} value={sd?.cartons_per_pallet ?? ""} onChange={(e) => setSd((s) => s && { ...s, cartons_per_pallet: e.target.value })} /></Field>
              <Field id="sp_label" label={T("Label note", "ملاحظة الملصق")}><Input id="sp_label" value={sd?.label_note ?? ""} onChange={(e) => setSd((s) => s && { ...s, label_note: e.target.value })} /></Field>
            </div>
            <DialogFooter><Button type="button" variant="outline" onClick={() => setSd(null)}>{T("Cancel", "إلغاء")}</Button><Button type="submit" disabled={saving}>{T("Save", "حفظ")}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
