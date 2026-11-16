import { useState } from "react";
import { useSearchParams } from "react-router";
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
import { useApi, post, put } from "../../hooks/useApi";
import { ItNav, ASSET_TYPE, ASSET_STATUS } from "../../components/it/ItNav";
import { ErrorBox, Field, selectClass } from "../../components/inventory/common";

interface Asset { id: number; code: string; name: string; type: string; brand: string | null; model: string | null; serial_no: string | null; location: string | null; status: string; purchase_date: string | null; warranty_end: string | null; cost: string | null; notes: string | null }
interface Warn { id: number; days_left: number }
const empty = { id: 0, code: "", name: "", type: "laptop", brand: "", model: "", serial_no: "", location: "", status: "In_Use", purchase_date: "", warranty_end: "", cost: "" };

/** The IT asset register: laptops, phones, scanners, label printers, scales, cold-room sensors, network gear, with warranty alerts. */
export function Assets() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const [sp] = useSearchParams();
  const onlyWarranty = sp.get("warranty") === "1";
  const [type, setType] = useState("");
  const q = useApi<Asset[]>(`/it-assets${type ? `?type=${type}` : ""}`);
  const warn = useApi<Warn[]>("/it-assets/expiring");
  const [dlg, setDlg] = useState<typeof empty | null>(null);
  const [busy, setBusy] = useState(false);
  const canEdit = can("it-assets", "edit") || can("it-assets", "create");
  const align = isRTL ? "text-right" : "text-left";
  const wmap = new Map((Array.isArray(warn.data) ? warn.data : []).map((w) => [w.id, w.days_left]));
  let rows = Array.isArray(q.data) ? q.data : [];
  if (onlyWarranty) rows = rows.filter((a) => wmap.has(a.id));

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    setBusy(true);
    const { id, ...f } = dlg;
    const body: Record<string, unknown> = { ...f, cost: f.cost === "" ? null : Number(f.cost), purchase_date: f.purchase_date || null, warranty_end: f.warranty_end || null };
    try { if (id) await put(`/it-assets/${id}`, body); else await post("/it-assets", body); toast.success(T("Saved", "تم الحفظ")); setDlg(null); await Promise.all([q.reload(), warn.reload()]); }
    catch (err: any) { toast.error(err.message); } finally { setBusy(false); }
  };
  const set = (k: keyof typeof empty, v: string) => dlg && setDlg({ ...dlg, [k]: v });

  return (
    <div className="space-y-6">
      <ItNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div><h1 className="text-3xl font-bold">{T("IT assets", "الأجهزة والأصول التقنية")}</h1><p className="text-muted-foreground mt-1">{T("Every device the factory runs on, where it is, and when its warranty ends. The warning window is a setting.", "كل جهاز يعمل عليه المصنع، وأين هو، ومتى ينتهي ضمانه. مدة التنبيه إعداد قابل للتغيير.")}</p></div>
        {canEdit && <Button className="gap-2" data-testid="new-asset" onClick={() => setDlg({ ...empty })}><Plus className="h-4 w-4" />{T("Add device", "إضافة جهاز")}</Button>}
      </div>
      {(q.error || warn.error) && <ErrorBox message={q.error || warn.error} />}
      <div className="flex gap-2 flex-wrap">
        <Button size="sm" variant={type === "" ? "default" : "outline"} onClick={() => setType("")}>{T("All", "الكل")}</Button>
        {Object.entries(ASSET_TYPE).map(([k, v]) => <Button key={k} size="sm" variant={type === k ? "default" : "outline"} onClick={() => setType(k)}>{v[isRTL ? 1 : 0]}</Button>)}
      </div>
      <Card className="p-6" data-testid="asset-list">
        <Table>
          <TableHeader><TableRow><TableHead className={align}>{T("Code", "الكود")}</TableHead><TableHead className={align}>{T("Device", "الجهاز")}</TableHead><TableHead className={align}>{T("Location", "المكان")}</TableHead><TableHead className={align}>{T("Status", "الحالة")}</TableHead><TableHead className={align}>{T("Warranty ends", "ينتهي الضمان")}</TableHead><TableHead /></TableRow></TableHeader>
          <TableBody>
            {rows.map((a) => (
              <TableRow key={a.id} data-testid={`asset-${a.code}`}>
                <TableCell dir="ltr">{a.code}</TableCell>
                <TableCell className="font-medium">{a.name}<div className="text-xs text-muted-foreground">{ASSET_TYPE[a.type]?.[isRTL ? 1 : 0]}{a.brand ? ` · ${a.brand}` : ""}{a.model ? ` ${a.model}` : ""}{a.serial_no ? ` · S/N ${a.serial_no}` : ""}</div></TableCell>
                <TableCell>{a.location ?? "—"}</TableCell>
                <TableCell><Badge variant="outline">{ASSET_STATUS[a.status]?.[isRTL ? 1 : 0] ?? a.status}</Badge></TableCell>
                <TableCell dir="ltr">{a.warranty_end ?? "—"} {wmap.has(a.id) && <Badge className={`ms-1 ${wmap.get(a.id)! < 0 ? "bg-red-600 hover:bg-red-600" : "bg-amber-500 hover:bg-amber-500"}`}>{wmap.get(a.id)! < 0 ? T("expired", "منتهي") : `${wmap.get(a.id)} ${T("d", "يوم")}`}</Badge>}</TableCell>
                <TableCell>{canEdit && <Button size="sm" variant="outline" data-testid={`edit-${a.code}`} onClick={() => setDlg({ id: a.id, code: a.code, name: a.name, type: a.type, brand: a.brand ?? "", model: a.model ?? "", serial_no: a.serial_no ?? "", location: a.location ?? "", status: a.status, purchase_date: a.purchase_date ?? "", warranty_end: a.warranty_end ?? "", cost: a.cost ?? "" })}>{T("Edit", "تعديل")}</Button>}</TableCell>
              </TableRow>
            ))}
            {q.data && rows.length === 0 && <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">{T("No devices", "لا توجد أجهزة")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent>
          <DialogHeader><DialogTitle>{dlg?.id ? T("Edit device", "تعديل جهاز") : T("Add device", "إضافة جهاز")}</DialogTitle></DialogHeader>
          {dlg && (
            <form onSubmit={save} className="space-y-3" data-testid="asset-form">
              <div className="grid grid-cols-2 gap-3">
                <Field id="as_code" label={T("Code", "الكود")}><Input id="as_code" dir="ltr" required value={dlg.code} disabled={!!dlg.id} onChange={(e) => set("code", e.target.value)} /></Field>
                <Field id="as_type" label={T("Type", "النوع")}><select id="as_type" className={selectClass} value={dlg.type} onChange={(e) => set("type", e.target.value)}>{Object.entries(ASSET_TYPE).map(([k, v]) => <option key={k} value={k}>{v[isRTL ? 1 : 0]}</option>)}</select></Field>
              </div>
              <Field id="as_name" label={T("Name", "الاسم")}><Input id="as_name" required value={dlg.name} onChange={(e) => set("name", e.target.value)} /></Field>
              <div className="grid grid-cols-2 gap-3">
                <Field id="as_brand" label={T("Brand", "الماركة")}><Input id="as_brand" value={dlg.brand} onChange={(e) => set("brand", e.target.value)} /></Field>
                <Field id="as_model" label={T("Model", "الموديل")}><Input id="as_model" value={dlg.model} onChange={(e) => set("model", e.target.value)} /></Field>
                <Field id="as_sn" label={T("Serial number", "الرقم التسلسلي")}><Input id="as_sn" dir="ltr" value={dlg.serial_no} onChange={(e) => set("serial_no", e.target.value)} /></Field>
                <Field id="as_loc" label={T("Location", "المكان")}><Input id="as_loc" value={dlg.location} onChange={(e) => set("location", e.target.value)} /></Field>
                <Field id="as_buy" label={T("Purchase date", "تاريخ الشراء")}><Input id="as_buy" type="date" value={dlg.purchase_date} onChange={(e) => set("purchase_date", e.target.value)} /></Field>
                <Field id="as_war" label={T("Warranty ends", "نهاية الضمان")}><Input id="as_war" type="date" value={dlg.warranty_end} onChange={(e) => set("warranty_end", e.target.value)} /></Field>
                <Field id="as_cost" label={T("Cost", "التكلفة")}><Input id="as_cost" type="number" min="0" step="0.01" dir="ltr" value={dlg.cost} onChange={(e) => set("cost", e.target.value)} /></Field>
                <Field id="as_st" label={T("Status", "الحالة")}><select id="as_st" className={selectClass} value={dlg.status} onChange={(e) => set("status", e.target.value)}>{Object.entries(ASSET_STATUS).map(([k, v]) => <option key={k} value={k}>{v[isRTL ? 1 : 0]}</option>)}</select></Field>
              </div>
              <DialogFooter><Button type="button" variant="outline" onClick={() => setDlg(null)}>{T("Cancel", "إلغاء")}</Button><Button type="submit" disabled={busy} data-testid="asset-save">{T("Save", "حفظ")}</Button></DialogFooter>
            </form>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
