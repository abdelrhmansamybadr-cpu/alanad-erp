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
import { ProcurementNav, StatusBadge, Supplier, money } from "../../components/procurement/common";
import { ErrorBox, Field, selectClass, Item, nm } from "../../components/inventory/common";

interface Row {
  id: number; number: string; market_name: string; status: string; buyer_name: string; supplier_name: string;
  offer_total: number; total_amount: number; issued_amount: number | null; due_date: string | null; is_overdue: boolean; pending_lines: number;
}
interface Custody { buyer_id: number; buyer_name: string; purchases: number; amount: number; overdue: number; earliest_due: string | null }

/** Buying at the market: the buyer's offer, the quality inspection, Finance approval, the cash custody and its settlement. */
export function MarketPurchases() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const nav = useNavigate();
  const [status, setStatus] = useState("");
  const rows = useApi<Row[]>(`/market-purchases${status ? `?status=${status}` : ""}`);
  const custody = useApi<Custody[]>("/market-purchases/custody");
  const suppliers = useApi<Supplier[]>("/suppliers");
  const items = useApi<Item[]>("/items");
  const [dlg, setDlg] = useState<{ market_name: string; supplier_id: string; lines: { item_id: string; qty_kg: string; offer_price: string }[] } | null>(null);
  const [saving, setSaving] = useState(false);
  const align = isRTL ? "text-right" : "text-left";

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    setSaving(true);
    try {
      const p = await post("/market-purchases", {
        market_name: dlg.market_name, supplier_id: Number(dlg.supplier_id),
        lines: dlg.lines.map((l) => ({ item_id: Number(l.item_id), qty_kg: Number(l.qty_kg), offer_price: Number(l.offer_price) })),
      });
      toast.success(isRTL ? "تم تجهيز عرض الشراء" : "Purchase offer prepared");
      setDlg(null);
      nav(`/procurement/market/${p.id}`);
    } catch (err: any) {
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };
  const setLine = (i: number, k: "item_id" | "qty_kg" | "offer_price", v: string) => setDlg((s) => s && { ...s, lines: s.lines.map((l, j) => (j === i ? { ...l, [k]: v } : l)) });
  const STATUSES = ["Draft", "Submitted", "Approved", "Returned", "Cash_Issued", "Settlement_Submitted", "Settled", "Cancelled"];

  return (
    <div className="space-y-6">
      <ProcurementNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "الشراء من السوق" : "Market buying"}</h1>
          <p className="text-muted-foreground mt-1">
            {isRTL ? "عرض الشراء ← فحص الجودة في السوق ← اعتماد المالية ← عهدة نقدية للمشتري ← تسوية بالإيصالات." : "Purchase offer → quality check at the market → Finance approval → cash custody → settlement with receipts."}
          </p>
        </div>
        {can("market-purchases", "create") && (
          <Button className="gap-2" data-testid="new-offer" onClick={() => setDlg({ market_name: "", supplier_id: "", lines: [{ item_id: "", qty_kg: "", offer_price: "" }] })}>
            <Plus className="h-4 w-4" />{isRTL ? "عرض شراء جديد" : "New purchase offer"}
          </Button>
        )}
      </div>
      {(rows.error || custody.error) && <ErrorBox message={rows.error || custody.error} />}

      {custody.data && custody.data.length > 0 && (
        <Card className="p-6" data-testid="custody-card">
          <h2 className="font-semibold mb-3">{isRTL ? "العُهَد المفتوحة عند المشترين" : "Open custody with buyers"}</h2>
          <Table>
            <TableHeader><TableRow>
              <TableHead className={align}>{isRTL ? "المشتري" : "Buyer"}</TableHead>
              <TableHead className={align}>{isRTL ? "عدد المشتريات" : "Purchases"}</TableHead>
              <TableHead className={align}>{isRTL ? "المبلغ" : "Amount"}</TableHead>
              <TableHead className={align}>{isRTL ? "أقرب استحقاق" : "Earliest due"}</TableHead>
              <TableHead className={align}>{isRTL ? "متأخرة" : "Overdue"}</TableHead>
            </TableRow></TableHeader>
            <TableBody>
              {custody.data.map((c) => (
                <TableRow key={c.buyer_id}>
                  <TableCell className="font-medium">{c.buyer_name}</TableCell><TableCell>{c.purchases}</TableCell><TableCell>{money(c.amount)}</TableCell>
                  <TableCell dir="ltr">{c.earliest_due ?? "—"}</TableCell>
                  <TableCell className={c.overdue ? "text-red-700 font-semibold" : ""}>{c.overdue}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      <div className="flex gap-2 flex-wrap">
        {["", ...STATUSES].map((s) => (
          <Button key={s || "all"} size="sm" variant={status === s ? "default" : "outline"} onClick={() => setStatus(s)}>
            {s ? <StatusBadge status={s} isRTL={isRTL} /> : isRTL ? "الكل" : "All"}
          </Button>
        ))}
      </div>
      <Card className="p-6">
        <Table>
          <TableHeader><TableRow>
            <TableHead className={align}>{isRTL ? "الرقم" : "No."}</TableHead>
            <TableHead className={align}>{isRTL ? "السوق / التاجر" : "Market / trader"}</TableHead>
            <TableHead className={align}>{isRTL ? "المشتري" : "Buyer"}</TableHead>
            <TableHead className={align}>{isRTL ? "قيمة العرض" : "Offer"}</TableHead>
            <TableHead className={align}>{isRTL ? "النقد المُسلَّم" : "Cash given"}</TableHead>
            <TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead>
          </TableRow></TableHeader>
          <TableBody>
            {rows.data?.map((r) => (
              <TableRow key={r.id}>
                <TableCell><Link className="font-mono text-primary underline" to={`/procurement/market/${r.id}`}>{r.number}</Link></TableCell>
                <TableCell>{r.market_name}<div className="text-xs text-muted-foreground">{r.supplier_name}</div></TableCell>
                <TableCell>{r.buyer_name}</TableCell>
                <TableCell>{money(r.offer_total)}</TableCell>
                <TableCell>{r.issued_amount != null ? money(r.issued_amount) : "—"}{r.is_overdue && <span className="text-red-700 text-xs ms-2">{isRTL ? "متأخرة" : "overdue"}</span>}</TableCell>
                <TableCell><StatusBadge status={r.status} isRTL={isRTL} /></TableCell>
              </TableRow>
            ))}
            {rows.data && rows.data.length === 0 && <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">{isRTL ? "لا توجد عروض" : "No offers"}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent className="max-w-2xl">
          <form onSubmit={save} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "عرض شراء من السوق" : "Market purchase offer"}</DialogTitle></DialogHeader>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <Field id="mp_market" label={isRTL ? "السوق" : "Market"}>
                <Input id="mp_market" required value={dlg?.market_name ?? ""} onChange={(e) => setDlg((s) => s && { ...s, market_name: e.target.value })} />
              </Field>
              <Field id="mp_supplier" label={isRTL ? "التاجر (المورّد)" : "Trader (supplier)"}>
                <select id="mp_supplier" className={selectClass} required value={dlg?.supplier_id} onChange={(e) => setDlg((s) => s && { ...s, supplier_id: e.target.value })}>
                  <option value="">—</option>
                  {suppliers.data?.filter((s) => Number(s.is_active) === 1).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
              </Field>
            </div>
            <div className="space-y-2">
              {dlg?.lines.map((l, i) => (
                <div key={i} className="flex gap-2 items-end">
                  <Field id={`mp_item_${i}`} label={i === 0 ? (isRTL ? "الصنف" : "Item") : ""} className="flex-1">
                    <select id={`mp_item_${i}`} className={selectClass} required value={l.item_id} onChange={(e) => setLine(i, "item_id", e.target.value)}>
                      <option value="">—</option>
                      {items.data?.map((it) => <option key={it.id} value={it.id}>{nm(isRTL, it.name, it.name_ar)}</option>)}
                    </select>
                  </Field>
                  <Field id={`mp_qty_${i}`} label={i === 0 ? (isRTL ? "كجم" : "kg") : ""} className="w-28">
                    <Input id={`mp_qty_${i}`} type="number" step="0.001" min="0.001" dir="ltr" required value={l.qty_kg} onChange={(e) => setLine(i, "qty_kg", e.target.value)} />
                  </Field>
                  <Field id={`mp_price_${i}`} label={i === 0 ? (isRTL ? "السعر/كجم" : "Price/kg") : ""} className="w-28">
                    <Input id={`mp_price_${i}`} type="number" step="0.01" min="0.01" dir="ltr" required value={l.offer_price} onChange={(e) => setLine(i, "offer_price", e.target.value)} />
                  </Field>
                  {dlg.lines.length > 1 && <Button type="button" variant="ghost" size="sm" aria-label="remove" onClick={() => setDlg((s) => s && { ...s, lines: s.lines.filter((_, j) => j !== i) })}><Trash2 className="h-4 w-4" /></Button>}
                </div>
              ))}
              <Button type="button" variant="outline" size="sm" onClick={() => setDlg((s) => s && { ...s, lines: [...s.lines, { item_id: "", qty_kg: "", offer_price: "" }] })}>
                <Plus className="h-4 w-4 me-1" />{isRTL ? "صنف آخر" : "Add item"}
              </Button>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setDlg(null)}>{isRTL ? "إلغاء" : "Cancel"}</Button>
              <Button type="submit" disabled={saving}>{saving ? "…" : isRTL ? "حفظ العرض" : "Save offer"}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
