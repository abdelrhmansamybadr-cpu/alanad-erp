import { useState } from "react";
import { Link, useParams } from "react-router";
import { toast } from "sonner";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";
import { useApi, post } from "../../hooks/useApi";
import { ProcurementNav, StatusBadge, money } from "../../components/procurement/common";
import { ErrorBox, Field, fmtKg, nm } from "../../components/inventory/common";

interface Line {
  id: number; item_name: string; item_name_ar: string | null; qty_kg: number; offer_price: number; amount: number;
  inspect_result: "Pending" | "Pass" | "Fail"; inspect_grade: string | null; inspect_note: string | null;
}
interface Purchase {
  id: number; number: string; market_name: string; status: string; buyer_id: number; notes: string | null; return_reason: string | null;
  offer_total: number; pass_total: number; total_amount: number; po_id: number | null; issued_amount: number | null; due_date: string | null; is_overdue: boolean;
  spent_amount: number | null; returned_amount: number | null; settlement_note: string | null; lines: Line[];
}
type Ask = { kind: "fail"; line: number } | { kind: "return" } | { kind: "reject" } | { kind: "cancel" } | { kind: "settle" } | null;

export function MarketPurchaseDetail() {
  const { id } = useParams();
  const { isRTL } = useI18n();
  const { can, me } = useAuth();
  const p = useApi<Purchase>(`/market-purchases/${id}`);
  const [busy, setBusy] = useState(false);
  const [ask, setAsk] = useState<Ask>(null);
  const [text, setText] = useState("");
  const [spent, setSpent] = useState("");
  const [back, setBack] = useState("");
  const d = p.data;
  const isBuyer = !!d && !!me && d.buyer_id === me.id;
  const finance = can("market-purchases", "approve") && !isBuyer;

  const run = async (path: string, ok: string, body: object = {}) => {
    setBusy(true);
    try { await post(`/market-purchases/${id}/${path}`, body); toast.success(ok); await p.reload(); return true; }
    catch (err: any) { toast.error(err.message); return false; }
    finally { setBusy(false); }
  };
  const close = () => { setAsk(null); setText(""); setSpent(""); setBack(""); };

  if (p.error) return <ErrorBox message={p.error} />;
  if (!d) return <p className="text-muted-foreground">…</p>;
  const align = isRTL ? "text-right" : "text-left";
  const canInspect = can("market-purchases", "inspect") && !isBuyer && ["Draft", "Returned"].includes(d.status);
  const T = (en: string, ar: string) => (isRTL ? ar : en);

  return (
    <div className="space-y-6">
      <ProcurementNav />
      <div>
        <Link to="/procurement/market" className="text-sm text-primary underline">{T("← Market buying", "← الشراء من السوق")}</Link>
        <div className="flex flex-wrap items-center gap-3 mt-1">
          <h1 className="text-3xl font-bold" dir="ltr">{d.number}</h1>
          <StatusBadge status={d.status} isRTL={isRTL} />
          {d.is_overdue && <span className="text-red-700 font-semibold text-sm">{T("Custody overdue", "العهدة متأخرة")}</span>}
        </div>
        <p className="text-muted-foreground mt-1">{d.market_name} · {T("Offer", "العرض")} {money(d.offer_total)}{d.total_amount ? ` · ${T("Approved", "المعتمد")} ${money(d.total_amount)}` : ""}</p>
        {d.return_reason && d.status === "Returned" && <div className="p-3 rounded-lg bg-amber-50 text-amber-800 text-sm mt-2">{T("Returned by Finance: ", "أعادته المالية: ")}{d.return_reason}</div>}
      </div>

      <div className="flex flex-wrap gap-2">
        {isBuyer && ["Draft", "Returned"].includes(d.status) && <Button disabled={busy} data-testid="submit" onClick={() => run("submit", T("Sent to Finance", "أُرسل للمالية"))}>{T("Send to Finance", "إرسال للمالية")}</Button>}
        {finance && d.status === "Submitted" && (
          <>
            <Button disabled={busy} data-testid="approve" onClick={() => run("approve", T("Approved: purchase order created", "تم الاعتماد وإنشاء أمر الشراء"))}>{T("Approve", "اعتماد")}</Button>
            <Button variant="outline" disabled={busy} onClick={() => setAsk({ kind: "return" })}>{T("Return", "إعادة")}</Button>
          </>
        )}
        {finance && d.status === "Approved" && <Button disabled={busy} data-testid="issue-cash" onClick={() => run("issue-cash", T("Cash given to the buyer", "سُلِّم النقد للمشتري"))}>{T("Give cash to the buyer", "تسليم النقد للمشتري")}</Button>}
        {isBuyer && d.status === "Cash_Issued" && <Button disabled={busy} data-testid="settle" onClick={() => { setSpent(String(d.issued_amount ?? "")); setBack("0"); setAsk({ kind: "settle" }); }}>{T("Settle with receipts", "تسوية بالإيصالات")}</Button>}
        {finance && d.status === "Settlement_Submitted" && (
          <>
            <Button disabled={busy} data-testid="confirm-settlement" onClick={() => run("confirm-settlement", T("Settlement confirmed", "تم تأكيد التسوية"))}>{T("Confirm settlement", "تأكيد التسوية")}</Button>
            <Button variant="outline" disabled={busy} onClick={() => setAsk({ kind: "reject" })}>{T("Send back", "إعادة للمشتري")}</Button>
          </>
        )}
        {["Draft", "Returned", "Submitted", "Approved"].includes(d.status) && (isBuyer || can("market-purchases", "approve")) && (
          <Button variant="ghost" className="text-red-700" disabled={busy} onClick={() => setAsk({ kind: "cancel" })}>{T("Cancel", "إلغاء")}</Button>
        )}
      </div>

      <Card className="p-6">
        <h2 className="font-semibold mb-3">{T("Items and field inspection", "الأصناف وفحص الجودة في السوق")}</h2>
        <Table>
          <TableHeader><TableRow>
            <TableHead className={align}>{T("Item", "الصنف")}</TableHead>
            <TableHead className={align}>{T("kg", "كجم")}</TableHead>
            <TableHead className={align}>{T("Price/kg", "السعر/كجم")}</TableHead>
            <TableHead className={align}>{T("Amount", "القيمة")}</TableHead>
            <TableHead className={align}>{T("Quality", "الجودة")}</TableHead>
            <TableHead />
          </TableRow></TableHeader>
          <TableBody>
            {d.lines.map((l) => (
              <TableRow key={l.id}>
                <TableCell className="font-medium">{nm(isRTL, l.item_name, l.item_name_ar)}</TableCell>
                <TableCell>{fmtKg(l.qty_kg)}</TableCell>
                <TableCell>{l.offer_price.toFixed(2)}</TableCell>
                <TableCell>{money(l.amount)}</TableCell>
                <TableCell>
                  {l.inspect_result === "Pending" ? <span className="text-muted-foreground text-sm">{T("Not inspected", "لم يُفحص")}</span> : <StatusBadge status={l.inspect_result} isRTL={isRTL} />}
                  {l.inspect_note && <div className="text-xs text-muted-foreground">{l.inspect_note}</div>}
                </TableCell>
                <TableCell className="whitespace-nowrap">
                  {canInspect && (
                    <>
                      <Button size="sm" variant="outline" disabled={busy} data-testid={`pass-${l.id}`} onClick={() => run("inspect", T("Passed", "مقبول"), { line_id: l.id, result: "Pass", grade: "A" })}>{T("Pass", "قبول")}</Button>
                      <Button size="sm" variant="ghost" className="text-red-700" disabled={busy} data-testid={`fail-${l.id}`} onClick={() => setAsk({ kind: "fail", line: l.id })}>{T("Fail", "رفض")}</Button>
                    </>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
        <p className="text-sm text-muted-foreground mt-3">{T("Value of the lines that passed: ", "قيمة البنود المقبولة: ")}{money(d.pass_total)}</p>
      </Card>

      {d.issued_amount != null && (
        <Card className="p-6" data-testid="custody-box">
          <h2 className="font-semibold mb-3">{T("Cash custody", "العهدة النقدية")}</h2>
          <p className="text-sm">{T("Cash given: ", "النقد المُسلَّم: ")}<b>{money(d.issued_amount)}</b> · {T("settle by ", "تُسوّى قبل ")}<span dir="ltr">{d.due_date}</span></p>
          {d.spent_amount != null && <p className="text-sm mt-1">{T("Spent: ", "المصروف: ")}<b>{money(d.spent_amount)}</b> · {T("returned: ", "المُعاد: ")}<b>{money(d.returned_amount)}</b> · {d.settlement_note}</p>}
        </Card>
      )}

      <Dialog open={!!ask} onOpenChange={(o) => !o && close()}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {ask?.kind === "fail" ? T("Reason for rejecting", "سبب الرفض") : ask?.kind === "return" ? T("Return to the buyer", "إعادة للمشتري") : ask?.kind === "reject" ? T("Send the settlement back", "إعادة التسوية") : ask?.kind === "cancel" ? T("Cancel", "إلغاء") : T("Settle the custody", "تسوية العهدة")}
            </DialogTitle>
          </DialogHeader>
          {ask?.kind === "settle" && (
            <div className="grid grid-cols-2 gap-3">
              <Field id="st_spent" label={T("Spent", "المصروف")}><Input id="st_spent" type="number" step="0.01" dir="ltr" value={spent} onChange={(e) => setSpent(e.target.value)} /></Field>
              <Field id="st_back" label={T("Returned to Finance", "المُعاد للمالية")}><Input id="st_back" type="number" step="0.01" dir="ltr" value={back} onChange={(e) => setBack(e.target.value)} /></Field>
            </div>
          )}
          <Field id="ask_text" label={ask?.kind === "settle" ? T("Receipts (numbers, traders)", "الإيصالات (أرقام، تجار)") : T("Reason", "السبب")}>
            <Input id="ask_text" value={text} onChange={(e) => setText(e.target.value)} />
          </Field>
          <DialogFooter>
            <Button variant="outline" onClick={close}>{T("Back", "رجوع")}</Button>
            <Button disabled={busy || !text.trim()} data-testid="ask-confirm" onClick={async () => {
              if (!ask) return;
              let ok = false;
              if (ask.kind === "fail") ok = await run("inspect", T("Rejected", "تم الرفض"), { line_id: ask.line, result: "Fail", note: text });
              else if (ask.kind === "return") ok = await run("return", T("Returned", "أُعيد"), { reason: text });
              else if (ask.kind === "reject") ok = await run("reject-settlement", T("Sent back", "أُعيدت"), { reason: text });
              else if (ask.kind === "cancel") ok = await run("cancel", T("Cancelled", "أُلغي"), { reason: text });
              else ok = await run("settle", T("Settlement sent to Finance", "أُرسلت التسوية للمالية"), { spent_amount: Number(spent), returned_amount: Number(back), note: text });
              if (ok) close();
            }}>{T("Confirm", "تأكيد")}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
