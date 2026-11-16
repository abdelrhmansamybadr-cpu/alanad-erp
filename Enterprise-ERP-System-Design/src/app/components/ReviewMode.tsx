import { useCallback, useEffect, useRef, useState } from "react";
import { apiRequest } from "../services/api";

/**
 * Tester "review mode". Open the site with ?review=1 (the choice is remembered in this browser; ?review=0 turns it off).
 * A small panel appears: press "Select" then click ANY part of the screen, choose the kind of note (comment / rename /
 * change / problem), write it and send. Notes are stored on the server so the owner can later say "make all the edits".
 */
const KINDS: [string, string][] = [["comment", "تعليق"], ["rename", "تغيير اسم"], ["change", "تعديل"], ["bug", "مشكلة"]];

function selectorOf(el: Element): string {
  const parts: string[] = [];
  let cur: Element | null = el;
  while (cur && cur.tagName !== "BODY" && parts.length < 6) {
    const tid = cur.getAttribute("data-testid");
    if (tid) { parts.unshift(`[data-testid="${tid}"]`); break; }
    if (cur.id) { parts.unshift(`#${cur.id}`); break; }
    let s = cur.tagName.toLowerCase();
    const parent: Element | null = cur.parentElement;
    if (parent) { const same = Array.from(parent.children).filter((c) => c.tagName === cur!.tagName); if (same.length > 1) s += `:nth-of-type(${same.indexOf(cur) + 1})`; }
    parts.unshift(s); cur = parent;
  }
  return parts.join(" > ");
}

