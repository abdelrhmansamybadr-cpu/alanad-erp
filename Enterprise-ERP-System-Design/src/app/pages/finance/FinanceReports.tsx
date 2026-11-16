import { useState } from "react";
import { Card } from "../../components/ui/card";
import { Badge } from "../../components/ui/badge";
import { Input } from "../../components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "../../components/ui/tabs";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";
import { ErrorBox, Field, selectClass, nm } from "../../components/inventory/common";
import { Supplier, money } from "../../components/procurement/common";
import { FinanceNav } from "../../components/finance/common";

interface TB { rows: { account_id: number; code: string; name_en: string; name_ar: string | null; type: string; debit: number; credit: number; balance: number }[]; total_debit: number; total_credit: number; balanced: boolean }
interface Aging { suppliers: { supplier_id: number; supplier_name: string; not_due: number; d1_30: number; d31_60: number; d61_90: number; over_90: number; total: number }[]; totals: Record<string, number> }
interface Stmt { supplier: { id: number; name: string }; rows: { date: string; kind: string; number: string; reference: string | null; invoiced: number; paid: number; balance: number }[]; balance: number }
interface Vat { from: string; to: string; purchase_invoices: number; taxable_purchases: number; input_vat: number; output_vat: number; net_vat_payable: number }

const n = (v: number) => v.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const monthStart = () => new Date(new Date().getFullYear(), new Date().getMonth(), 1).toLocaleDateString("sv");
const monthEnd = () => new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).toLocaleDateString("sv");

