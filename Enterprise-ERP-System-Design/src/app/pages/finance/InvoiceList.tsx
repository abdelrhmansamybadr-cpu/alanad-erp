import { useEffect, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router";
import { Plus, Search } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Badge } from "../../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { useApi } from "../../hooks/useApi";
import { ErrorBox, selectClass } from "../../components/inventory/common";
import { StatusBadge, money } from "../../components/procurement/common";
import { FinanceNav, Invoice } from "../../components/finance/common";
import { InvoiceFormDialog } from "../../components/finance/InvoiceFormDialog";

const STATUSES = ["Draft", "Pending", "On_Hold", "Approved", "Rejected"];

export function InvoiceList() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const [status, setStatus] = useState(params.get("status") ?? "");
  const [search, setSearch] = useState("");
  const q = new URLSearchParams();
  if (status) q.set("status", status);
  if (search.trim()) q.set("search", search.trim());
  const list = useApi<Invoice[]>(`/ap-invoices${q.toString() ? `?${q}` : ""}`);
  const [dlg, setDlg] = useState(false);
  const [preset, setPreset] = useState<string | undefined>();
  const align = isRTL ? "text-right" : "text-left";

  // coming from a purchase order: "Enter invoice"
  useEffect(() => {
    const po = params.get("po");
    if (po) { setPreset(po); setDlg(true); setParams({}, { replace: true }); }
  }, [params, setParams]);

  return (
    <div className="space-y-6">
      <FinanceNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "فواتير الموردين" : "Supplier Invoices"}</h1>
          <p className="text-muted-foreground mt-1">{isRTL ? "المطابقة الثلاثية: أمر الشراء ↔ الكمية المستلمة (الصافي) ↔ الفاتورة." : "3-way match: purchase order ↔ net weight received ↔ invoice."}</p>
        </div>
        {can("ap-invoices", "create") && (
          <Button className="gap-2" onClick={() => { setPreset(undefined); setDlg(true); }}><Plus className="h-4 w-4" />{isRTL ? "فاتورة جديدة" : "New invoice"}</Button>
        )}
      </div>
      <ErrorBox message={list.error} />
      <Card className="p-6 space-y-4">
        <div className="flex flex-wrap gap-3">
          <div className="relative">
            <Search className="absolute top-2.5 start-3 h-4 w-4 text-muted-foreground" />
            <Input className="ps-9 w-64" placeholder={isRTL ? "رقم الفاتورة" : "Invoice number"} value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
          <select aria-label="status" className={`${selectClass} max-w-[220px]`} value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{isRTL ? "كل الحالات" : "All statuses"}</option>
            {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        </div>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className={align}>{isRTL ? "الرقم" : "Number"}</TableHead>
                <TableHead className={align}>{isRTL ? "المورد" : "Supplier"}</TableHead>
                <TableHead className={align}>{isRTL ? "فاتورة المورد" : "Supplier inv."}</TableHead>
                <TableHead className={align}>{isRTL ? "الاستحقاق" : "Due"}</TableHead>
                <TableHead className={align}>{isRTL ? "الإجمالي" : "Total"}</TableHead>
                <TableHead className={align}>{isRTL ? "المتبقي" : "Open"}</TableHead>
                <TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data?.map((r) => (
                <TableRow key={r.id}>
                  <TableCell className="font-mono"><Link className="text-primary hover:underline" to={`/accounting/invoices/${r.id}`}>{r.number}</Link></TableCell>
                  <TableCell>{r.supplier_name}</TableCell>
                  <TableCell dir="ltr" className="text-sm">{r.supplier_invoice_no}</TableCell>
                  <TableCell dir="ltr" className="text-sm whitespace-nowrap">{r.due_date}{r.overdue && <Badge variant="destructive" className="ms-2">{isRTL ? "متأخرة" : "Overdue"}</Badge>}</TableCell>
                  <TableCell dir="ltr" className="font-mono text-sm">{money(r.total)}</TableCell>
                  <TableCell dir="ltr" className="font-mono text-sm">{r.status === "Approved" ? money(r.open_amount) : "—"}</TableCell>
                  <TableCell><StatusBadge status={r.status} isRTL={isRTL} /></TableCell>
                </TableRow>
              ))}
              {list.data && list.data.length === 0 && (
                <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground">{isRTL ? "لا توجد فواتير" : "No invoices"}</TableCell></TableRow>
              )}
            </TableBody>
          </Table>
        </div>
      </Card>
      <InvoiceFormDialog open={dlg} edit={null} presetPo={preset} onClose={() => setDlg(false)} onSaved={(id) => { setDlg(false); navigate(`/accounting/invoices/${id}`); }} />
    </div>
  );
}
