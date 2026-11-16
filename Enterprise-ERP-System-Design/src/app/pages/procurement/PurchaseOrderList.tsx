import { useState } from "react";
import { Link } from "react-router";
import { Search } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Input } from "../../components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";
import { ErrorBox, selectClass } from "../../components/inventory/common";
import { ProcurementNav, PO, StatusBadge, money } from "../../components/procurement/common";

const STATUSES = ["Draft", "Submitted", "Approved", "Received", "Cancelled"];

export function PurchaseOrderList() {
  const { isRTL } = useI18n();
  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");
  const q = new URLSearchParams();
  if (status) q.set("status", status);
  if (search.trim()) q.set("search", search.trim());
  const pos = useApi<PO[]>(`/purchase-orders${q.toString() ? `?${q}` : ""}`);
  const align = isRTL ? "text-right" : "text-left";

  return (
    <div className="space-y-6">
      <ProcurementNav />
      <div>
        <h1 className="text-3xl font-bold">{isRTL ? "أوامر الشراء" : "Purchase Orders"}</h1>
        <p className="text-muted-foreground mt-1">{isRTL ? "تُنشأ من طلب شراء معتمد، بأسعار المورد الحالية. الأوامر الأكبر من حد المالية تحتاج اعتماداً." : "Created from an approved request at the supplier's current prices. Orders above the finance limit need approval."}</p>
      </div>
      <ErrorBox message={pos.error} />
      <Card className="p-6 space-y-4">
        <div className="flex flex-wrap gap-3">
          <div className="relative">
            <Search className="absolute top-2.5 start-3 h-4 w-4 text-muted-foreground" />
            <Input className="ps-9 w-64" placeholder={isRTL ? "رقم الأمر أو المورد" : "Number or supplier"} value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
          <select aria-label="status" className={`${selectClass} max-w-[200px]`} value={status} onChange={(e) => setStatus(e.target.value)}>
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
                <TableHead className={align}>{isRTL ? "التاريخ" : "Date"}</TableHead>
                <TableHead className={align}>{isRTL ? "الإجمالي" : "Total"}</TableHead>
                <TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {pos.data?.map((p) => (
                <TableRow key={p.id}>
                  <TableCell className="font-mono"><Link className="text-primary hover:underline" to={`/procurement/purchase-orders/${p.id}`}>{p.number}</Link></TableCell>
                  <TableCell>{p.supplier_name || "—"}</TableCell>
                  <TableCell dir="ltr" className="text-sm">{p.date}</TableCell>
                  <TableCell dir="ltr" className="font-mono text-sm">{money(p.total, p.currency)}</TableCell>
                  <TableCell><StatusBadge status={p.status} isRTL={isRTL} /></TableCell>
                </TableRow>
              ))}
              {pos.data && pos.data.length === 0 && (
                <TableRow><TableCell colSpan={5} className="text-center text-muted-foreground">{isRTL ? "لا توجد أوامر شراء" : "No purchase orders"}</TableCell></TableRow>
              )}
            </TableBody>
          </Table>
        </div>
      </Card>
    </div>
  );
}
