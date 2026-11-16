import { NavLink } from "react-router";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";

export const TICKET_STATUS: Record<string, [string, string, string]> = {
  New: ["New", "جديدة", "bg-sky-600 hover:bg-sky-600"], Assigned: ["Assigned", "مسندة", "bg-indigo-500 hover:bg-indigo-500"], In_Progress: ["In progress", "قيد التنفيذ", "bg-amber-500 hover:bg-amber-500"],
  Waiting: ["Waiting", "بانتظار رد", "bg-orange-500 hover:bg-orange-500"], Resolved: ["Resolved", "تم الحل", "bg-emerald-600 hover:bg-emerald-600"], Closed: ["Closed", "مغلقة", "bg-slate-500 hover:bg-slate-500"], Cancelled: ["Cancelled", "ملغاة", "bg-slate-400 hover:bg-slate-400"],
};
export const PRIORITY: Record<string, [string, string, string]> = {
  low: ["Low", "منخفضة", "bg-slate-400 hover:bg-slate-400"], medium: ["Medium", "متوسطة", "bg-sky-600 hover:bg-sky-600"], high: ["High", "عالية", "bg-orange-500 hover:bg-orange-500"], critical: ["Critical", "حرجة", "bg-red-600 hover:bg-red-600"],
};
export const CATEGORY: Record<string, [string, string]> = {
  hardware: ["Hardware (device, scale, printer)", "أجهزة (جهاز، ميزان، طابعة)"], software: ["Software", "برامج"], network: ["Network / Wi-Fi", "الشبكة / الواي فاي"], account: ["Account / password", "حساب / كلمة مرور"], erp: ["The ERP system", "نظام ERP"], other: ["Other", "أخرى"],
};
export const ASSET_TYPE: Record<string, [string, string]> = {
  laptop: ["Laptop", "لابتوب"], desktop: ["Desktop", "كمبيوتر مكتبي"], phone: ["Phone / tablet", "هاتف / تابلت"], scanner: ["Barcode scanner", "قارئ باركود"], printer: ["Label / document printer", "طابعة"], scale: ["Scale", "ميزان"], sensor: ["Cold-room sensor", "حساس غرفة تبريد"], network: ["Network device", "جهاز شبكة"], other: ["Other", "أخرى"],
};
export const ASSET_STATUS: Record<string, [string, string]> = { In_Use: ["In use", "قيد الاستخدام"], In_Stock: ["In stock", "في المخزن"], Repair: ["In repair", "في الصيانة"], Retired: ["Retired", "خارج الخدمة"] };

export function ItNav() {
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const staff = can("it-tickets", "work") || can("it-tickets", "manage");
  const tabs: [string, string, string, boolean][] = [["/it", "Overview", "نظرة عامة", true], ["/it/tickets", staff ? "Tickets" : "My tickets", staff ? "التذاكر" : "تذاكري", false]];
  if (staff) tabs.push(["/it/assets", "Assets", "الأجهزة والأصول", false]);
  return (
    <nav className="flex gap-1 overflow-x-auto border-b pb-3" aria-label="it">
      {tabs.map(([to, en, ar, end]) => (
        <NavLink key={to} to={to} end={end} className={({ isActive }) => `px-3 py-1.5 rounded-md text-sm whitespace-nowrap ${isActive ? "bg-primary text-primary-foreground font-semibold" : "text-muted-foreground hover:bg-muted"}`}>
          {isRTL ? ar : en}
        </NavLink>
      ))}
    </nav>
  );
}
