import { Fragment, useState } from "react";
import { toast } from "sonner";
import { Plus, Trash2 } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "../../components/ui/tabs";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { apiRequest } from "../../services/api";
import { useApi, post, put } from "../../hooks/useApi";
import { ErrorBox, Field, selectClass, nm } from "../../components/inventory/common";
import { StatusBadge, money } from "../../components/procurement/common";
import { FinanceNav } from "../../components/finance/common";

interface Account { id: number; code: string; name_en: string; name_ar: string | null; type: string; parent_id: number | null; is_postable: number | boolean; system_key: string | null; is_active: number | boolean }
interface Entry { id: number; number: string; date: string; description: string; source_type: string; status: string; total: number }
interface EntryDetail extends Entry { lines: { id: number; account_code: string; account_name: string; account_name_ar: string | null; debit: number; credit: number; memo: string | null }[] }
interface MRow { account_id: string; debit: string; credit: string; memo: string }

const SRC_AR: Record<string, string> = { manual: "يدوي", receipt: "استلام بضاعة", ap_invoice: "فاتورة مورد", ap_payment: "دفعة مورد", reversal: "عكس قيد" };
const TYPE_AR: Record<string, string> = { asset: "أصول", liability: "التزامات", equity: "حقوق ملكية", revenue: "إيرادات", expense: "مصروفات" };

