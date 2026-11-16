import { useState } from "react";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { useI18n } from "../../context/I18nContext";
import { useApi } from "../../hooks/useApi";
import { HrNav, Expiring, DOC_AR, sevClass } from "../../components/hr/HrNav";
import { ErrorBox } from "../../components/inventory/common";

export function Expiry() {
  const { isRTL } = useI18n();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const [days, setDays] = useState(60);
  const q = useApi<Expiring[]>(`/hr-expiry?days=${days}`);
  const align = isRTL ? "text-right" : "text-left";
  return (
    <div className="space-y-6">
      <HrNav />
      <div><h1 className="text-3xl font-bold">{T("Expiring documents", "الوثائق المنتهية")}</h1><p className="text-muted-foreground mt-1">{T("Iqama, passport, health card (food handlers) and contract end dates. The warning window is a setting (HR → days).", "الإقامة والجواز والشهادة الصحية (لمن يتعاملون مع الغذاء) وانتهاء العقود. مدة التنبيه إعداد قابل للتغيير.")}</p></div>
      {q.error && <ErrorBox message={q.error} />}
      <div className="flex gap-2">{[30, 60, 90, 180].map((d) => <Button key={d} size="sm" variant={days === d ? "default" : "outline"} onClick={() => setDays(d)}>{d} {T("days", "يوم")}</Button>)}</div>
      <Card className="p-6" data-testid="expiry-list">
        <Table>
          <TableHeader><TableRow><TableHead className={align}>{T("Employee", "الموظف")}</TableHead><TableHead className={align}>{T("Document", "الوثيقة")}</TableHead><TableHead className={align}>{T("Expires", "تنتهي")}</TableHead><TableHead className={align}>{T("Days left", "المتبقي")}</TableHead></TableRow></TableHeader>
          <TableBody>
            {q.data?.map((x, i) => (
              <TableRow key={i}><TableCell className="font-medium">{x.name}<div className="text-xs text-muted-foreground">{x.code} · {x.department}</div></TableCell><TableCell>{isRTL ? DOC_AR[x.document] ?? x.document : x.document}</TableCell><TableCell dir="ltr">{x.expires_on}</TableCell>
                <TableCell><Badge className={sevClass(x.severity)}>{x.days_left < 0 ? `${T("expired", "منتهية")} (${-x.days_left})` : x.days_left}</Badge></TableCell></TableRow>
            ))}
            {q.data && q.data.length === 0 && <TableRow><TableCell colSpan={4} className="text-center text-muted-foreground">{T("Everything is valid for this window", "كل الوثائق سارية في هذه المدة")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>
    </div>
  );
}
