import { Link } from "react-router";
import { Blocks, Building2, MapPin, Users, ScrollText, Workflow, SlidersHorizontal } from "lucide-react";
import { Card, CardContent } from "../../components/ui/card";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";

interface Mod { is_enabled: number | boolean }
interface Page { total: number }

/** Entry page of the admin area: the four real screens, with live counts. */
export function AdminDashboard() {
  const { isRTL } = useI18n();
  const modules = useApi<Mod[]>("/modules");
  const companies = useApi<unknown[]>("/companies");
  const settings = useApi<unknown[]>("/settings");
  const designs = useApi<unknown[]>("/workflows");
  const branches = useApi<unknown[]>("/company-branches");
  const users = useApi<unknown[]>("/users");
  const audit = useApi<Page>("/audit-logs?per_page=1");

  const open = modules.data?.filter((m) => Number(m.is_enabled) === 1).length;
  const cards: [string, string, string, string, React.ComponentType<{ className?: string }>, string][] = [
    ["Modules", "الوحدات", "Open or close modules for this company", "فتح وإغلاق الوحدات لهذه الشركة", Blocks, modules.data ? `${open} / ${modules.data.length}` : "…"],
    ["Companies", "الشركات", "The companies of the group", "شركات المجموعة", Building2, String(companies.data?.length ?? "…")],
    ["Branches", "الفروع", "Locations of this company and who works where", "مواقع الشركة ومن يعمل في أي فرع", MapPin, String(branches.data?.length ?? "…")],
    ["Process designer", "مصمم العمليات", "Arrange who approves what, step by step", "رتّب من يعتمد ماذا وبأي ترتيب", Workflow, String(designs.data?.length ?? "…")],
    ["System settings", "إعدادات النظام", "Every limit, switch and default of the system", "كل حد ومفتاح وقيمة افتراضية في النظام", SlidersHorizontal, String(settings.data?.length ?? "…")],
    ["Users & Permissions", "المستخدمون والصلاحيات", "Roles, permissions and accounts", "الأدوار والصلاحيات والحسابات", Users, String(users.data?.length ?? "…")],
    ["Audit Log", "سجل التدقيق", "Who did what, and when", "من فعل ماذا ومتى", ScrollText, String(audit.data?.total ?? "…")],
  ];
  const hrefs = ["/admin/modules", "/admin/tenants", "/admin/branches", "/admin/designer", "/admin/settings", "/admin/roles", "/admin/audit-logs"];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">{isRTL ? "لوحة الإدارة" : "Admin Panel"}</h1>
        <p className="text-muted-foreground mt-1">{isRTL ? "إدارة الشركات والمستخدمين والوحدات ومراجعة سجل النشاط." : "Manage companies, users and modules, and review the activity log."}</p>
      </div>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        {cards.map(([en, ar, descEn, descAr, Icon, n], i) => (
          <Link key={en} to={hrefs[i]}>
            <Card className="hover:shadow-md transition-shadow h-full">
              <CardContent className="pt-6 flex items-start gap-4">
                <div className="p-3 rounded-lg bg-primary/10"><Icon className="h-6 w-6 text-primary" /></div>
                <div className="flex-1">
                  <div className="flex items-center justify-between">
                    <h2 className="font-semibold">{isRTL ? ar : en}</h2>
                    <span className="text-xl font-bold" dir="ltr">{n}</span>
                  </div>
                  <p className="text-sm text-muted-foreground mt-1">{isRTL ? descAr : descEn}</p>
                </div>
              </CardContent>
            </Card>
          </Link>
        ))}
      </div>
    </div>
  );
}
