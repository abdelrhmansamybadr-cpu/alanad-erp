import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Plus, Trash2 } from "lucide-react";
import { Button } from "../ui/button";
import { Input } from "../ui/input";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { post, put } from "../../hooks/useApi";
import { Field, selectClass, Item, nm } from "../inventory/common";
import { PR } from "./common";

interface Row { item_id: string; qty: string; note: string }
export interface PRDraft { id: number | null; needed_by: string; notes: string; lines: Row[] }

export const emptyDraft = (lines: Row[] = [{ item_id: "", qty: "", note: "" }]): PRDraft => ({ id: null, needed_by: "", notes: "", lines });
export const draftFromPR = (pr: PR): PRDraft => ({
  id: pr.id,
  needed_by: pr.needed_by ?? "",
  notes: pr.notes ?? "",
  lines: (pr.lines ?? []).map((l) => ({ item_id: String(l.item_id), qty: String(l.qty), note: l.note ?? "" })),
});

/** Create or edit a purchase request (lines = item + kg). */
export function PRFormDialog({ draft, items, onClose, onSaved }: { draft: PRDraft | null; items: Item[]; onClose: () => void; onSaved: (pr: PR) => void }) {
  const { isRTL } = useI18n();
  const [d, setD] = useState<PRDraft | null>(draft);
  const [busy, setBusy] = useState(false);
  useEffect(() => setD(draft), [draft]);

  const setLine = (i: number, k: keyof Row, v: string) =>
    setD((s) => s && { ...s, lines: s.lines.map((l, j) => (j === i ? { ...l, [k]: v } : l)) });

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!d) return;
    setBusy(true);
    try {
      const body = {
        needed_by: d.needed_by || null,
        notes: d.notes || null,
        lines: d.lines.map((l) => ({ item_id: Number(l.item_id), qty: Number(l.qty), note: l.note || undefined })),
      };
      const pr = d.id ? await put<PR>(`/purchase-requests/${d.id}`, body) : await post<PR>("/purchase-requests", body);
      toast.success(isRTL ? "تم حفظ الطلب" : "Request saved");
      onSaved(pr);
    } catch (err: any) {
      toast.error(err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={!!d} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <form onSubmit={submit} className="space-y-4">
          <DialogHeader>
            <DialogTitle>{d?.id ? (isRTL ? "تعديل طلب شراء" : "Edit purchase request") : isRTL ? "طلب شراء جديد" : "New purchase request"}</DialogTitle>
          </DialogHeader>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Field id="pr_needed" label={isRTL ? "مطلوب بتاريخ" : "Needed by"}>
              <Input id="pr_needed" type="date" value={d?.needed_by ?? ""} onChange={(e) => setD((s) => s && { ...s, needed_by: e.target.value })} />
            </Field>
            <Field id="pr_notes" label={isRTL ? "ملاحظات" : "Notes"}>
              <Input id="pr_notes" value={d?.notes ?? ""} onChange={(e) => setD((s) => s && { ...s, notes: e.target.value })} />
            </Field>
          </div>
          <div className="space-y-2">
            <p className="text-sm font-medium">{isRTL ? "الأصناف (بالكيلو)" : "Items (kg)"}</p>
            {d?.lines.map((l, i) => (
              <div key={i} className="flex gap-2 items-center">
                <select aria-label={`item ${i + 1}`} className={`${selectClass} flex-1`} required value={l.item_id} onChange={(e) => setLine(i, "item_id", e.target.value)}>
                  <option value="">{isRTL ? "اختر صنفاً…" : "Choose an item…"}</option>
                  {items.map((it) => <option key={it.id} value={it.id}>{nm(isRTL, it.name, it.name_ar)}</option>)}
                </select>
                <Input aria-label={`qty ${i + 1}`} className="w-28" type="number" step="any" min="0" dir="ltr" required placeholder="kg" value={l.qty} onChange={(e) => setLine(i, "qty", e.target.value)} />
                <Button type="button" variant="ghost" size="sm" disabled={d.lines.length === 1} onClick={() => setD((s) => s && { ...s, lines: s.lines.filter((_, j) => j !== i) })} aria-label="remove line">
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            ))}
            <Button type="button" variant="outline" size="sm" className="gap-2" onClick={() => setD((s) => s && { ...s, lines: [...s.lines, { item_id: "", qty: "", note: "" }] })}>
              <Plus className="h-4 w-4" />
              {isRTL ? "سطر" : "Line"}
            </Button>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>{isRTL ? "إلغاء" : "Cancel"}</Button>
            <Button type="submit" disabled={busy}>{busy ? "…" : isRTL ? "حفظ" : "Save"}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
