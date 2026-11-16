import { useMemo } from "react";
import { Link } from "react-router";
import { Package, Snowflake, Clock, AlertTriangle, ClipboardList, ShoppingCart, Truck, CalendarDays, LifeBuoy, UserCircle, Factory, Landmark, FileText, Users, BarChart3 } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "../components/ui/card";
import { Badge } from "../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../components/ui/table";
import { useI18n } from "../context/I18nContext";
import { useAuth } from "../context/AuthContext";
import { useAccess } from "../access";
import { useApi } from "../hooks/useApi";
import { DaysBadge, fmtKg, ColdRoom, Balance, nm } from "../components/inventory/common";
import type { PR, PO } from "../components/procurement/common";

interface UseFirstRow { item_name: string; item_name_ar: string | null; batch_no: string; qty: number; days_left: number }
interface Alarm { id: number; cold_room_name: string; first_value: number }

const STATUS: Record<string, [string, string, string]> = {
  ok: ["Normal", "سليمة", "bg-emerald-600 hover:bg-emerald-600"],
  alarm: ["Alarm", "إنذار", "bg-amber-500 hover:bg-amber-500"],
  hold: ["On hold", "محجوزة", "bg-red-600 hover:bg-red-600"],
};

type Kpi = { en: string; ar: string; value: string | number; icon: React.ComponentType<{ className?: string }>; href: string; warn?: boolean };

