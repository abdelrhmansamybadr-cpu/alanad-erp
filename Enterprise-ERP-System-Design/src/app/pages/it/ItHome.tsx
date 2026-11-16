import { Link } from "react-router";
import { ExternalLink } from "lucide-react";
import { Card } from "../../components/ui/card";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";
import { ItNav, CATEGORY } from "../../components/it/ItNav";
import { ErrorBox } from "../../components/inventory/common";

interface Summary { open: number; overdue: number; avg_resolution_hours: number | null; avg_first_response_minutes: number | null; by_status: Record<string, number>; by_category: { category: string; n: number }[]; assets: number; warranty_expiring: number; portal_url: string; is_it: boolean }

export function ItHome() {
  const { isRTL } = useI18n();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const s = useApi<Summary>("/it-tickets/summary");
  const d = s.data;
  const cards: [string, string, number | string, string][] = [
    ["Open tickets", "تذاكر مفتوحة", d?.open ?? "…", "/it/tickets"],
    ["Overdue (past the SLA)", "متأخرة عن الوقت المحدد", d?.overdue ?? "…", "/it/tickets?overdue=1"],
    ["Average time to solve (hours)", "متوسط وقت الحل (ساعات)", d ? d.avg_resolution_hours ?? "—" : "…", "/it/tickets"],
    ["Average first reply (minutes)", "متوسط أول رد (دقائق)", d ? d.avg_first_response_minutes ?? "—" : "…", "/it/tickets"],
  ];
  if (d?.is_it) { cards.push(["Devices registered", "الأجهزة المسجلة", d.assets, "/it/assets"], ["Warranty ending soon", "ضمان ينتهي قريباً", d.warranty_expiring, "/it/assets?warranty=1"]); }
  return (
    <div className="space-y-6">
      <ItNav />
      <div><h1 className="text-3xl font-bold">{T("IT support", "الدعم التقني")}</h1><p className="text-muted-foreground mt-1">{T("Tell IT about a problem (scale, scanner, printer, Wi-Fi, your account, the ERP) and follow it until it is solved. Every ticket has a time limit by priority.", "أبلغ قسم التقنية عن أي مشكلة (ميزان، قارئ باركود، طابعة، واي فاي، حسابك، النظام) وتابعها حتى تُحل. لكل تذكرة وقت محدد حسب الأولوية.")}</p></div>
      {s.error && <ErrorBox message={s.error} />}
      <div className="grid grid-cols-2 lg:grid-cols-3 gap-3" data-testid="it-cards">
        {cards.map(([en, ar, n, to]) => <Link key={en} to={to}><Card className="p-4 hover:shadow-md transition-shadow h-full"><div className="text-3xl font-bold" dir="ltr">{n}</div><div className="text-sm text-muted-foreground mt-1">{T(en, ar)}</div></Card></Link>)}
      </div>
      {d && d.by_category.length > 0 && (
        <Card className="p-6" data-testid="it-by-category">
          <h2 className="font-semibold mb-3">{T("Tickets by kind of problem", "التذاكر حسب نوع المشكلة")}</h2>
          <div className="space-y-2">{d.by_category.map((c) => { const max = Math.max(...d.by_category.map((x) => Number(x.n))); return (
            <div key={c.category} className="flex items-center gap-3 text-sm"><div className="w-48 shrink-0">{CATEGORY[c.category] ? CATEGORY[c.category][isRTL ? 1 : 0] : c.category}</div><div className="flex-1 bg-muted rounded h-3"><div className="bg-primary h-3 rounded" style={{ width: `${(Number(c.n) / max) * 100}%` }} /></div><div className="w-8 text-end" dir="ltr">{c.n}</div></div>); })}</div>
        </Card>
      )}
      {d?.portal_url && (
        <Card className="p-4 flex items-center justify-between gap-4" data-testid="it-portal">
          <div className="text-sm"><b>{T("TechDesk", "TechDesk")}</b><div className="text-muted-foreground">{T("Remote support sessions, the knowledge base and the mobile app of the IT team stay in TechDesk.", "جلسات الدعم عن بُعد وقاعدة المعرفة وتطبيق الجوال لفريق التقنية تبقى في TechDesk.")}</div></div>
          <a className="inline-flex items-center gap-1 text-primary underline whitespace-nowrap" href={d.portal_url} target="_blank" rel="noreferrer">{T("Open", "فتح")} <ExternalLink className="h-4 w-4" /></a>
        </Card>
      )}
    </div>
  );
}
