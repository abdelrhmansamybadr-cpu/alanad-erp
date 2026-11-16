import { NavLink } from "react-router";
import { useI18n } from "../../context/I18nContext";
import { useAccess } from "../../access";

const TABS: [string, string, string, boolean][] = [
  ["/hr", "Overview", "نظرة عامة", true],
  ["/hr/employees", "Employees", "الموظفون", false],
  ["/hr/leave", "Leave", "الإجازات", false],
  ["/hr/expiry", "Expiring documents", "الوثائق المنتهية", false],
];

export function HrNav() {
  const { isRTL } = useI18n();
  const { canOpen } = useAccess();
  return (
    <nav className="flex gap-1 overflow-x-auto border-b pb-3" aria-label="hr">
      {TABS.filter(([to]) => canOpen(to)).map(([to, en, ar, end]) => (
        <NavLink key={to} to={to} end={end} className={({ isActive }) => `px-3 py-1.5 rounded-md text-sm whitespace-nowrap ${isActive ? "bg-primary text-primary-foreground font-semibold" : "text-muted-foreground hover:bg-muted"}`}>
          {isRTL ? ar : en}
        </NavLink>
      ))}
    </nav>
  );
}

export interface Expiring { employee_id: number; code: string; name: string; department: string | null; document: string; expires_on: string; days_left: number; severity: "expired" | "critical" | "soon" }
export const DOC_AR: Record<string, string> = { Iqama: "الإقامة", Passport: "جواز السفر", "Health card": "الشهادة الصحية", Contract: "العقد" };
export const sevClass = (s: string) => (s === "expired" ? "bg-red-600 hover:bg-red-600" : s === "critical" ? "bg-orange-500 hover:bg-orange-500" : "bg-amber-500 hover:bg-amber-500");
