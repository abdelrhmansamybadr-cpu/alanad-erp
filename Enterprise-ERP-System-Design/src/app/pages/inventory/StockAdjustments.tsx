import { useState } from "react";
import { toast } from "sonner";
import { Card, CardContent } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Tabs, TabsList, TabsTrigger } from "../../components/ui/tabs";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { useApi, post } from "../../hooks/useApi";
import { InventoryNav } from "../../components/inventory/InventoryNav";
import { ErrorBox, Field, fmtKg, selectClass, Item, ColdRoom, Balance, nm } from "../../components/inventory/common";

type Op = "receive" | "issue" | "waste" | "transfer" | "adjust";
const OPS: [Op, string, string][] = [
  ["receive", "Receive", "استلام"],
  ["issue", "Issue (FEFO)", "صرف (الأقدم أولاً)"],
  ["waste", "Waste", "هالك"],
  ["transfer", "Transfer", "تحويل"],
  ["adjust", "Adjust", "تسوية"],
];
const today = () => new Date().toISOString().slice(0, 10);
const EMPTY = { item_id: "", cold_room_id: "", qty: "", arrival_date: today(), expiry_date: "", grade: "", rack: "", position: "", batch_no: "", reason: "", balance: "", to_cold_room_id: "", qty_delta: "" };

