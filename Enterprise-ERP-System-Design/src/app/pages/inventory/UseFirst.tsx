import { useState } from "react";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";
import { InventoryNav } from "../../components/inventory/InventoryNav";
import { DaysBadge, ErrorBox, fmtKg, selectClass, nm } from "../../components/inventory/common";

interface Row { item_id: number; item_name: string; item_name_ar: string | null; batch_id: number; batch_no: string; expiry_date: string; qty: number; cold_room_name: string | null; days_left: number; expired: boolean }

export function UseFirst() {
  const { isRTL } = useI18n();
  const [days, setDays] = useState("3");
  const res = useApi<Row[]>(`/stock/use-first?days=${days}`);
  const align = isRTL ? "text-right" : "text-left";
  const expired = res.data?.filter((r) => r.expired) ?? [];
  const soon = res.data?.filter((r) => !r.expired) ?? [];
  const kg = (rows: Row[]) => rows.reduce((s, r) => s + r.qty, 0);

  return (
    <div className="space-y-6">
      <InventoryNav />
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">{isRTL ? "استخدم أولاً" : "Use First"}</h1>
          <p className="text-muted-foreground mt-1">{isRTL ? "الدفعات التي تنتهي صلاحيتها قريباً، الأقدم أولاً." : "Batches about to expire, earliest first."}</p>
        </div>
        <select aria-label="days" className={`${selectClass} max-w-[200px]`} value={days} onChange={(e) => setDays(e.target.value)}>
          {["1", "3", "5", "7", "14"].map((d) => <option key={d} value={d}>{isRTL ? `خلال ${d} يوم` : `Within ${d} days`}</option>)}
        </select>
      </div>
      <ErrorBox message={res.error} />
      <div className="grid grid-cols-2 gap-4">
        <Card className="p-4"><p className="text-xs text-muted-foreground">{isRTL ? "منتهية الصلاحية" : "Expired"}</p><p className="text-2xl font-bold text-red-600">{fmtKg(kg(expired))} kg</p></Card>
        <Card className="p-4"><p className="text-xs text-muted-foreground">{isRTL ? "تنتهي قريباً" : "Expiring soon"}</p><p className="text-2xl font-bold text-amber-600">{fmtKg(kg(soon))} kg</p></Card>
      </div>
      <Card className="p-6 overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className={align}>{isRTL ? "الصنف" : "Item"}</TableHead>
              <TableHead className={align}>{isRTL ? "الدفعة" : "Batch"}</TableHead>
              <TableHead className={align}>{isRTL ? "التلاجة" : "Room"}</TableHead>
              <TableHead className={align}>{isRTL ? "الانتهاء" : "Expires"}</TableHead>
              <TableHead className={align}>{isRTL ? "المتبقي" : "Left"}</TableHead>
              <TableHead className={align}>{isRTL ? "الكمية (كجم)" : "Qty (kg)"}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {res.data?.map((r, k) => (
              <TableRow key={k}>
                <TableCell className="font-medium">{nm(isRTL, r.item_name, r.item_name_ar)}</TableCell>
                <TableCell className="font-mono text-sm">{r.batch_no}</TableCell>
                <TableCell>{r.cold_room_name || "—"}</TableCell>
                <TableCell dir="ltr" className="text-sm">{r.expiry_date}</TableCell>
                <TableCell><DaysBadge days={r.days_left} isRTL={isRTL} /></TableCell>
                <TableCell className="font-mono">{fmtKg(r.qty)}</TableCell>
              </TableRow>
            ))}
            {res.data && res.data.length === 0 && (
              <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">{isRTL ? "لا توجد دفعات قريبة الانتهاء" : "Nothing is about to expire"}</TableCell></TableRow>
            )}
          </TableBody>
        </Table>
      </Card>
    </div>
  );
}