export function LedgerPage() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const [src, setSrc] = useState("");
  const entries = useApi<Entry[]>(`/gl-entries${src ? `?source_type=${src}` : ""}`);
  const accts = useApi<Account[]>("/gl-accounts");
  const [open, setOpen] = useState<number | null>(null);
  const [detail, setDetail] = useState<EntryDetail | null>(null);
  const [error, setError] = useState("");
  const [man, setMan] = useState<{ date: string; description: string; rows: MRow[] } | null>(null);
  const [rev, setRev] = useState<{ id: number; number: string; reason: string } | null>(null);
  const [acc, setAcc] = useState<{ code: string; name_en: string; name_ar: string; type: string; parent_id: string } | null>(null);
  const align = isRTL ? "text-right" : "text-left";
  const postable = (accts.data ?? []).filter((a) => Number(a.is_postable) === 1 && Number(a.is_active) === 1);

  const toggle = async (e: Entry) => {
    if (open === e.id) { setOpen(null); return; }
    setOpen(e.id);
    try { setDetail(await apiRequest<EntryDetail>(`/gl-entries/${e.id}`)); } catch (err: any) { setError(err.message); }
  };

  const dr = man?.rows.reduce((s, r) => s + (Number(r.debit) || 0), 0) ?? 0;
  const cr = man?.rows.reduce((s, r) => s + (Number(r.credit) || 0), 0) ?? 0;
  const setRow = (i: number, k: keyof MRow, v: string) => setMan((s) => s && { ...s, rows: s.rows.map((r, j) => (j === i ? { ...r, [k]: v } : r)) });

  const saveManual = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!man) return;
    setError("");
    try {
      await post("/gl-entries", { date: man.date, description: man.description, lines: man.rows.filter((r) => r.account_id).map((r) => ({ account_id: Number(r.account_id), debit: Number(r.debit) || 0, credit: Number(r.credit) || 0, memo: r.memo || undefined })) });
      toast.success(isRTL ? "تم ترحيل القيد" : "Entry posted");
      setMan(null); entries.reload();
    } catch (err: any) { setError(err.message); }
  };
  const reverse = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!rev) return;
    try { await post(`/gl-entries/${rev.id}/reverse`, { reason: rev.reason }); toast.success(isRTL ? "تم عكس القيد" : "Entry reversed"); setRev(null); setOpen(null); entries.reload(); } catch (err: any) { setError(err.message); setRev(null); }
  };
  const saveAcc = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!acc) return;
    try {
      await post("/gl-accounts", { code: acc.code, name_en: acc.name_en, name_ar: acc.name_ar || undefined, type: acc.type, parent_id: acc.parent_id ? Number(acc.parent_id) : undefined });
      toast.success(isRTL ? "تم إنشاء الحساب" : "Account created");
      setAcc(null); accts.reload();
    } catch (err: any) { setError(err.message); }
  };
  const activate = async (a: Account) => {
    try { await put(`/gl-accounts/${a.id}`, { is_active: Number(a.is_active) !== 1 }); accts.reload(); } catch (err: any) { setError(err.message); }
  };

  return (
    <div className="space-y-6">
      <FinanceNav />
      <div>
        <h1 className="text-3xl font-bold">{isRTL ? "القيود ودليل الحسابات" : "Journal & Chart of Accounts"}</h1>
        <p className="text-muted-foreground mt-1">{isRTL ? "القيود لا تُعدَّل ولا تُحذف. الخطأ يُصحَّح بقيد عكسي. المستندات ترحّل قيودها تلقائياً." : "Entries are never edited or deleted. A mistake is corrected by a reversal. Documents post their own entries automatically."}</p>
      </div>
      <ErrorBox message={entries.error || accts.error || error} />
      <Tabs defaultValue="journal">
        <TabsList>
          <TabsTrigger value="journal">{isRTL ? "اليومية" : "Journal"}</TabsTrigger>
          <TabsTrigger value="chart">{isRTL ? "دليل الحسابات" : "Chart of accounts"}</TabsTrigger>
        </TabsList>

        <TabsContent value="journal" className="space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <select aria-label="source" className={`${selectClass} max-w-[220px]`} value={src} onChange={(e) => setSrc(e.target.value)}>
              <option value="">{isRTL ? "كل المصادر" : "All sources"}</option>
              {Object.keys(SRC_AR).map((k) => <option key={k} value={k}>{isRTL ? SRC_AR[k] : k}</option>)}
            </select>
            {can("gl-entries", "approve") && (
              <Button className="gap-2" onClick={() => setMan({ date: new Date().toISOString().slice(0, 10), description: "", rows: [{ account_id: "", debit: "", credit: "", memo: "" }, { account_id: "", debit: "", credit: "", memo: "" }] })}><Plus className="h-4 w-4" />{isRTL ? "قيد يدوي" : "Manual entry"}</Button>
            )}
          </div>
          <Card className="p-6 overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className={align}>{isRTL ? "الرقم" : "Number"}</TableHead>
                  <TableHead className={align}>{isRTL ? "التاريخ" : "Date"}</TableHead>
                  <TableHead className={align}>{isRTL ? "البيان" : "Description"}</TableHead>
                  <TableHead className={align}>{isRTL ? "المصدر" : "Source"}</TableHead>
                  <TableHead className={align}>{isRTL ? "المبلغ" : "Amount"}</TableHead>
                  <TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {entries.data?.map((e) => (
                  <Fragment key={e.id}>
                    <TableRow className="cursor-pointer hover:bg-muted/50" onClick={() => toggle(e)}>
                      <TableCell className="font-mono">{e.number}</TableCell>
                      <TableCell dir="ltr" className="text-sm">{e.date}</TableCell>
                      <TableCell className="text-sm">{e.description}</TableCell>
                      <TableCell><Badge variant="secondary">{isRTL ? SRC_AR[e.source_type] ?? e.source_type : e.source_type}</Badge></TableCell>
                      <TableCell dir="ltr" className="font-mono text-sm">{money(e.total)}</TableCell>
                      <TableCell><StatusBadge status={e.status} isRTL={isRTL} /></TableCell>
                    </TableRow>
                    {open === e.id && detail?.id === e.id && (
                      <TableRow>
                        <TableCell colSpan={6} className="bg-muted/30">
                          <table className="w-full text-sm">
                            <tbody>
                              {detail.lines.map((l) => (
                                <tr key={l.id}>
                                  <td className="py-1 font-mono" dir="ltr">{l.account_code}</td>
                                  <td className="py-1">{nm(isRTL, l.account_name, l.account_name_ar)}{l.memo ? <span className="text-muted-foreground"> · {l.memo}</span> : ""}</td>
                                  <td className="py-1 font-mono text-end" dir="ltr">{l.debit ? money(l.debit) : ""}</td>
                                  <td className="py-1 font-mono text-end" dir="ltr">{l.credit ? `(${money(l.credit)})` : ""}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                          {e.source_type === "manual" && e.status === "Posted" && can("gl-entries", "approve") && (
                            <Button size="sm" variant="outline" className="mt-2" onClick={() => setRev({ id: e.id, number: e.number, reason: "" })}>{isRTL ? "عكس القيد" : "Reverse"}</Button>
                          )}
                        </TableCell>
                      </TableRow>
                    )}
                  </Fragment>
                ))}
                {entries.data && entries.data.length === 0 && <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">{isRTL ? "لا توجد قيود" : "No entries"}</TableCell></TableRow>}
              </TableBody>
            </Table>
          </Card>
        </TabsContent>

        <TabsContent value="chart" className="space-y-4">
          {can("gl-accounts", "create") && (
            <div className="flex justify-end"><Button className="gap-2" onClick={() => setAcc({ code: "", name_en: "", name_ar: "", type: "expense", parent_id: "" })}><Plus className="h-4 w-4" />{isRTL ? "حساب جديد" : "New account"}</Button></div>
          )}
          <Card className="p-6 overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className={align}>{isRTL ? "الكود" : "Code"}</TableHead>
                  <TableHead className={align}>{isRTL ? "الحساب" : "Account"}</TableHead>
                  <TableHead className={align}>{isRTL ? "النوع" : "Type"}</TableHead>
                  <TableHead className={align}>{isRTL ? "ملاحظات" : "Notes"}</TableHead>
                  <TableHead />
                </TableRow>
              </TableHeader>
              <TableBody>
                {accts.data?.map((a) => (
                  <TableRow key={a.id} className={Number(a.is_active) !== 1 ? "opacity-50" : ""}>
                    <TableCell className="font-mono" dir="ltr">{a.code}</TableCell>
                    <TableCell className={Number(a.is_postable) === 1 ? "" : "font-bold"}>{nm(isRTL, a.name_en, a.name_ar)}</TableCell>
                    <TableCell>{isRTL ? TYPE_AR[a.type] : a.type}</TableCell>
                    <TableCell className="text-xs text-muted-foreground">{a.system_key ? (isRTL ? "حساب نظام (ترحيل تلقائي)" : "system account (automatic postings)") : Number(a.is_postable) === 1 ? "" : isRTL ? "عنوان" : "heading"}</TableCell>
                    <TableCell>{can("gl-accounts", "edit") && !a.system_key && <Button variant="ghost" size="sm" onClick={() => activate(a)}>{Number(a.is_active) === 1 ? (isRTL ? "إيقاف" : "Disable") : isRTL ? "تفعيل" : "Enable"}</Button>}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        </TabsContent>
      </Tabs>

      <Dialog open={!!man} onOpenChange={(o) => !o && setMan(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
          <form onSubmit={saveManual} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "قيد يدوي" : "Manual journal entry"}</DialogTitle></DialogHeader>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <Field id="me_date" label={isRTL ? "التاريخ" : "Date"}><Input id="me_date" type="date" required value={man?.date ?? ""} onChange={(e) => setMan((s) => s && { ...s, date: e.target.value })} /></Field>
              <Field id="me_desc" label={isRTL ? "البيان" : "Description"} className="sm:col-span-2"><Input id="me_desc" required value={man?.description ?? ""} onChange={(e) => setMan((s) => s && { ...s, description: e.target.value })} /></Field>
            </div>
            <div className="space-y-2">
              {man?.rows.map((r, i) => (
                <div key={i} className="grid grid-cols-[1fr_110px_110px_32px] gap-2">
                  <select aria-label={`me account ${i + 1}`} className={selectClass} value={r.account_id} onChange={(e) => setRow(i, "account_id", e.target.value)}>
                    <option value="">{isRTL ? "الحساب…" : "Account…"}</option>
                    {postable.map((a) => <option key={a.id} value={a.id}>{a.code} · {nm(isRTL, a.name_en, a.name_ar)}</option>)}
                  </select>
                  <Input aria-label={`me debit ${i + 1}`} type="number" step="0.01" min="0" dir="ltr" placeholder={isRTL ? "مدين" : "Debit"} value={r.debit} onChange={(e) => setRow(i, "debit", e.target.value)} />
                  <Input aria-label={`me credit ${i + 1}`} type="number" step="0.01" min="0" dir="ltr" placeholder={isRTL ? "دائن" : "Credit"} value={r.credit} onChange={(e) => setRow(i, "credit", e.target.value)} />
                  <Button type="button" variant="ghost" size="sm" disabled={man.rows.length <= 2} onClick={() => setMan((s) => s && { ...s, rows: s.rows.filter((_, j) => j !== i) })} aria-label="remove line"><Trash2 className="h-4 w-4" /></Button>
                </div>
              ))}
              <Button type="button" variant="outline" size="sm" onClick={() => setMan((s) => s && { ...s, rows: [...s.rows, { account_id: "", debit: "", credit: "", memo: "" }] })}><Plus className="h-4 w-4" /></Button>
              <p className={`text-sm text-end ${Math.abs(dr - cr) < 0.005 && dr > 0 ? "text-emerald-700" : "text-red-600"}`} dir="ltr">Dr {dr.toFixed(2)} · Cr {cr.toFixed(2)} · {Math.abs(dr - cr) < 0.005 && dr > 0 ? "balanced" : `difference ${(dr - cr).toFixed(2)}`}</p>
            </div>
            <DialogFooter><Button type="submit" disabled={Math.abs(dr - cr) >= 0.005 || dr <= 0}>{isRTL ? "ترحيل" : "Post"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!rev} onOpenChange={(o) => !o && setRev(null)}>
        <DialogContent>
          <form onSubmit={reverse} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? `عكس القيد ${rev?.number}` : `Reverse ${rev?.number}`}</DialogTitle></DialogHeader>
            <Field id="rv_reason" label={isRTL ? "السبب (إلزامي)" : "Reason (required)"}><Input id="rv_reason" required value={rev?.reason ?? ""} onChange={(e) => setRev((s) => s && { ...s, reason: e.target.value })} /></Field>
            <DialogFooter><Button type="submit">{isRTL ? "عكس" : "Reverse"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!acc} onOpenChange={(o) => !o && setAcc(null)}>
        <DialogContent>
          <form onSubmit={saveAcc} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "حساب جديد" : "New account"}</DialogTitle></DialogHeader>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <Field id="ac_code" label={isRTL ? "الكود (أرقام)" : "Code (digits)"}><Input id="ac_code" dir="ltr" required value={acc?.code ?? ""} onChange={(e) => setAcc((s) => s && { ...s, code: e.target.value })} /></Field>
              <Field id="ac_type" label={isRTL ? "النوع" : "Type"}>
                <select id="ac_type" className={selectClass} value={acc?.type ?? ""} onChange={(e) => setAcc((s) => s && { ...s, type: e.target.value, parent_id: "" })}>
                  {Object.keys(TYPE_AR).map((t) => <option key={t} value={t}>{isRTL ? TYPE_AR[t] : t}</option>)}
                </select>
              </Field>
              <Field id="ac_en" label={isRTL ? "الاسم (إنجليزي)" : "Name (English)"}><Input id="ac_en" required value={acc?.name_en ?? ""} onChange={(e) => setAcc((s) => s && { ...s, name_en: e.target.value })} /></Field>
              <Field id="ac_ar" label={isRTL ? "الاسم (عربي)" : "Name (Arabic)"}><Input id="ac_ar" value={acc?.name_ar ?? ""} onChange={(e) => setAcc((s) => s && { ...s, name_ar: e.target.value })} /></Field>
              <Field id="ac_parent" label={isRTL ? "الحساب الأب" : "Parent account"} className="sm:col-span-2">
                <select id="ac_parent" className={selectClass} value={acc?.parent_id ?? ""} onChange={(e) => setAcc((s) => s && { ...s, parent_id: e.target.value })}>
                  <option value="">—</option>
                  {(accts.data ?? []).filter((a) => a.type === acc?.type && Number(a.is_postable) !== 1).map((a) => <option key={a.id} value={a.id}>{a.code} · {nm(isRTL, a.name_en, a.name_ar)}</option>)}
                </select>
              </Field>
            </div>
            <DialogFooter><Button type="submit">{isRTL ? "حفظ" : "Save"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