export function StockAdjustments() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const items = useApi<Item[]>("/items");
  const rooms = useApi<ColdRoom[]>("/cold-rooms");
  const bal = useApi<Balance[]>("/stock/balance");
  const [op, setOp] = useState<Op>("receive");
  const [f, setF] = useState({ ...EMPTY });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [result, setResult] = useState<string[] | null>(null);

  const set = (k: keyof typeof EMPTY, v: string) => setF((s) => ({ ...s, [k]: v }));
  const rowOf = (key: string) => bal.data?.[Number(key)];
  const picked = rowOf(f.balance);
  const picker = (
    <Field id="op_bal" label={isRTL ? "الدفعة والمكان" : "Batch and location"} className="sm:col-span-2">
      <select id="op_bal" className={selectClass} required value={f.balance} onChange={(e) => set("balance", e.target.value)}>
        <option value="">{isRTL ? "اختر…" : "Choose…"}</option>
        {bal.data?.map((b, k) => (
          <option key={k} value={k}>
            {nm(isRTL, b.item_name, b.item_name_ar)} · {b.batch_no} · {b.cold_room_name || "—"}{b.rack ? ` ${b.rack}` : ""} · {fmtKg(b.qty)} kg
          </option>
        ))}
      </select>
    </Field>
  );

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true); setError(""); setResult(null);
    try {
      const n = (v: string) => (v === "" ? undefined : Number(v));
      let r: any;
      if (op === "receive") {
        r = await post("/stock/receive", { item_id: Number(f.item_id), cold_room_id: Number(f.cold_room_id), qty: Number(f.qty), arrival_date: f.arrival_date || undefined, expiry_date: f.expiry_date || undefined, grade: f.grade || undefined, rack: f.rack || undefined, position: f.position || undefined, batch_no: f.batch_no || undefined });
        setResult([isRTL ? `تم الاستلام في الدفعة ${r.batch_no} (تنتهي ${r.expiry_date})` : `Received into batch ${r.batch_no} (expires ${r.expiry_date})`]);
      } else if (op === "issue" || op === "waste") {
        r = await post(`/stock/${op}`, { item_id: Number(f.item_id), qty: Number(f.qty), cold_room_id: n(f.cold_room_id), reason: f.reason || undefined });
        setResult([isRTL ? "الدفعات المستخدمة:" : "Batches used:", ...((r.allocations ?? []) as any[]).map((a) => `${a.batch_no ?? "#" + a.batch_id} — ${fmtKg(Number(a.qty))} kg`)]);
      } else if (op === "transfer" && picked) {
        await post("/stock/transfer", { batch_id: picked.batch_id, qty: Number(f.qty), from_cold_room_id: picked.cold_room_id, from_rack: picked.rack || undefined, from_position: picked.position || undefined, to_cold_room_id: Number(f.to_cold_room_id), to_rack: f.rack || undefined, to_position: f.position || undefined });
        setResult([isRTL ? "تم التحويل" : "Transferred"]);
      } else if (op === "adjust" && picked) {
        r = await post("/stock/adjust", { batch_id: picked.batch_id, cold_room_id: picked.cold_room_id, rack: picked.rack || undefined, position: picked.position || undefined, qty_delta: Number(f.qty_delta), reason: f.reason });
        setResult([isRTL ? `تمت التسوية. الرصيد الآن ${fmtKg(r.balance)} كجم` : `Adjusted. Balance is now ${fmtKg(r.balance)} kg`]);
      }
      toast.success(isRTL ? "تمت العملية" : "Done");
      setF({ ...EMPTY });
      bal.reload();
    } catch (err: any) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  const needsItem = op === "receive" || op === "issue" || op === "waste";
  const roomSelect = (id: string, key: "cold_room_id" | "to_cold_room_id", label: string, required: boolean) => (
    <Field id={id} label={label}>
      <select id={id} className={selectClass} required={required} value={f[key]} onChange={(e) => set(key, e.target.value)}>
        <option value="">{required ? (isRTL ? "اختر…" : "Choose…") : isRTL ? "أي تلاجة" : "Any room"}</option>
        {rooms.data?.map((r) => <option key={r.id} value={r.id}>{nm(isRTL, r.name, r.name_ar)}</option>)}
      </select>
    </Field>
  );

  return (
    <div className="space-y-6">
      <InventoryNav />
      <div>
        <h1 className="text-3xl font-bold">{isRTL ? "عمليات المخزون" : "Stock Operations"}</h1>
        <p className="text-muted-foreground mt-1">{isRTL ? "الاستلام والصرف والهالك والتحويل والتسوية. كلها تُسجَّل في سجل الحركة." : "Receive, issue, waste, transfer and adjust. All are written to the ledger."}</p>
      </div>
      <Tabs value={op} onValueChange={(v) => { setOp(v as Op); setError(""); setResult(null); setF({ ...EMPTY }); }}>
        <TabsList className="flex-wrap h-auto">
          {OPS.filter(([k]) => k !== "adjust" || can("stock", "approve")).map(([k, en, ar]) => <TabsTrigger key={k} value={k}>{isRTL ? ar : en}</TabsTrigger>)}
        </TabsList>
      </Tabs>
      <ErrorBox message={error || items.error || rooms.error} />
      <Card>
        <CardContent className="pt-6">
          <form onSubmit={submit} className="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-3xl">
            {needsItem && (
              <Field id="op_item" label={isRTL ? "الصنف" : "Item"}>
                <select id="op_item" className={selectClass} required value={f.item_id} onChange={(e) => set("item_id", e.target.value)}>
                  <option value="">{isRTL ? "اختر…" : "Choose…"}</option>
                  {items.data?.map((i) => <option key={i.id} value={i.id}>{nm(isRTL, i.name, i.name_ar)}</option>)}
                </select>
              </Field>
            )}
            {op === "receive" && roomSelect("op_room", "cold_room_id", isRTL ? "غرفة التبريد" : "Cold room", true)}
            {(op === "issue" || op === "waste") && roomSelect("op_room", "cold_room_id", isRTL ? "من تلاجة (اختياري)" : "From room (optional)", false)}
            {(op === "transfer" || op === "adjust") && picker}
            {op === "transfer" && roomSelect("op_to", "to_cold_room_id", isRTL ? "إلى تلاجة" : "To room", true)}

            {op !== "adjust" && (
              <Field id="op_qty" label={isRTL ? "الكمية (كجم)" : "Quantity (kg)"}>
                <Input id="op_qty" type="number" step="any" min="0" dir="ltr" required value={f.qty} onChange={(e) => set("qty", e.target.value)} />
              </Field>
            )}
            {op === "adjust" && (
              <Field id="op_delta" label={isRTL ? "الفرق (+ زيادة / − نقص) كجم" : "Difference (+ more / − less) kg"}>
                <Input id="op_delta" type="number" step="any" dir="ltr" required value={f.qty_delta} onChange={(e) => set("qty_delta", e.target.value)} />
              </Field>
            )}

            {op === "receive" && (
              <>
                <Field id="op_arr" label={isRTL ? "تاريخ الوصول" : "Arrival date"}><Input id="op_arr" type="date" required value={f.arrival_date} onChange={(e) => set("arrival_date", e.target.value)} /></Field>
                <Field id="op_exp" label={isRTL ? "تاريخ الانتهاء (يُحسب من مدة الصلاحية إن تُرك فارغاً)" : "Expiry date (from shelf life if empty)"}><Input id="op_exp" type="date" value={f.expiry_date} onChange={(e) => set("expiry_date", e.target.value)} /></Field>
                <Field id="op_grade" label={isRTL ? "الدرجة" : "Grade"}>
                  <select id="op_grade" className={selectClass} value={f.grade} onChange={(e) => set("grade", e.target.value)}>
                    <option value="">—</option><option>A</option><option>B</option><option>C</option>
                  </select>
                </Field>
                <Field id="op_batch" label={isRTL ? "رقم الدفعة (تلقائي إن تُرك فارغاً)" : "Batch no. (auto if empty)"}><Input id="op_batch" dir="ltr" value={f.batch_no} onChange={(e) => set("batch_no", e.target.value)} /></Field>
              </>
            )}
            {(op === "receive" || op === "transfer") && (
              <>
                <Field id="op_rack" label={isRTL ? "الرف" : "Rack"}><Input id="op_rack" dir="ltr" value={f.rack} onChange={(e) => set("rack", e.target.value)} /></Field>
                <Field id="op_pos" label={isRTL ? "الموضع" : "Position"}><Input id="op_pos" dir="ltr" value={f.position} onChange={(e) => set("position", e.target.value)} /></Field>
              </>
            )}
            {(op === "issue" || op === "waste" || op === "adjust") && (
              <Field id="op_reason" label={op === "issue" ? (isRTL ? "السبب (اختياري)" : "Reason (optional)") : isRTL ? "السبب (إلزامي)" : "Reason (required)"} className="sm:col-span-2">
                <Input id="op_reason" required={op !== "issue"} value={f.reason} onChange={(e) => set("reason", e.target.value)} />
              </Field>
            )}
            <div className="sm:col-span-2">
              <Button type="submit" disabled={busy}>{busy ? "…" : isRTL ? "تنفيذ" : "Submit"}</Button>
            </div>
          </form>
          {result && (
            <div className="mt-4 p-3 rounded-lg bg-emerald-50 text-emerald-800 text-sm space-y-1" role="status">
              {result.map((l, k) => <p key={k}>{l}</p>)}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
