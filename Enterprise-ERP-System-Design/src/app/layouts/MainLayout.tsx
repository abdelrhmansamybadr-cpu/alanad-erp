import { Outlet, Link, useLocation, useNavigate } from "react-router";
import { useEffect, useState } from "react";
import {
  LayoutDashboard,
  Package,
  ShoppingCart,
  FileText,
  Users,
  Headset,
  Briefcase,
  BarChart3,
  Settings,
  Shield,
  Download,
  Smartphone,
  Factory,
  Palette,
  Menu,
  X,
  Bell,
  BellRing,
  Search,
  ChevronDown,
  LogOut,
  Landmark,
  Truck,
  ChevronRight,
  CheckCheck,
  ArrowRight,
  Clock,
  PackageCheck,
  ClipboardList,
  FileSearch,
  Globe,
  CalendarDays,
  UserCircle,
  LifeBuoy,
} from "lucide-react";
import { Button } from "../components/ui/button";
import { Input } from "../components/ui/input";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "../components/ui/dropdown-menu";
import { Badge } from "../components/ui/badge";
import { useWorkQueue } from "../hooks/useWorkQueue";
import { useModuleContext } from "../context/ModuleContext";
import { useUser } from "../context/UserContext";
import { useAuth } from "../context/AuthContext";
import { useI18n } from "../context/I18nContext";
import { tokenStorage } from "../services/api";
import { useAccess } from "../access";

interface NavItem {
  title: string;
  /** Arabic title for the fixed "My services" items (the work modules use the i18n keys below) */
  titleAr?: string;
  href: string;
  /** where the link goes when it differs from href (a work module opens the first tab the person may use) */
  to?: string;
  /** prefix that marks the item active */
  match?: string;
  icon: React.ComponentType<{ className?: string }>;
  badge?: string;
}

/** LAYER 1 — fixed for every signed-in person; each shows only the person's OWN data. */
const servicesNavigation: NavItem[] = [
  { title: "My day", titleAr: "لوحتي", href: "/", icon: LayoutDashboard },
  { title: "My leave", titleAr: "إجازاتي", href: "/my/leave", icon: CalendarDays },
  { title: "My IT tickets", titleAr: "بلاغاتي التقنية", href: "/it/tickets", icon: LifeBuoy },
  { title: "My profile", titleAr: "بياناتي", href: "/my/profile", icon: UserCircle },
  { title: "Settings", titleAr: "الإعدادات", href: "/settings", icon: Settings },
];

/** LAYER 2 — the work modules: each appears only if the person may open at least one of its tabs. */
const workNavigation: (NavItem & { module?: string })[] = [
  { title: "Inventory", href: "/inventory", icon: Package, module: "inventory" },
  { title: "Purchases", href: "/procurement", icon: ShoppingCart, module: "procurement" },
  { title: "Production", href: "/production", icon: Factory, module: "production" },
  { title: "Accounting", href: "/accounting", icon: Landmark, module: "accounting" },
  { title: "Sales", href: "/sales", icon: FileText, module: "sales" },
  { title: "HR", href: "/hr", icon: Users, module: "hr" },
  { title: "IT", href: "/it", icon: Headset },
  { title: "Reports", href: "/reports", icon: BarChart3, module: "reports" },
];

const toolsNavigation: NavItem[] = [
  { title: "Admin Panel", href: "/admin", icon: Shield },
  { title: "Download Center", href: "/downloads", icon: Download },
  { title: "Design System", href: "/design-system", icon: Palette },
  { title: "وصف المشروع (AR)", href: "/client-proposal-ar", icon: FileSearch },
];

// Maps sidebar href → module ID. Items with no mapping are always shown.
const NAV_MODULE_MAP: Record<string, string> = {
  "/inventory":   "inventory",
  "/procurement": "procurement",
  "/production":  "production",
  "/accounting":  "accounting",
  "/sales":       "sales",
  "/projects":    "projects",
  "/hr":          "hr",
  "/it":          "it",
};