export function ReviewMode() {
  const [on, setOn] = useState(false);
  const [pick, setPick] = useState(false);
  const [target, setTarget] = useState<{ selector: string; text: string; path: string } | null>(null);
  const [kind, setKind] = useState("comment");
  const [note, setNote] = useState("");
  const [newText, setNewText] = useState("");
  const [msg, setMsg] = useState("");
  const [count, setCount] = useState(0);
  const [busy, setBusy] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  const hl = useRef<HTMLElement | null>(null);

  useEffect(() => {
    try {
      const q = new URLSearchParams(window.location.search).get("review");
      if (q === "1") localStorage.setItem("review_mode", "1");
      if (q === "0") localStorage.removeItem("review_mode");
      setOn(localStorage.getItem("review_mode") === "1");
    } catch { setOn(new URLSearchParams(window.location.search).get("review") === "1"); }
  }, []);

  const clearHl = () => { if (hl.current) { hl.current.style.outline = ""; hl.current.style.outlineOffset = ""; hl.current = null; } };
  const inBox = (t: EventTarget | null) => !!(t instanceof Node && box.current?.contains(t));

  useEffect(() => {
    if (!pick) { clearHl(); return; }
    const over = (e: MouseEvent) => {
      const t = e.target as HTMLElement; if (inBox(t)) return;
      clearHl(); hl.current = t; t.style.outline = "2px solid #e11d48"; t.style.outlineOffset = "1px";
    };
    const click = (e: MouseEvent) => {
      const t = e.target as HTMLElement; if (inBox(t)) return;
      e.preventDefault(); e.stopPropagation();
      const text = (t.innerText || t.getAttribute("aria-label") || t.getAttribute("placeholder") || "").trim().replace(/\s+/g, " ").slice(0, 200);
      clearHl();
      setTarget({ selector: selectorOf(t), text, path: window.location.pathname + window.location.search.replace(/[?&]review=\d/, "") });
      setPick(false); setMsg("");
    };
    const swallow = (e: Event) => { if (!inBox(e.target)) { e.preventDefault(); e.stopPropagation(); } };
    document.addEventListener("mouseover", over, true); document.addEventListener("click", click, true);
    document.addEventListener("mousedown", swallow, true); document.addEventListener("submit", swallow, true);
    return () => { document.removeEventListener("mouseover", over, true); document.removeEventListener("click", click, true); document.removeEventListener("mousedown", swallow, true); document.removeEventListener("submit", swallow, true); clearHl(); };
  }, [pick]);

  const refresh = useCallback(() => { apiRequest<any[]>("/review-notes").then((r) => setCount(r.length)).catch(() => {}); }, []);
  useEffect(() => { if (on) refresh(); }, [on, refresh]);

  const send = async () => {
    if (!target) return;
    setBusy(true); setMsg("");
    try {
      await apiRequest("/review-notes", { method: "POST", body: JSON.stringify({ page_path: target.path, selector: target.selector, element_text: target.text, kind, new_text: newText, note }) });
      setTarget(null); setNote(""); setNewText(""); setKind("comment"); setMsg("تم حفظ الملاحظة ✔"); refresh();
    } catch (e: any) { setMsg(e.message || "تعذّر الحفظ (سجّل الدخول أولاً)"); } finally { setBusy(false); }
  };

  if (!on) return null;
  const S = { fontFamily: "Tahoma,Arial,sans-serif", direction: "rtl" as const };
  const btn = (bg: string) => ({ background: bg, color: "#fff", border: 0, borderRadius: 8, padding: "7px 12px", cursor: "pointer", fontSize: 13 });
  const field = { width: "100%", boxSizing: "border-box" as const, border: "1px solid #cbd5e1", borderRadius: 6, padding: 6, fontSize: 13, marginTop: 4 };
  return (
    <div ref={box} data-review-ui style={{ position: "fixed", left: 12, bottom: 12, zIndex: 2147483000, width: 300, background: "#fff", border: "2px solid #e11d48", borderRadius: 12, boxShadow: "0 8px 30px rgba(0,0,0,.25)", padding: 12, ...S }}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", fontWeight: 700, fontSize: 14, color: "#be123c" }}>
        <span>وضع المراجعة (للمُختبِر)</span>
        <button title="إغلاق وضع المراجعة" onClick={() => { try { localStorage.removeItem("review_mode"); } catch { /* ignore */ } setOn(false); }} style={{ background: "none", border: 0, cursor: "pointer", fontSize: 16 }}>✕</button>
      </div>
      {!target && (
        <div style={{ marginTop: 8, fontSize: 12.5, color: "#334155", lineHeight: 1.8 }}>
          {pick ? "اضغط الآن على أي جزء من الشاشة (زر أو عنوان أو حقل أو جدول…)." : "اضغط «تحديد» ثم اضغط على أي جزء تريد التعليق عليه أو تعديل اسمه."}
          <div style={{ display: "flex", gap: 8, marginTop: 8 }}>
            <button style={btn(pick ? "#64748b" : "#e11d48")} onClick={() => { setPick(!pick); setMsg(""); }}>{pick ? "إلغاء التحديد" : "تحديد جزء"}</button>
            <span style={{ alignSelf: "center" }}>ملاحظاتك: <b>{count}</b></span>
          </div>
        </div>
      )}
      {target && (
        <div style={{ marginTop: 8, fontSize: 12.5 }}>
          <div style={{ background: "#f1f5f9", borderRadius: 6, padding: 6, marginBottom: 6 }}>
            <div style={{ color: "#64748b" }}>الجزء المحدد:</div>
            <b>{target.text || "(عنصر بلا نص)"}</b>
            <div dir="ltr" style={{ color: "#94a3b8", fontSize: 10.5, wordBreak: "break-all" }}>{target.path}</div>
          </div>
          <label>النوع
            <select value={kind} onChange={(e) => setKind(e.target.value)} style={field}>{KINDS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
          </label>
          {(kind === "rename" || kind === "change") && (
            <label style={{ display: "block", marginTop: 6 }}>{kind === "rename" ? "الاسم الجديد" : "النص/القيمة الجديدة"}
              <input value={newText} onChange={(e) => setNewText(e.target.value)} style={field} />
            </label>
          )}
          <label style={{ display: "block", marginTop: 6 }}>الملاحظة
            <textarea rows={3} value={note} onChange={(e) => setNote(e.target.value)} style={field} placeholder="اكتب ما تريد تغييره أو سؤالك…" />
          </label>
          <div style={{ display: "flex", gap: 8, marginTop: 8 }}>
            <button style={btn("#0f766e")} disabled={busy || (!note.trim() && !newText.trim())} onClick={send}>{busy ? "…" : "إرسال"}</button>
            <button style={btn("#64748b")} onClick={() => setTarget(null)}>إلغاء</button>
          </div>
        </div>
      )}
      {msg && <div style={{ marginTop: 8, fontSize: 12.5, color: msg.includes("✔") ? "#0f766e" : "#b91c1c" }}>{msg}</div>}
    </div>
  );
}
