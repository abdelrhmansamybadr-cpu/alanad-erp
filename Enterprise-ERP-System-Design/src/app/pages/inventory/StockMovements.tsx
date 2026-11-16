import { useState } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";
import { InventoryNav } from "../../components/inventory/InventoryNav";
import { ErrorBox, fmtKg, selectClass, Item, ColdRoom, nm } from "../../components/inventory/common";

interface Row { id: number; item_id: number; batch_id: number; cold_room_id: number | null; movement: string; qty: number; reason: string | null; created_at: string }
interface Page { data: Row[]; total: number; page: number; per_page: number }

const MOVES = ["receipt", "issue", "transfer_in", "transfer_out", "adjust", "waste", "return"];
const MOVE_AR: Record<string, string> = {
  receipt: "استلام", issue: "صرف", transfer_in: "تحويل وارد", transfer_out: "تحويل صادر", adjust: "تسوية", waste: "هالك", return: "مرتجع",
};
const PER = 30;

export function StockMovements() {
  const { isRTL } = useI18n();
  const [page, setPage] = useState(1);
  const [movement, setMovement] = useState("");
  const [item, setItem] = useState("");
  const items = useApi<Item[]>("/items");
  const rooms = useApi<ColdRoom[]>("/cold-rooms");
  const q = new URLSearchParams({ page: String(page), per_page: String(PER) });
  if (movement) q.set("movement", movement);
  if (item) q.set("item_id", item);
  const led = useApi<Page>(`/stock/ledger?${q}`);

  const itemName = (id: number) => { const i = items.data?.find((x) => x.id === id); return i ? nm(isRTL, i.name, i.name_ar) : `#${id}`; };
  const roomName = (id: number | null) => { const r = rooms.data?.find((x) => x.id === id); return r ? nm(isRTL, r.name, r.name_ar) : "—"; };
  const pages = led.data ? Math.max(1, Math.ceil(led.data.total / led.data.per_page)) : 1;
  const align = isRTL ? "text-right" : "text-left";
  const Prev = isRTL ? ChevronRight : ChevronLeft;
  const Next = isRTL ? ChevronLeft : ChevronRight;

  return (
    <div className="space-y-6">
      <InventoryNav />
      <div>
        <h1 className="text-3xl font-bold">{isRTL ? "سجل حركة المخزون" : "Stock Ledger"}</h1>
        <p className="text-muted-foreground mt-1">{isRTL ? "كل حركة تُسجَّل ولا تُعدَّل ولا تُحذف." : "Every movement is recorded and never edited or deleted."}</p>
      </div>
      <ErrorBox message={led.error} />
      <Card className="p-4 flex flex-wrap gap-3">
        <select aria-label="item" className={`${selectClass} max-w-xs`} value={item} onChange={(e) => { setItem(e.target.value); setPage(1); }}>
          <option value="">{isRTL ? "كل الأصناف" : "All items"}</option>
          {items.data?.map((i) => <option key={i.id} value={i.id}>{nm(isRTL, i.name, i.name_ar)}</option>)}
        </select>
        <select aria-label="movement" className={`${selectClass} max-w-xs`} value={movement} onChange={(e) => { setMovement(e.target.value); setPage(1); }}>
          <option value="">{isRTL ? "كل الحركات" : "All movements"}</option>
          {MOVES.map((m) => <option key={m} value={m}>{isRTL ? MOVE_AR[m] : m}</option>)}
        </select>
      </Card>
      <Card className="p-6 overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className={align}>{isRTL ? "الوقت" : "Time"}</TableHead>
              <TableHead className={align}>{isRTL ? "الصنف" : "Item"}</TableHead>
              <TableHead className={align}>{isRTL ? "الحركة" : "Movement"}</TableHead>
              <TableHead className={align}>{isRTL ? "التلاجة" : "Room"}</TableHead>
              <TableHead className={align}>{isRTL ? "الكمية (كجم)" : "Qty (kg)"}</TableHead>
              <TableHead className={align}>{isRTL ? "السبب" : "Reason"}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {led.data?.data.map((r) => (
              <TableRow key={r.id}>
                <TableCell dir="ltr" className="text-xs font-mono whitespace-nowrap">{r.created_at}</TableCell>
                <TableCell>{itemName(r.item_id)}</TableCell>
                <TableCell>{isRTL ? MOVE_AR[r.movement] ?? r.movement : r.movement}</TableCell>
                <TableCell>{roomName(r.cold_room_id)}</TableCell>
                <TableCell dir="ltr" className={`font-mono ${Number(r.qty) < 0 ? "text-red-600" : "text-emerald-700"}`}>{Number(r.qty) > 0 ? "+" : ""}{fmtKg(Number(r.qty))}</TableCell>
                <TableCell className="text-sm">{r.reason || "—"}</TableCell>
              </TableRow>
            ))}
            {led.data && led.data.data.length === 0 && (
              <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">{isRTL ? "لا توجد حركات" : "No movements"}</TableCell></TableRow>
            )}
          </TableBody>
        </Table>
        <div className="flex items-center justify-between mt-4 text-sm text-muted-foreground">
          <span>{led.data ? (isRTL ? `${led.data.total} حركة` : `${led.data.total} movements`) : ""}</span>
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)} aria-label="previous"><Prev className="h-4 w-4" /></Button>
            <span>{isRTL ? `صفحة ${page} من ${pages}` : `Page ${page} of ${pages}`}</span>
            <Button variant="outline" size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)} aria-label="next"><Next className="h-4 w-4" /></Button>
          </div>
        </div>
      </Card>
    </div>
  );
}
