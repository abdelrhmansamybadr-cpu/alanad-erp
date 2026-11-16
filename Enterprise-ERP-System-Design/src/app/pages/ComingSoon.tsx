import { Link, useLocation } from "react-router";
import { Hammer } from "lucide-react";
import { Card, CardContent } from "../components/ui/card";
import { Button } from "../components/ui/button";
import { useI18n } from "../context/I18nContext";

// [English name, Arabic name, phase of the master plan in which the module is built]
const MODULES: Record<string, [string, string, string, string]> = {
  sales: ["Sales & Dispatch", "المبيعات والتوزيع", "المرحلة ٦", "Phase 6"],
  projects: ["Projects", "المشاريع", "مرحلة لاحقة", "a later phase"],
  hr: ["Human Resources", "الموارد البشرية", "مرحلة لاحقة", "a later phase"],
  reports: ["Reports & Analytics", "التقارير والتحليلات", "مرحلة لاحقة", "a later phase"],
};

/** Shown for modules whose backend is not built yet, instead of demo screens that would show invented numbers. */
export function ComingSoon() {
  const { isRTL } = useI18n();
  const key = useLocation().pathname.split("/")[1];
  const [en, ar, phaseAr, phaseEn] = MODULES[key] ?? [key, key, "", ""];
  return (
    <div className="max-w-xl mx-auto mt-16">
      <Card>
        <CardContent className="pt-10 pb-10 text-center space-y-4">
          <Hammer className="h-10 w-10 mx-auto text-primary/70" />
          <h1 className="text-2xl font-bold">{isRTL ? ar : en}</h1>
          <p className="text-muted-foreground">
            {isRTL
              ? `هذه الوحدة لم تُجهَّز بعد. تُبنى في ${phaseAr} من خطة التنفيذ، بقواعد شركة العناد.`
              : `This module is not set up yet. It is built in ${phaseEn} of the plan, with Alanad's own rules.`}
          </p>
          <Button asChild variant="outline">
            <Link to="/">{isRTL ? "العودة للوحة التحكم" : "Back to the dashboard"}</Link>
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}
