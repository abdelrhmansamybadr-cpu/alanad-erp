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
import { useApi, post, put } from "../../hooks/useApi";
import { ProcurementNav } from "../../components/procurement/common";
import { ErrorBox, Field, selectClass, fmtKg } from "../../components/inventory/common";

interface Vehicle { id: number; plate: string; kind: "own" | "rented"; rental_company: string | null; capacity_kg: number | null; refrigerated: number | boolean; default_driver: string | null; is_active: number | boolean; busy: boolean; open_permit: string | null; open_delivery: string | null }
interface Gate { id: number; code: string; name: string; name_ar: string | null; branch_id: number | null; is_active: number | boolean }

/** The truck fleet (own and rented) and the gates of each branch. */
export function FleetGates() {
  const { isRTL, } = useI18n();
  const { can, me } = useAuth();
  const vehicles = useApi<Vehicle[]>("/vehicles");
  const gates = useApi<Gate[]>("/gates");
  const [vd, setVd] = useState<Record<string, string> | null>(null);
  const [gd, setGd] = useState<Record<string, string> | null>(null);
  const [saving, setSaving] = useState(false);
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const align = isRTL ? "text-right" : "text-left";
  const branchName = (id: number | null) => { const b = me?.branches?.find((x) => x.id === id); return b ? (isRTL && b.name_ar ? b.name_ar : b.name_en) : "—"; };

  const saveVehicle = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!vd) return;
    setSaving(true);
    try {
      await post("/vehicles", {
        plate: vd.plate, kind: vd.kind, rental_company: vd.kind === "rented" ? vd.rental_company : null,
        capacity_kg: vd.capacity_kg ? Number(vd.capacity_kg) : null, refrigerated: vd.refrigerated === "1",
        default_driver: vd.default_driver || null, default_driver_id_no: vd.default_driver_id_no || null,
      });
      toast.success(T("Truck added", "أُضيفت الشاحنة")); setVd(null); await vehicles.reload();
    } catch (err: any) { toast.error(err.message); } finally { setSaving(false); }
  };
  const saveGate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!gd) return;
    setSaving(true);
    try {
      await post("/gates", { code: gd.code, name: gd.name, name_ar: gd.name_ar || null, ...(gd.branch_id ? { branch_id: Number(gd.branch_id) } : {}) });
      toast.success(T("Gate added", "أُضيفت البوابة")); setGd(null); await gates.reload();
    } catch (err: any) { toast.error(err.message); } finally { setSaving(false); }
  };
  const toggle = async (path: string, on: boolean, reload: () => Promise<void>) => {
    try { await put(path, { is_active: on ? 1 : 0 }); await reload(); } catch (err: any) { toast.error(err.message); }
  };

  return (
    <div className="space-y-6">
      <ProcurementNav />
      <div>
        <h1 className="text-3xl font-bold">{T("Fleet & gates", "الأسطول والبوابات")}</h1>
        <p className="text-muted-foreground mt-1">{T("Own trucks first; when they are all busy, hire a rented one. Each branch has its gates.", "الشاحنات الخاصة أولاً، وإذا كانت كلها مشغولة تُستأجر شاحنة. ولكل فرع بواباته.")}</p>
      </div>
      {(vehicles.error || gates.error) && <ErrorBox message={vehicles.error || gates.error} />}

      <Card className="p-6">
        <div className="flex items-center justify-between mb-3">
          <h2 className="font-semibold">{T("Trucks", "الشاحنات")}</h2>
          {can("vehicles", "create") && <Button size="sm" className="gap-1" data-testid="add-vehicle" onClick={() => setVd({ plate: "", kind: "own", rental_company: "", capacity_kg: "", refrigerated: "1", default_driver: "", default_driver_id_no: "" })}><Plus className="h-4 w-4" />{T("Add truck", "إضافة شاحنة")}</Button>}
        </div>
        <Table>
          <TableHeader><TableRow>
            <TableHead className={align}>{T("Plate", "اللوحة")}</TableHead><TableHead className={align}>{T("Kind", "النوع")}</TableHead>
            <TableHead className={align}>{T("Capacity", "السعة")}</TableHead><TableHead className={align}>{T("Driver", "السائق")}</TableHead>
            <TableHead className={align}>{T("Now", "الآن")}</TableHead><TableHead />
          </TableRow></TableHeader>
          <TableBody>
            {vehicles.data?.map((v) => (
              <TableRow key={v.id}>
                <TableCell className="font-medium" dir="ltr">{v.plate}</TableCell>
                <TableCell>{v.kind === "own" ? T("Own", "ملك الشركة") : `${T("Rented", "مستأجرة")} · ${v.rental_company ?? ""}`}{Number(v.refrigerated) ? " ❄" : ""}</TableCell>
                <TableCell>{v.capacity_kg ? `${fmtKg(v.capacity_kg)} kg` : "—"}</TableCell>
                <TableCell>{v.default_driver ?? "—"}</TableCell>
                <TableCell>{Number(v.is_active) !== 1 ? <Badge variant="secondary">{T("Disabled", "موقوفة")}</Badge> : v.busy ? <Badge className="bg-amber-500 hover:bg-amber-500">{T("Busy", "مشغولة")} {v.open_permit ?? v.open_delivery}</Badge> : <Badge className="bg-emerald-600 hover:bg-emerald-600">{T("Free", "متاحة")}</Badge>}</TableCell>
                <TableCell>{can("vehicles", "edit") && <Button size="sm" variant="ghost" onClick={() => toggle(`/vehicles/${v.id}`, Number(v.is_active) !== 1, vehicles.reload)}>{Number(v.is_active) === 1 ? T("Disable", "إيقاف") : T("Enable", "تفعيل")}</Button>}</TableCell>
              </TableRow>
            ))}
            {vehicles.data && vehicles.data.length === 0 && <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">{T("No trucks yet", "لا توجد شاحنات")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      <Card className="p-6">
        <div className="flex items-center justify-between mb-3">
          <h2 className="font-semibold">{T("Gates", "البوابات")}</h2>
          {can("gates", "create") && <Button size="sm" className="gap-1" data-testid="add-gate" onClick={() => setGd({ code: "", name: "", name_ar: "", branch_id: "" })}><Plus className="h-4 w-4" />{T("Add gate", "إضافة بوابة")}</Button>}
        </div>
        <Table>
          <TableHeader><TableRow>
            <TableHead className={align}>{T("Code", "الرمز")}</TableHead><TableHead className={align}>{T("Name", "الاسم")}</TableHead>
            <TableHead className={align}>{T("Branch", "الفرع")}</TableHead><TableHead className={align}>{T("Status", "الحالة")}</TableHead><TableHead />
          </TableRow></TableHeader>
          <TableBody>
            {gates.data?.map((g) => (
              <TableRow key={g.id}>
                <TableCell className="font-mono">{g.code}</TableCell><TableCell className="font-medium">{isRTL && g.name_ar ? g.name_ar : g.name}</TableCell>
                <TableCell>{branchName(g.branch_id)}</TableCell>
                <TableCell><Badge variant={Number(g.is_active) === 1 ? "default" : "secondary"}>{Number(g.is_active) === 1 ? T("Open", "مفتوحة") : T("Closed", "مغلقة")}</Badge></TableCell>
                <TableCell>{can("gates", "edit") && <Button size="sm" variant="ghost" onClick={() => toggle(`/gates/${g.id}`, Number(g.is_active) !== 1, gates.reload)}>{Number(g.is_active) === 1 ? T("Close", "إغلاق") : T("Open", "فتح")}</Button>}</TableCell>
              </TableRow>
            ))}
            {gates.data && gates.data.length === 0 && <TableRow><TableCell colSpan={5} className="text-center text-muted-foreground">{T("No gates yet", "لا توجد بوابات")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={!!vd} onOpenChange={(o) => !o && setVd(null)}>
        <DialogContent>
          <form onSubmit={saveVehicle} className="space-y-3">
            <DialogHeader><DialogTitle>{T("New truck", "شاحنة جديدة")}</DialogTitle></DialogHeader>
            <div className="grid grid-cols-2 gap-3">
              <Field id="v_plate" label={T("Plate", "اللوحة")}><Input id="v_plate" dir="ltr" required value={vd?.plate ?? ""} onChange={(e) => setVd((s) => s && { ...s, plate: e.target.value })} /></Field>
              <Field id="v_kind" label={T("Kind", "النوع")}>
                <select id="v_kind" className={selectClass} value={vd?.kind} onChange={(e) => setVd((s) => s && { ...s, kind: e.target.value })}>
                  <option value="own">{T("Own", "ملك الشركة")}</option><option value="rented">{T("Rented", "مستأجرة")}</option>
                </select>
              </Field>
              {vd?.kind === "rented" && <Field id="v_rent" label={T("Rental company", "شركة التأجير")} className="col-span-2"><Input id="v_rent" required value={vd.rental_company} onChange={(e) => setVd((s) => s && { ...s, rental_company: e.target.value })} /></Field>}
              <Field id="v_cap" label={T("Capacity (kg)", "السعة (كجم)")}><Input id="v_cap" type="number" dir="ltr" value={vd?.capacity_kg ?? ""} onChange={(e) => setVd((s) => s && { ...s, capacity_kg: e.target.value })} /></Field>
              <Field id="v_ref" label={T("Refrigerated", "مبرّدة")}>
                <select id="v_ref" className={selectClass} value={vd?.refrigerated} onChange={(e) => setVd((s) => s && { ...s, refrigerated: e.target.value })}><option value="1">{T("Yes", "نعم")}</option><option value="0">{T("No", "لا")}</option></select>
              </Field>
              <Field id="v_drv" label={T("Usual driver", "السائق المعتاد")}><Input id="v_drv" value={vd?.default_driver ?? ""} onChange={(e) => setVd((s) => s && { ...s, default_driver: e.target.value })} /></Field>
              <Field id="v_drvid" label={T("Driver ID no.", "رقم هوية السائق")}><Input id="v_drvid" dir="ltr" value={vd?.default_driver_id_no ?? ""} onChange={(e) => setVd((s) => s && { ...s, default_driver_id_no: e.target.value })} /></Field>
            </div>
            <DialogFooter><Button type="button" variant="outline" onClick={() => setVd(null)}>{T("Cancel", "إلغاء")}</Button><Button type="submit" disabled={saving}>{T("Save", "حفظ")}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!gd} onOpenChange={(o) => !o && setGd(null)}>
        <DialogContent>
          <form onSubmit={saveGate} className="space-y-3">
            <DialogHeader><DialogTitle>{T("New gate", "بوابة جديدة")}</DialogTitle></DialogHeader>
            <div className="grid grid-cols-2 gap-3">
              <Field id="g_code" label={T("Code", "الرمز")}><Input id="g_code" dir="ltr" required value={gd?.code ?? ""} onChange={(e) => setGd((s) => s && { ...s, code: e.target.value })} /></Field>
              <Field id="g_branch" label={T("Branch", "الفرع")}>
                <select id="g_branch" className={selectClass} value={gd?.branch_id} onChange={(e) => setGd((s) => s && { ...s, branch_id: e.target.value })}>
                  <option value="">{T("Main / current", "الرئيسي / الحالي")}</option>
                  {me?.branches?.map((b) => <option key={b.id} value={b.id}>{isRTL && b.name_ar ? b.name_ar : b.name_en}</option>)}
                </select>
              </Field>
              <Field id="g_name" label={T("Name (English)", "الاسم (إنجليزي)")}><Input id="g_name" required value={gd?.name ?? ""} onChange={(e) => setGd((s) => s && { ...s, name: e.target.value })} /></Field>
              <Field id="g_name_ar" label={T("Name (Arabic)", "الاسم (عربي)")}><Input id="g_name_ar" value={gd?.name_ar ?? ""} onChange={(e) => setGd((s) => s && { ...s, name_ar: e.target.value })} /></Field>
            </div>
            <DialogFooter><Button type="button" variant="outline" onClick={() => setGd(null)}>{T("Cancel", "إلغاء")}</Button><Button type="submit" disabled={saving}>{T("Save", "حفظ")}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
