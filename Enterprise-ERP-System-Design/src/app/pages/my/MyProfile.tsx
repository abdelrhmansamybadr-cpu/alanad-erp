import { Link } from "react-router";
import { Card } from "../../components/ui/card";
import { Badge } from "../../components/ui/badge";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";
import { ErrorBox } from "../../components/inventory/common";

interface Profile {
  user: { id: number; name: string; email: string; role: string };
  employee: { code: string; name: string; department: string | null; designation: string | null; joined: string | null; status: string; mobile: string | null; email: string | null; branch: { name_en: string; name_ar: string | null } | null } | null;
  documents: { document: string; expires_on: string; days_left: number }[];
  team_head: string | null;
}
const DOC_AR: Record<string, string> = { Iqama: "الإقامة", Passport: "جواز السفر", "Health card": "الشهادة الصحية", Contract: "العقد" };

/** MY PROFILE — a fixed tab for every employee: my own card and my own documents. No salary, nobody else's data. */
export function MyProfile() {
  const { isRTL } = useI18n();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const p = useApi<Profile>("/my/profile");
  const e = p.data?.employee;
  const row = (en: string, ar: string, v: React.ReactNode) => (
    <div className="flex justify-between gap-4 py-2 border-b last:border-0"><span className="text-muted-foreground">{T(en, ar)}</span><span className="font-medium text-end">{v ?? "—"}</span></div>
  );
  return (
    <div className="space-y-6 max-w-3xl">
      <div>
        <h1 className="text-3xl font-bold">{T("My profile", "بياناتي")}</h1>
        <p className="text-muted-foreground mt-1">{T("Your own employee card and documents. Only you see this page.", "بطاقتك ووثائقك أنت فقط. لا يراها غيرك.")}</p>
      </div>
      {p.error && <ErrorBox message={p.error} />}
      {p.data && (
        <>
          <Card className="p-6" data-testid="profile-card">
            <h2 className="font-semibold mb-2">{T("Account", "الحساب")}</h2>
            {row("Name", "الاسم", p.data.user.name)}
            {row("E-mail", "البريد", <span dir="ltr">{p.data.user.email}</span>)}
            <div className="pt-3"><Link className="text-primary underline text-sm" to="/auth/change-password">{T("Change my password", "تغيير كلمة المرور")}</Link></div>
          </Card>
          {e ? (
            <Card className="p-6" data-testid="employee-card">
              <h2 className="font-semibold mb-2">{T("Employee card", "بطاقة الموظف")}</h2>
              {row("Employee no.", "رقم الموظف", <span dir="ltr">{e.code}</span>)}
              {row("Department", "القسم", e.department)}
              {row("Job title", "المسمى الوظيفي", e.designation)}
              {row("Branch", "الفرع", e.branch ? (isRTL ? e.branch.name_ar || e.branch.name_en : e.branch.name_en) : null)}
              {row("Team head", "رئيس الفريق", p.data.team_head)}
              {row("Joined", "تاريخ الالتحاق", <span dir="ltr">{e.joined}</span>)}
              {row("Mobile", "الجوال", e.mobile ? <span dir="ltr">{e.mobile}</span> : null)}
            </Card>
          ) : (
            <Card className="p-6" data-testid="no-card"><p className="text-sm text-amber-700">{T("Your account is not linked to an employee record yet. Ask HR to link it.", "حسابك غير مربوط ببطاقة موظف بعد. اطلب من الموارد البشرية ربطه.")}</p></Card>
          )}
          {p.data.documents.length > 0 && (
            <Card className="p-6" data-testid="my-documents">
              <h2 className="font-semibold mb-2">{T("My documents", "وثائقي")}</h2>
              {p.data.documents.map((d) => (
                <div key={d.document} className="flex items-center justify-between py-2 border-b last:border-0">
                  <span>{isRTL ? DOC_AR[d.document] ?? d.document : d.document}</span>
                  <span className="flex items-center gap-2"><span dir="ltr" className="text-sm">{d.expires_on}</span>
                    <Badge className={d.days_left < 0 ? "bg-red-600 hover:bg-red-600" : d.days_left <= 30 ? "bg-orange-500 hover:bg-orange-500" : d.days_left <= 90 ? "bg-amber-500 hover:bg-amber-500" : "bg-emerald-600 hover:bg-emerald-600"}>
                      {d.days_left < 0 ? T("Expired", "منتهية") : isRTL ? `${d.days_left} يوم` : `${d.days_left} days`}
                    </Badge></span>
                </div>
              ))}
            </Card>
          )}
        </>
      )}
    </div>
  );
}
