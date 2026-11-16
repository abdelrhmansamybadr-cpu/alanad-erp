import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Plus, Thermometer, AlertTriangle, ShieldAlert } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { apiRequest, tokenStorage } from "../../services/api";
import { useApi, post } from "../../hooks/useApi";
import { InventoryNav } from "../../components/inventory/InventoryNav";
import { ErrorBox, Field, selectClass, ColdRoom, Warehouse, nm } from "../../components/inventory/common";

interface Reading { id: number; temperature: number; humidity: number | null; created_at: string }
interface Alarm { id: number; cold_room_id: number; cold_room_name: string; kind: string; status: string; first_value: number; opened_at: string; temp_min: number; temp_max: number }
interface Hold { id: number; cold_room_name: string | null; batch_no: string | null; reason: string; auto: number | boolean; created_at: string }

const STATUS: Record<string, [string, string, string]> = {
  ok: ["Normal", "سليمة", "bg-emerald-600 hover:bg-emerald-600"],
  alarm: ["Alarm", "إنذار", "bg-amber-500 hover:bg-amber-500"],
  hold: ["On hold", "محجوزة للجودة", "bg-red-600 hover:bg-red-600"],
};
const KIND_AR: Record<string, string> = { temp_high: "حرارة مرتفعة", temp_low: "حرارة منخفضة", humidity: "رطوبة" };
const WH_TYPES: [string, string][] = [["cold", "تلاجة"], ["freezer", "مجمّد"], ["dry", "جاف"], ["receiving", "استلام"], ["dispatch", "شحن"], ["hold", "حجز"]];

function RoomCard({ room, isRTL, canWrite, onChanged }: { room: ColdRoom; isRTL: boolean; canWrite: boolean; onChanged: () => void }) {
  const [last, setLast] = useState<Reading | null>(null);
  const [form, setForm] = useState<{ temperature: string; humidity: string } | null>(null);
  const loadLast = () => apiRequest<Reading[]>(`/cold-rooms/${room.id}/readings?limit=1`).then((r) => setLast(r[0] ?? null)).catch(() => {});
  useEffect(() => { loadLast(); }, [room.id, room.status]);
  const [en, ar, cls] = STATUS[room.status] ?? STATUS.ok;

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form) return;
    try {
      await post(`/cold-rooms/${room.id}/readings`, { temperature: Number(form.temperature), humidity: form.humidity === "" ? undefined : Number(form.humidity) });
      setForm(null);
      toast.success(isRTL ? "تم تسجيل القراءة" : "Reading recorded");
      await loadLast();
      onChanged();
    } catch (err: any) { toast.error(err.message); }
  };

  const outside = last && (Number(last.temperature) < Number(room.temp_min) || Number(last.temperature) > Number(room.temp_max));
  return (
    <Card data-testid="room-card">
      <CardHeader className="pb-2">
        <div className="flex items-center justify-between gap-2">
          <CardTitle className="text-base">{nm(isRTL, room.name, room.name_ar)}</CardTitle>
          <Badge className={cls}>{isRTL ? ar : en}</Badge>
        </div>
        <p className="text-xs text-muted-foreground font-mono">{room.code}</p>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="flex items-end justify-between">
          <div>
            <p className="text-xs text-muted-foreground">{isRTL ? "آخر قراءة" : "Last reading"}</p>
            <p className={`text-3xl font-bold ${outside ? "text-red-600" : ""}`} dir="ltr">{last ? `${Number(last.temperature).toFixed(1)}°` : "—"}</p>
            {last && <p className="text-[11px] text-muted-foreground" dir="ltr">{last.created_at}</p>}
          </div>
          <div className="text-end text-sm">
            <p className="text-xs text-muted-foreground">{isRTL ? "النطاق" : "Range"}</p>
            <p dir="ltr" className="font-mono">{Number(room.temp_min)}° … {Number(room.temp_max)}°</p>
            {room.capacity_kg != null && <p className="text-xs text-muted-foreground" dir="ltr">{Number(room.capacity_kg).toLocaleString()} kg</p>}
          </div>
        </div>
        {canWrite && (
          <Button size="sm" variant="outline" className="gap-2 w-full" onClick={() => setForm({ temperature: "", humidity: "" })}>
            <Thermometer className="h-4 w-4" />
            {isRTL ? "تسجيل قراءة" : "Record reading"}
          </Button>
        )}
      </CardContent>
      <Dialog open={!!form} onOpenChange={(o) => !o && setForm(null)}>
        <DialogContent>
          <form onSubmit={submit} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? `قراءة: ${nm(isRTL, room.name, room.name_ar)}` : `Reading: ${room.name}`}</DialogTitle></DialogHeader>
            <Field id={`rd_t_${room.id}`} label={isRTL ? "الحرارة °م" : "Temperature °C"}>
              <Input id={`rd_t_${room.id}`} type="number" step="0.1" dir="ltr" required value={form?.temperature ?? ""} onChange={(e) => setForm((s) => s && { ...s, temperature: e.target.value })} />
            </Field>
            <Field id={`rd_h_${room.id}`} label={isRTL ? "الرطوبة % (اختياري)" : "Humidity % (optional)"}>
              <Input id={`rd_h_${room.id}`} type="number" step="0.1" dir="ltr" value={form?.humidity ?? ""} onChange={(e) => setForm((s) => s && { ...s, humidity: e.target.value })} />
            </Field>
            <DialogFooter><Button type="submit">{isRTL ? "حفظ" : "Save"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  );
}