const NAV_TITLE_MAP: Record<string, string> = {
  "/": "nav.dashboard",
  "/inventory": "nav.inventory",
  "/procurement": "nav.procurement",
  "/production": "nav.production",
  "/accounting": "nav.accounting",
  "/sales": "nav.sales",
  "/projects": "nav.projects",
  "/hr": "nav.hr",
  "/it": "nav.it",
  "/reports": "nav.reports",
  "/settings": "nav.settings",
  "/admin": "nav.admin",
  "/downloads": "nav.downloads",
  "/design-system": "nav.designSystem",
  "/mobile": "nav.mobilePreview",
  "/client-proposal-ar": "nav.clientProposal",
};

export function MainLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [notifRead, setNotifRead] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();
  const { pendingPRs, pendingApprovalPOs, pendingReceivePOs, pendingFinance } = useWorkQueue();
  const purchaseOrders: { status: string; id: number; poNumber: string; vendor: string; grandTotal: number }[] = [];   // accounting review is not part of the real flow yet
  const getPendingInventoryPOs = () => pendingReceivePOs;
  const getPendingAdmin = () => pendingApprovalPOs;
  const getPendingPRs = () => pendingPRs;
  const { isEnabled } = useModuleContext();
  const { profile } = useUser();
  const { me, can, moduleOpen, switchCompany, switchBranch, logout } = useAuth();
  const company = me?.companies.find((c) => c.id === me.company_id);
  const { isRTL, direction, toggleLanguage, setLanguage, t } = useI18n();
  const { canOpen, firstTab, itStaff } = useAccess();

  // a page this person may not use cannot be reached by typing its address: go home (the server refuses it as well)
  useEffect(() => {
    if (me && !canOpen(location.pathname)) navigate("/", { replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname, me]);

  // Accounting badge: pending_accounting
  const pendingAccountingCount = purchaseOrders.filter(
    (p) => p.status === "pending_accounting"
  ).length;

  // Inventory badge: POs sent to inventory not yet received
  const pendingInventoryCount = getPendingInventoryPOs().length;

  // Admin badge: POs escalated to admin for approval
  const pendingAdminCount = getPendingAdmin().length;

  // Procurement badge: pending project purchase requisitions
  const pendingPRCount = getPendingPRs().length;

  // Total notification count for bell
  const totalNotifications =
    pendingFinance + pendingInventoryCount + pendingAdminCount + pendingPRCount;

  const displayCount = notifRead ? 0 : totalNotifications;

  const isActive = (href: string) => {
    if (href === "/") {
      return location.pathname === href;
    }
    return location.pathname.startsWith(href);
  };

  const handleLogout = async () => {
    await logout();
    navigate("/auth/login", { replace: true });
  };

  const renderNavItem = (item: NavItem) => {
    const Icon = item.icon;
    const active = isActive(item.match ?? item.href);
    const localizedTitle = item.titleAr ? (isRTL ? item.titleAr : item.title) : NAV_TITLE_MAP[item.href] ? t(NAV_TITLE_MAP[item.href]) : item.title;

    // Determine badge content per module
    let badgeCount: number | null = null;
    let badgeColor = "bg-amber-500";

    if (item.href === "/accounting" && pendingFinance > 0) {
      badgeCount = pendingFinance;
      badgeColor = "bg-amber-500";
    } else if (item.href === "/inventory" && pendingInventoryCount > 0) {
      badgeCount = pendingInventoryCount;
      badgeColor = "bg-blue-500";
    } else if (item.href === "/procurement" && pendingPRCount + pendingAdminCount > 0) {
      badgeCount = pendingPRCount + pendingAdminCount;
      badgeColor = "bg-amber-500";
    }

    return (
      <Link
        key={item.href}
        to={item.to ?? item.href}
        className={`flex items-center justify-between px-3 py-2.5 rounded-lg transition-colors ${
          active
            ? "bg-sidebar-primary text-sidebar-primary-foreground font-semibold"
            : "text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
        }`}
        onClick={() => setSidebarOpen(false)}
      >
        <div className="flex items-center gap-3 min-w-0">
          <Icon className="h-5 w-5 flex-shrink-0" />
          <span className="text-sm font-medium truncate">{localizedTitle}</span>
        </div>
        {badgeCount !== null && badgeCount > 0 ? (
          <span
            className={`inline-flex items-center justify-center h-5 min-w-5 px-1 rounded-full text-[10px] font-bold ${badgeColor} text-white flex-shrink-0`}
          >
            {badgeCount}
          </span>
        ) : item.badge ? (
          <Badge variant="secondary" className="text-xs flex-shrink-0">
            {item.badge}
          </Badge>
        ) : null}
      </Link>
    );
  };

  return (
    <div className="min-h-screen bg-background overflow-x-hidden" dir={direction}>
      {/* Sidebar */}
      <aside
        className={`fixed inset-y-0 z-50 w-64 bg-sidebar text-sidebar-foreground transform transition-transform duration-200 ease-in-out lg:translate-x-0 ${
          isRTL
            ? `right-0 left-auto ${sidebarOpen ? "translate-x-0" : "translate-x-full"}`
            : `left-0 right-auto ${sidebarOpen ? "translate-x-0" : "-translate-x-full"}`
        }`}
      >
        <div className="flex flex-col h-full">
          {/* Logo */}
          <div className="flex items-center justify-between h-16 px-6 border-b border-sidebar-border flex-shrink-0">
            <div className="flex items-center gap-3 min-w-0">
              <img
                src={`${import.meta.env.BASE_URL}brand/logo-square.png`}
                alt="Alanad"
                className="w-10 h-10 rounded-lg shadow-sm flex-shrink-0 object-cover"
              />
              <div className="min-w-0">
                <h2 className="text-sm font-semibold truncate">Alanad ERP</h2>
                {me && me.companies.length > 1 ? (
                  <select
                    value={me.company_id}
                    onChange={(e) => switchCompany(Number(e.target.value))}
                    className="mt-0.5 max-w-[9.5rem] text-xs bg-transparent text-sidebar-foreground/80 border border-sidebar-border rounded px-1 py-0.5"
                    aria-label={isRTL ? "الشركة" : "Company"}
                  >
                    {me.companies.map((c) => (
                      <option key={c.id} value={c.id} className="text-black">
                        {isRTL && c.name_ar ? c.name_ar : c.name_en}
                      </option>
                    ))}
                  </select>
                ) : (
                  <p className="text-xs text-sidebar-foreground/60 truncate">
                    {company ? (isRTL && company.name_ar ? company.name_ar : company.name_en) : ""}
                  </p>
                )}
                {me && (me.branches?.length ?? 0) > 1 && (
                  <select
                    value={tokenStorage.getBranch() ?? ""}
                    onChange={(e) => switchBranch(e.target.value ? Number(e.target.value) : null)}
                    className="mt-0.5 max-w-[9.5rem] text-xs bg-transparent text-sidebar-foreground/80 border border-sidebar-border rounded px-1 py-0.5"
                    aria-label={isRTL ? "الفرع" : "Branch"}
                    data-testid="branch-switcher"
                  >
                    <option value="" className="text-black">{isRTL ? "كل الفروع" : "All branches"}</option>
                    {me.branches!.map((b) => (
                      <option key={b.id} value={b.id} className="text-black">
                        {isRTL && b.name_ar ? b.name_ar : b.name_en}
                      </option>
                    ))}
                  </select>
                )}
              </div>
            </div>
            <Button
              variant="ghost"
              size="sm"
              className="lg:hidden text-sidebar-foreground flex-shrink-0"
              onClick={() => setSidebarOpen(false)}
            >
              <X className="h-5 w-5" />
            </Button>
          </div>

          {/* Navigation */}
          <nav className="flex-1 px-4 py-4 overflow-y-auto">
            {/* Layer 1: fixed for everybody */}
            <div className="space-y-0.5" data-testid="nav-services">
              <p className="text-[10px] uppercase tracking-widest text-sidebar-foreground/40 px-3 py-1.5 font-medium">
                {isRTL ? "خدماتي" : "My services"}
              </p>
              {servicesNavigation.map((item) => renderNavItem(item))}
            </div>

            {/* Layer 2: my work, by role */}
            {(() => {
              const items = workNavigation.flatMap((item) => {
                const id = item.module ? item.module : null;
                const uiId = item.href.slice(1);
                if (NAV_MODULE_MAP[item.href] && !moduleOpen(NAV_MODULE_MAP[item.href])) return [];   // closed by the administrator for this company
                if (id) {
                  const first = firstTab(id);
                  return first ? [{ ...item, to: first, match: item.href }] : [];
                }
                if (item.href === "/it") return itStaff ? [{ ...item, match: "/it" }] : [];
                return uiId ? [item] : [];
              });
              return items.length > 0 ? (
                <div className="mt-4 pt-4 border-t border-sidebar-border space-y-0.5" data-testid="nav-work">
                  <p className="text-[10px] uppercase tracking-widest text-sidebar-foreground/40 px-3 py-1.5 font-medium">
                    {isRTL ? "عملي" : "My work"}
                  </p>
                  {items.map((item) => renderNavItem(item))}
                </div>
              ) : null;
            })()}

            {/* Tools & Admin */}
            {can("admin", "manage") && (
              <div className="mt-4 pt-4 border-t border-sidebar-border space-y-0.5">
                <p className="text-[10px] uppercase tracking-widest text-sidebar-foreground/40 px-3 py-1.5 font-medium">
                  {t("nav.adminTools")}
                </p>
                {toolsNavigation.map((item) => renderNavItem(item))}
              </div>
            )}

            {/* Goods Receipts quick-link if pending */}
            {pendingInventoryCount > 0 && canOpen("/inventory/receiving") && (
              <div className="mt-3">
                <Link
                  to="/inventory/receiving"
                  onClick={() => setSidebarOpen(false)}
                  className="flex items-center justify-between px-3 py-2 rounded-lg bg-blue-900/30 border border-blue-700/30 hover:bg-blue-900/50 transition-colors"
                >
                  <div className={`flex items-center gap-2 ${isRTL ? "flex-row-reverse" : ""}`}>
                    <Truck className="h-4 w-4 text-blue-400" />
                    <span className="text-xs font-medium text-blue-300">{t("nav.goodsReceipts")}</span>
                  </div>
                  <div className="flex items-center gap-1">
                    <span className="text-[10px] font-bold bg-blue-500 text-white rounded-full h-4 w-4 flex items-center justify-center">
                      {pendingInventoryCount}
                    </span>
                    <ChevronRight className={`h-3 w-3 text-blue-400 ${isRTL ? "rtl-flip-icon" : ""}`} />
                  </div>
                </Link>
              </div>
            )}
          </nav>

          {/* User Profile */}
          <div className="p-4 border-t border-sidebar-border flex-shrink-0">
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <button className={`flex items-center w-full gap-3 p-2 rounded-lg hover:bg-sidebar-accent transition-colors ${isRTL ? "text-right" : "text-left"}`}>
                  {profile.avatar ? (
                    <img src={profile.avatar} alt="avatar" className="w-8 h-8 rounded-full object-cover flex-shrink-0" />
                  ) : (
                    <div className="w-8 h-8 rounded-full bg-blue-500 flex items-center justify-center text-white font-medium text-sm flex-shrink-0">
                      {isRTL && profile.firstName === "John" ? "ش" : profile.firstName[0]}
                      {isRTL && profile.lastName === "Doe" ? "س" : profile.lastName[0]}
                    </div>
                  )}
                  <div className={`flex-1 min-w-0 ${isRTL ? "text-right" : "text-left"}`}>
                    <p className="text-sm font-medium text-sidebar-foreground truncate">
                      {isRTL && profile.firstName === "John" && profile.lastName === "Doe"
                        ? "شمس السيد"
                        : `${profile.firstName} ${profile.lastName}`}
                    </p>
                    <p className="text-xs text-sidebar-foreground/60 truncate">
                      {isRTL && profile.jobTitle === "System Administrator"
                        ? "مدير النظام الرئيسي"
                        : profile.jobTitle}
                    </p>
                  </div>
                  <ChevronDown className="h-4 w-4 text-sidebar-foreground/60 flex-shrink-0" />
                </button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align={isRTL ? "start" : "end"} className="w-56">
                <DropdownMenuLabel>{t("nav.myAccount")}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={() => navigate("/settings")}>{t("nav.profileSettings")}</DropdownMenuItem>
                <DropdownMenuItem onClick={() => navigate("/settings")}>{t("nav.changePassword")}</DropdownMenuItem>
                <DropdownMenuItem onClick={() => navigate("/settings")}>{t("nav.preferences")}</DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={toggleLanguage} className="cursor-pointer">
                  <Globe className={`h-4 w-4 ${isRTL ? "ml-2" : "mr-2"}`} />
                  {isRTL ? "English (LTR)" : "العربية (RTL)"}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={handleLogout} className="text-red-600 cursor-pointer">
                  <LogOut className={`h-4 w-4 ${isRTL ? "ml-2" : "mr-2"}`} />
                  {t("nav.logout")}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>
      </aside>

      {/* Main Content */}
      <div className={`min-w-0 ${isRTL ? "lg:pr-64 lg:pl-0" : "lg:pl-64 lg:pr-0"}`}>
        {/* Top Bar */}
        <header className="sticky top-0 z-40 flex items-center h-16 px-4 sm:px-6 bg-card border-b border-border">
          <Button
            variant="ghost"
            size="sm"
            className={`lg:hidden flex-shrink-0 ${isRTL ? "ml-3" : "mr-3"}`}
            onClick={() => setSidebarOpen(true)}
          >
            <Menu className="h-5 w-5" />
          </Button>

          <div className="flex-1 flex items-center justify-between gap-3 sm:gap-4 min-w-0">
            <div className="flex-1 max-w-xs sm:max-w-sm md:max-w-md min-w-0">
              <div className="relative">
                <Search className={`absolute ${isRTL ? "right-3" : "left-3"} top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none`} />
                <Input
                  type="search"
                  placeholder={t("nav.searchPlaceholder")}
                  className={`w-full h-9 ${isRTL ? "pr-9 pl-3 text-right" : "pl-9 pr-3"} bg-background text-xs sm:text-sm`}
                />
              </div>
            </div>

            <div className="flex items-center gap-2 sm:gap-2.5 flex-shrink-0">
              {/* Language Switcher Button */}
              <Button
                variant="outline"
                size="sm"
                onClick={toggleLanguage}
                className="flex items-center gap-1.5 h-9 px-2.5 sm:px-3 text-xs font-semibold rounded-lg border border-border bg-card hover:bg-accent hover:text-accent-foreground transition-colors cursor-pointer"
                title={isRTL ? "Switch to English (LTR)" : "التبديل إلى العربية (RTL)"}
              >
                <Globe className="h-4 w-4 text-primary flex-shrink-0" />
                <span className="hidden sm:inline">{isRTL ? "English" : "العربية"}</span>
                <span className="sm:hidden">{isRTL ? "EN" : "ع"}</span>
              </Button>

              {/* Notification Bell */}
              <DropdownMenu onOpenChange={(open) => { if (!open && notifRead) setNotifRead(false); }}>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="sm" className="relative h-9 w-9 p-0 flex items-center justify-center">
                    <Bell className="h-5 w-5" />
                    {displayCount > 0 && (
                      <span className={`absolute -top-1 ${isRTL ? "-left-1" : "-right-1"} h-4 min-w-4 px-1 bg-red-500 rounded-full text-[10px] text-white flex items-center justify-center font-bold shadow-sm`}>
                        {displayCount > 9 ? "9+" : displayCount}
                      </span>
                    )}
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align={isRTL ? "start" : "end"} className="w-96 p-0" sideOffset={8}>
                  {/* Header */}
                  <div className="flex items-center justify-between px-4 py-3 border-b border-border bg-muted/30">
                    <div className="flex items-center gap-2">
                      <BellRing className="h-4 w-4 text-foreground" />
                      <span className="font-semibold text-sm">{t("nav.notifications")}</span>
                      {totalNotifications > 0 && (
                        <span className="inline-flex items-center justify-center h-4 min-w-4 px-1.5 rounded-full text-[10px] font-bold bg-red-500 text-white">
                          {totalNotifications}
                        </span>
                      )}
                    </div>
                    {totalNotifications > 0 && (
                      <button
                        onClick={() => setNotifRead(true)}
                        className="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1 transition-colors"
                      >
                        <CheckCheck className="h-3.5 w-3.5" />
                        {t("nav.markAllRead")}
                      </button>
                    )}
                  </div>

                  {/* Notification Items */}
                  <div className="max-h-[420px] overflow-y-auto">
                    {totalNotifications === 0 ? (
                      <div className="flex flex-col items-center justify-center py-12 px-4 text-center">
                        <div className="w-12 h-12 rounded-full bg-muted flex items-center justify-center mb-3">
                          <Bell className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <p className="text-sm font-medium text-foreground">{t("nav.allCaughtUp")}</p>
                        <p className="text-xs text-muted-foreground mt-1">{t("nav.noPendingActions")}</p>
                      </div>
                    ) : (
                      <div className="py-1.5 space-y-0.5">

                        {/* Accounting POs */}
                        {pendingAccountingCount > 0 && (() => {
                          const pos = purchaseOrders.filter(p => p.status === "pending_accounting").slice(0, 3);
                          const extra = pendingAccountingCount - pos.length;
                          return (
                            <div>
                              <div className="px-4 py-1.5 flex items-center gap-1.5">
                                <Landmark className="h-3 w-3 text-amber-500" />
                                <span className="text-[10px] uppercase tracking-widest font-semibold text-amber-600">
                                  {t("nav.accountingReview")}
                                </span>
                              </div>
                              {pos.map((po) => (
                                <Link key={po.id} to="/accounting" className="flex items-start gap-3 px-4 py-2.5 hover:bg-muted/50 transition-colors group border-l-2 border-l-amber-400 mx-2 rounded-r-md mb-0.5">
                                  <div className="mt-0.5 flex-shrink-0 w-7 h-7 rounded-full bg-amber-100 flex items-center justify-center">
                                    <Clock className="h-3.5 w-3.5 text-amber-600" />
                                  </div>
                                  <div className="flex-1 min-w-0">
                                    <div className="flex items-center justify-between gap-2">
                                      <span className="text-xs font-semibold text-foreground">{po.poNumber}</span>
                                      <span className="text-[11px] font-medium text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded">
                                        {po.grandTotal.toLocaleString()} SAR
                                      </span>
                                    </div>
                                    <p className="text-[11px] text-muted-foreground truncate mt-0.5">{po.vendor} · Awaiting accounting approval</p>
                                  </div>
                                </Link>
                              ))}
                              {extra > 0 && (
                                <Link to="/accounting" className="flex items-center gap-1.5 px-4 py-1 text-[11px] text-amber-600 hover:text-amber-700 hover:underline ml-2">
                                  <ArrowRight className="h-3 w-3" /> +{extra} more pending
                                </Link>
                              )}
                            </div>
                          );
                        })()}

                        {/* Admin POs */}
                        {pendingAdminCount > 0 && (() => {
                          const pos = getPendingAdmin().slice(0, 3);
                          const extra = pendingAdminCount - pos.length;
                          return (
                            <div>
                              <div className="px-4 py-1.5 flex items-center gap-1.5">
                                <Shield className="h-3 w-3 text-purple-500" />
                                <span className="text-[10px] uppercase tracking-widest font-semibold text-purple-600">
                                  {isRTL ? "اعتماد الإدارة" : "Admin Approval"}
                                </span>
                              </div>
                              {pos.map((po) => (
                                <Link key={po.id} to={`/procurement/purchase-orders/${po.id}`} className="flex items-start gap-3 px-4 py-2.5 hover:bg-muted/50 transition-colors group border-l-2 rtl:border-l-0 rtl:border-r-2 border-l-purple-400 rtl:border-r-purple-400 mx-2 rounded-r-md rtl:rounded-r-none rtl:rounded-l-md mb-0.5">
                                  <div className="mt-0.5 flex-shrink-0 w-7 h-7 rounded-full bg-purple-100 flex items-center justify-center">
                                    <Shield className="h-3.5 w-3.5 text-purple-600" />
                                  </div>
                                  <div className="flex-1 min-w-0">
                                    <div className="flex items-center justify-between gap-2">
                                      <span className="text-xs font-semibold text-foreground">{po.poNumber}</span>
                                      <span className="text-[11px] font-medium text-purple-700 bg-purple-50 px-1.5 py-0.5 rounded">
                                        {po.grandTotal.toLocaleString()} SAR
                                      </span>
                                    </div>
                                    <p className="text-[11px] text-muted-foreground truncate mt-0.5">
                                      {po.vendor} · {isRTL ? "بانتظار اعتماد الإدارة" : "Awaiting admin approval"}
                                    </p>
                                  </div>
                                </Link>
                              ))}
                              {extra > 0 && (
                                <Link to="/procurement/purchase-orders" className="flex items-center gap-1.5 px-4 py-1 text-[11px] text-purple-600 hover:text-purple-700 hover:underline ml-2 rtl:ml-0 rtl:mr-2">
                                  <ArrowRight className={`h-3 w-3 ${isRTL ? "rotate-180" : ""}`} />
                                  {isRTL ? `+${extra} طلبات إضافية معلقة` : `+${extra} more pending`}
                                </Link>
                              )}
                            </div>
                          );
                        })()}

                        {/* Inventory GRs */}
                        {pendingInventoryCount > 0 && (() => {
                          const pos = getPendingInventoryPOs().slice(0, 3);
                          const extra = pendingInventoryCount - pos.length;
                          return (
                            <div>
                              <div className="px-4 py-1.5 flex items-center gap-1.5">
                                <PackageCheck className="h-3 w-3 text-blue-500" />
                                <span className="text-[10px] uppercase tracking-widest font-semibold text-blue-600">
                                  {isRTL ? "استلام البضائع" : "Goods Receipt"}
                                </span>
                              </div>
                              {pos.map((po) => (
                                <Link key={po.id} to={`/inventory/receiving?po=${po.id}`} className="flex items-start gap-3 px-4 py-2.5 hover:bg-muted/50 transition-colors group border-l-2 rtl:border-l-0 rtl:border-r-2 border-l-blue-400 rtl:border-r-blue-400 mx-2 rounded-r-md rtl:rounded-r-none rtl:rounded-l-md mb-0.5">
                                  <div className="mt-0.5 flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 flex items-center justify-center">
                                    <Truck className="h-3.5 w-3.5 text-blue-600" />
                                  </div>
                                  <div className="flex-1 min-w-0">
                                    <div className="flex items-center justify-between gap-2">
                                      <span className="text-xs font-semibold text-foreground">{po.poNumber}</span>
                                      <span className="text-[11px] font-medium text-blue-700 bg-blue-50 px-1.5 py-0.5 rounded">
                                        {po.grandTotal.toLocaleString()} SAR
                                      </span>
                                    </div>
                                    <p className="text-[11px] text-muted-foreground truncate mt-0.5">
                                      {po.vendor} · {isRTL ? "بانتظار استلام البضائع" : "Awaiting goods receipt"}
                                    </p>
                                  </div>
                                </Link>
                              ))}
                              {extra > 0 && (
                                <Link to="/inventory/receiving" className="flex items-center gap-1.5 px-4 py-1 text-[11px] text-blue-600 hover:text-blue-700 hover:underline ml-2 rtl:ml-0 rtl:mr-2">
                                  <ArrowRight className={`h-3 w-3 ${isRTL ? "rotate-180" : ""}`} />
                                  {isRTL ? `+${extra} طلبات إضافية معلقة` : `+${extra} more pending`}
                                </Link>
                              )}
                            </div>
                          );
                        })()}

                        {/* Procurement PRs */}
                        {pendingPRCount > 0 && (() => {
                          const prs = getPendingPRs().slice(0, 3);
                          const extra = pendingPRCount - prs.length;
                          return (
                            <div>
                              <div className="px-4 py-1.5 flex items-center gap-1.5">
                                <ClipboardList className="h-3 w-3 text-orange-500" />
                                <span className="text-[10px] uppercase tracking-widest font-semibold text-orange-600">
                                  {isRTL ? "طلبات الشراء" : "Purchase Requisitions"}
                                </span>
                              </div>
                              {prs.map((pr) => (
                                <Link key={pr.id} to={`/procurement/requests/${pr.id}`} className="flex items-start gap-3 px-4 py-2.5 hover:bg-muted/50 transition-colors group border-l-2 rtl:border-l-0 rtl:border-r-2 border-l-orange-400 rtl:border-r-orange-400 mx-2 rounded-r-md rtl:rounded-r-none rtl:rounded-l-md mb-0.5">
                                  <div className="mt-0.5 flex-shrink-0 w-7 h-7 rounded-full bg-orange-100 flex items-center justify-center">
                                    <ClipboardList className="h-3.5 w-3.5 text-orange-600" />
                                  </div>
                                  <div className="flex-1 min-w-0">
                                    <div className="flex items-center justify-between gap-2">
                                      <span className="text-xs font-semibold text-foreground">{pr.prNumber}</span>
                                      <span className="text-[11px] font-medium text-orange-700 bg-orange-50 px-1.5 py-0.5 rounded">
                                        {pr.lines} {isRTL ? "أصناف" : "items"}
                                      </span>
                                    </div>
                                    <p className="text-[11px] text-muted-foreground truncate mt-0.5">
                                      {pr.projectName} · {isRTL ? "بانتظار الاعتماد" : "Awaiting approval"}
                                    </p>
                                  </div>
                                </Link>
                              ))}
                              {extra > 0 && (
                                <Link to="/procurement" className="flex items-center gap-1.5 px-4 py-1 text-[11px] text-orange-600 hover:text-orange-700 hover:underline ml-2 rtl:ml-0 rtl:mr-2">
                                  <ArrowRight className={`h-3 w-3 ${isRTL ? "rotate-180" : ""}`} />
                                  {isRTL ? `+${extra} طلبات إضافية معلقة` : `+${extra} more pending`}
                                </Link>
                              )}
                            </div>
                          );
                        })()}

                      </div>
                    )}
                  </div>

                  {/* Footer */}
                  {totalNotifications > 0 && (
                    <div className="border-t border-border p-2 bg-muted/20">
                      <Link
                        to="/reports"
                        className="flex items-center justify-center gap-1.5 w-full py-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors rounded-md hover:bg-muted"
                      >
                        {isRTL ? "عرض كل النشاطات" : "View all activity"}
                        <ArrowRight className={`h-3 w-3 ${isRTL ? "rotate-180" : ""}`} />
                      </Link>
                    </div>
                  )}
                </DropdownMenuContent>
              </DropdownMenu>

              {/* Quick workflow badges - visible on xl screens to maintain clean spacing */}
              {pendingAdminCount > 0 && (
                <Link to="/procurement/purchase-orders" className="hidden xl:flex">
                  <div className="flex items-center gap-1.5 px-2.5 py-1.5 bg-purple-50 dark:bg-purple-950/40 border border-purple-200 dark:border-purple-800 rounded-lg hover:bg-purple-100 dark:hover:bg-purple-900/60 transition-colors cursor-pointer">
                    <Shield className="h-3.5 w-3.5 text-purple-600 dark:text-purple-400 flex-shrink-0" />
                    <span className="text-xs font-medium text-purple-800 dark:text-purple-300 whitespace-nowrap">
                      {isRTL ? `${pendingAdminCount} أوامر بانتظار الاعتماد` : `${pendingAdminCount} PO${pendingAdminCount !== 1 ? "s" : ""} to approve`}
                    </span>
                  </div>
                </Link>
              )}

              {pendingInventoryCount > 0 && (
                <Link to="/inventory/receiving" className="hidden xl:flex">
                  <div className="flex items-center gap-1.5 px-2.5 py-1.5 bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-800 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/60 transition-colors cursor-pointer">
                    <Truck className="h-3.5 w-3.5 text-blue-600 dark:text-blue-400 flex-shrink-0" />
                    <span className="text-xs font-medium text-blue-800 dark:text-blue-300 whitespace-nowrap">
                      {isRTL ? `${pendingInventoryCount} استلام بضائع معلق` : `${pendingInventoryCount} GR pending`}
                    </span>
                  </div>
                </Link>
              )}

              {/* Environment Badge */}
              <div className="flex items-center gap-2 px-2.5 sm:px-3 py-1.5 bg-blue-50 dark:bg-blue-950/40 rounded-lg border border-blue-200 dark:border-blue-800 flex-shrink-0">
                <div className="w-2 h-2 bg-green-500 rounded-full animate-pulse flex-shrink-0"></div>
                <span className="text-xs sm:text-sm font-medium text-blue-900 dark:text-blue-200 whitespace-nowrap">
                  {isRTL ? "بيئة الإنتاج" : "Production"}
                </span>
              </div>
            </div>
          </div>
        </header>

        {/* Page Content */}
        <main className="p-6">
          {canOpen(location.pathname) ? <Outlet /> : null}
        </main>
      </div>

      {/* Mobile Overlay */}
      {sidebarOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/50 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}
    </div>
  );
}