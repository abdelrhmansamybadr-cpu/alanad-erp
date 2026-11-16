import { useEffect, useState } from "react";
import { ChevronLeft, ChevronRight, RefreshCw } from "lucide-react";
import { Card } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { Badge } from "../../components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { apiRequest } from "../../services/api";
import { useI18n } from "../../context/I18nContext";

interface Entry {
  id: number;
  user_email: string | null;
  action: string;
  resource: string;
  resource_id: string | null;
  http_status: number | null;
  details: unknown;
  ip: string | null;
  created_at: string;
}
interface Page { data: Entry[]; total: number; page: number; per_page: number }

const ACTION_AR: Record<string, string> = {
  create: "إنشاء", update: "تعديل", delete: "حذف", approve: "اعتماد", reject: "رفض", login: "دخول", login_failed: "دخول فاشل",
};
const PER = 25;

export function AuditLogs() {
  const { isRTL } = useI18n();
  const [res, setRes] = useState<Page | null>(null);
  const [error, setError] = useState("");
  const [page, setPage] = useState(1);
  const [resource, setResource] = useState("");
  const [action, setAction] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");

  const load = () => {
    const q = new URLSearchParams({ page: String(page), per_page: String(PER) });
    if (resource.trim()) q.set("resource", resource.trim());
    if (action.trim()) q.set("action", action.trim());
    if (from) q.set("from", from + " 00:00:00");
    if (to) q.set("to", to + " 23:59:59");
    setError("");
    apiRequest<Page>(`/audit-logs?${q}`).then(setRes).catch((e) => setError(e.message));
  };
  useEffect(load, [page]);

  const apply = (e: React.FormEvent) => {
    e.preventDefault();
    if (page === 1) load();
    else setPage(1);
  };

  const pages = res ? Math.max(1, Math.ceil(res.total / res.per_page)) : 1;
  const align = isRTL ? "text-right" : "text-left";
  const Prev = isRTL ? ChevronRight : ChevronLeft;
  const Next = isRTL ? ChevronLeft : ChevronRight;

  const statusBadge = (s: number | null) =>
    s == null ? null : (
      <Badge variant={s >= 400 ? "destructive" : "secondary"}>{s}</Badge>
    );

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">{isRTL ? "سجل التدقيق" : "Audit Log"}</h1>
        <p className="text-muted-foreground mt-1">
          {isRTL ? "سجل لا يُعدَّل ولا يُحذف: من فعل ماذا ومتى." : "A record that can't be edited or deleted: who did what, and when."}
        </p>
      </div>

      <Card className="p-4">
        <form onSubmit={apply} className="grid grid-cols-2 md:grid-cols-5 gap-3 items-end">
          <div className="space-y-1">
            <Label htmlFor="a_res">{isRTL ? "المورد" : "Resource"}</Label>
            <Input id="a_res" dir="ltr" placeholder="purchase-orders" value={resource} onChange={(e) => setResource(e.target.value)} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="a_act">{isRTL ? "الإجراء" : "Action"}</Label>
            <Input id="a_act" dir="ltr" placeholder="create" value={action} onChange={(e) => setAction(e.target.value)} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="a_from">{isRTL ? "من" : "From"}</Label>
            <Input id="a_from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="a_to">{isRTL ? "إلى" : "To"}</Label>
            <Input id="a_to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </div>
          <Button type="submit" className="gap-2">
            <RefreshCw className="h-4 w-4" />
            {isRTL ? "تطبيق" : "Apply"}
          </Button>
        </form>
      </Card>

      {error && (
        <div className="p-3 rounded-lg bg-red-50 text-red-700 text-sm" role="alert">
          {error}
        </div>
      )}

      <Card className="p-6 overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className={align}>{isRTL ? "الوقت" : "Time"}</TableHead>
              <TableHead className={align}>{isRTL ? "المستخدم" : "User"}</TableHead>
              <TableHead className={align}>{isRTL ? "الإجراء" : "Action"}</TableHead>
              <TableHead className={align}>{isRTL ? "المورد" : "Resource"}</TableHead>
              <TableHead className={align}>{isRTL ? "الحالة" : "Status"}</TableHead>
              <TableHead className={align}>IP</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {res?.data.map((r) => (
              <TableRow key={r.id}>
                <TableCell className="font-mono text-xs whitespace-nowrap" dir="ltr">{r.created_at}</TableCell>
                <TableCell className="text-sm" dir="ltr">{r.user_email || "—"}</TableCell>
                <TableCell>{isRTL ? ACTION_AR[r.action] ?? r.action : r.action}</TableCell>
                <TableCell className="font-mono text-sm" dir="ltr">
                  {r.resource}
                  {r.resource_id ? ` #${r.resource_id}` : ""}
                </TableCell>
                <TableCell>{statusBadge(r.http_status)}</TableCell>
                <TableCell className="font-mono text-xs" dir="ltr">{r.ip || "—"}</TableCell>
              </TableRow>
            ))}
            {res && res.data.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="text-center text-muted-foreground">
                  {isRTL ? "لا توجد سجلات" : "No entries"}
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>

        <div className="flex items-center justify-between mt-4 text-sm text-muted-foreground">
          <span>{res ? (isRTL ? `${res.total} سجل` : `${res.total} entries`) : ""}</span>
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)} aria-label="previous">
              <Prev className="h-4 w-4" />
            </Button>
            <span>{isRTL ? `صفحة ${page} من ${pages}` : `Page ${page} of ${pages}`}</span>
            <Button variant="outline" size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)} aria-label="next">
              <Next className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </Card>
    </div>
  );
}
