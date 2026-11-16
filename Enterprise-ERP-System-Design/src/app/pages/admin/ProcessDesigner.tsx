import { useCallback, useEffect, useMemo, useState } from "react";
import { ReactFlow, Background, Controls, MarkerType, Position, applyNodeChanges, type Node, type Edge, type NodeChange } from "@xyflow/react";
import "@xyflow/react/dist/style.css";
import { toast } from "sonner";
import { Plus, Trash2, History } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";
import { useApi, put, post } from "../../hooks/useApi";
import { ErrorBox, Field, selectClass } from "../../components/inventory/common";

interface Step {
  key: string; name_en: string; name_ar: string; role_key: string; rule: "maker_not_checker" | "any";
  time_limit_hours: string; reject_to_position: string; reject_label_en: string; reject_label_ar: string; pos_x: number; pos_y: number;
}
interface Flow { id: number; wf_key: string; name_en: string; name_ar: string | null; doc_type: string; is_active: number | boolean; steps: any[] }
interface Version { version: number; note: string | null; created_at: string; saved_by_name: string | null; steps: any[] }
interface Role { role_key: string; name_en: string; name_ar: string | null }

const GAP = 280;
let seq = 0;
const newKey = () => `s${Date.now()}_${seq++}`;
const toSteps = (raw: any[]): Step[] =>
  raw.map((s, i) => ({
    key: newKey(), name_en: s.name_en ?? "", name_ar: s.name_ar ?? "", role_key: s.role_key ?? "", rule: s.rule ?? "maker_not_checker",
    time_limit_hours: s.time_limit_hours != null ? String(s.time_limit_hours) : "", reject_to_position: s.reject_to_position != null ? String(s.reject_to_position) : "",
    reject_label_en: s.reject_label_en ?? "", reject_label_ar: s.reject_label_ar ?? "",
    pos_x: s.pos_x != null ? Number(s.pos_x) : i * GAP, pos_y: s.pos_y != null ? Number(s.pos_y) : 60,
  }));

