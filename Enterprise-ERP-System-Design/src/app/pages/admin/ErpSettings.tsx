import { useMemo, useState } from "react";
import { toast } from "sonner";
import { Card } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";
import { useApi, put } from "../../hooks/useApi";
import { ErrorBox } from "../../components/inventory/common";

interface S { setting_key: string; value: unknown; value_type: "string" | "int" | "decimal" | "bool" | "json"; group_name: string; label_en: string | null; label_ar: string | null }
const GROUPS: Record<string, [string, string]> = {
  workflow: ["Approvals", "الاعتمادات"], sales: ["Sales", "المبيعات"], market: ["Market buying and cash custody", "الشراء من السوق والعهدة النقدية"], fleet: ["Trucks and gates", "الأسطول والبوابات"],
  receiving: ["Receiving", "الاستلام"], sorting: ["Sorting", "الفرز"], packing: ["Packing", "التعبئة"], production: ["Preparation and food safety", "التجهيز وسلامة الغذاء"],
  stock: ["Stock and cold rooms", "المخزون والتبريد"], purchasing: ["Purchasing", "المشتريات"], finance: ["Finance", "المالية"], hr: ["Human resources", "الموارد البشرية"], zatca: ["E-invoice (ZATCA)", "الفاتورة الإلكترونية"], it: ["IT support", "الدعم التقني"],
};
const ORDER = Object.keys(GROUPS);

/** Every rule of the system that is a setting, in one place, grouped by area. Saving a group changes the system immediately. */
export function ErpSettings() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const q = useApi<S[]>("/settings");
  const [draft, setDraft] = useState<Record<string, unknown>>({});
  const [open, setOpen] = useState<string | null>("sorting");
  const [saving, setSaving] = useState(false);
  const canEdit = can("admin", "manage");
  const groups = useMemo(() => {
    const m: Record<string, S[]> = {};
    for (const s of q.data ?? []) (m[s.group_name] ??= []).push(s);
    return Object.keys(m).sort((a, b) => (ORDER.indexOf(a) === -1 ? 99 : ORDER.indexOf(a)) - (ORDER.indexOf(b) === -1 ? 99 : ORDER.indexOf(b))).map((g) => [g, m[g]] as const);
  }, [q.data]);
  const val = (s: S) => (s.setting_key in draft ? draft[s.setting_key] : s.value);
  const dirty = (g: string) => (q.data ?? []).filter((s) => s.group_name === g && s.setting_key in draft && draft[s.setting_key] !== s.value);

  const save = async (g: string) => {
    const body: Record<string, unknown> = {};
    for (const s of dirty(g)) body[s.setting_key] = draft[s.setting_key];
    if (!Object.keys(body).length) return;
    setSaving(true);
    try { await put("/settings", body); toast.success(T("Saved: the system now follows these settings", "تم الحفظ: النظام يتبع هذه الإعدادات الآن")); setDraft((d) => { const c = { ...d }; for (const k of Object.keys(body)) delete c[k]; return c; }); await q.reload(); }
    catch (e: any) { toast.error(e.message); } finally { setSaving(false); }
  };

  return (
    <div className="space-y-6">
      <div><h1 className="text-3xl font-bold">{T("System settings", "إعدادات النظام")}</h1><p className="text-muted-foreground mt-1">{T("Every rule that is a choice (limits, switches, defaults) is here. Open an area, change what you need and save.", "كل قاعدة هي اختيار (حدود ومفاتيح وقيم افتراضية) موجودة هنا. افتح القسم وغيّر ما تريد واحفظ.")}</p></div>
      {q.error && <ErrorBox message={q.error} />}
      {groups.map(([g, items]) => (
        <Card key={g} className="p-0 overflow-hidden" data-testid={`group-${g}`}>
          <button className="w-full flex items-center justify-between px-5 py-4 text-start hover:bg-muted/50" onClick={() => setOpen(open === g ? null : g)} aria-expanded={open === g}>
            <span className="font-semibold">{GROUPS[g] ? T(GROUPS[g][0], GROUPS[g][1]) : g}<span className="text-xs text-muted-foreground ms-2">{items.length}</span></span>
            <span>{open === g ? "−" : "+"}</span>
          </button>
          {open === g && (
            <div className="px-5 pb-5 space-y-4 border-t pt-4">
              {items.map((s) => (
                <div key={s.setting_key} className="grid sm:grid-cols-[1fr_220px] gap-3 items-center">
                  <div><div className="text-sm">{isRTL && s.label_ar ? s.label_ar : s.label_en ?? s.setting_key}</div><div className="text-[11px] text-muted-foreground font-mono" dir="ltr">{s.setting_key}</div></div>
                  {s.value_type === "bool" ? (
                    <label className="inline-flex items-center gap-2"><input type="checkbox" data-testid={`set-${s.setting_key}`} disabled={!canEdit} checked={!!val(s)} onChange={(e) => setDraft((d) => ({ ...d, [s.setting_key]: e.target.checked }))} /><span className="text-sm">{val(s) ? T("On", "مفعّل") : T("Off", "مُطفأ")}</span></label>
                  ) : s.value_type === "json" ? (
                    <span className="text-xs text-muted-foreground">JSON</span>
                  ) : (
                    <Input data-testid={`set-${s.setting_key}`} dir="ltr" disabled={!canEdit} type={s.value_type === "int" || s.value_type === "decimal" ? "number" : "text"} step={s.value_type === "decimal" ? "0.01" : undefined} value={String(val(s) ?? "")}
                      onChange={(e) => setDraft((d) => ({ ...d, [s.setting_key]: s.value_type === "int" || s.value_type === "decimal" ? (e.target.value === "" ? "" : Number(e.target.value)) : e.target.value }))} />
                  )}
                </div>
              ))}
              {canEdit && <div className="pt-2"><Button disabled={saving || dirty(g).length === 0} data-testid={`save-${g}`} onClick={() => save(g)}>{T("Save", "حفظ")} {dirty(g).length > 0 ? `(${dirty(g).length})` : ""}</Button></div>}
            </div>
          )}
        </Card>
      ))}
    </div>
  );
}
