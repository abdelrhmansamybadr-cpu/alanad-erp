import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";
import { Lock, Plus, Save, Trash2, KeyRound } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "../../components/ui/card";
import { Button } from "../../components/ui/button";
import { Badge } from "../../components/ui/badge";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { Checkbox } from "../../components/ui/checkbox";
import { Switch } from "../../components/ui/switch";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "../../components/ui/tabs";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { apiRequest } from "../../services/api";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";

interface Role { id: number; company_id: number | null; role_key: string; name_en: string; name_ar: string | null; is_system: number | boolean; permissions: string[] }
interface User { id: number; name: string; email: string; role_id: number | null; lang: string; is_active: number | boolean; must_change_password: number | boolean }

const ACTIONS = ["view", "create", "edit", "delete", "approve", "export"] as const;
const ACTION_AR: Record<string, string> = { view: "عرض", create: "إنشاء", edit: "تعديل", delete: "حذف", approve: "اعتماد", export: "تصدير" };

// [resource key used by the API, English, Arabic]
const RESOURCES: [string, string, string][] = [
  ["items", "Items", "الأصناف"],
  ["warehouses", "Warehouses", "المستودعات"],
  ["cold-rooms", "Cold rooms", "غرف التبريد"],
  ["stock", "Stock movements", "حركة المخزون"],
  ["item-batches", "Batches", "الدفعات"],
  ["alarms", "Alarms", "الإنذارات"],
  ["quality-holds", "Quality holds", "الحجز النوعي"],
  ["deliveries", "Receiving & quality (trucks)", "الاستلام والجودة (الشاحنات)"],
  ["suppliers", "Suppliers", "الموردون"],
  ["supplier-prices", "Supplier prices", "أسعار الموردين"],
  ["purchase-requests", "Purchase requests", "طلبات الشراء"],
  ["purchase-orders", "Purchase orders", "أوامر الشراء"],
  ["customers", "Customers", "العملاء"],
  ["quotations", "Quotations", "عروض الأسعار"],
  ["sales-orders", "Sales orders", "أوامر البيع"],
  ["invoices", "Invoices", "الفواتير"],
  ["payments", "Payments", "المدفوعات"],
  ["prep-specs", "Preparation specifications", "مواصفات التجهيز"],
  ["prep-orders", "Preparation orders", "أوامر التجهيز"],
  ["haccp-logs", "Food-safety logs", "سجلات سلامة الغذاء"],
  ["production-reports", "Production reports", "تقارير الإنتاج"],
  ["ap-invoices", "Supplier invoices", "فواتير الموردين"],
  ["ap-payments", "Supplier payments", "مدفوعات الموردين"],
  ["gl-entries", "Journal entries", "قيود اليومية"],
  ["gl-accounts", "Chart of accounts", "دليل الحسابات"],
  ["finance-reports", "Finance reports", "التقارير المالية"],
  ["employees", "Employees", "الموظفون"],
  ["projects", "Projects", "المشاريع"],
  ["workflows", "Workflows", "مسارات الاعتماد"],
];

/** Does one stored permission cover resource.action? */
const covers = (p: string, res: string, act: string) =>
  p === "*" || p === `*.${act}` || p === `${res}.*` || p === `${res}.${act}`;
const coveredBy = (perms: string[], res: string, act: string) => perms.some((p) => covers(p, res, act));

