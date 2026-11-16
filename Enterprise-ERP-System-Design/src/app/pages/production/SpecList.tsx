import { useState } from "react";
import { toast } from "sonner";
import { Plus, Pencil } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { useApi, post, put } from "../../hooks/useApi";
import { ErrorBox, Field, selectClass, Item, nm } from "../../components/inventory/common";
import { ProductionNav, Spec } from "../../components/production/common";

type Form = Record<string, string>;
const EMPTY: Form = { code: "", name_en: "", name_ar: "", input_item_id: "", output_item_id: "", byproduct_item_id: "", planned_yield_pct: "85", yield_tolerance_pct: "3", waste_high_pct: "20", max_minutes_outside: "45", requires_wash: "1" };

export function SpecList() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const specs = useApi<Spec[]>("/prep-specs");
  const items = useApi<Item[]>("/items");
  const [edit, setEdit] = useState<{ id: number | null; f: Form } | null>(null);
  const [error, setError] = useState("");
  const align = isRTL ? "text-right" : "text-left";

  const open = (s?: Spec) =>
    setEdit({ id: s?.id ?? null, f: s ? Object.fromEntries(Object.keys(EMPTY).map((k) => [k, String((s as any)[k] ?? "")])) : { ...EMPTY } });
  const set = (k: string, v: string) => setEdit((e) => e && { ...e, f: { ...e.f, [k]: v } });

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!edit) return;
    setError("");
    const f = edit.f;
    const body = {
      code: f.code, name_en: f.name_en, name_ar: f.name_ar || undefined, input_item_id: Number(f.input_item_id), output_item_id: Number(f.output_item_id), byproduct_item_id: f.byproduct_item_id ? Number(f.byproduct_item_id) : null,
      planned_yield_pct: Number(f.planned_yield_pct), yield_tolerance_pct: Number(f.yield_tolerance_pct), waste_high_pct: Number(f.waste_high_pct), max_minutes_outside: Number(f.max_minutes_outside), requires_wash: f.requires_wash === "1",
    };
    try {
      if (edit.id) await put(`/prep-specs/${edit.id}`, body); else await post("/prep-specs", body);
      toast.success(isRTL ? "تم الحفظ" : "Saved");
      setEdit(null); specs.reload();
    } catch (err: any) { setError(err.message); }
  };
  const toggle = async (s: Spec) => {
    try { await put(`/prep-specs/${s.id}`, { is_active: Number(s.is_active) !== 1 }); specs.reload(); } catch (err: any) { setError(err.message); }
  };
  const itemSelect = (id: string, key: string, label: string, required: boolean) => (
    <Field id={id} label={label}>
      <select id={id} className={selectClass} required={required} value={edit?.f[key] ?? ""} onChange={(e) => set(key, e.target.value)}>
        <option value="">{required ? (isRTL ? "اختر…" : "Choose…") : "—"}</option>
        {items.data?.map((i) => <option key={i.id} value={i.id}>{nm(isRTL, i.name, i.name_ar)}</option>)}
      </select>
    </Field>
  );
  const num = (id: string, key: string, label: string) => (
    <Field id={id} label={label}><Input id={id} type="number" step="any" min="0" dir="ltr" required value={edit?.f[key] ?? ""} onChange={(e) => set(key, e.target.value)} /></Field>
  );

  return (
    <div className="space-y-6">
      <ProductionNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "مواصفات التجهيز" : "Preparation Specifications"}</h1>
          <p className="text-muted-foreground mt-1">{isRTL ? "لكل منتج: الخامة والمنتج الجاهز، الإنتاجية المخططة، حد الهالك، والحد الأقصى للوقت خارج غرفة التبريد." : "Per product: raw material and finished product, planned yield, the waste limit and the maximum time outside the cold room."}</p>
        </div>
        {can("prep-specs", "create") && <Button className="gap-2" onClick={() => open()}><Plus className="h-4 w-4" />{isRTL ? "مواصفة جديدة" : "New specification"}</Button>}
      </div>
      <ErrorBox message={specs.error || error} />
      <Card className="p-6 overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className={align}>{isRTL ? "الكود" : "Code"}</TableHead>
              <TableHead className={align}>{isRTL ? "الاسم" : "Name"}</TableHead>
              <TableHead className={align}>{isRTL ? "الخامة ← المنتج" : "Raw → Finished"}</TableHead>
              <TableHead className={align}>{isRTL ? "الإنتاجية" : "Yield"}</TableHead>
              <TableHead className={align}>{isRTL ? "حد الهالك" : "Waste limit"}</TableHead>
              <TableHead className={align}>{isRTL ? "دقائق خارج التبريد" : "Min. outside"}</TableHead>
              <TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {specs.data?.map((s) => (
              <TableRow key={s.id} className={Number(s.is_active) !== 1 ? "opacity-50" : ""}>
                <TableCell className="font-mono text-sm">{s.code}</TableCell>
                <TableCell className="font-medium">{nm(isRTL, s.name_en, s.name_ar)}</TableCell>
                <TableCell className="text-sm">{nm(isRTL, s.input_item_name, s.input_item_name_ar)} → {nm(isRTL, s.output_item_name, s.output_item_name_ar)}</TableCell>
                <TableCell dir="ltr" className="font-mono text-sm">{Number(s.planned_yield_pct)}% ±{Number(s.yield_tolerance_pct)}</TableCell>
                <TableCell dir="ltr" className="font-mono text-sm">{Number(s.waste_high_pct)}%</TableCell>
                <TableCell className="font-mono text-sm">{s.max_minutes_outside}</TableCell>
                <TableCell><Badge variant={Number(s.is_active) === 1 ? "default" : "secondary"}>{Number(s.is_active) === 1 ? (isRTL ? "نشطة" : "Active") : isRTL ? "موقوفة" : "Inactive"}</Badge></TableCell>
                <TableCell className="whitespace-nowrap">
                  {can("prep-specs", "edit") && (<><Button variant="ghost" size="sm" onClick={() => open(s)} aria-label="edit"><Pencil className="h-4 w-4" /></Button><Button variant="ghost" size="sm" onClick={() => toggle(s)}>{Number(s.is_active) === 1 ? (isRTL ? "إيقاف" : "Disable") : isRTL ? "تفعيل" : "Enable"}</Button></>)}
                </TableCell>
              </TableRow>
            ))}
            {specs.data && specs.data.length === 0 && <TableRow><TableCell colSpan={8} className="text-center text-muted-foreground">{isRTL ? "لا توجد مواصفات" : "No specifications yet"}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={!!edit} onOpenChange={(o) => !o && setEdit(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
          <form onSubmit={save} className="space-y-4">
            <DialogHeader><DialogTitle>{edit?.id ? (isRTL ? "تعديل مواصفة" : "Edit specification") : isRTL ? "مواصفة جديدة" : "New specification"}</DialogTitle></DialogHeader>
            <ErrorBox message={error} />
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <Field id="sp_code" label={isRTL ? "الكود" : "Code"}><Input id="sp_code" dir="ltr" required value={edit?.f.code ?? ""} onChange={(e) => set("code", e.target.value)} /></Field>
              <Field id="sp_en" label={isRTL ? "الاسم (إنجليزي)" : "Name (English)"}><Input id="sp_en" required value={edit?.f.name_en ?? ""} onChange={(e) => set("name_en", e.target.value)} /></Field>
              <Field id="sp_ar" label={isRTL ? "الاسم (عربي)" : "Name (Arabic)"} className="sm:col-span-2"><Input id="sp_ar" value={edit?.f.name_ar ?? ""} onChange={(e) => set("name_ar", e.target.value)} /></Field>
              {itemSelect("sp_in", "input_item_id", isRTL ? "الخامة (المدخل)" : "Raw material (input)", true)}
              {itemSelect("sp_out", "output_item_id", isRTL ? "المنتج الجاهز (المخرج)" : "Finished product (output)", true)}
              {itemSelect("sp_by", "byproduct_item_id", isRTL ? "منتج ثانوي (اختياري)" : "By-product item (optional)", false)}
              <div />
              {num("sp_y", "planned_yield_pct", isRTL ? "الإنتاجية المخططة %" : "Planned yield %")}
              {num("sp_t", "yield_tolerance_pct", isRTL ? "سماح الإنتاجية %" : "Yield tolerance %")}
              {num("sp_w", "waste_high_pct", isRTL ? "حد الهالك المرتفع %" : "High-waste limit %")}
              {num("sp_m", "max_minutes_outside", isRTL ? "أقصى دقائق خارج التبريد" : "Max minutes outside the cold")}
              <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" checked={edit?.f.requires_wash === "1"} onChange={(e) => set("requires_wash", e.target.checked ? "1" : "0")} />{isRTL ? "يتطلب تسجيل حرارة مياه الغسيل ومستوى المعقّم قبل النتائج" : "Requires wash-water temperature and sanitiser level before the results"}</label>
            </div>
            <DialogFooter><Button type="submit">{isRTL ? "حفظ" : "Save"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
