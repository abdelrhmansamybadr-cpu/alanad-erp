import { useState } from "react";
import { Link, useNavigate } from "react-router";
import { toast } from "sonner";
import { Plus, Trash2 } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";
import { useApi, post } from "../../hooks/useApi";
import { SalesNav } from "../../components/sales/SalesNav";
import { StatusBadge } from "../../components/procurement/common";
import { ErrorBox, Field, selectClass, fmtKg, Item, nm } from "../../components/inventory/common";

export interface ClientOrderRow {
  id: number; number: string; customer_name: string; channel: string; order_date: string; due_date: string | null;
  status: string; total_kg: number; reserved_kg: number; purchase_request_id: number | null;
}
const CHANNELS: [string, string, string][] = [["email", "E-mail", "بريد إلكتروني"], ["phone", "Phone", "هاتف"], ["whatsapp", "WhatsApp", "واتساب"], ["visit", "Visit", "زيارة"], ["other", "Other", "أخرى"]];
const STATUSES = ["Draft", "Reserved", "Short", "Cancelled"];

/** Client orders: the order that arrives by e-mail or phone, before the stock check and the reservation. */
export function ClientOrders() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const nav = useNavigate();
  const [status, setStatus] = useState("");
  const orders = useApi<ClientOrderRow[]>(`/client-orders${status ? `?status=${status}` : ""}`);
  const items = useApi<Item[]>("/items");
  const [dlg, setDlg] = useState<{ customer_name: string; channel: string; due_date: string; notes: string; lines: { item_id: string; qty_kg: string }[] } | null>(null);
  const [saving, setSaving] = useState(false);
  const align = isRTL ? "text-right" : "text-left";

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    setSaving(true);
    try {
      const o = await post("/client-orders", {
        customer_name: dlg.customer_name, channel: dlg.channel, due_date: dlg.due_date || null, notes: dlg.notes || null,
        lines: dlg.lines.map((l) => ({ item_id: Number(l.item_id), qty_kg: Number(l.qty_kg) })),
      });
      toast.success(isRTL ? "تم تسجيل الطلب" : "Order recorded");
      setDlg(null);
      nav(`/sales/orders/${o.id}`);
    } catch (err: any) {
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };
  const setLine = (i: number, k: "item_id" | "qty_kg", v: string) => setDlg((s) => s && { ...s, lines: s.lines.map((l, j) => (j === i ? { ...l, [k]: v } : l)) });

  return (
    <div className="space-y-6">
      <SalesNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "طلبات العملاء" : "Client orders"}</h1>
          <p className="text-muted-foreground mt-1">
            {isRTL ? "الطلب الذي يصل من العميل: نفحص المخزون ثم نحجزه، وما ينقص يتحول إلى طلب شراء." : "The order a client sends: check the stock, reserve it, and turn any shortage into a purchase request."}
          </p>
        </div>
        {can("client-orders", "create") && (
          <Button className="gap-2" data-testid="new-order" onClick={() => setDlg({ customer_name: "", channel: "email", due_date: "", notes: "", lines: [{ item_id: "", qty_kg: "" }] })}>
            <Plus className="h-4 w-4" />{isRTL ? "طلب جديد" : "New order"}
          </Button>
        )}
      </div>
      {orders.error && <ErrorBox message={orders.error} />}
      <div className="flex gap-2 flex-wrap">
        {["", ...STATUSES].map((s) => (
          <Button key={s || "all"} size="sm" variant={status === s ? "default" : "outline"} onClick={() => setStatus(s)}>
            {s ? <StatusBadge status={s} isRTL={isRTL} /> : isRTL ? "الكل" : "All"}
          </Button>
        ))}
      </div>
      <Card className="p-6">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className={align}>{isRTL ? "الرقم" : "No."}</TableHead>
              <TableHead className={align}>{isRTL ? "العميل" : "Client"}</TableHead>
              <TableHead className={align}>{isRTL ? "الوصول" : "Via"}</TableHead>
              <TableHead className={align}>{isRTL ? "التسليم" : "Due"}</TableHead>
              <TableHead className={align}>{isRTL ? "المطلوب (كجم)" : "Ordered (kg)"}</TableHead>
              <TableHead className={align}>{isRTL ? "المحجوز (كجم)" : "Reserved (kg)"}</TableHead>
              <TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {orders.data?.map((o) => (
              <TableRow key={o.id}>
                <TableCell><Link className="font-mono text-primary underline" to={`/sales/orders/${o.id}`}>{o.number}</Link></TableCell>
                <TableCell className="font-medium">{o.customer_name}</TableCell>
                <TableCell>{CHANNELS.find(([k]) => k === o.channel)?.[isRTL ? 2 : 1] ?? o.channel}</TableCell>
                <TableCell dir="ltr">{o.due_date ?? "—"}</TableCell>
                <TableCell>{fmtKg(o.total_kg)}</TableCell>
                <TableCell>{fmtKg(o.reserved_kg)}</TableCell>
                <TableCell><StatusBadge status={o.status} isRTL={isRTL} /></TableCell>
              </TableRow>
            ))}
            {orders.data && orders.data.length === 0 && (
              <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground">{isRTL ? "لا توجد طلبات" : "No orders"}</TableCell></TableRow>
            )}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent className="max-w-2xl">
          <form onSubmit={save} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "طلب عميل جديد" : "New client order"}</DialogTitle></DialogHeader>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <Field id="co_name" label={isRTL ? "اسم العميل" : "Client name"} className="sm:col-span-2">
                <Input id="co_name" required value={dlg?.customer_name ?? ""} onChange={(e) => setDlg((s) => s && { ...s, customer_name: e.target.value })} />
              </Field>
              <Field id="co_channel" label={isRTL ? "وصل عن طريق" : "Received by"}>
                <select id="co_channel" className={selectClass} value={dlg?.channel} onChange={(e) => setDlg((s) => s && { ...s, channel: e.target.value })}>
                  {CHANNELS.map(([k, en, ar]) => <option key={k} value={k}>{isRTL ? ar : en}</option>)}
                </select>
              </Field>
              <Field id="co_due" label={isRTL ? "موعد التسليم" : "Delivery date"}>
                <Input id="co_due" type="date" dir="ltr" value={dlg?.due_date ?? ""} onChange={(e) => setDlg((s) => s && { ...s, due_date: e.target.value })} />
              </Field>
            </div>
            <div className="space-y-2">
              {dlg?.lines.map((l, i) => (
                <div key={i} className="flex gap-2 items-end">
                  <Field id={`co_item_${i}`} label={i === 0 ? (isRTL ? "الصنف" : "Item") : ""} className="flex-1">
                    <select id={`co_item_${i}`} className={selectClass} required value={l.item_id} onChange={(e) => setLine(i, "item_id", e.target.value)}>
                      <option value="">—</option>
                      {items.data?.map((it) => <option key={it.id} value={it.id}>{nm(isRTL, it.name, it.name_ar)}</option>)}
                    </select>
                  </Field>
                  <Field id={`co_qty_${i}`} label={i === 0 ? (isRTL ? "الكمية (كجم)" : "Qty (kg)") : ""} className="w-32">
                    <Input id={`co_qty_${i}`} type="number" step="0.001" min="0.001" dir="ltr" required value={l.qty_kg} onChange={(e) => setLine(i, "qty_kg", e.target.value)} />
                  </Field>
                  {dlg.lines.length > 1 && (
                    <Button type="button" variant="ghost" size="sm" aria-label="remove" onClick={() => setDlg((s) => s && { ...s, lines: s.lines.filter((_, j) => j !== i) })}><Trash2 className="h-4 w-4" /></Button>
                  )}
                </div>
              ))}
              <Button type="button" variant="outline" size="sm" onClick={() => setDlg((s) => s && { ...s, lines: [...s.lines, { item_id: "", qty_kg: "" }] })}>
                <Plus className="h-4 w-4 me-1" />{isRTL ? "صنف آخر" : "Add item"}
              </Button>
            </div>
            <Field id="co_notes" label={isRTL ? "ملاحظات" : "Notes"}>
              <Input id="co_notes" value={dlg?.notes ?? ""} onChange={(e) => setDlg((s) => s && { ...s, notes: e.target.value })} />
            </Field>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setDlg(null)}>{isRTL ? "إلغاء" : "Cancel"}</Button>
              <Button type="submit" disabled={saving}>{saving ? "…" : isRTL ? "تسجيل الطلب" : "Record order"}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