export function RoleManagement() {
  const { isRTL } = useI18n();
  const { can, me } = useAuth();
  const canManage = can("admin", "manage");

  const [roles, setRoles] = useState<Role[]>([]);
  const [users, setUsers] = useState<User[]>([]);
  const [error, setError] = useState("");
  const [sel, setSel] = useState<number | null>(null);
  const [perms, setPerms] = useState<string[]>([]);
  const [newRole, setNewRole] = useState<{ role_key: string; name_en: string; name_ar: string } | null>(null);
  const [newUser, setNewUser] = useState<{ name: string; email: string; password: string; role_id: string } | null>(null);
  const [resetFor, setResetFor] = useState<{ id: number; password: string } | null>(null);

  const loadRoles = () =>
    apiRequest<Role[]>("/roles")
      .then((r) => {
        setRoles(r);
        setSel((cur) => cur ?? r[0]?.id ?? null);
      })
      .catch((e) => setError(e.message));
  const loadUsers = () => apiRequest<User[]>("/users").then(setUsers).catch((e) => setError(e.message));
  useEffect(() => {
    loadRoles();
    loadUsers();
  }, []);

  const role = roles.find((r) => r.id === sel) ?? null;
  useEffect(() => setPerms(role?.permissions ?? []), [role?.id, roles]);

  const locked = !!role && (role.role_key === "admin" || !canManage);
  const dirty = useMemo(
    () => !!role && JSON.stringify([...perms].sort()) !== JSON.stringify([...role.permissions].sort()),
    [perms, role]
  );

  const toggle = (perm: string, on: boolean) =>
    setPerms((p) => (on ? [...new Set([...p, perm])] : p.filter((x) => x !== perm)));

  const roleName = (r: Role) => (isRTL ? r.name_ar || r.name_en : r.name_en);
  const usersOf = (id: number) => users.filter((u) => u.role_id === id).length;

  const savePerms = async () => {
    if (!role) return;
    try {
      await apiRequest(`/roles/${role.id}`, { method: "PUT", body: JSON.stringify({ permissions: perms }) });
      toast.success(isRTL ? "تم حفظ الصلاحيات" : "Permissions saved");
      await loadRoles();
    } catch (e: any) {
      toast.error(e.message);
    }
  };
  const createRole = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newRole) return;
    try {
      const r = await apiRequest<Role>("/roles", { method: "POST", body: JSON.stringify({ ...newRole, permissions: ["*.view"] }) });
      setNewRole(null);
      setSel(r.id);
      await loadRoles();
      toast.success(isRTL ? "تم إنشاء الدور" : "Role created");
    } catch (err: any) {
      toast.error(err.message);
    }
  };
  const deleteRole = async () => {
    if (!role) return;
    try {
      await apiRequest(`/roles/${role.id}`, { method: "DELETE" });
      setSel(null);
      await loadRoles();
      toast.success(isRTL ? "تم حذف الدور" : "Role deleted");
    } catch (e: any) {
      toast.error(e.message);
    }
  };

  const createUser = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newUser) return;
    try {
      await apiRequest("/users", {
        method: "POST",
        body: JSON.stringify({ ...newUser, role_id: Number(newUser.role_id) || undefined }),
      });
      setNewUser(null);
      await loadUsers();
      toast.success(isRTL ? "تم إنشاء المستخدم. سيُطلب منه تغيير كلمة المرور." : "User created. They must change the password at first login.");
    } catch (err: any) {
      toast.error(err.message);
    }
  };
  const patchUser = async (id: number, body: object) => {
    try {
      await apiRequest(`/users/${id}`, { method: "PUT", body: JSON.stringify(body) });
      await loadUsers();
    } catch (e: any) {
      toast.error(e.message);
    }
  };
  const resetPassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!resetFor) return;
    await patchUser(resetFor.id, { password: resetFor.password });
    setResetFor(null);
    toast.success(isRTL ? "تم تعيين كلمة مرور مؤقتة" : "Temporary password set");
  };

  const align = isRTL ? "text-right" : "text-left";

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">{isRTL ? "المستخدمون والصلاحيات" : "Users & Permissions"}</h1>
        <p className="text-muted-foreground mt-1">
          {isRTL ? "كل ما لا يُمنح صراحةً يكون ممنوعاً." : "Anything not granted explicitly is denied."}
        </p>
      </div>
      {error && (
        <div className="p-3 rounded-lg bg-red-50 text-red-700 text-sm" role="alert">
          {error}
        </div>
      )}

      <Tabs defaultValue="roles">
        <TabsList>
          <TabsTrigger value="roles">{isRTL ? "الأدوار" : "Roles"}</TabsTrigger>
          <TabsTrigger value="users">{isRTL ? "المستخدمون" : "Users"}</TabsTrigger>
        </TabsList>

        <TabsContent value="roles" className="space-y-4">
          <div className="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-4">
            <Card>
              <CardHeader className="flex-row items-center justify-between">
                <CardTitle className="text-base">{isRTL ? "الأدوار" : "Roles"}</CardTitle>
                {canManage && (
                  <Button size="sm" variant="outline" onClick={() => setNewRole({ role_key: "", name_en: "", name_ar: "" })}>
                    <Plus className="h-4 w-4" />
                  </Button>
                )}
              </CardHeader>
              <CardContent className="space-y-1">
                {roles.map((r) => (
                  <button
                    key={r.id}
                    onClick={() => setSel(r.id)}
                    className={`w-full flex items-center justify-between rounded-md px-3 py-2 text-sm ${align} ${
                      r.id === sel ? "bg-primary/10 font-semibold" : "hover:bg-muted"
                    }`}
                  >
                    <span>{roleName(r)}</span>
                    <span className="text-xs text-muted-foreground">{usersOf(r.id)}</span>
                  </button>
                ))}
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="flex-row items-center justify-between gap-2">
                <CardTitle className="text-base flex items-center gap-2">
                  {role ? roleName(role) : "—"}
                  {role && Number(role.is_system) === 1 && <Badge variant="secondary">{isRTL ? "نظامي" : "System"}</Badge>}
                  {role?.role_key === "admin" && <Lock className="h-4 w-4 text-muted-foreground" />}
                </CardTitle>
                {canManage && role && (
                  <div className="flex gap-2">
                    {Number(role.is_system) !== 1 && (
                      <Button size="sm" variant="outline" onClick={deleteRole}>
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    )}
                    <Button size="sm" disabled={!dirty || locked} onClick={savePerms} className="gap-2">
                      <Save className="h-4 w-4" />
                      {isRTL ? "حفظ" : "Save"}
                    </Button>
                  </div>
                )}
              </CardHeader>
              <CardContent className="overflow-x-auto">
                {role?.role_key === "admin" && (
                  <p className="text-sm text-muted-foreground mb-3">
                    {isRTL ? "دور المدير يملك كل الصلاحيات ولا يمكن تعديله." : "The admin role has every permission and cannot be changed."}
                  </p>
                )}
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className={align}>{isRTL ? "المورد" : "Resource"}</TableHead>
                      {ACTIONS.map((a) => (
                        <TableHead key={a} className="text-center">{isRTL ? ACTION_AR[a] : a}</TableHead>
                      ))}
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    <TableRow className="bg-muted/40">
                      <TableCell className="font-semibold">{isRTL ? "كل الموارد" : "All resources"}</TableCell>
                      {ACTIONS.map((a) => {
                        const on = perms.includes("*") || perms.includes(`*.${a}`);
                        return (
                          <TableCell key={a} className="text-center">
                            <Checkbox
                              checked={on}
                              disabled={locked || perms.includes("*")}
                              onCheckedChange={(v) => toggle(`*.${a}`, !!v)}
                              aria-label={`all ${a}`}
                            />
                          </TableCell>
                        );
                      })}
                    </TableRow>
                    {RESOURCES.map(([key, en, ar]) => (
                      <TableRow key={key}>
                        <TableCell>{isRTL ? ar : en}</TableCell>
                        {ACTIONS.map((a) => {
                          const global = perms.includes("*") || perms.includes(`*.${a}`);
                          const on = coveredBy(perms, key, a);
                          return (
                            <TableCell key={a} className="text-center">
                              <Checkbox
                                checked={on}
                                disabled={locked || global}
                                onCheckedChange={(v) => toggle(`${key}.${a}`, !!v)}
                                aria-label={`${key} ${a}`}
                              />
                            </TableCell>
                          );
                        })}
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        <TabsContent value="users" className="space-y-4">
          {canManage && (
            <div className="flex justify-end">
              <Button className="gap-2" onClick={() => setNewUser({ name: "", email: "", password: "", role_id: String(roles.find((r) => r.role_key === "user")?.id ?? "") })}>
                <Plus className="h-4 w-4" />
                {isRTL ? "مستخدم جديد" : "New user"}
              </Button>
            </div>
          )}
          <Card className="p-6 overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className={align}>{isRTL ? "الاسم" : "Name"}</TableHead>
                  <TableHead className={align}>{isRTL ? "البريد" : "Email"}</TableHead>
                  <TableHead className={align}>{isRTL ? "الدور" : "Role"}</TableHead>
                  <TableHead className={align}>{isRTL ? "نشط" : "Active"}</TableHead>
                  <TableHead />
                </TableRow>
              </TableHeader>
              <TableBody>
                {users.map((u) => (
                  <TableRow key={u.id}>
                    <TableCell className="font-medium">
                      {u.name}
                      {Number(u.must_change_password) === 1 && (
                        <Badge variant="outline" className="ms-2">{isRTL ? "كلمة مؤقتة" : "temp password"}</Badge>
                      )}
                    </TableCell>
                    <TableCell dir="ltr" className="text-sm">{u.email}</TableCell>
                    <TableCell>
                      {canManage ? (
                        <select
                          className="border rounded-md px-2 py-1 text-sm bg-background"
                          value={u.role_id ?? ""}
                          onChange={(e) => patchUser(u.id, { role_id: Number(e.target.value) })}
                          aria-label="role"
                        >
                          {roles.map((r) => (
                            <option key={r.id} value={r.id}>{roleName(r)}</option>
                          ))}
                        </select>
                      ) : (
                        roleName(roles.find((r) => r.id === u.role_id) ?? ({ name_en: "—" } as Role))
                      )}
                    </TableCell>
                    <TableCell>
                      <Switch
                        checked={Number(u.is_active) === 1}
                        disabled={!canManage || u.id === me?.id}
                        onCheckedChange={(v) => patchUser(u.id, { is_active: v ? 1 : 0 })}
                        aria-label="active"
                      />
                    </TableCell>
                    <TableCell>
                      {canManage && (
                        <Button variant="ghost" size="sm" onClick={() => setResetFor({ id: u.id, password: "" })} aria-label="reset password">
                          <KeyRound className="h-4 w-4" />
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        </TabsContent>
      </Tabs>

      <Dialog open={!!newRole} onOpenChange={(o) => !o && setNewRole(null)}>
        <DialogContent>
          <form onSubmit={createRole} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "دور جديد" : "New role"}</DialogTitle></DialogHeader>
            <div className="space-y-1">
              <Label htmlFor="rk">{isRTL ? "المفتاح (حروف صغيرة وأرقام و _)" : "Key (lowercase, digits, _)"}</Label>
              <Input id="rk" dir="ltr" required value={newRole?.role_key ?? ""} onChange={(e) => setNewRole((s) => s && { ...s, role_key: e.target.value })} />
            </div>
            <div className="space-y-1">
              <Label htmlFor="rn">{isRTL ? "الاسم (إنجليزي)" : "Name (English)"}</Label>
              <Input id="rn" required value={newRole?.name_en ?? ""} onChange={(e) => setNewRole((s) => s && { ...s, name_en: e.target.value })} />
            </div>
            <div className="space-y-1">
              <Label htmlFor="ra">{isRTL ? "الاسم (عربي)" : "Name (Arabic)"}</Label>
              <Input id="ra" value={newRole?.name_ar ?? ""} onChange={(e) => setNewRole((s) => s && { ...s, name_ar: e.target.value })} />
            </div>
            <DialogFooter><Button type="submit">{isRTL ? "إنشاء" : "Create"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!newUser} onOpenChange={(o) => !o && setNewUser(null)}>
        <DialogContent>
          <form onSubmit={createUser} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "مستخدم جديد" : "New user"}</DialogTitle></DialogHeader>
            <div className="space-y-1">
              <Label htmlFor="un">{isRTL ? "الاسم" : "Name"}</Label>
              <Input id="un" required value={newUser?.name ?? ""} onChange={(e) => setNewUser((s) => s && { ...s, name: e.target.value })} />
            </div>
            <div className="space-y-1">
              <Label htmlFor="ue">{isRTL ? "البريد الإلكتروني" : "Email"}</Label>
              <Input id="ue" type="email" dir="ltr" required value={newUser?.email ?? ""} onChange={(e) => setNewUser((s) => s && { ...s, email: e.target.value })} />
            </div>
            <div className="space-y-1">
              <Label htmlFor="up">{isRTL ? "كلمة مرور مؤقتة (١٠ أحرف على الأقل، حروف وأرقام)" : "Temporary password (10+ chars, letters and digits)"}</Label>
              <Input id="up" type="text" dir="ltr" required value={newUser?.password ?? ""} onChange={(e) => setNewUser((s) => s && { ...s, password: e.target.value })} />
            </div>
            <div className="space-y-1">
              <Label htmlFor="ur">{isRTL ? "الدور" : "Role"}</Label>
              <select id="ur" className="w-full border rounded-md px-2 py-2 text-sm bg-background" value={newUser?.role_id ?? ""} onChange={(e) => setNewUser((s) => s && { ...s, role_id: e.target.value })}>
                {roles.map((r) => (<option key={r.id} value={r.id}>{roleName(r)}</option>))}
              </select>
            </div>
            <DialogFooter><Button type="submit">{isRTL ? "إنشاء" : "Create"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!resetFor} onOpenChange={(o) => !o && setResetFor(null)}>
        <DialogContent>
          <form onSubmit={resetPassword} className="space-y-4">
            <DialogHeader><DialogTitle>{isRTL ? "كلمة مرور مؤقتة جديدة" : "New temporary password"}</DialogTitle></DialogHeader>
            <Input dir="ltr" required aria-label="temporary password" value={resetFor?.password ?? ""} onChange={(e) => setResetFor((s) => s && { ...s, password: e.target.value })} />
            <p className="text-xs text-muted-foreground">
              {isRTL ? "سيُطلب من المستخدم تغييرها عند الدخول." : "The user must change it at the next login."}
            </p>
            <DialogFooter><Button type="submit">{isRTL ? "تعيين" : "Set"}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
