import { useState } from "react";
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
import { SalesNav } from "../../components/sales/SalesNav";
import { ErrorBox, Field, selectClass, fmtKg, Item, nm } from "../../components/inventory/common";

interface Price { id: number; item_id: number; grade: string | null; customer_id: number | null; price_per_kg: string; valid_from: string }
interface Customer { id: number; name: string }

/** Selling prices per kg: by item, grade (class 1/2/3 = A/B/C) and client; the client's own price wins. */
export function SalesPrices() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const prices = useApi<Price[]>("/sales-prices");
  const items = useApi<Item[]>("/items");
  const customers = useApi<Customer[]>("/customers");
  const [d, setD] = useState<{ item_id: string; grade: string; customer_id: string; price: string } | null>(null);
  const align = isRTL ? "text-right" : "text-left";
  const itemName = (id: number) => { const i = items.data?.find((x) => x.id === id); return i ? nm(isRTL, i.name, i.name_ar) : id; };
  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!d) return;
    try { await post("/sales-prices", { item_id: Number(d.item_id), grade: d.grade || null, customer_id: d.customer_id ? Number(d.customer_id) : null, price_per_kg: Number(d.price) }); toast.success(T("Price saved", "حُفظ السعر")); setD(null); await prices.reload(); }
    catch (err: any) { toast.error(err.message); }
  };
  return (
    <div className="space-y-6">
      <SalesNav />
      <div className="flex items-center justify-between gap-4">
        <div><h1 className="text-3xl font-bold">{T("Selling prices", "أسعار البيع")}</h1><p className="text-muted-foreground mt-1">{T("Price per kg by item, grade (A = class 1, B = class 2, C = class 3) and client. A client's own price wins over the general one; the latest date wins.", "السعر للكيلو بحسب الصنف والدرجة (A = الأولى، B = الثانية، C = الثالثة) والعميل. سعر العميل الخاص يغلب العام، والأحدث تاريخاً يغلب.")}</p></div>
        {can("sales-prices", "create") && <Button className="gap-2" data-testid="new-price" onClick={() => setD({ item_id: "", grade: "A", customer_id: "", price: "" })}><Plus className="h-4 w-4" />{T("Add price", "إضافة سعر")}</Button>}
      </div>
      {prices.error && <ErrorBox message={prices.error} />}
      <Card className="p-6">
        <Table>
          <TableHeader><TableRow><TableHead className={align}>{T("Item", "الصنف")}</TableHead><TableHead className={align}>{T("Grade", "الدرجة")}</TableHead><TableHead className={align}>{T("Client", "العميل")}</TableHead><TableHead className={align}>{T("Price / kg", "السعر/كجم")}</TableHead><TableHead className={align}>{T("From", "من")}</TableHead></TableRow></TableHeader>
          <TableBody>
            {prices.data?.map((p) => <TableRow key={p.id}><TableCell>{itemName(p.item_id)}</TableCell><TableCell>{p.grade ?? T("any", "أي")}</TableCell><TableCell>{p.customer_id ? customers.data?.find((c) => c.id === p.customer_id)?.name ?? p.customer_id : T("any client", "أي عميل")}</TableCell><TableCell className="font-semibold">{Number(p.price_per_kg).toFixed(2)}</TableCell><TableCell dir="ltr">{p.valid_from}</TableCell></TableRow>)}
            {prices.data && prices.data.length === 0 && <TableRow><TableCell colSpan={5} className="text-center text-muted-foreground">{T("No prices yet", "لا توجد أسعار")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>
      <Dialog open={!!d} onOpenChange={(o) => !o && setD(null)}>
        <DialogContent>
          <form onSubmit={save} className="space-y-3">
            <DialogHeader><DialogTitle>{T("New price", "سعر جديد")}</DialogTitle></DialogHeader>
            <Field id="pr_item" label={T("Item", "الصنف")}><select id="pr_item" className={selectClass} required value={d?.item_id} onChange={(e) => setD((s) => s && { ...s, item_id: e.target.value })}><option value="">—</option>{items.data?.map((i) => <option key={i.id} value={i.id}>{nm(isRTL, i.name, i.name_ar)}</option>)}</select></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field id="pr_grade" label={T("Grade", "الدرجة")}><select id="pr_grade" className={selectClass} value={d?.grade} onChange={(e) => setD((s) => s && { ...s, grade: e.target.value })}><option value="A">A · {T("class 1", "الأولى")}</option><option value="B">B · {T("class 2", "الثانية")}</option><option value="C">C · {T("class 3", "الثالثة")}</option><option value="">{T("any", "أي")}</option></select></Field>
              <Field id="pr_price" label={T("Price / kg", "السعر/كجم")}><Input id="pr_price" type="number" step="0.01" min="0.01" dir="ltr" required value={d?.price ?? ""} onChange={(e) => setD((s) => s && { ...s, price: e.target.value })} /></Field>
            </div>
            <Field id="pr_cust" label={T("Client", "العميل")}><select id="pr_cust" className={selectClass} value={d?.customer_id} onChange={(e) => setD((s) => s && { ...s, customer_id: e.target.value })}><option value="">{T("Any client", "أي عميل")}</option>{customers.data?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <DialogFooter><Button type="button" variant="outline" onClick={() => setD(null)}>{T("Cancel", "إلغاء")}</Button><Button type="submit" data-testid="save-price">{T("Save", "حفظ")}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}

interface RcRow { id: number; number: string; status: string; reason: string; batch_no: string; item_name: string; created_at: string }
interface RcDetail extends RcRow { in_stock_kg: number; customers: string[]; pack_orders: { id: number; number: string; customer_name: string | null; kg: number }[]; unshipped_units: { code: string; boxes: number; kg: number }[]; shipments: { id: number; number: string; customer_name: string; status: string; units: number; kg: number }[] }

/** Recalls: find where a batch went, hold what is left, and close it when it is safe. */
export function Recalls() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const list = useApi<RcRow[]>("/recalls");
  const [sel, setSel] = useState<number | null>(null);
  const det = useApi<RcDetail>(sel ? `/recalls/${sel}` : null);
  const [dlg, setDlg] = useState<{ batch: string; reason: string } | null>(null);
  const [closing, setClosing] = useState<string | null>(null);
  const align = isRTL ? "text-right" : "text-left";
  const open = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    try { const r = await post("/recalls", { batch_no: dlg.batch, reason: dlg.reason }); toast.success(T("Recall opened: the batch is on hold", "فُتح الاستدعاء: الدفعة محجوزة")); setDlg(null); await list.reload(); setSel(r.id); }
    catch (err: any) { toast.error(err.message); }
  };
  const close = async () => {
    try { await post(`/recalls/${sel}/close`, { note: closing }); toast.success(T("Recall closed", "أُغلق الاستدعاء")); setClosing(null); await Promise.all([list.reload(), det.reload()]); } catch (err: any) { toast.error(err.message); }
  };
  return (
    <div className="space-y-6">
      <SalesNav />
      <div className="flex items-center justify-between gap-4">
        <div><h1 className="text-3xl font-bold">{T("Recalls", "الاستدعاء")}</h1><p className="text-muted-foreground mt-1">{T("When a batch is found bad: what is left is put on hold, and the system lists every pack order, shipment and client that may have received it.", "عند اكتشاف خلل في دفعة: يُحجز ما تبقى منها ويعرض النظام كل أمر تعبئة وشحنة وعميل قد يكون استلمها.")}</p></div>
        {can("recalls", "create") && <Button className="gap-2" data-testid="new-recall" onClick={() => setDlg({ batch: "", reason: "" })}><Plus className="h-4 w-4" />{T("Open a recall", "فتح استدعاء")}</Button>}
      </div>
      {list.error && <ErrorBox message={list.error} />}
      <Card className="p-6" data-testid="recall-list">
        <Table>
          <TableHeader><TableRow><TableHead className={align}>{T("No.", "الرقم")}</TableHead><TableHead className={align}>{T("Batch", "الدفعة")}</TableHead><TableHead className={align}>{T("Reason", "السبب")}</TableHead><TableHead className={align}>{T("Status", "الحالة")}</TableHead></TableRow></TableHeader>
          <TableBody>
            {list.data?.map((r) => <TableRow key={r.id} className="cursor-pointer" onClick={() => setSel(r.id)}><TableCell className="font-mono text-primary underline">{r.number}</TableCell><TableCell dir="ltr">{r.batch_no}<div className="text-xs text-muted-foreground">{r.item_name}</div></TableCell><TableCell>{r.reason}</TableCell><TableCell>{r.status === "Open" ? <Badge className="bg-red-600 hover:bg-red-600">{T("Open", "مفتوح")}</Badge> : <Badge variant="secondary">{T("Closed", "مغلق")}</Badge>}</TableCell></TableRow>)}
            {list.data && list.data.length === 0 && <TableRow><TableCell colSpan={4} className="text-center text-muted-foreground">{T("No recalls", "لا توجد استدعاءات")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      {det.data && (
        <Card className="p-6 space-y-4" data-testid="recall-detail">
          <div className="flex items-center justify-between"><h2 className="font-semibold">{det.data.number} · <span dir="ltr">{det.data.batch_no}</span></h2>{det.data.status === "Open" && can("stock", "approve") && <Button size="sm" variant="outline" data-testid="close-recall" onClick={() => setClosing("")}>{T("Close the recall", "إغلاق الاستدعاء")}</Button>}</div>
          <p className="text-sm">{det.data.reason}</p>
          <div className="grid sm:grid-cols-3 gap-3 text-sm">
            <div className="p-3 border rounded-lg"><div className="text-muted-foreground">{T("Still in stock (on hold)", "ما زال في المخزون (محجوز)")}</div><b>{fmtKg(det.data.in_stock_kg)} kg</b></div>
            <div className="p-3 border rounded-lg"><div className="text-muted-foreground">{T("Packed, not shipped (blocked)", "معبأ ولم يُشحن (ممنوع شحنه)")}</div><b>{det.data.unshipped_units.length} {T("units", "وحدة")}</b></div>
            <div className="p-3 border rounded-lg"><div className="text-muted-foreground">{T("Clients that received goods", "عملاء استلموا بضاعة")}</div><b data-testid="rc-customers">{det.data.customers.join("، ") || "—"}</b></div>
          </div>
          <Table>
            <TableHeader><TableRow><TableHead className={align}>{T("Shipment", "الشحنة")}</TableHead><TableHead className={align}>{T("Client", "العميل")}</TableHead><TableHead className={align}>{T("Units", "وحدات")}</TableHead><TableHead className={align}>{T("kg", "كجم")}</TableHead><TableHead className={align}>{T("Status", "الحالة")}</TableHead></TableRow></TableHeader>
            <TableBody>{det.data.shipments.map((s) => <TableRow key={s.id}><TableCell className="font-mono">{s.number}</TableCell><TableCell>{s.customer_name}</TableCell><TableCell>{s.units}</TableCell><TableCell>{fmtKg(s.kg)}</TableCell><TableCell>{s.status}</TableCell></TableRow>)}</TableBody>
          </Table>
        </Card>
      )}

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent>
          <form onSubmit={open} className="space-y-3">
            <DialogHeader><DialogTitle>{T("Open a recall", "فتح استدعاء")}</DialogTitle></DialogHeader>
            <Field id="rc_batch" label={T("Batch number", "رقم الدفعة")}><Input id="rc_batch" dir="ltr" required value={dlg?.batch ?? ""} onChange={(e) => setDlg((s) => s && { ...s, batch: e.target.value })} /></Field>
            <Field id="rc_reason" label={T("Reason", "السبب")}><Input id="rc_reason" required value={dlg?.reason ?? ""} onChange={(e) => setDlg((s) => s && { ...s, reason: e.target.value })} /></Field>
            <DialogFooter><Button type="button" variant="outline" onClick={() => setDlg(null)}>{T("Cancel", "إلغاء")}</Button><Button type="submit" data-testid="open-recall">{T("Open and hold the batch", "فتح وحجز الدفعة")}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
      <Dialog open={closing !== null} onOpenChange={(o) => !o && setClosing(null)}>
        <DialogContent>
          <DialogHeader><DialogTitle>{T("Close the recall", "إغلاق الاستدعاء")}</DialogTitle></DialogHeader>
          <Field id="rc_close" label={T("Closing note", "ملاحظة الإغلاق")}><Input id="rc_close" value={closing ?? ""} onChange={(e) => setClosing(e.target.value)} /></Field>
          <DialogFooter><Button variant="outline" onClick={() => setClosing(null)}>{T("Back", "رجوع")}</Button><Button disabled={!closing?.trim()} data-testid="close-confirm" onClick={close}>{T("Confirm", "تأكيد")}</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