/** The visual process designer. The approval steps of a document are boxes on a canvas; they run from left to right. Saving changes the real approval flow. */
export function ProcessDesigner() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const flows = useApi<Flow[]>("/workflows");
  const roles = useApi<Role[]>("/roles");
  const [flowId, setFlowId] = useState<number | null>(null);
  const flow = flows.data?.find((f) => f.id === flowId) ?? null;
  const versions = useApi<Version[]>(flowId ? `/workflows/${flowId}/versions` : null);
  const [steps, setSteps] = useState<Step[]>([]);
  const [sel, setSel] = useState<string | null>(null);
  const [note, setNote] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [dirty, setDirty] = useState(false);
  const canEdit = can("admin", "manage");

  useEffect(() => { if (!flowId && flows.data?.length) setFlowId(flows.data[0].id); }, [flows.data, flowId]);
  useEffect(() => { if (flow) { setSteps(toSteps(flow.steps)); setSel(null); setDirty(false); setError(""); } }, [flowId, flows.data]);   // eslint-disable-line react-hooks/exhaustive-deps

  const order = useMemo(() => [...steps].sort((a, b) => a.pos_x - b.pos_x), [steps]);
  const roleLabel = (k: string) => { const r = roles.data?.find((x) => x.role_key === k); return k ? (r ? (isRTL && r.name_ar ? r.name_ar : r.name_en) : k) : T("anyone", "أي شخص"); };

  const derived: Node[] = useMemo(() => {
    const last = order.length ? order[order.length - 1].pos_x : 0;
    const out: Node[] = [
      { id: "start", position: { x: (order[0]?.pos_x ?? 0) - 200, y: 85 }, data: { label: T("Document created", "إنشاء المستند") }, sourcePosition: Position.Right, targetPosition: Position.Left, draggable: false, selectable: false, style: { background: "#e2e8f0", borderRadius: 999, width: 150, fontSize: 12, textAlign: "center" } },
      { id: "end", position: { x: last + GAP - 20, y: 85 }, data: { label: T("Approved ✔", "معتمد ✔") }, sourcePosition: Position.Right, targetPosition: Position.Left, draggable: false, selectable: false, style: { background: "#bbf7d0", borderRadius: 999, width: 150, fontSize: 12, textAlign: "center" } },
    ];
    order.forEach((s, i) => out.push({
      id: s.key, position: { x: s.pos_x, y: s.pos_y }, selected: sel === s.key, sourcePosition: Position.Right, targetPosition: Position.Left,
      data: { label: (
        <div data-testid={`step-node-${i + 1}`} className="text-start space-y-1">
          <div className="text-[10px] text-muted-foreground">{T("Step", "الخطوة")} {i + 1}</div>
          <div className="font-semibold text-sm">{(isRTL && s.name_ar ? s.name_ar : s.name_en) || "…"}</div>
          <div className="text-xs">{roleLabel(s.role_key)}</div>
          <div className="text-[10px] text-muted-foreground">{s.rule === "maker_not_checker" ? T("not the maker", "ليس صاحب المستند") : T("anyone in the role", "أي شخص في الدور")}{s.time_limit_hours ? ` · ${s.time_limit_hours}h` : ""}</div>
        </div>) },
      style: { width: 210, border: sel === s.key ? "2px solid #0f766e" : "1px solid #94a3b8", borderRadius: 10, background: "white", padding: 8 },
    }));
    return out;
  }, [order, sel, roles.data, isRTL]);   // eslint-disable-line react-hooks/exhaustive-deps

  const [rf, setRf] = useState<Node[]>([]);
  useEffect(() => {
    setRf((prev) => derived.map((n) => { const o = prev.find((p) => p.id === n.id); return o ? { ...n, measured: o.measured, width: o.width, height: o.height } : n; }));
  }, [derived]);

  const edges: Edge[] = useMemo(() => {
    const out: Edge[] = [];
    const chain = ["start", ...order.map((s) => s.key), "end"];
    chain.slice(0, -1).forEach((id, i) => out.push({ id: `e${i}`, source: id, target: chain[i + 1], markerEnd: { type: MarkerType.ArrowClosed } }));
    order.forEach((s, i) => {
      const back = s.reject_to_position ? Number(s.reject_to_position) - 1 : i - 1;   // the engine returns a rejected document to the previous step by default
      if (back >= 0 && back < order.length && i > 0) out.push({ id: `r${i}`, source: s.key, target: order[Math.min(back, i)].key, label: (isRTL ? s.reject_label_ar : s.reject_label_en) || (isRTL ? s.reject_label_ar || s.reject_label_en : s.reject_label_en || s.reject_label_ar) || T("rejected", "مرفوض"), animated: true, style: { stroke: "#dc2626", strokeDasharray: "5 4" }, markerEnd: { type: MarkerType.ArrowClosed, color: "#dc2626" }, type: "smoothstep", sourceHandle: undefined });
    });
    return out;
  }, [order, isRTL]);   // eslint-disable-line react-hooks/exhaustive-deps

  const onNodesChange = useCallback((ch: NodeChange[]) => {
    setRf((prev) => applyNodeChanges(ch, prev));
    const moves = ch.filter((c): c is Extract<NodeChange, { type: "position" }> => c.type === "position" && !!c.position);
    if (moves.length) {
      setSteps((cur) => cur.map((s) => { const m = moves.find((c) => c.id === s.key); return m ? { ...s, pos_x: Math.round(m.position!.x), pos_y: Math.round(m.position!.y) } : s; }));
      setDirty(true);
    }
  }, []);

  // after a drag the boxes snap back to even spacing in their new left-to-right order
  const onDragStop = useCallback(() => {
    setSteps((cur) => { const sorted = [...cur].sort((a, b) => a.pos_x - b.pos_x); return cur.map((s) => ({ ...s, pos_x: sorted.findIndex((x) => x.key === s.key) * GAP, pos_y: 60 })); });
  }, []);
  const patch = (key: string, p: Partial<Step>) => { setSteps((cur) => cur.map((s) => (s.key === key ? { ...s, ...p } : s))); setDirty(true); };
  const add = () => {
    const x = order.length ? order[order.length - 1].pos_x + GAP : 0;
    const s: Step = { key: newKey(), name_en: T("New step", "New step"), name_ar: "خطوة جديدة", role_key: "manager", rule: "maker_not_checker", time_limit_hours: "", reject_to_position: "", reject_label_en: "", reject_label_ar: "", pos_x: x, pos_y: 60 };
    setSteps((c) => [...c, s]); setSel(s.key); setDirty(true);
  };
  const remove = (key: string) => { setSteps((c) => c.filter((s) => s.key !== key)); setSel(null); setDirty(true); };
  const selected = steps.find((s) => s.key === sel);
  const selIndex = selected ? order.findIndex((s) => s.key === selected.key) : -1;

  const save = async () => {
    if (!flow) return;
    setSaving(true); setError("");
    try {
      // the position in the list is the order on the canvas; reject-to numbers are re-based on the new order
      const pos = new Map(order.map((s, i) => [s.key, i + 1]));
      await put(`/workflows/${flow.id}`, {
        note: note || undefined,
        steps: order.map((s) => ({
          name_en: s.name_en, name_ar: s.name_ar || null, role_key: s.role_key || null, rule: s.rule,
          time_limit_hours: s.time_limit_hours ? Number(s.time_limit_hours) : null,
          reject_to_position: s.reject_to_position ? Math.min(Number(s.reject_to_position), pos.get(s.key)!) : null, reject_label_en: s.reject_label_en || null, reject_label_ar: s.reject_label_ar || null, pos_x: s.pos_x, pos_y: s.pos_y,
        })),
      });
      toast.success(T("Saved: the approval flow now works this way", "تم الحفظ: مسار الاعتماد يعمل الآن بهذا الشكل"));
      setNote(""); await Promise.all([flows.reload(), versions.reload()]);
    } catch (e: any) { setError(e.message); } finally { setSaving(false); }
  };
  const restore = async (v: number) => {
    if (!flow) return;
    try { await post(`/workflows/${flow.id}/restore`, { version: v }); toast.success(T(`Version ${v} restored`, `استُرجعت النسخة ${v}`)); await Promise.all([flows.reload(), versions.reload()]); }
    catch (e: any) { setError(e.message); }
  };

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-3xl font-bold">{T("Process designer", "مصمم العمليات")}</h1>
        <p className="text-muted-foreground mt-1">{T("Pick a document, arrange its approval steps on the canvas (they run from left to right), choose who approves each step, and save. The change applies to the system straight away.", "اختر المستند ورتّب خطوات اعتماده على اللوحة (تسير من اليسار إلى اليمين) وحدّد من يعتمد كل خطوة ثم احفظ. التغيير يسري على النظام فوراً.")}</p>
      </div>
      {(flows.error || roles.error || error) && <ErrorBox message={flows.error || roles.error || error} />}

      <div className="grid lg:grid-cols-[220px_1fr] gap-4">
        <Card className="p-3 space-y-1 h-fit" data-testid="flow-list">
          {flows.data?.map((f) => (
            <button key={f.id} data-testid={`flow-${f.wf_key}`} onClick={() => { if (!dirty || confirm(T("Discard unsaved changes?", "تجاهل التغييرات غير المحفوظة؟"))) setFlowId(f.id); }}
              className={`w-full text-start px-3 py-2 rounded-md text-sm ${f.id === flowId ? "bg-primary text-primary-foreground font-semibold" : "hover:bg-muted"}`}>
              {isRTL && f.name_ar ? f.name_ar : f.name_en}
              <div className={`text-[10px] ${f.id === flowId ? "opacity-80" : "text-muted-foreground"}`}>{f.steps.length} {T("step(s)", "خطوة")}</div>
            </button>
          ))}
        </Card>

        <div className="space-y-4">
          <Card className="p-0 overflow-hidden" style={{ height: 300 }} data-testid="canvas">
            <ReactFlow key={`${flowId}-${steps.length}`} nodes={rf} edges={edges} onNodesChange={onNodesChange} onNodeDragStop={onDragStop} onEdgeClick={(_, e) => { if (e.id.startsWith("r")) setSel(e.source); }} onNodeClick={(_, n) => setSel(n.id === "start" || n.id === "end" ? null : n.id)} onPaneClick={() => setSel(null)}
              nodesConnectable={false} nodesDraggable={canEdit} fitView fitViewOptions={{ padding: 0.25 }} proOptions={{ hideAttribution: true }} minZoom={0.3}>
              <Background /><Controls showInteractive={false} />
            </ReactFlow>
          </Card>

          <div className="flex flex-wrap items-center gap-2">
            {canEdit && <Button variant="outline" size="sm" className="gap-1" data-testid="add-step" onClick={add}><Plus className="h-4 w-4" />{T("Add step", "إضافة خطوة")}</Button>}
            <Input className="max-w-xs" placeholder={T("What did you change? (optional)", "ماذا غيّرت؟ (اختياري)")} value={note} onChange={(e) => setNote(e.target.value)} aria-label="note" />
            {canEdit && <Button disabled={!dirty || saving || steps.length === 0} data-testid="save-flow" onClick={save}>{saving ? "…" : T("Save design", "حفظ التصميم")}</Button>}
            {dirty && <Badge variant="secondary">{T("unsaved", "غير محفوظ")}</Badge>}
          </div>

          {selected && (
            <Card className="p-4 space-y-3" data-testid="inspector">
              <div className="flex items-center justify-between"><h2 className="font-semibold">{T("Step", "الخطوة")} {selIndex + 1}</h2>{canEdit && steps.length > 1 && <Button variant="ghost" size="sm" className="text-red-700 gap-1" data-testid="remove-step" onClick={() => remove(selected.key)}><Trash2 className="h-4 w-4" />{T("Remove", "حذف")}</Button>}</div>
              <div className="grid sm:grid-cols-2 gap-3">
                <Field id="st_en" label={T("Name (English)", "الاسم (إنجليزي)")}><Input id="st_en" value={selected.name_en} onChange={(e) => patch(selected.key, { name_en: e.target.value })} /></Field>
                <Field id="st_ar" label={T("Name (Arabic)", "الاسم (عربي)")}><Input id="st_ar" value={selected.name_ar} onChange={(e) => patch(selected.key, { name_ar: e.target.value })} /></Field>
                <Field id="st_role" label={T("Who approves (role)", "من يعتمد (الدور)")}>
                  <select id="st_role" className={selectClass} value={selected.role_key} onChange={(e) => patch(selected.key, { role_key: e.target.value })}>
                    <option value="">{T("Anyone with the right", "أي شخص بالصلاحية")}</option>
                    {roles.data?.map((r) => <option key={r.role_key} value={r.role_key}>{isRTL && r.name_ar ? r.name_ar : r.name_en}</option>)}
                  </select>
                </Field>
                <Field id="st_rule" label={T("Separation rule", "قاعدة الفصل")}>
                  <select id="st_rule" className={selectClass} value={selected.rule} onChange={(e) => patch(selected.key, { rule: e.target.value as Step["rule"] })}>
                    <option value="maker_not_checker">{T("Not the person who created it", "ليس من أنشأ المستند")}</option>
                    <option value="any">{T("Anyone in the role", "أي شخص في الدور")}</option>
                  </select>
                </Field>
                <Field id="st_back" label={T("A rejection returns to", "الرفض يعيد إلى")}>
                  <select id="st_back" className={selectClass} value={selected.reject_to_position} onChange={(e) => patch(selected.key, { reject_to_position: e.target.value })}>
                    <option value="">{T("The previous step (default)", "الخطوة السابقة (الافتراضي)")}</option>
                    {order.slice(0, Math.max(selIndex, 0) + 1).map((s, i) => <option key={s.key} value={i + 1}>{T("Step", "الخطوة")} {i + 1}: {s.name_en}</option>)}
                  </select>
                </Field>
                {selIndex > 0 && <>
                  <Field id="st_rl_ar" label={T("Rejection arrow label (Arabic)", "كلمة سهم الرفض (عربي)")}><Input id="st_rl_ar" maxLength={60} placeholder="مرفوض" value={selected.reject_label_ar} onChange={(e) => patch(selected.key, { reject_label_ar: e.target.value })} /></Field>
                  <Field id="st_rl_en" label={T("Rejection arrow label (English)", "كلمة سهم الرفض (إنجليزي)")}><Input id="st_rl_en" maxLength={60} dir="ltr" placeholder="rejected" value={selected.reject_label_en} onChange={(e) => patch(selected.key, { reject_label_en: e.target.value })} /></Field>
                </>}
                <Field id="st_limit" label={T("Time limit (hours, optional)", "المهلة (ساعات، اختياري)")}><Input id="st_limit" type="number" min="1" dir="ltr" value={selected.time_limit_hours} onChange={(e) => patch(selected.key, { time_limit_hours: e.target.value })} /></Field>
              </div>
            </Card>
          )}

          <Card className="p-4" data-testid="versions">
            <h2 className="font-semibold flex items-center gap-2 mb-2"><History className="h-4 w-4" />{T("Saved versions", "النسخ المحفوظة")}</h2>
            {versions.data && versions.data.length === 0 && <p className="text-sm text-muted-foreground">{T("No changes saved yet.", "لم تُحفظ تغييرات بعد.")}</p>}
            <ul className="space-y-1 text-sm">
              {versions.data?.map((v, i) => (
                <li key={v.version} className="flex items-center justify-between gap-2 border-b py-1">
                  <span><b>v{v.version}</b> · <span dir="ltr">{v.created_at}</span> · {v.saved_by_name ?? "—"} · {v.steps.length} {T("step(s)", "خطوة")}{v.note ? ` · ${v.note}` : ""}{i === 0 ? ` (${T("current", "الحالية")})` : ""}</span>
                  {canEdit && i > 0 && <Button size="sm" variant="outline" data-testid={`restore-${v.version}`} onClick={() => restore(v.version)}>{T("Restore", "استرجاع")}</Button>}
                </li>
              ))}
            </ul>
          </Card>
        </div>
      </div>
    </div>
  );
}
