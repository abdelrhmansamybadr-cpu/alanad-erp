import { Link } from "react-router";
import { ExternalLink } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Badge } from "../../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";
import { HrNav, Expiring, DOC_AR, sevClass } from "../../components/hr/HrNav";
import { ErrorBox } from "../../components/inventory/common";

interface Summary { active_employees: number; on_leave_today: number; pending_leaves: number; expired: number; expiring_soon: number; portal_url: string }

export function HrHome() {
  const { isRTL } = useI18n();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const s = useApi<Summary>("/hr-expiry/summary");
  const ex = useApi<Expiring[]>("/hr-expiry");
  const align = isRTL ? "text-right" : "text-left";
  const cards: [string, string, number | string, string][] = [
    ["Active employees", "الموظفون على رأس العمل", s.data?.active_employees ?? "…", "/hr/employees"],
    ["On leave today", "في إجازة اليوم", s.data?.on_leave_today ?? "…", "/hr/leave"],
    ["Leave waiting for approval", "إجازات بانتظار الموافقة", s.data?.pending_leaves ?? "…", "/hr/leave"],
    ["Documents expired", "وثائق منتهية", s.data?.expired ?? "…", "/hr/expiry"],
    ["Documents expiring soon", "وثائق تنتهي قريباً", s.data?.expiring_soon ?? "…", "/hr/expiry"],
  ];
  return (
    <div className="space-y-6">
      <HrNav />
      <div><h1 className="text-3xl font-bold">{T("Human resources", "الموارد البشرية")}</h1><p className="text-muted-foreground mt-1">{T("Employees, leave and the documents the factory must keep valid (iqama, passport, health card, contract).", "الموظفون والإجازات والوثائق التي يجب أن تبقى سارية في المصنع (الإقامة والجواز والشهادة الصحية والعقد).")}</p></div>
      {(s.error || ex.error) && <ErrorBox message={s.error || ex.error} />}
      <div className="grid grid-cols-2 lg:grid-cols-5 gap-3" data-testid="hr-cards">
        {cards.map(([en, ar, n, to]) => <Link key={en} to={to}><Card className="p-4 hover:shadow-md transition-shadow h-full"><div className="text-3xl font-bold" dir="ltr">{n}</div><div className="text-sm text-muted-foreground mt-1">{T(en, ar)}</div></Card></Link>)}
      </div>
      <Card className="p-6" data-testid="expiring-top">
        <h2 className="font-semibold mb-3">{T("Needs attention", "تحتاج انتباهاً")}</h2>
        <Table>
          <TableHeader><TableRow><TableHead className={align}>{T("Employee", "الموظف")}</TableHead><TableHead className={align}>{T("Document", "الوثيقة")}</TableHead><TableHead className={align}>{T("Expires", "تنتهي")}</TableHead><TableHead className={align}>{T("Days", "الأيام")}</TableHead></TableRow></TableHeader>
          <TableBody>
            {ex.data?.slice(0, 8).map((x, i) => (
              <TableRow key={i}><TableCell>{x.name}<div className="text-xs text-muted-foreground">{x.department}</div></TableCell><TableCell>{isRTL ? DOC_AR[x.document] ?? x.document : x.document}</TableCell><TableCell dir="ltr">{x.expires_on}</TableCell>
                <TableCell><Badge className={sevClass(x.severity)}>{x.days_left < 0 ? T("expired", "منتهية") : x.days_left}</Badge></TableCell></TableRow>
            ))}
            {ex.data && ex.data.length === 0 && <TableRow><TableCell colSpan={4} className="text-center text-muted-foreground">{T("Everything is valid", "كل الوثائق سارية")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>
      {s.data?.portal_url && (
        <Card className="p-4 flex items-center justify-between gap-4" data-testid="portal-card">
          <div className="text-sm"><b>{T("HR portal", "بوابة الموارد البشرية")}</b><div className="text-muted-foreground">{T("Attendance with GPS, payroll, recruitment, certificates and the employee mobile app stay in the HR portal.", "الحضور بالموقع والرواتب والتوظيف والشهادات وتطبيق الجوال للموظف تبقى في بوابة الموارد البشرية.")}</div></div>
          <a className="inline-flex items-center gap-1 text-primary underline whitespace-nowrap" href={s.data.portal_url} target="_blank" rel="noreferrer">{T("Open", "فتح")} <ExternalLink className="h-4 w-4" /></a>
        </Card>
      )}
    </div>
  );
}