/** The home page: real numbers from the stock ledger and the purchasing flow. Each block only loads what the user may see. */
export function DashboardPage() {
  const { isRTL } = useI18n();
  const { me, can, moduleOpen } = useAuth();
  const { firstTab } = useAccess();
  const stock = moduleOpen("inventory") && can("stock", "view");
  const buy = moduleOpen("procurement") && can("purchase-orders", "view");

  const bal = useApi<Balance[]>(stock ? "/stock/balance" : null);
  const rooms = useApi<ColdRoom[]>(stock ? "/cold-rooms" : null);
  const first = useApi<UseFirstRow[]>(stock ? "/stock/use-first" : null);
  const alarms = useApi<Alarm[]>(stock ? "/alarms?status=open" : null);
  const prs = useApi<PR[]>(buy ? "/purchase-requests" : null);
  const pos = useApi<PO[]>(buy ? "/purchase-orders" : null);

  const totalKg = bal.data?.reduce((s, b) => s + b.qty, 0) ?? 0;
  const byRoom = useMemo(() => {
    const m = new Map<number, number>();
    bal.data?.forEach((b) => b.cold_room_id != null && m.set(b.cold_room_id, (m.get(b.cold_room_id) ?? 0) + b.qty));
    return m;
  }, [bal.data]);
  const count = (rows: { status: string }[] | null, s: string) => (rows ? rows.filter((r) => r.status === s).length : "…");
  const expired = first.data?.filter((r) => r.days_left < 0).length ?? 0;
  const align = isRTL ? "text-right" : "text-left";

  const kpis: Kpi[] = [];
  if (stock) {
    kpis.push(
      { en: "In stock (kg)", ar: "المخزون (كجم)", value: bal.data ? fmtKg(totalKg) : "…", icon: Package, href: "/inventory" },
      { en: "Cold rooms", ar: "غرف التبريد", value: rooms.data?.length ?? "…", icon: Snowflake, href: "/inventory/warehouses" },
      { en: "Use first", ar: "استخدم أولاً", value: first.data?.length ?? "…", icon: Clock, href: "/inventory/use-first", warn: expired > 0 },
      { en: "Open alarms", ar: "إنذارات مفتوحة", value: alarms.data?.length ?? "…", icon: AlertTriangle, href: "/inventory/warehouses", warn: (alarms.data?.length ?? 0) > 0 }
    );
  }
  if (buy) {
    kpis.push(
      { en: "Requests awaiting approval", ar: "طلبات بانتظار الاعتماد", value: count(prs.data, "Pending"), icon: ClipboardList, href: "/procurement/requests" },
      { en: "Orders awaiting approval", ar: "أوامر بانتظار الاعتماد", value: count(pos.data, "Submitted"), icon: ShoppingCart, href: "/procurement/purchase-orders" },
      { en: "Orders to receive", ar: "أوامر بانتظار الاستلام", value: count(pos.data, "Approved"), icon: Truck, href: "/inventory/goods-receipts" }
    );
  }

  const company = me?.companies.find((c) => c.id === me.company_id);
  const first_name = (me?.name ?? "").split(" ")[0];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">{isRTL ? `أهلاً ${first_name}` : `Welcome, ${first_name}`}</h1>
        <p className="text-muted-foreground mt-1">
          {isRTL ? company?.name_ar || company?.name_en : company?.name_en} · {isRTL ? "أرقام مباشرة من النظام." : "live numbers from the system."}
        </p>
      </div>


      {/* Layer 1: fixed services, the same for every employee */}
      <div data-testid="home-services">
        <h2 className="text-sm font-semibold text-muted-foreground mb-2">{isRTL ? "خدماتي" : "My services"}</h2>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          {[
            { to: "/my/leave", en: "My leave", ar: "إجازاتي", d_en: "Balances, request leave, follow your request", d_ar: "أرصدتك، اطلب إجازة، وتابع طلبك", icon: CalendarDays },
            { to: "/it/tickets", en: "My IT tickets", ar: "بلاغاتي التقنية", d_en: "Report a problem and follow it", d_ar: "أبلغ عن عطل وتابعه", icon: LifeBuoy },
            { to: "/my/profile", en: "My profile", ar: "بياناتي", d_en: "Your card, documents and password", d_ar: "بطاقتك ووثائقك وكلمة المرور", icon: UserCircle },
          ].map((x) => (
            <Link key={x.to} to={x.to}>
              <Card className="hover:shadow-md transition-shadow h-full"><CardContent className="pt-6 flex items-center gap-3">
                <x.icon className="h-8 w-8 text-primary shrink-0" />
                <div><p className="font-semibold">{isRTL ? x.ar : x.en}</p><p className="text-xs text-muted-foreground">{isRTL ? x.d_ar : x.d_en}</p></div>
              </CardContent></Card>
            </Link>
          ))}
        </div>
      </div>

      {/* Layer 2: only the work of this person's role */}
      {(() => {
        const mods: [string, string, string, React.ComponentType<{ className?: string }>][] = [
          ["inventory", "Inventory", "المخزون", Package], ["procurement", "Purchases", "المشتريات", ShoppingCart], ["production", "Production", "الإنتاج", Factory],
          ["accounting", "Accounting", "المحاسبة", Landmark], ["sales", "Sales", "المبيعات", FileText], ["hr", "HR", "الموارد البشرية", Users], ["reports", "Reports", "التقارير", BarChart3],
        ];
        const mine = mods.filter(([m]) => (m === "reports" || moduleOpen(m)) && firstTab(m));
        return mine.length > 0 ? (
          <div data-testid="home-work">
            <h2 className="text-sm font-semibold text-muted-foreground mb-2">{isRTL ? "عملي" : "My work"}</h2>
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              {mine.map(([m, en, ar, Icon]) => (
                <Link key={m} to={firstTab(m)!}><Card className="hover:shadow-md transition-shadow h-full"><CardContent className="pt-6 flex items-center gap-3"><Icon className="h-6 w-6 text-primary/70 shrink-0" /><p className="font-semibold">{isRTL ? ar : en}</p></CardContent></Card></Link>
              ))}
            </div>
          </div>
        ) : null;
      })()}

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {kpis.map((k) => (
          <Link key={k.en} to={k.href}>
            <Card className={`hover:shadow-md transition-shadow h-full ${k.warn ? "border-amber-300" : ""}`}>
              <CardContent className="pt-6 flex items-center justify-between gap-2">
                <div>
                  <p className="text-xs text-muted-foreground">{isRTL ? k.ar : k.en}</p>
                  <p className="text-2xl font-bold mt-1" dir="ltr">{k.value}</p>
                </div>
                <k.icon className={`h-7 w-7 shrink-0 ${k.warn ? "text-amber-500" : "text-primary/60"}`} />
              </CardContent>
            </Card>
          </Link>
        ))}
      </div>

      {stock && (
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
                  {rooms.data?.slice(0, 8).map((r) => {
                    const [en, ar, cls] = STATUS[r.status] ?? STATUS.ok;
                    return (
                      <TableRow key={r.id}>
                        <TableCell className="font-medium">{nm(isRTL, r.name, r.name_ar)}</TableCell>
                        <TableCell dir="ltr" className="text-sm">{Number(r.temp_min)}…{Number(r.temp_max)}°</TableCell>
                        <TableCell className="font-mono text-sm" dir="ltr">{fmtKg(byRoom.get(r.id) ?? 0)}</TableCell>
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
      )}
    </div>
  );
}
