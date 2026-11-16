import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { Switch } from "../../components/ui/switch";
import { Badge } from "../../components/ui/badge";
import { CheckCircle } from "lucide-react";
import { apiRequest } from "../../services/api";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";

// Names of the server-side module keys
const NAMES: Record<string, [string, string]> = {
  sales: ["Sales", "المبيعات"],
  crm: ["CRM", "علاقات العملاء"],
  purchase: ["Purchasing", "المشتريات والموردين"],
  stock: ["Inventory & cold rooms", "المخزون وغرف التبريد"],
  finance: ["Accounting & finance", "المحاسبة والمالية"],
  hr: ["Human resources", "الموارد البشرية"],
  manufacturing: ["Manufacturing", "التصنيع"],
  quality: ["Quality", "الجودة"],
  assets: ["Assets", "الأصول"],
  projects: ["Projects", "المشاريع"],
  pos: ["Point of sale", "نقاط البيع"],
  website: ["Website", "الموقع الإلكتروني"],
};

interface Row { module_key: string; is_enabled: number | boolean; changed_at: string | null }

export function ModuleManagement() {
  const { isRTL } = useI18n();
  const { reload, can } = useAuth();
  const [rows, setRows] = useState<Row[] | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState("");

  const load = () =>
    apiRequest<Row[]>("/modules").then(setRows).catch((e) => setError(e.message));
  useEffect(() => { load(); }, []);

  const toggle = async (key: string, on: boolean) => {
    setBusy(key);
    try {
      await apiRequest(`/modules/${key}`, { method: "PUT", body: JSON.stringify({ is_enabled: on }) });
      await Promise.all([load(), reload()]);
      toast.success(isRTL ? (on ? "تم تفعيل الوحدة" : "تم إغلاق الوحدة (البيانات محفوظة)") : on ? "Module opened" : "Module closed (data kept)");
    } catch (e: any) {
      toast.error(e.message);
    } finally {
      setBusy(null);
    }
  };

  const canEdit = can("admin", "manage");
  const active = rows?.filter((r) => Number(r.is_enabled) === 1).length ?? 0;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">{isRTL ? "إدارة الوحدات" : "Module Management"}</h1>
        <p className="text-muted-foreground mt-1">
          {isRTL
            ? "إغلاق وحدة يخفيها ويمنع الوصول إليها لهذه الشركة، وتبقى بياناتها محفوظة."
            : "Closing a module hides it and blocks access for this company. Its data is kept."}
        </p>
      </div>

      {error && <div className="p-3 rounded-lg bg-red-50 text-red-700 text-sm" role="alert">{error}</div>}

      {rows && (
        <div className="flex items-center gap-3 px-4 py-3 rounded-lg bg-primary/10 border border-primary/20 text-sm">
          <CheckCircle className="h-4 w-4 flex-shrink-0 text-primary" />
          <span className="font-semibold">
            {isRTL ? `${active} من ${rows.length} وحدات مفعّلة` : `${active} of ${rows.length} modules open`}
          </span>
        </div>
      )}

      {!rows && !error && <p className="text-muted-foreground">{isRTL ? "جارٍ التحميل…" : "Loading…"}</p>}

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {rows?.map((r) => {
          const on = Number(r.is_enabled) === 1;
          const [en, ar] = NAMES[r.module_key] ?? [r.module_key, r.module_key];
          return (
            <Card key={r.module_key} className={on ? "" : "opacity-60 border-dashed"}>
              <CardHeader>
                <div className="flex items-center justify-between gap-3">
                  <CardTitle className="text-lg">{isRTL ? ar : en}</CardTitle>
                  <div className="flex items-center gap-3">
                    <Badge variant={on ? "default" : "secondary"}>
                      {on ? (isRTL ? "مفتوحة" : "Open") : isRTL ? "مغلقة" : "Closed"}
                    </Badge>
                    <Switch
                      checked={on}
                      disabled={!canEdit || busy === r.module_key}
                      onCheckedChange={(v) => toggle(r.module_key, v)}
                      aria-label={en}
                    />
                  </div>
                </div>
              </CardHeader>
              <CardContent className="text-xs text-muted-foreground">
                {r.changed_at ? (isRTL ? `آخر تغيير: ${r.changed_at}` : `Last changed: ${r.changed_at}`) : "\u00A0"}
              </CardContent>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
