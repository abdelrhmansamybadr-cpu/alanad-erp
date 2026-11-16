import { NavLink } from "react-router";
import { useI18n } from "../../context/I18nContext";
import { useAccess } from "../../access";

const TABS: [string, string, string, boolean][] = [
  ["/production", "Orders", "أوامر التجهيز", true],
  ["/production/specs", "Specifications", "المواصفات", false],
  ["/production/packing", "Packing", "التعبئة", false],
  ["/production/food-safety", "Food safety", "سلامة الغذاء", false],
  ["/production/reports", "Yield & waste", "الإنتاجية والهالك", false],
];

export function ProductionNav() {
  const { isRTL } = useI18n();
  const { canOpen } = useAccess();
  return (
    <nav className="flex gap-1 overflow-x-auto border-b pb-3" aria-label="production">
      {TABS.filter(([to]) => canOpen(to)).map(([to, en, ar, end]) => (
        <NavLink
          key={to}
          to={to}
          end={end}
          className={({ isActive }) =>
            `px-3 py-1.5 rounded-md text-sm whitespace-nowrap ${isActive ? "bg-primary text-primary-foreground font-semibold" : "text-muted-foreground hover:bg-muted"}`
          }
        >
          {isRTL ? ar : en}
        </NavLink>
      ))}
    </nav>
  );
}

export interface Spec {
  id: number; code: string; name_en: string; name_ar: string | null; input_item_id: number; output_item_id: number; byproduct_item_id: number | null;
  planned_yield_pct: number; yield_tolerance_pct: number; waste_high_pct: number; max_minutes_outside: number; requires_wash: number | boolean; is_active: number | boolean;
  input_item_name?: string; input_item_name_ar?: string | null; output_item_name?: string; output_item_name_ar?: string | null; byproduct_item_name?: string | null;
}
export interface OrderRow {
  id: number; number: string; status: string; planned_input_kg: number; input_kg: number | null; output_kg: number | null; waste_kg: number | null;
  yield_pct: number | null; waste_pct: number | null; result: string | null; over_time: number | null; over_time_now: boolean; minutes_running: number | null;
  spec_code: string; spec_name: string; spec_name_ar: string | null; max_minutes_outside: number; waste_pending: number; created_by: number; results_by: number | null;
}
export interface WasteReason { code: string; name_en: string; name_ar: string | null; waste_class: "high" | "low"; default_decision: string }

export const DECISION_EN: Record<string, string> = { rework: "Rework (back to stock)", by_product: "By-product (feed)", scrap_sale: "Scrap sale", destroy: "Destroy (write-off)" };
export const DECISION_AR: Record<string, string> = { rework: "إعادة تصنيع (للمخزون)", by_product: "منتج ثانوي (أعلاف)", scrap_sale: "بيع كخردة", destroy: "إتلاف (شطب)" };