export function FinanceReports() {
  const { isRTL } = useI18n();
  const [to, setTo] = useState("");
  const tb = useApi<TB>(`/finance-reports/trial-balance${to ? `?to=${to}` : ""}`);
  const ag = useApi<Aging>("/finance-reports/aging");
  const sup = useApi<Supplier[]>("/suppliers");
  const [supplier, setSupplier] = useState("");
  const st = useApi<Stmt>(supplier ? `/finance-reports/statement?supplier_id=${supplier}` : null);
  const [from, setFrom] = useState(monthStart());
  const [until, setUntil] = useState(monthEnd());
  const vat = useApi<Vat>(`/finance-reports/vat-return?from=${from}&to=${until}`);
  const align = isRTL ? "text-right" : "text-left";

  return (
    <div className="space-y-6">
      <FinanceNav />
      <div>
        <h1 className="text-3xl font-bold">{isRTL ? "التقارير المالية" : "Finance Reports"}</h1>
        <p className="text-muted-foreground mt-1">{isRTL ? "كلها من دفتر القيود والفواتير المعتمدة." : "All from the ledger and the approved invoices."}</p>
      </div>
      <ErrorBox message={tb.error || ag.error || vat.error} />
      <Tabs defaultValue="tb">
        <TabsList className="flex-wrap h-auto">
          <TabsTrigger value="tb">{isRTL ? "ميزان المراجعة" : "Trial balance"}</TabsTrigger>
          <TabsTrigger value="aging">{isRTL ? "أعمار الذمم" : "Payables ageing"}</TabsTrigger>
          <TabsTrigger value="stmt">{isRTL ? "كشف حساب مورد" : "Supplier statement"}</TabsTrigger>
          <TabsTrigger value="vat">{isRTL ? "إقرار الضريبة" : "VAT return"}</TabsTrigger>
        </TabsList>

        <TabsContent value="tb" className="space-y-4">
          <Field id="tb_to" label={isRTL ? "حتى تاريخ (اختياري)" : "As of date (optional)"} className="max-w-[220px]"><Input id="tb_to" type="date" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
          <Card className="p-6 overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className={align}>{isRTL ? "الكود" : "Code"}</TableHead>
                  <TableHead className={align}>{isRTL ? "الحساب" : "Account"}</TableHead>
                  <TableHead className={align}>{isRTL ? "مدين" : "Debit"}</TableHead>
                  <TableHead className={align}>{isRTL ? "دائن" : "Credit"}</TableHead>
                  <TableHead className={align}>{isRTL ? "الرصيد" : "Balance"}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {tb.data?.rows.map((r) => (
                  <TableRow key={r.account_id}>
                    <TableCell className="font-mono" dir="ltr">{r.code}</TableCell>
                    <TableCell>{nm(isRTL, r.name_en, r.name_ar)}</TableCell>
                    <TableCell dir="ltr" className="font-mono">{r.debit ? n(r.debit) : ""}</TableCell>
                    <TableCell dir="ltr" className="font-mono">{r.credit ? n(r.credit) : ""}</TableCell>
                    <TableCell dir="ltr" className="font-mono">{n(r.balance)}</TableCell>
                  </TableRow>
                ))}
                {tb.data && (
                  <TableRow className="font-bold bg-muted/40">
                    <TableCell colSpan={2}>{isRTL ? "الإجمالي" : "Total"} {tb.data.balanced ? <Badge className="ms-2 bg-emerald-600 hover:bg-emerald-600">{isRTL ? "متوازن" : "balanced"}</Badge> : <Badge variant="destructive" className="ms-2">{isRTL ? "غير متوازن" : "NOT balanced"}</Badge>}</TableCell>
                    <TableCell dir="ltr" className="font-mono">{n(tb.data.total_debit)}</TableCell>
                    <TableCell dir="ltr" className="font-mono">{n(tb.data.total_credit)}</TableCell>
                    <TableCell />
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </Card>
        </TabsContent>

        <TabsContent value="aging">
          <Card className="p-6 overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className={align}>{isRTL ? "المورد" : "Supplier"}</TableHead>
                  <TableHead className={align}>{isRTL ? "غير مستحقة" : "Not due"}</TableHead>
                  <TableHead className={align}>1-30</TableHead>
                  <TableHead className={align}>31-60</TableHead>
                  <TableHead className={align}>61-90</TableHead>
                  <TableHead className={align}>90+</TableHead>
                  <TableHead className={align}>{isRTL ? "الإجمالي" : "Total"}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {ag.data?.suppliers.map((s) => (
                  <TableRow key={s.supplier_id}>
                    <TableCell className="font-medium">{s.supplier_name}</TableCell>
                    {[s.not_due, s.d1_30, s.d31_60, s.d61_90, s.over_90, s.total].map((v, i) => <TableCell key={i} dir="ltr" className={`font-mono ${i === 5 ? "font-bold" : ""} ${i === 4 && v > 0 ? "text-red-600" : ""}`}>{v ? n(v) : "—"}</TableCell>)}
                  </TableRow>
                ))}
                {ag.data && ag.data.suppliers.length === 0 && <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground">{isRTL ? "لا توجد ذمم مفتوحة" : "No open payables"}</TableCell></TableRow>}
                {ag.data && ag.data.suppliers.length > 0 && (
                  <TableRow className="font-bold bg-muted/40">
                    <TableCell>{isRTL ? "الإجمالي" : "Total"}</TableCell>
                    {["not_due", "d1_30", "d31_60", "d61_90", "over_90", "total"].map((k) => <TableCell key={k} dir="ltr" className="font-mono">{n(ag.data!.totals[k])}</TableCell>)}
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </Card>
        </TabsContent>

        <TabsContent value="stmt" className="space-y-4">
          <select aria-label="supplier" className={`${selectClass} max-w-xs`} value={supplier} onChange={(e) => setSupplier(e.target.value)}>
            <option value="">{isRTL ? "اختر المورد…" : "Choose a supplier…"}</option>
            {sup.data?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
          {st.data && (
            <Card className="p-6 overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className={align}>{isRTL ? "التاريخ" : "Date"}</TableHead>
                    <TableHead className={align}>{isRTL ? "المستند" : "Document"}</TableHead>
                    <TableHead className={align}>{isRTL ? "المرجع" : "Reference"}</TableHead>
                    <TableHead className={align}>{isRTL ? "فواتير" : "Invoiced"}</TableHead>
                    <TableHead className={align}>{isRTL ? "مدفوع" : "Paid"}</TableHead>
                    <TableHead className={align}>{isRTL ? "الرصيد" : "Balance"}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {st.data.rows.map((r, i) => (
                    <TableRow key={i}>
                      <TableCell dir="ltr" className="text-sm">{r.date}</TableCell>
                      <TableCell className="font-mono text-sm">{r.number}</TableCell>
                      <TableCell dir="ltr" className="text-sm">{r.reference ?? ""}</TableCell>
                      <TableCell dir="ltr" className="font-mono">{r.invoiced ? n(r.invoiced) : ""}</TableCell>
                      <TableCell dir="ltr" className="font-mono">{r.paid ? n(r.paid) : ""}</TableCell>
                      <TableCell dir="ltr" className="font-mono">{n(r.balance)}</TableCell>
                    </TableRow>
                  ))}
                  <TableRow className="font-bold bg-muted/40"><TableCell colSpan={5}>{isRTL ? "المستحق للمورد" : "Owed to the supplier"}</TableCell><TableCell dir="ltr" className="font-mono">{money(st.data.balance)}</TableCell></TableRow>
                </TableBody>
              </Table>
            </Card>
          )}
        </TabsContent>

        <TabsContent value="vat" className="space-y-4">
          <div className="flex flex-wrap gap-3">
            <Field id="vat_from" label={isRTL ? "من" : "From"}><Input id="vat_from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
            <Field id="vat_to" label={isRTL ? "إلى" : "To"}><Input id="vat_to" type="date" value={until} onChange={(e) => setUntil(e.target.value)} /></Field>
          </div>
          {vat.data && (
            <Card className="p-6 max-w-xl space-y-2 text-sm">
              {[
                [isRTL ? "فواتير الشراء المعتمدة" : "Approved purchase invoices", String(vat.data.purchase_invoices)],
                [isRTL ? "المشتريات الخاضعة للضريبة" : "Taxable purchases", money(vat.data.taxable_purchases)],
                [isRTL ? "ضريبة المدخلات (قابلة للاسترداد)" : "Input VAT (recoverable)", money(vat.data.input_vat)],
                [isRTL ? "ضريبة المخرجات (من المبيعات)" : "Output VAT (from sales)", money(vat.data.output_vat)],
              ].map(([k, v]) => <div key={k} className="flex justify-between"><span>{k}</span><span className="font-mono" dir="ltr">{v}</span></div>)}
              <div className="flex justify-between font-bold text-base border-t pt-2"><span>{isRTL ? "صافي الضريبة المستحقة / (المستردة)" : "Net VAT payable / (refundable)"}</span><span className="font-mono" dir="ltr">{money(vat.data.net_vat_payable)}</span></div>
              <p className="text-xs text-muted-foreground">{isRTL ? "ضريبة المخرجات تظهر عند بناء المبيعات (المرحلة ٦). تأكد مع المحاسب من معاملة الخضار والفاكهة الطازجة." : "Output VAT appears when Sales is built (phase 6). Confirm with your accountant how fresh produce is treated."}</p>
            </Card>
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
}
