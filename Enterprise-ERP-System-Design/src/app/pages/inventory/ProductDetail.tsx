import { Link, useParams } from "react-router";
import { ArrowLeft, ArrowRight } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";
import { InventoryNav } from "../../components/inventory/InventoryNav";
import { DaysBadge, ErrorBox, fmtKg, Item, Balance, nm } from "../../components/inventory/common";

interface LedgerRow { id: number; batch_id: number; movement: string; qty: number; reason: string | null; created_at: string; cold_room_id: number | null }
interface LedgerPage { data: LedgerRow[]; total: number }

const MOVE_AR: Record<string, string> = {
  receipt: "استلام", issue: "صرف", transfer_in: "تحويل وارد", transfer_out: "تحويل صادر", adjust: "تسوية", waste: "هالك", return: "مرتجع",
};

export function ProductDetail() {
  const { id } = useParams();
  const { isRTL } = useI18n();
  const item = useApi<Item>(`/items/${id}`);
  const bal = useApi<Balance[]>(`/stock/balance?item_id=${id}`);
  const led = useApi<LedgerPage>(`/stock/ledger?item_id=${id}&per_page=30`);
  const Back = isRTL ? ArrowRight : ArrowLeft;
  const align = isRTL ? "text-right" : "text-left";
  const total = bal.data?.reduce((s, b) => s + b.qty, 0) ?? 0;
  const i = item.data;

  return (
    <div className="space-y-6">
      <InventoryNav />
      <Link to="/inventory/products" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline">
        <Back className="h-4 w-4" />
        {isRTL ? "كل الأصناف" : "All items"}
      </Link>
      <ErrorBox message={item.error || bal.error || led.error} />

      {i && (
        <>
          <div>
            <h1 className="text-3xl font-bold">{nm(isRTL, i.name, i.name_ar)}</h1>
            <p className="text-muted-foreground mt-1" dir="ltr">
              {[i.code, i.barcode, i.item_group].filter(Boolean).join(" · ")}
            </p>
          </div>

          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            {[
              [isRTL ? "في المخزون" : "In stock", `${fmtKg(total)} ${i.uom || "kg"}`],
              [isRTL ? "الصلاحية" : "Shelf life", i.shelf_life_days ? (isRTL ? `${i.shelf_life_days} يوم` : `${i.shelf_life_days} days`) : "—"],
              [isRTL ? "حرارة التخزين" : "Storage temp", i.storage_temp_min != null ? `${i.storage_temp_min}…${i.storage_temp_max} °C` : "—"],
              [isRTL ? "مجموعة التوافق" : "Compat. group", i.compat_group || "—"],
            ].map(([k, v]) => (
              <Card key={k as string}>
                <CardContent className="pt-6">
                  <p className="text-xs text-muted-foreground">{k}</p>
                  <p className="text-xl font-semibold mt-1" dir="ltr">{v}</p>
                </CardContent>
              </Card>
            ))}
          </div>

          <Card>
            <CardHeader><CardTitle className="text-base">{isRTL ? "الدفعات الموجودة (الأقدم صلاحية أولاً)" : "Batches in stock (earliest expiry first)"}</CardTitle></CardHeader>
            <CardContent className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className={align}>{isRTL ? "الدفعة" : "Batch"}</TableHead>
                    <TableHead className={align}>{isRTL ? "التلاجة" : "Cold room"}</TableHead>
                    <TableHead className={align}>{isRTL ? "الرف" : "Rack"}</TableHead>
                    <TableHead className={align}>{isRTL ? "الوصول" : "Arrived"}</TableHead>
                    <TableHead className={align}>{isRTL ? "الانتهاء" : "Expires"}</TableHead>
                    <TableHead className={align}>{isRTL ? "المتبقي" : "Left"}</TableHead>
                    <TableHead className={align}>{isRTL ? "الكمية (كجم)" : "Qty (kg)"}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {bal.data?.map((b, k) => (
                    <TableRow key={k}>
                      <TableCell className="font-mono text-sm">{b.batch_no}{b.grade ? ` (${b.grade})` : ""}</TableCell>
                      <TableCell>{b.cold_room_name || "—"}</TableCell>
                      <TableCell>{[b.rack, b.position].filter(Boolean).join("/") || "—"}</TableCell>
                      <TableCell dir="ltr" className="text-sm">{b.arrival_date}</TableCell>
                      <TableCell dir="ltr" className="text-sm">{b.expiry_date}</TableCell>
                      <TableCell><DaysBadge days={b.days_left} isRTL={isRTL} /></TableCell>
                      <TableCell className="font-mono">{fmtKg(b.qty)}</TableCell>
                    </TableRow>
                  ))}
                  {bal.data && bal.data.length === 0 && (
                    <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground">{isRTL ? "لا يوجد مخزون" : "Nothing in stock"}</TableCell></TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          <Card>
            <CardHeader><CardTitle className="text-base">{isRTL ? "آخر الحركات" : "Latest movements"}</CardTitle></CardHeader>
            <CardContent className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className={align}>{isRTL ? "الوقت" : "Time"}</TableHead>
                    <TableHead className={align}>{isRTL ? "الحركة" : "Movement"}</TableHead>
                    <TableHead className={align}>{isRTL ? "الكمية" : "Qty"}</TableHead>
                    <TableHead className={align}>{isRTL ? "السبب" : "Reason"}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {led.data?.data.map((r) => (
                    <TableRow key={r.id}>
                      <TableCell dir="ltr" className="text-xs font-mono whitespace-nowrap">{r.created_at}</TableCell>
                      <TableCell>{isRTL ? MOVE_AR[r.movement] ?? r.movement : r.movement}</TableCell>
                      <TableCell className={`font-mono ${Number(r.qty) < 0 ? "text-red-600" : "text-emerald-700"}`} dir="ltr">{Number(r.qty) > 0 ? "+" : ""}{fmtKg(Number(r.qty))}</TableCell>
                      <TableCell className="text-sm">{r.reason || "—"}</TableCell>
                    </TableRow>
                  ))}
                  {led.data && led.data.data.length === 0 && (
                    <TableRow><TableCell colSpan={4} className="text-center text-muted-foreground">{isRTL ? "لا توجد حركات" : "No movements"}</TableCell></TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}