export function WarehouseList() {
  const { isRTL } = useI18n();
  const { can, me } = useAuth();
  const branchName = (id?: number) => { const b = me?.branches?.find((x) => x.id === id); return b ? (isRTL && b.name_ar ? b.name_ar : b.name_en) : "—"; };
  const wh = useApi<Warehouse[]>("/warehouses");
  const rooms = useApi<ColdRoom[]>("/cold-rooms");
  const alarms = useApi<Alarm[]>("/alarms?status=open");
  const holds = useApi<Hold[]>("/quality-holds?status=active");
  const [dlg, setDlg] = useState<{ kind: "wh" | "room"; form: Record<string, string> } | null>(null);
  const [note, setNote] = useState<{ kind: "ack" | "release"; id: number; text: string } | null>(null);

  const refreshAll = () => { rooms.reload(); alarms.reload(); holds.reload(); };
  const canWrite = can("cold-rooms", "edit") || can("stock", "create");

  const saveDlg = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dlg) return;
    const f = dlg.form;
    try {
      if (dlg.kind === "wh") {
        await post("/warehouses", { code: f.code, name: f.name, name_ar: f.name_ar, type: f.type, ...(f.branch_id ? { branch_id: Number(f.branch_id) } : {}) });
        wh.reload();
      } else {
        const num = (v: string) => (v === "" ? null : Number(v));
        await post("/cold-rooms", {
          warehouse_id: Number(f.warehouse_id), code: f.code, name: f.name, name_ar: f.name_ar,
          temp_min: Number(f.temp_min), temp_max: Number(f.temp_max), humidity_min: num(f.humidity_min), humidity_max: num(f.humidity_max),
          capacity_kg: num(f.capacity_kg), compat_group: f.compat_group,
        });
        rooms.reload();
      }
      toast.success(isRTL ? "تم الحفظ" : "Saved");
      setDlg(null);
    } catch (err: any) { toast.error(err.message); }
  };

  const submitNote = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!note) return;
    try {
      if (note.kind === "ack") await post(`/alarms/${note.id}/ack`, { note: note.text });
      else await post(`/quality-holds/${note.id}/release`, { note: note.text });
      setNote(null);
      toast.success(isRTL ? "تم" : "Done");
      refreshAll();
    } catch (err: any) { toast.error(err.message); }
  };

  const set = (k: string, v: string) => setDlg((s) => (s ? { ...s, form: { ...s.form, [k]: v } } : s));
  const align = isRTL ? "text-right" : "text-left";
  const firstWh = wh.data?.[0]?.id ?? "";

  return (
    <div className="space-y-6">
      <InventoryNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "المستودعات وغرف التبريد" : "Warehouses & Cold Rooms"}</h1>
          <p className="text-muted-foreground mt-1">{isRTL ? "حالة كل تلاجة وآخر قراءة حرارة، مع الإنذارات والحجز النوعي." : "Each room's state and last temperature, with alarms and quality holds."}</p>
        </div>
        {can("warehouses", "create") && (
          <div className="flex gap-2">
            <Button variant="outline" className="gap-2" onClick={() => setDlg({ kind: "wh", form: { code: "", name: "", name_ar: "", type: "cold", branch_id: String(tokenStorage.getBranch() ?? me?.branches?.find((b) => Number(b.is_main) === 1)?.id ?? "") } })}>
              <Plus className="h-4 w-4" />{isRTL ? "مستودع" : "Warehouse"}
            </Button>
            <Button className="gap-2" onClick={() => setDlg({ kind: "room", form: { warehouse_id: String(firstWh), code: "", name: "", name_ar: "", temp_min: "", temp_max: "", humidity_min: "", humidity_max: "", capacity_kg: "", compat_group: "" } })}>
              <Plus className="h-4 w-4" />{isRTL ? "غرفة تبريد" : "Cold room"}
            </Button>
          </div>
        )}
      </div>
      <ErrorBox message={wh.error || rooms.error || alarms.error || holds.error} />

      {(alarms.data?.length ?? 0) > 0 && (
        <Card className="border-amber-300">
          <CardHeader className="pb-2"><CardTitle className="text-base flex items-center gap-2"><AlertTriangle className="h-4 w-4 text-amber-600" />{isRTL ? "إنذارات مفتوحة" : "Open alarms"}</CardTitle></CardHeader>
          <CardContent className="space-y-2">
            {alarms.data!.map((a) => (
              <div key={a.id} className="flex items-center justify-between gap-3 text-sm">
                <span>
                  <b>{a.cold_room_name}</b> · {isRTL ? KIND_AR[a.kind] ?? a.kind : a.kind} · <span dir="ltr">{Number(a.first_value).toFixed(1)}° ({Number(a.temp_min)}…{Number(a.temp_max)})</span>
                  <span className="text-muted-foreground" dir="ltr"> · {a.opened_at}</span>
                </span>
                <Button size="sm" variant="outline" onClick={() => setNote({ kind: "ack", id: a.id, text: "" })}>{isRTL ? "تأكيد الاستلام" : "Acknowledge"}</Button>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      {(holds.data?.length ?? 0) > 0 && (
        <Card className="border-red-300">
          <CardHeader className="pb-2"><CardTitle className="text-base flex items-center gap-2"><ShieldAlert className="h-4 w-4 text-red-600" />{isRTL ? "حجز نوعي نشط" : "Active quality holds"}</CardTitle></CardHeader>
          <CardContent className="space-y-2">
            {holds.data!.map((h) => (
              <div key={h.id} className="flex items-center justify-between gap-3 text-sm">
                <span>
                  <b>{h.cold_room_name || h.batch_no}</b> · {h.reason}
                  {Number(h.auto) === 1 && <Badge variant="outline" className="ms-2">{isRTL ? "تلقائي" : "auto"}</Badge>}
                </span>
                {can("stock", "approve") && <Button size="sm" variant="outline" onClick={() => setNote({ kind: "release", id: h.id, text: "" })}>{isRTL ? "رفع الحجز" : "Release"}</Button>}
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        {rooms.data?.map((r) => <RoomCard key={r.id} room={r} isRTL={isRTL} canWrite={canWrite} onChanged={refreshAll} />)}
      </div>
      {rooms.data && rooms.data.length === 0 && <p className="text-muted-foreground">{isRTL ? "لا توجد غرف تبريد بعد." : "No cold rooms yet."}</p>}

      <Card className="p-6 overflow-x-auto">
        <h2 className="font-semibold mb-3">{isRTL ? "المستودعات" : "Warehouses"}</h2>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className={align}>{isRTL ? "الكود" : "Code"}</TableHead>
              <TableHead className={align}>{isRTL ? "الاسم" : "Name"}</TableHead>
              <TableHead className={align}>{isRTL ? "النوع" : "Type"}</TableHead>
              <TableHead className={align}>{isRTL ? "الفرع" : "Branch"}</TableHead>
              <TableHead className={align}>{isRTL ? "عدد التلاجات" : "Rooms"}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {wh.data?.map((w) => (
              <TableRow key={w.id}>
                <TableCell className="font-mono text-sm">{w.code}</TableCell>
                <TableCell className="font-medium">{nm(isRTL, w.name, w.name_ar)}</TableCell>
                <TableCell>{isRTL ? WH_TYPES.find(([k]) => k === w.type)?.[1] ?? w.type : w.type}</TableCell>
                <TableCell>{branchName(w.branch_id)}</TableCell>
                <TableCell>{rooms.data?.filter((r) => r.warehouse_id === w.id).length ?? 0}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={!!dlg} onOpenChange={(o) => !o && setDlg(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto">
          <form onSubmit={saveDlg} className="space-y-4">
            <DialogHeader><DialogTitle>{dlg?.kind === "wh" ? (isRTL ? "مستودع جديد" : "New warehouse") : isRTL ? "غرفة تبريد جديدة" : "New cold room"}</DialogTitle></DialogHeader>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              {dlg?.kind === "room" && (
                <Field id="d_wh" label={isRTL ? "المستودع" : "Warehouse"} className="sm:col-span-2">
                  <select id="d_wh" className={selectClass} value={dlg.form.warehouse_id} onChange={(e) => set("warehouse_id", e.target.value)} required>
                    {wh.data?.map((w) => <option key={w.id} value={w.id}>{nm(isRTL, w.name, w.name_ar)}</option>)}
                  </select>
                </Field>
              )}
              <Field id="d_code" label={isRTL ? "الكود" : "Code"}><Input id="d_code" dir="ltr" required value={dlg?.form.code ?? ""} onChange={(e) => set("code", e.target.value)} /></Field>
              <Field id="d_name" label={isRTL ? "الاسم (إنجليزي)" : "Name (English)"}><Input id="d_name" required value={dlg?.form.name ?? ""} onChange={(e) => set("name", e.target.value)} /></Field>
              <Field id="d_name_ar" label={isRTL ? "الاسم (عربي)" : "Name (Arabic)"}><Input id="d_name_ar" value={dlg?.form.name_ar ?? ""} onChange={(e) => set("name_ar", e.target.value)} /></Field>
              {dlg?.kind === "wh" && (me?.branches?.length ?? 0) > 1 && (
                <Field id="d_branch" label={isRTL ? "الفرع" : "Branch"}>
                  <select id="d_branch" className={selectClass} value={dlg.form.branch_id} onChange={(e) => set("branch_id", e.target.value)}>
                    {me!.branches!.map((b) => <option key={b.id} value={b.id}>{isRTL && b.name_ar ? b.name_ar : b.name_en}</option>)}
                  </select>
                </Field>
              )}
              {dlg?.kind === "wh" ? (
                <Field id="d_type" label={isRTL ? "النوع" : "Type"}>
                  <select id="d_type" className={selectClass} value={dlg.form.type} onChange={(e) => set("type", e.target.value)}>
                    {WH_TYPES.map(([k, ar]) => <option key={k} value={k}>{isRTL ? ar : k}</option>)}
                  </select>
                </Field>
              ) : (
                <>
                  <Field id="d_tmin" label={isRTL ? "أقل حرارة °م" : "Temp min °C"}><Input id="d_tmin" type="number" step="0.1" dir="ltr" required value={dlg?.form.temp_min ?? ""} onChange={(e) => set("temp_min", e.target.value)} /></Field>
                  <Field id="d_tmax" label={isRTL ? "أعلى حرارة °م" : "Temp max °C"}><Input id="d_tmax" type="number" step="0.1" dir="ltr" required value={dlg?.form.temp_max ?? ""} onChange={(e) => set("temp_max", e.target.value)} /></Field>
                  <Field id="d_hmin" label={isRTL ? "أقل رطوبة %" : "Humidity min %"}><Input id="d_hmin" type="number" step="0.1" dir="ltr" value={dlg?.form.humidity_min ?? ""} onChange={(e) => set("humidity_min", e.target.value)} /></Field>
                  <Field id="d_hmax" label={isRTL ? "أعلى رطوبة %" : "Humidity max %"}><Input id="d_hmax" type="number" step="0.1" dir="ltr" value={dlg?.form.humidity_max ?? ""} onChange={(e) => set("humidity_max", e.target.value)} /></Field>
                  <Field id="d_cap" label={isRTL ? "السعة (كجم)" : "Capacity (kg)"}><Input id="d_cap" type="number" dir="ltr" value={dlg?.form.capacity_kg ?? ""} onChange={(e) => set("capacity_kg", e.target.value)} /></Field>
                  <Field id="d_cg" label={isRTL ? "مجموعة التوافق" : "Compatibility group"}><Input id="d_cg" value={dlg?.form.compat_group ?? ""} onChange={(e) => set("compat_group", e.target.value)} /></Field>
                </>
              )}
            </div>
            <DialogFooter><Button type="submit">{isRTL ? "حفظ" : "Save"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!note} onOpenChange={(o) => !o && setNote(null)}>
        <DialogContent>
          <form onSubmit={submitNote} className="space-y-4">
            <DialogHeader><DialogTitle>{note?.kind === "ack" ? (isRTL ? "ماذا تم فعله؟" : "What was done?") : isRTL ? "سبب رفع الحجز" : "Reason for releasing"}</DialogTitle></DialogHeader>
            <Input aria-label="note" required value={note?.text ?? ""} onChange={(e) => setNote((s) => s && { ...s, text: e.target.value })} />
            <DialogFooter><Button type="submit">{isRTL ? "تأكيد" : "Confirm"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
