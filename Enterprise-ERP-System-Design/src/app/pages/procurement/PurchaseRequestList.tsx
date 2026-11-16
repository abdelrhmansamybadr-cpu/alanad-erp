import { useState } from "react";
import { Link, useNavigate } from "react-router";
import { Plus, Lightbulb } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { useApi } from "../../hooks/useApi";
import { ErrorBox, fmtKg, selectClass, Item, nm } from "../../components/inventory/common";
import { ProcurementNav, PR, StatusBadge } from "../../components/procurement/common";
import { PRFormDialog, PRDraft, emptyDraft } from "../../components/procurement/PRFormDialog";

interface Suggestion { item_id: number; name: string; name_ar: string | null; on_hand: number; reorder_level: number; shortfall: number }
const STATUSES = ["Draft", "Pending", "Approved", "Rejected", "Converted"];

export function PurchaseRequestList() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const navigate = useNavigate();
  const [status, setStatus] = useState("");
  const prs = useApi<PR[]>(`/purchase-requests${status ? `?status=${status}` : ""}`);
  const sugg = useApi<Suggestion[]>("/purchase-requests/suggestions");
  const items = useApi<Item[]>("/items");
  const [draft, setDraft] = useState<PRDraft | null>(null);
  const align = isRTL ? "text-right" : "text-left";

  const fromSuggestions = () =>
    setDraft(emptyDraft(sugg.data!.map((s) => ({ item_id: String(s.item_id), qty: String(Math.ceil(s.shortfall)), note: "" }))));

  return (
    <div className="space-y-6">
      <ProcurementNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "طلبات الشراء" : "Purchase Requests"}</h1>
          <p className="text-muted-foreground mt-1">{isRTL ? "الطلب يُرسل للمشرف، وبعد اعتماده يتحول إلى أمر شراء." : "A request goes to a supervisor; once approved it becomes a purchase order."}</p>
        </div>
        {can("purchase-requests", "create") && (
          <Button className="gap-2" onClick={() => setDraft(emptyDraft())}>
            <Plus className="h-4 w-4" />
            {isRTL ? "طلب جديد" : "New request"}
          </Button>
        )}
      </div>
      <ErrorBox message={prs.error || sugg.error} />

      {(sugg.data?.length ?? 0) > 0 && can("purchase-requests", "create") && (
        <Card className="border-amber-300">
          <CardHeader className="pb-2 flex-row items-center justify-between gap-2">
            <CardTitle className="text-base flex items-center gap-2"><Lightbulb className="h-4 w-4 text-amber-600" />{isRTL ? "أصناف تحت حد إعادة الطلب" : "Items below their reorder level"}</CardTitle>
            <Button size="sm" variant="outline" onClick={fromSuggestions}>{isRTL ? "طلب كل النواقص" : "Request all shortfalls"}</Button>
          </CardHeader>
          <CardContent className="text-sm space-y-1">
            {sugg.data!.slice(0, 6).map((s) => (
              <div key={s.item_id} className="flex justify-between gap-3">
                <span>{nm(isRTL, s.name, s.name_ar)}</span>
                <span className="text-muted-foreground" dir="ltr">{fmtKg(s.on_hand)} / {fmtKg(s.reorder_level)} kg → <b>-{fmtKg(s.shortfall)}</b></span>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      <Card className="p-6 space-y-4">
        <select aria-label="status" className={`${selectClass} max-w-[220px]`} value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">{isRTL ? "كل الحالات" : "All statuses"}</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className={align}>{isRTL ? "الرقم" : "Number"}</TableHead>
                <TableHead className={align}>{isRTL ? "التاريخ" : "Date"}</TableHead>
                <TableHead className={align}>{isRTL ? "مقدّم الطلب" : "Requested by"}</TableHead>
                <TableHead className={align}>{isRTL ? "الأصناف" : "Lines"}</TableHead>
                <TableHead className={align}>{isRTL ? "مطلوب بتاريخ" : "Needed by"}</TableHead>
                <TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {prs.data?.map((p) => (
                <TableRow key={p.id}>
                  <TableCell className="font-mono"><Link className="text-primary hover:underline" to={`/procurement/requests/${p.id}`}>{p.number}</Link></TableCell>
                  <TableCell dir="ltr" className="text-sm">{p.date}</TableCell>
                  <TableCell>{p.requested_by_name || "—"}</TableCell>
                  <TableCell>{p.line_count}</TableCell>
                  <TableCell dir="ltr" className="text-sm">{p.needed_by || "—"}</TableCell>
                  <TableCell><StatusBadge status={p.status} isRTL={isRTL} /></TableCell>
                </TableRow>
              ))}
              {prs.data && prs.data.length === 0 && (
                <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">{isRTL ? "لا توجد طلبات" : "No requests"}</TableCell></TableRow>
              )}
            </TableBody>
          </Table>
        </div>
      </Card>

      <PRFormDialog draft={draft} items={items.data ?? []} onClose={() => setDraft(null)} onSaved={(pr) => { setDraft(null); navigate(`/procurement/requests/${pr.id}`); }} />
    </div>
  );
}
