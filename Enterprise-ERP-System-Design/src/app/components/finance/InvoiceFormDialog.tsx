import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Trash2 } from "lucide-react";
import { Button } from "../ui/button";
import { Input } from "../ui/input";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { apiRequest } from "../../services/api";
import { useApi, post, put } from "../../hooks/useApi";
import { ErrorBox, Field, fmtKg, selectClass, nm } from "../inventory/common";
import { PO } from "../procurement/common";
import { InvoiceDetailData } from "./common";

interface Receivable { po_id: number; supplier_name: string | null; lines: { item_id: number; item_name: string; item_name_ar: string | null; ordered: number; received: number; invoiced: number; billable: number; po_price: number }[] }
interface Row { item_id: string; qty_kg: string; price: string }

/** Enter (or correct) a supplier invoice. The lines start from what was received and not yet invoiced, at the order price. */
export function InvoiceFormDialog({ open, edit, presetPo, onClose, onSaved }: { open: boolean; edit: InvoiceDetailData | null; presetPo?: string; onClose: () => void; onSaved: (id: number) => void }) {
  const { isRTL } = useI18n();
  const approved = useApi<PO[]>(open && !edit ? "/purchase-orders?status=Approved" : null);
  const received = useApi<PO[]>(open && !edit ? "/purchase-orders?status=Received" : null);
  const [poId, setPoId] = useState("");
  const [rec, setRec] = useState<Receivable | null>(null);
  const [no, setNo] = useState("");
  const [date, setDate] = useState("");
  const [due, setDue] = useState("");
  const [vat, setVat] = useState("15");
  const [notes, setNotes] = useState("");
  const [rows, setRows] = useState<Row[]>([]);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!open) return;
    setError("");
    if (edit) {
      setPoId(String(edit.purchase_order_id)); setNo(edit.supplier_invoice_no); setDate(edit.invoice_date); setDue(edit.due_date); setVat(String(edit.vat_rate_pct)); setNotes(edit.notes ?? "");
      setRows(edit.lines.map((l) => ({ item_id: String(l.item_id), qty_kg: String(l.qty_kg), price: String(l.price) })));
    } else {
      setPoId(presetPo ?? ""); setNo(""); setDate(new Date().toISOString().slice(0, 10)); setDue(""); setVat("15"); setNotes(""); setRows([]);
    }
  }, [open, edit, presetPo]);

  // what can still be billed on the chosen order
  useEffect(() => {
    if (!open || !poId) { setRec(null); return; }
    apiRequest<Receivable>(`/ap-invoices/receivable?po_id=${poId}`)
      .then((r) => {
        setRec(r);
        if (!edit) setRows(r.lines.filter((l) => l.billable > 0).map((l) => ({ item_id: String(l.item_id), qty_kg: String(l.billable), price: String(l.po_price) })));
      })
      .catch((e) => setError(e.message));
  }, [open, poId, edit]);

  const setRow = (i: number, k: keyof Row, v: string) => setRows((s) => s.map((r, j) => (j === i ? { ...r, [k]: v } : r)));
  const subtotal = rows.reduce((s, r) => s + (Number(r.qty_kg) || 0) * (Number(r.price) || 0), 0);
  const vatAmt = Math.round(subtotal * (Number(vat) || 0)) / 100;

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true); setError("");
    try {
      const body = {
        po_id: Number(poId), supplier_invoice_no: no, invoice_date: date, due_date: due || undefined, vat_rate_pct: Number(vat), notes: notes || undefined,
        lines: rows.map((r) => ({ item_id: Number(r.item_id), qty_kg: Number(r.qty_kg), price: Number(r.price) })),
      };
      const inv = edit ? await put<{ id: number }>(`/ap-invoices/${edit.id}`, body) : await post<{ id: number }>("/ap-invoices", body);
      toast.success(isRTL ? "تم حفظ الفاتورة" : "Invoice saved");
      onSaved(inv.id);
    } catch (err: any) { setError(err.message); } finally { setBusy(false); }
  };

  const pos = [...(approved.data ?? []), ...(received.data ?? [])];
  const info = (itemId: string) => rec?.lines.find((l) => String(l.item_id) === itemId);

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
        <form onSubmit={submit} className="space-y-4">
          <DialogHeader><DialogTitle>{edit ? (isRTL ? "تعديل فاتورة" : "Edit invoice") : isRTL ? "فاتورة مورد جديدة" : "New supplier invoice"}</DialogTitle></DialogHeader>
          <ErrorBox message={error} />
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Field id="iv_po" label={isRTL ? "أمر الشراء" : "Purchase order"} className="sm:col-span-2">
              <select id="iv_po" className={selectClass} required disabled={!!edit} value={poId} onChange={(e) => setPoId(e.target.value)}>
                <option value="">{isRTL ? "اختر…" : "Choose…"}</option>
                {pos.map((p) => <option key={p.id} value={p.id}>{p.number} · {p.supplier_name}</option>)}
                {edit && <option value={edit.purchase_order_id}>{edit.po_number} · {edit.supplier_name}</option>}
              </select>
            </Field>
            <Field id="iv_no" label={isRTL ? "رقم فاتورة المورد" : "Supplier's invoice no."}><Input id="iv_no" dir="ltr" required value={no} onChange={(e) => setNo(e.target.value)} /></Field>
            <Field id="iv_vat" label={isRTL ? "نسبة الضريبة % (٠ للمعفى)" : "VAT % (0 for exempt)"}><Input id="iv_vat" type="number" step="any" min="0" max="100" dir="ltr" required value={vat} onChange={(e) => setVat(e.target.value)} /></Field>
            <Field id="iv_date" label={isRTL ? "تاريخ الفاتورة" : "Invoice date"}><Input id="iv_date" type="date" required value={date} onChange={(e) => setDate(e.target.value)} /></Field>
            <Field id="iv_due" label={isRTL ? "تاريخ الاستحقاق (تلقائي إن تُرك)" : "Due date (automatic if empty)"}><Input id="iv_due" type="date" value={due} onChange={(e) => setDue(e.target.value)} /></Field>
          </div>
          <div className="space-y-2">
            <p className="text-sm font-medium">{isRTL ? "البنود (بالكيلو)" : "Lines (kg)"}</p>
            {rows.map((r, i) => {
              const x = info(r.item_id);
              return (
                <div key={i} className="grid grid-cols-[1fr_90px_90px_32px] gap-2 items-center">
                  <div>
                    <select aria-label={`inv item ${i + 1}`} className={selectClass} required value={r.item_id} onChange={(e) => setRow(i, "item_id", e.target.value)}>
                      <option value="">{isRTL ? "صنف…" : "Item…"}</option>
                      {rec?.lines.map((l) => <option key={l.item_id} value={l.item_id}>{nm(isRTL, l.item_name, l.item_name_ar)}</option>)}
                    </select>
                    {x && <p className="text-[11px] text-muted-foreground mt-0.5" dir="ltr">ordered {fmtKg(x.ordered)} · received {fmtKg(x.received)} · billable {fmtKg(x.billable)} kg @ {x.po_price}</p>}
                  </div>
                  <Input aria-label={`inv qty ${i + 1}`} type="number" step="any" min="0" dir="ltr" required placeholder="kg" value={r.qty_kg} onChange={(e) => setRow(i, "qty_kg", e.target.value)} />
                  <Input aria-label={`inv price ${i + 1}`} type="number" step="any" min="0" dir="ltr" required placeholder="SAR/kg" value={r.price} onChange={(e) => setRow(i, "price", e.target.value)} />
                  <Button type="button" variant="ghost" size="sm" disabled={rows.length === 1} onClick={() => setRows((s) => s.filter((_, j) => j !== i))} aria-label="remove line"><Trash2 className="h-4 w-4" /></Button>
                </div>
              );
            })}
            {poId && rows.length === 0 && <p className="text-sm text-muted-foreground">{isRTL ? "لا توجد كميات مستلمة وغير مُفوتَرة على هذا الأمر." : "Nothing received and not yet invoiced on this order."}</p>}
            <div className="text-sm text-end space-y-0.5" dir="ltr">
              <p>{isRTL ? "قبل الضريبة" : "Subtotal"}: <b>{subtotal.toFixed(2)}</b> · VAT: <b>{vatAmt.toFixed(2)}</b> · {isRTL ? "الإجمالي" : "Total"}: <b>{(subtotal + vatAmt).toFixed(2)}</b></p>
            </div>
          </div>
          <Field id="iv_notes" label={isRTL ? "ملاحظات" : "Notes"}><Input id="iv_notes" value={notes} onChange={(e) => setNotes(e.target.value)} /></Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>{isRTL ? "إلغاء" : "Cancel"}</Button>
            <Button type="submit" disabled={busy || rows.length === 0}>{busy ? "…" : isRTL ? "حفظ" : "Save"}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
