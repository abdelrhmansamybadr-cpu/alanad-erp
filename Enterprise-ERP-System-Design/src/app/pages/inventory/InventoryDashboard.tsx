import { useMemo } from "react";
import { Link } from "react-router";
import { Package, Snowflake, Clock, AlertTriangle } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { Badge } from "../../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";
import { InventoryNav } from "../../components/inventory/InventoryNav";
import { DaysBadge, ErrorBox, fmtKg, Item, ColdRoom, Balance, nm } from "../../components/inventory/common";

interface UseFirstRow { item_name: string; item_name_ar: string | null; batch_no: string; qty: number; days_left: number; expired: boolean; cold_room_name: string | null }
interface Alarm { id: number; cold_room_name: string; kind: string; first_value: number }

const STATUS: Record<string, [string, string, string]> = {
  ok: ["Normal", "سليمة", "bg-emerald-600 hover:bg-emerald-600"],
  alarm: ["Alarm", "إنذار", "bg-amber-500 hover:bg-amber-500"],
  hold: ["On hold", "محجوزة", "bg-red-600 hover:bg-red-600"],
};

export function InventoryDashboard() {
  const { isRTL } = useI18n();
  const items = useApi<Item[]>("/items");
  const bal = useApi<Balance[]>("/stock/balance");
  const rooms = useApi<ColdRoom[]>("/cold-rooms");
  const first = useApi<UseFirstRow[]>("/stock/use-first");
  const alarms = useApi<Alarm[]>("/alarms?status=open");

  const totalKg = bal.data?.reduce((s, b) => s + b.qty, 0) ?? 0;
  const byRoom = useMemo(() => {
    const m = new Map<number, number>();
    bal.data?.forEach((b) => b.cold_room_id != null && m.set(b.cold_room_id, (m.get(b.cold_room_id) ?? 0) + b.qty));
    return m;
  }, [bal.data]);
  const expired = first.data?.filter((r) => r.expired).length ?? 0;
  const align = isRTL ? "text-right" : "text-left";

  const kpis: [string, string, string | number, React.ComponentType<{ className?: string }>, string][] = [
    ["Items", "الأصناف", items.data?.length ?? "…", Package, "/inventory/products"],
    ["In stock (kg)", "المخزون (كجم)", bal.data ? fmtKg(totalKg) : "…", Package, "/inventory/movements"],
    ["Cold rooms", "غرف التبريد", rooms.data?.length ?? "…", Snowflake, "/inventory/warehouses"],
    ["Use first", "استخدم أولاً", first.data?.length ?? "…", Clock, "/inventory/use-first"],
  ];

  return (
    <div className="space-y-6">
      <InventoryNav />
      <div>
        <h1 className="text-3xl font-bold">{isRTL ? "المخزون وغرف التبريد" : "Inventory & Cold Rooms"}</h1>
        <p className="text-muted-foreground mt-1">{isRTL ? "الأرقام مباشرة من سجل المخزون." : "Live numbers from the stock ledger."}</p>
      </div>
      <ErrorBox message={items.error || bal.error || rooms.error || first.error} />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {kpis.map(([en, ar, v, Icon, href]) => (
          <Link key={href} to={href}>
            <Card className="hover:shadow-md transition-shadow">
              <CardContent className="pt-6 flex items-center justify-between">
                <div>
                  <p className="text-xs text-muted-foreground">{isRTL ? ar : en}</p>
                  <p className="text-2xl font-bold mt-1" dir="ltr">{v}</p>
                </div>
                <Icon className="h-8 w-8 text-primary/60" />
              </CardContent>
            </Card>
          </Link>
        ))}
      </div>

      {(alarms.data?.length ?? 0) > 0 && (
        <Card className="border-amber-300">
          <CardContent className="pt-6 flex flex-wrap items-center gap-3 text-sm">
            <AlertTriangle className="h-5 w-5 text-amber-600" />
            <b>{isRTL ? "إنذارات مفتوحة:" : "Open alarms:"}</b>
            {alarms.data!.map((a) => <Badge key={a.id} className="bg-amber-500 hover:bg-amber-500">{a.cold_room_name} · {Number(a.first_value).toFixed(1)}°</Badge>)}
            <Link to="/inventory/warehouses" className="text-primary hover:underline ms-auto">{isRTL ? "فتح" : "Open"}</Link>
          </CardContent>
        </Card>
      )}

      <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <Card>
          <CardHeader className="pb-2"><CardTitle className="text-base">{isRTL ? "غرف التبريد" : "Cold rooms"}</CardTitle></CardHeader>
          <CardContent className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className={align}>{isRTL ? "الغرفة" : "Room"}</TableHead>
                  <TableHead className={align}>{isRTL ? "النطاق" : "Range"}</TableHead>
                  <TableHead className={align}>{isRTL ? "كجم" : "kg"}</TableHead>
                  <TableHead className={align}>{isRTL ? "الحالة" : "State"}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rooms.data?.map((r) => {
                  const [en, ar, cls] = STATUS[r.status] ?? STATUS.ok;
                  const used = byRoom.get(r.id) ?? 0;
                  return (
                    <TableRow key={r.id}>
                      <TableCell className="font-medium">{nm(isRTL, r.name, r.name_ar)}</TableCell>
                      <TableCell dir="ltr" className="text-sm">{Number(r.temp_min)}…{Number(r.temp_max)}°</TableCell>
                      <TableCell className="font-mono text-sm" dir="ltr">
                        {fmtKg(used)}{r.capacity_kg ? ` / ${Number(r.capacity_kg).toLocaleString()}` : ""}
                      </TableCell>
                      <TableCell><Badge className={cls}>{isRTL ? ar : en}</Badge></TableCell>
                    </TableRow>
                  );
                })}
                {rooms.data && rooms.data.length === 0 && (
                  <TableRow><TableCell colSpan={4} className="text-center text-muted-foreground">{isRTL ? "لا توجد غرف تبريد" : "No cold rooms"}</TableCell></TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2 flex-row items-center justify-between">
            <CardTitle className="text-base">{isRTL ? "استخدم أولاً" : "Use first"}</CardTitle>
            {expired > 0 && <Badge variant="destructive">{isRTL ? `${expired} منتهية` : `${expired} expired`}</Badge>}
          </CardHeader>
          <CardContent className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className={align}>{isRTL ? "الصنف" : "Item"}</TableHead>
                  <TableHead className={align}>{isRTL ? "الدفعة" : "Batch"}</TableHead>
                  <TableHead className={align}>{isRTL ? "المتبقي" : "Left"}</TableHead>
                  <TableHead className={align}>{isRTL ? "كجم" : "kg"}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {first.data?.slice(0, 6).map((r, k) => (
                  <TableRow key={k}>
                    <TableCell className="font-medium">{nm(isRTL, r.item_name, r.item_name_ar)}</TableCell>
                    <TableCell className="font-mono text-xs">{r.batch_no}</TableCell>
                    <TableCell><DaysBadge days={r.days_left} isRTL={isRTL} /></TableCell>
                    <TableCell className="font-mono text-sm">{fmtKg(r.qty)}</TableCell>
                  </TableRow>
                ))}
                {first.data && first.data.length === 0 && (
                  <TableRow><TableCell colSpan={4} className="text-center text-muted-foreground">{isRTL ? "لا شيء قريب الانتهاء" : "Nothing is about to expire"}</TableCell></TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
