import { useState, useRef, useEffect } from "react";
import {
  User, Bell, Shield, Globe, Palette, Database, Mail, Smartphone,
  Key, Lock, CheckCircle, XCircle, Eye, EyeOff, Camera, Save,
  AlertTriangle, LogOut, RefreshCw, Link2, Unlink,
  Sun, Moon, Monitor, Check, Clock, MapPin, Wifi,
} from "lucide-react";
import { useUser } from "../context/UserContext";
import type { UserPreferences } from "../context/UserContext";
import { useI18n } from "../context/I18nContext";

// ─── Password strength ────────────────────────────────────────────────────────
function getStrength(pw: string): { score: number; label: string; color: string } {
  let score = 0;
  if (pw.length >= 8)  score++;
  if (pw.length >= 12) score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;
  if (score <= 1) return { score, label: "Very Weak",  color: "bg-red-500" };
  if (score === 2) return { score, label: "Weak",       color: "bg-orange-500" };
  if (score === 3) return { score, label: "Fair",       color: "bg-yellow-500" };
  if (score === 4) return { score, label: "Strong",     color: "bg-blue-500" };
  return              { score, label: "Very Strong", color: "bg-green-500" };
}

// ─── Toast helper ─────────────────────────────────────────────────────────────
function useToast() {
  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);
  const show = (msg: string, type: "success" | "error" = "success") => {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3000);
  };
  return { toast, show };
}

function Toast({ toast }: { toast: { msg: string; type: "success" | "error" } | null }) {
  if (!toast) return null;
  return (
    <div className={`fixed bottom-6 right-6 z-50 flex items-center gap-3 px-5 py-3.5 rounded-xl shadow-lg text-white transition-all ${toast.type === "success" ? "bg-green-600" : "bg-red-600"}`}>
      {toast.type === "success"
        ? <CheckCircle className="w-5 h-5 flex-shrink-0" />
        : <XCircle className="w-5 h-5 flex-shrink-0" />}
      <span className="text-sm font-medium">{toast.msg}</span>
    </div>
  );
}

// ─── Main Page ────────────────────────────────────────────────────────────────
export function SettingsPage() {
  const { isRTL, t } = useI18n();
  const [activeTab, setActiveTab] = useState("profile");
  const { toast, show: showToast } = useToast();

  const tabs = [
    { id: "profile",      label: t("settings.tabs.profile"),       icon: User },
    { id: "notifications",label: t("settings.tabs.notifications"), icon: Bell },
    { id: "security",     label: t("settings.tabs.security"),      icon: Shield },
    { id: "preferences",  label: t("settings.tabs.preferences"),   icon: Palette },
    { id: "integrations", label: t("settings.tabs.integrations"),  icon: Database },
  ];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-3xl font-bold text-gray-900">{t("settings.title")}</h1>
        <p className="text-gray-500 mt-1 text-sm">{t("settings.subtitle")}</p>
      </div>

      <div className={`flex gap-6 items-start flex-col md:flex-row ${isRTL ? "md:flex-row-reverse" : ""}`}>
        {/* ── Sidebar nav ── (selected element) */}
        <div className="w-full md:w-64 bg-white rounded-xl shadow-sm border border-gray-200 p-3 h-fit sticky top-20">
          <nav className="space-y-0.5">
            {tabs.map((tab) => {
              const Icon = tab.icon;
              const active = activeTab === tab.id;
              return (
                <button
                  key={tab.id}
                  onClick={() => setActiveTab(tab.id)}
                  className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-all ${isRTL ? "text-right" : "text-left"} cursor-pointer ${
                    active
                      ? "bg-blue-50 text-blue-700 font-medium shadow-sm"
                      : "text-gray-600 hover:bg-gray-50 hover:text-gray-900"
                  }`}
                >
                  <Icon className={`w-5 h-5 flex-shrink-0 ${active ? "text-blue-600" : "text-gray-400"}`} />
                  <span className="text-sm">{tab.label}</span>
                  {active && <div className={`${isRTL ? "mr-auto" : "ml-auto"} w-1.5 h-1.5 rounded-full bg-blue-500`} />}
                </button>
              );
            })}
          </nav>
        </div>

        {/* Content area */}
        <div className="flex-1 min-w-0">
          {activeTab === "profile"       && <ProfileSettings      isRTL={isRTL} onToast={showToast} />}
          {activeTab === "notifications" && <NotificationSettings isRTL={isRTL} onToast={showToast} />}
          {activeTab === "security"      && <SecuritySettings     isRTL={isRTL} onToast={showToast} />}
          {activeTab === "preferences"   && <PreferenceSettings   isRTL={isRTL} onToast={showToast} />}
          {activeTab === "integrations"  && <IntegrationSettings  isRTL={isRTL} onToast={showToast} />}
        </div>
      </div>

      <Toast toast={toast} />
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  PROFILE SETTINGS
// ═══════════════════════════════════════════════════════════════════════════════
function ProfileSettings({ isRTL, onToast }: { isRTL: boolean; onToast: (m: string, t?: "success" | "error") => void }) {
  const { profile, updateProfile } = useUser();
  const [form, setForm] = useState({ ...profile });
  const [saving, setSaving] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const isDirty = JSON.stringify(form) !== JSON.stringify(profile);

  // Sync if profile changes externally
  useEffect(() => { setForm({ ...profile }); }, [profile]);

  const handlePhoto = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
      onToast(isRTL ? "حجم الملف يتجاوز الحد المسموح به 5 ميجابايت" : "File exceeds 5 MB limit", "error"); return;
    }
    const reader = new FileReader();
    reader.onload = (ev) => {
      setForm(f => ({ ...f, avatar: ev.target?.result as string }));
    };
    reader.readAsDataURL(file);
  };

  const handleSave = () => {
    if (!form.firstName.trim() || !form.lastName.trim()) {
      onToast(isRTL ? "الاسم الأول واسم العائلة مطلوبان" : "First and last name are required", "error"); return;
    }
    if (!form.email.includes("@")) {
      onToast(isRTL ? "يرجى إدخال عنوان بريد إلكتروني صالح" : "Enter a valid email address", "error"); return;
    }
    setSaving(true);
    setTimeout(() => {
      updateProfile(form);
      setSaving(false);
      onToast(isRTL ? "تم تحديث الملف الشخصي بنجاح" : "Profile updated successfully");
    }, 800);
  };

  const removePhoto = () => setForm(f => ({ ...f, avatar: null }));

  return (
    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
      <div className="flex items-center justify-between mb-6">
        <h2 className="text-xl font-bold text-gray-900">{isRTL ? "معلومات الملف الشخصي" : "Profile Information"}</h2>
        {isDirty && (
          <span className="flex items-center gap-1.5 text-xs text-amber-600 bg-amber-50 border border-amber-200 px-2.5 py-1 rounded-full">
            <AlertTriangle className="w-3 h-3" /> {isRTL ? "تعديلات غير محفوظة" : "Unsaved changes"}
          </span>
        )}
      </div>

      <div className="space-y-6">
        {/* Avatar */}
        <div className="flex items-center gap-6">
          <div className="relative group">
            {form.avatar ? (
              <img src={form.avatar} alt="avatar" className="w-24 h-24 rounded-full object-cover border-4 border-blue-100" />
            ) : (
              <div className="w-24 h-24 bg-gradient-to-br from-blue-400 to-blue-600 rounded-full flex items-center justify-center border-4 border-blue-100">
                <span className="text-3xl font-bold text-white">
                  {form.firstName[0] || "?"}{form.lastName[0] || ""}
                </span>
              </div>
            )}
            <button
              onClick={() => fileRef.current?.click()}
              className="absolute inset-0 flex items-center justify-center bg-black/40 rounded-full opacity-0 group-hover:opacity-100 transition-opacity"
            >
              <Camera className="w-6 h-6 text-white" />
            </button>
          </div>
          <div>
            <p className="font-medium text-gray-900 mb-2">{form.firstName} {form.lastName}</p>
            <div className="flex gap-2">
              <button
                onClick={() => fileRef.current?.click()}
                className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium"
              >
                <Camera className="w-4 h-4" /> {isRTL ? "رفع صورة" : "Upload Photo"}
              </button>
              {form.avatar && (
                <button
                  onClick={removePhoto}
                  className="px-4 py-2 border border-red-300 text-red-600 rounded-lg hover:bg-red-50 transition-colors text-sm font-medium"
                >
                  {isRTL ? "إزالة" : "Remove"}
                </button>
              )}
            </div>
            <p className="text-xs text-gray-400 mt-1.5">{isRTL ? "JPG, PNG أو GIF • بحد أقصى 5 ميجابايت" : "JPG, PNG or GIF • max 5 MB"}</p>
          </div>
          <input ref={fileRef} type="file" accept="image/jpeg,image/png,image/gif" className="hidden" onChange={handlePhoto} />
        </div>

        {/* Name */}
        <div className="grid grid-cols-2 gap-4">
          {[
            { label: isRTL ? "الاسم الأول" : "First Name", key: "firstName" },
            { label: isRTL ? "اسم العائلة" : "Last Name",  key: "lastName" },
          ].map(({ label, key }) => (
            <div key={key}>
              <label className="block text-sm font-medium text-gray-700 mb-1.5">{label}</label>
              <input
                type="text"
                value={form[key as keyof typeof form] as string}
                onChange={(e) => setForm(f => ({ ...f, [key]: e.target.value }))}
                className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
              />
            </div>
          ))}
        </div>

        {/* Email */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1.5">{isRTL ? "عنوان البريد الإلكتروني" : "Email Address"}</label>
          <input
            type="email"
            value={form.email}
            onChange={(e) => setForm(f => ({ ...f, email: e.target.value }))}
            className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
          />
        </div>

        {/* Phone */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1.5">{isRTL ? "رقم الهاتف" : "Phone Number"}</label>
          <input
            type="tel"
            value={form.phone}
            onChange={(e) => setForm(f => ({ ...f, phone: e.target.value }))}
            className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
          />
        </div>

        {/* Job Title */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1.5">{isRTL ? "المسمى الوظيفي" : "Job Title"}</label>
          <input
            type="text"
            value={form.jobTitle}
            onChange={(e) => setForm(f => ({ ...f, jobTitle: e.target.value }))}
            className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
          />
        </div>

        {/* Department */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1.5">{isRTL ? "القسم" : "Department"}</label>
          <select
            value={form.department}
            onChange={(e) => setForm(f => ({ ...f, department: e.target.value }))}
            className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm bg-white"
          >
            {[
              { en: "IT Operations", ar: "تقنية المعلومات والتشغيل" },
              { en: "Finance", ar: "المالية والمحاسبة" },
              { en: "Sales", ar: "المبيعات" },
              { en: "Human Resources", ar: "الموارد البشرية" },
              { en: "Operations", ar: "العمليات" },
              { en: "Procurement", ar: "المشتريات والتوريد" },
              { en: "Management", ar: "الإدارة العامة" },
            ].map(d => (
              <option key={d.en} value={d.en}>{isRTL ? d.ar : d.en}</option>
            ))}
          </select>
        </div>

        {/* Preview card */}
        <div className="p-4 bg-gray-50 rounded-xl border border-gray-200">
          <p className="text-xs text-gray-500 font-medium mb-3 uppercase tracking-wide">{isRTL ? "معاينة الملف في الشريط الجانبي" : "Sidebar Preview"}</p>
          <div className="flex items-center gap-3 bg-slate-800 rounded-xl p-3 w-fit">
            {form.avatar ? (
              <img src={form.avatar} alt="preview" className="w-9 h-9 rounded-full object-cover" />
            ) : (
              <div className="w-9 h-9 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold text-sm">
                {form.firstName[0] || "?"}{form.lastName[0] || ""}
              </div>
            )}
            <div>
              <p className="text-sm font-medium text-white">{form.firstName || "First"} {form.lastName || "Last"}</p>
              <p className="text-xs text-slate-400">{form.jobTitle || (isRTL ? "المسمى الوظيفي" : "Job Title")}</p>
            </div>
          </div>
        </div>

        <div className="flex gap-3 pt-2">
          <button
            onClick={handleSave}
            disabled={saving || !isDirty}
            className="flex items-center gap-2 px-6 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors font-medium text-sm"
          >
            {saving ? <RefreshCw className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
            {saving ? (isRTL ? "جاري الحفظ…" : "Saving…") : (isRTL ? "حفظ التغييرات" : "Save Changes")}
          </button>
          {isDirty && (
            <button
              onClick={() => setForm({ ...profile })}
              className="px-4 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm"
            >
              {isRTL ? "تراجع" : "Discard"}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  NOTIFICATION SETTINGS
// ═══════════════════════════════════════════════════════════════════════════════
function NotificationSettings({ isRTL, onToast }: { isRTL: boolean; onToast: (m: string, t?: "success" | "error") => void }) {
  const { notifications, updateNotifications } = useUser();

  const toggle = (key: keyof typeof notifications) => {
    updateNotifications({ [key]: !notifications[key] });
    onToast(isRTL
      ? `${!notifications[key] ? "تم تفعيل" : "تم تعطيل"} الإشعار`
      : `${!notifications[key] ? "Enabled" : "Disabled"} notification`);
  };

  const groups = [
    {
      title: isRTL ? "إشعارات البريد الإلكتروني" : "Email Notifications", icon: Mail, color: "text-blue-600", bg: "bg-blue-50",
      items: [
        { key: "emailOrders"    as const, label: isRTL ? "طلبات البيع الجديدة" : "New Orders",         desc: isRTL ? "استلام بريد عند إنشاء أي أمر بيع جديد للعملاء" : "Receive an email for every new sales order placed" },
        { key: "emailInventory" as const, label: isRTL ? "تنبيهات نقص المخزون" : "Low Stock Alerts",   desc: isRTL ? "تنبيه بريدي فوري عند انخفاض رصيد أي منتج عن حد إعادة الطلب" : "Email when any product drops below its reorder threshold" },
        { key: "emailReports"   as const, label: isRTL ? "جاهزية التقارير" : "Report Generation",  desc: isRTL ? "إشعار بريدي عند اكتمال توليد التقارير المجدولة" : "Notify when scheduled reports are ready to download" },
      ],
    },
    {
      title: isRTL ? "الإشعارات المنبثقة والفورية" : "Push Notifications", icon: Smartphone, color: "text-purple-600", bg: "bg-purple-50",
      items: [
        { key: "pushApprovals" as const, label: isRTL ? "طلبات الاعتماد والموافقة" : "Approval Requests",  desc: isRTL ? "تنبيه فوري عند وجود أمر شراء أو طلب مواد بانتظار توقيعك" : "Alert when a PO or PR is waiting for your approval" },
        { key: "pushMentions"  as const, label: isRTL ? "الإشارات والتعليقات" : "Mentions & Comments", desc: isRTL ? "إشعار عند قيام أحد الزملاء بالإشارة إلى حسابك في ملاحظة" : "When someone tags you in a comment or note" },
        { key: "pushUpdates"   as const, label: isRTL ? "تحديثات النظام" : "System Updates",     desc: isRTL ? "الإعلانات الإدارية الهامة وفترات الصيانة المجدولة" : "Important announcements and maintenance windows" },
      ],
    },
  ];

  const enabledCount = Object.values(notifications).filter(Boolean).length;

  return (
    <div className="space-y-6">
      <div className="bg-blue-50 border border-blue-200 rounded-xl p-4 flex items-center gap-3">
        <Bell className="w-5 h-5 text-blue-600 flex-shrink-0" />
        <div className="flex-1">
          <p className="text-sm font-medium text-blue-900">
            {enabledCount} {isRTL ? "من أصل" : "of"} {Object.keys(notifications).length} {isRTL ? "إشعارات مفعلة" : "notifications enabled"}
          </p>
          <p className="text-xs text-blue-600 mt-0.5">{isRTL ? "يتم حفظ التغييرات تلقائياً" : "Changes are saved automatically"}</p>
        </div>
        <button
          onClick={() => {
            const all = enabledCount < Object.keys(notifications).length;
            updateNotifications({ emailOrders: all, emailInventory: all, emailReports: all, pushApprovals: all, pushMentions: all, pushUpdates: all });
            onToast(all ? (isRTL ? "تم تفعيل جميع الإشعارات" : "All notifications enabled") : (isRTL ? "تم تعطيل جميع الإشعارات" : "All notifications disabled"));
          }}
          className="text-xs font-medium text-blue-700 hover:text-blue-900 underline"
        >
          {enabledCount < Object.keys(notifications).length ? (isRTL ? "تفعيل الكل" : "Enable all") : (isRTL ? "تعطيل الكل" : "Disable all")}
        </button>
      </div>

      {groups.map((group) => {
        const Icon = group.icon;
        return (
          <div key={group.title} className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div className="flex items-center gap-3 mb-5">
              <div className={`w-10 h-10 ${group.bg} rounded-xl flex items-center justify-center`}>
                <Icon className={`w-5 h-5 ${group.color}`} />
              </div>
              <h2 className="font-bold text-gray-900">{group.title}</h2>
            </div>
            <div className="space-y-3">
              {group.items.map((item) => (
                <div key={item.key} className="flex items-center justify-between p-4 border border-gray-100 rounded-xl hover:border-gray-200 transition-colors">
                  <div className={`flex-1 min-w-0 ${isRTL ? "ml-4" : "mr-4"}`}>
                    <div className="font-medium text-gray-900 text-sm">{item.label}</div>
                    <div className="text-xs text-gray-500 mt-0.5">{item.desc}</div>
                  </div>
                  <button
                    role="switch"
                    aria-checked={notifications[item.key]}
                    onClick={() => toggle(item.key)}
                    className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors flex-shrink-0 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${notifications[item.key] ? "bg-blue-600" : "bg-gray-300"}`}
                  >
                    <span className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${notifications[item.key] ? (isRTL ? "-translate-x-6" : "translate-x-6") : (isRTL ? "-translate-x-1" : "translate-x-1")}`} />
                  </button>
                </div>
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  SECURITY SETTINGS
// ═══════════════════════════════════════════════════════════════════════════════
function SecuritySettings({ isRTL, onToast }: { isRTL: boolean; onToast: (m: string, t?: "success" | "error") => void }) {
  const { sessions, revokeSession, twoFactor, setTwoFactor } = useUser();

  // Password form state
  const [pw, setPw] = useState({ current: "", newPw: "", confirm: "" });
  const [showPw, setShowPw] = useState({ current: false, newPw: false, confirm: false });
  const [pwSaving, setPwSaving] = useState(false);
  const strength = getStrength(pw.newPw);
  const pwMatch = pw.confirm.length > 0 && pw.newPw === pw.confirm;
  const pwMismatch = pw.confirm.length > 0 && pw.newPw !== pw.confirm;

  const handlePwSave = () => {
    if (!pw.current) { onToast(isRTL ? "أدخل كلمة المرور الحالية" : "Enter your current password", "error"); return; }
    if (pw.newPw.length < 8) { onToast(isRTL ? "يجب ألا تقل كلمة المرور الجديدة عن 8 أحرف" : "New password must be at least 8 characters", "error"); return; }
    if (pw.newPw !== pw.confirm) { onToast(isRTL ? "كلمتا المرور غير متطابقتين" : "Passwords do not match", "error"); return; }
    if (strength.score < 3) { onToast(isRTL ? "كلمة المرور ضعيفة — اختر كلمة أقوى" : "Password is too weak — aim for Fair or stronger", "error"); return; }
    setPwSaving(true);
    setTimeout(() => {
      setPw({ current: "", newPw: "", confirm: "" });
      setPwSaving(false);
      onToast(isRTL ? "تم تغيير كلمة المرور بنجاح" : "Password changed successfully");
    }, 1000);
  };

  // 2FA setup flow
  const [show2FASetup, setShow2FASetup] = useState(false);
  const [twoFACode, setTwoFACode] = useState("");
  const [verifying, setVerifying] = useState(false);

  const handle2FAToggle = () => {
    if (twoFactor.enabled) {
      setTwoFactor({ ...twoFactor, enabled: false });
      onToast(isRTL ? "تم تعطيل المصادقة الثنائية" : "Two-factor authentication disabled");
    } else {
      setShow2FASetup(true);
    }
  };

  const verify2FA = () => {
    if (twoFACode.length !== 6) { onToast(isRTL ? "أدخل الرمز المكون من 6 أرقام" : "Enter the 6-digit code", "error"); return; }
    setVerifying(true);
    setTimeout(() => {
      setTwoFactor({ enabled: true, method: "app" });
      setShow2FASetup(false);
      setTwoFACode("");
      setVerifying(false);
      onToast(isRTL ? "تم تفعيل المصادقة الثنائية بنجاح" : "Two-factor authentication enabled");
    }, 1200);
  };

  const handleRevoke = (id: string, device: string) => {
    revokeSession(id);
    onToast(isRTL ? `تم إنهاء الجلسة: ${device}` : `Session revoked: ${device}`);
  };

  const PwInput = ({ field, label }: { field: "current" | "newPw" | "confirm"; label: string }) => (
    <div>
      <label className="block text-sm font-medium text-gray-700 mb-1.5">{label}</label>
      <div className="relative">
        <input
          type={showPw[field] ? "text" : "password"}
          value={pw[field]}
          onChange={(e) => setPw(p => ({ ...p, [field]: e.target.value }))}
          className={`w-full px-4 py-2.5 ${isRTL ? "pl-10 pr-4" : "pr-10 pl-4"} border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm ${
            field === "confirm" && pw.confirm
              ? pwMatch ? "border-green-400 focus:ring-green-500" : "border-red-400 focus:ring-red-500"
              : "border-gray-300"
          }`}
        />
        <button
          type="button"
          onClick={() => setShowPw(s => ({ ...s, [field]: !s[field] }))}
          className={`absolute ${isRTL ? "left-3" : "right-3"} top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600`}
        >
          {showPw[field] ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
        </button>
      </div>
      {field === "confirm" && pw.confirm && (
        <p className={`text-xs mt-1 flex items-center gap-1 ${pwMatch ? "text-green-600" : "text-red-600"}`}>
          {pwMatch ? (
            <><CheckCircle className="w-3 h-3" /> {isRTL ? "كلمتا المرور متطابقتان" : "Passwords match"}</>
          ) : (
            <><XCircle className="w-3 h-3" /> {isRTL ? "كلمتا المرور غير متطابقتين" : "Passwords don't match"}</>
          )}
        </p>
      )}
    </div>
  );

  return (
    <div className="space-y-6">
      {/* Password */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div className="flex items-center gap-3 mb-5">
          <div className="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center">
            <Key className="w-5 h-5 text-blue-600" />
          </div>
          <div>
            <h2 className="font-bold text-gray-900">{isRTL ? "تغيير كلمة المرور" : "Change Password"}</h2>
            <p className="text-xs text-gray-500">{isRTL ? "استخدم كلمة مرور قوية وفريدة لحماية حسابك" : "Use a strong, unique password"}</p>
          </div>
        </div>

        <div className="space-y-4">
          <PwInput field="current" label={isRTL ? "كلمة المرور الحالية" : "Current Password"} />
          <PwInput field="newPw"   label={isRTL ? "كلمة المرور الجديدة" : "New Password"} />

          {/* Strength meter */}
          {pw.newPw.length > 0 && (
            <div>
              <div className="flex gap-1 mb-1">
                {[1, 2, 3, 4, 5].map((i) => (
                  <div key={i} className={`h-1.5 flex-1 rounded-full transition-colors ${i <= strength.score ? strength.color : "bg-gray-200"}`} />
                ))}
              </div>
              <p className={`text-xs font-medium ${strength.score <= 2 ? "text-red-600" : strength.score === 3 ? "text-yellow-600" : "text-green-600"}`}>
                {isRTL
                  ? (strength.score <= 1 ? "ضعيفة جداً" : strength.score === 2 ? "ضعيفة" : strength.score === 3 ? "متوسطة" : strength.score === 4 ? "قوية" : "قوية جداً")
                  : strength.label}
              </p>
            </div>
          )}

          <PwInput field="confirm" label={isRTL ? "تأكيد كلمة المرور الجديدة" : "Confirm New Password"} />

          <button
            onClick={handlePwSave}
            disabled={pwSaving || !pw.current || !pw.newPw || pwMismatch}
            className="flex items-center gap-2 px-5 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-sm font-medium"
          >
            {pwSaving ? <RefreshCw className="w-4 h-4 animate-spin" /> : <Key className="w-4 h-4" />}
            {pwSaving ? (isRTL ? "جاري التحديث…" : "Updating…") : (isRTL ? "تحديث كلمة المرور" : "Update Password")}
          </button>
        </div>
      </div>

      {/* 2FA */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div className="flex items-center gap-3 mb-5">
          <div className="w-10 h-10 bg-green-50 rounded-xl flex items-center justify-center">
            <Lock className="w-5 h-5 text-green-600" />
          </div>
          <div>
            <h2 className="font-bold text-gray-900">{isRTL ? "المصادقة الثنائية (2FA)" : "Two-Factor Authentication"}</h2>
            <p className="text-xs text-gray-500">{isRTL ? "إضافة طبقة أمان إضافية لحماية الحساب من الاختراق" : "Add an extra layer of protection"}</p>
          </div>
          <div className={`${isRTL ? "mr-auto" : "ml-auto"}`}>
            <span className={`px-3 py-1 rounded-full text-xs font-medium ${twoFactor.enabled ? "bg-green-100 text-green-700" : "bg-gray-100 text-gray-600"}`}>
              {twoFactor.enabled ? (isRTL ? "مفعلة" : "Enabled") : (isRTL ? "معطلة" : "Disabled")}
            </span>
          </div>
        </div>

        <div className="p-4 border border-gray-200 rounded-xl flex items-start justify-between gap-4">
          <div>
            <p className="font-medium text-gray-900 text-sm">{isRTL ? "تطبيق المصادقة (Authenticator)" : "Authenticator App"}</p>
            <p className="text-xs text-gray-500 mt-0.5">{isRTL ? "استخدم تطبيق Google Authenticator أو Authy لتوليد رموز التحقق المؤقتة" : "Use Google Authenticator, Authy, or similar app to generate codes"}</p>
          </div>
          <button
            onClick={handle2FAToggle}
            className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors flex-shrink-0 ${
              twoFactor.enabled
                ? "bg-red-50 text-red-600 hover:bg-red-100 border border-red-200"
                : "bg-green-600 text-white hover:bg-green-700"
            }`}
          >
            {twoFactor.enabled ? <><XCircle className="w-4 h-4" /> {isRTL ? "تعطيل" : "Disable"}</> : <><CheckCircle className="w-4 h-4" /> {isRTL ? "تفعيل" : "Enable"}</>}
          </button>
        </div>

        {/* 2FA Setup flow */}
        {show2FASetup && (
          <div className="mt-4 p-5 bg-blue-50 border border-blue-200 rounded-xl space-y-4">
            <h3 className="font-semibold text-gray-900 text-sm">{isRTL ? "إعداد المصادقة الثنائية" : "Set up 2FA"}</h3>
            <p className="text-xs text-gray-600">{isRTL ? "امسح رمز الاستجابة السريعة (QR) بتطبيق المصادقة، ثم أدخل الرمز المكون من 6 أرقام أدناه:" : "Scan this QR code with your authenticator app, then enter the 6-digit code below."}</p>
            {/* Simulated QR code */}
            <div className="w-32 h-32 bg-white border-2 border-gray-300 rounded-lg flex items-center justify-center mx-auto">
              <div className="grid grid-cols-5 gap-0.5">
                {Array.from({ length: 25 }).map((_, i) => (
                  <div key={i} className={`w-4 h-4 rounded-sm ${Math.random() > 0.5 ? "bg-gray-900" : "bg-white"}`} />
                ))}
              </div>
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1.5">{isRTL ? "رمز التحقق (6 أرقام)" : "Verification Code"}</label>
              <input
                type="text"
                maxLength={6}
                placeholder="000000"
                value={twoFACode}
                onChange={(e) => setTwoFACode(e.target.value.replace(/\D/g, ""))}
                className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm text-center tracking-widest font-mono"
              />
            </div>
            <div className="flex gap-3">
              <button
                onClick={verify2FA}
                disabled={verifying || twoFACode.length !== 6}
                className="flex-1 flex items-center justify-center gap-2 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 text-sm font-medium"
              >
                {verifying ? <RefreshCw className="w-4 h-4 animate-spin" /> : <Check className="w-4 h-4" />}
                {verifying ? (isRTL ? "جاري التحقق…" : "Verifying…") : (isRTL ? "تحقق وتفعيل" : "Verify & Enable")}
              </button>
              <button onClick={() => { setShow2FASetup(false); setTwoFACode(""); }} className="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm">
                {isRTL ? "إلغاء" : "Cancel"}
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Sessions */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div className="flex items-center gap-3 mb-5">
          <div className="w-10 h-10 bg-orange-50 rounded-xl flex items-center justify-center">
            <Wifi className="w-5 h-5 text-orange-600" />
          </div>
          <div>
            <h2 className="font-bold text-gray-900">{isRTL ? "الجلسات النشطة" : "Active Sessions"}</h2>
            <p className="text-xs text-gray-500">
              {sessions.length} {isRTL ? "جلسات تسجيل دخول نشطة" : `active session${sessions.length !== 1 ? "s" : ""}`}
            </p>
          </div>
        </div>

        {sessions.length === 0 ? (
          <p className="text-center py-6 text-sm text-gray-400">{isRTL ? "لا توجد جلسات نشطة" : "No active sessions"}</p>
        ) : (
          <div className="space-y-3">
            {sessions.map((session) => (
              <div key={session.id} className={`flex items-center gap-4 p-4 border rounded-xl transition-colors ${session.isCurrent ? "border-green-200 bg-green-50" : "border-gray-200 hover:bg-gray-50"}`}>
                <div className="w-10 h-10 bg-white rounded-xl border border-gray-200 flex items-center justify-center flex-shrink-0">
                  {session.device.includes("iPhone") || session.device.includes("Mobile")
                    ? <Smartphone className="w-5 h-5 text-gray-500" />
                    : <Monitor className="w-5 h-5 text-gray-500" />}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <p className="font-medium text-gray-900 text-sm">{session.device}</p>
                    {session.isCurrent && (
                      <span className="text-[10px] font-bold text-green-700 bg-green-200 px-2 py-0.5 rounded-full">{isRTL ? "الجلسة الحالية" : "CURRENT"}</span>
                    )}
                  </div>
                  <div className="flex items-center gap-3 text-xs text-gray-500 mt-0.5">
                    <span className="flex items-center gap-1"><Monitor className="w-3 h-3" /> {session.browser}</span>
                    <span className="flex items-center gap-1"><MapPin className="w-3 h-3" /> {session.location}</span>
                    <span className="flex items-center gap-1"><Clock className="w-3 h-3" /> {session.lastActive}</span>
                  </div>
                </div>
                {!session.isCurrent && (
                  <button
                    onClick={() => handleRevoke(session.id, session.device)}
                    className="flex items-center gap-1.5 px-3 py-2 text-red-600 hover:bg-red-50 border border-red-200 rounded-lg transition-colors text-xs font-medium flex-shrink-0"
                  >
                    <LogOut className="w-3.5 h-3.5" /> {isRTL ? "إنهاء الجلسة" : "Revoke"}
                  </button>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  PREFERENCE SETTINGS  — every change fires instantly into context → DOM
// ═══════════════════════════════════════════════════════════════════════════════
function PreferenceSettings({ onToast }: { onToast: (m: string, t?: "success" | "error") => void }) {
  const { preferences, updatePreferences } = useUser();
  const { isRTL, setLanguage, t } = useI18n();

  // Write directly to context — UserProvider useEffect fires → DOM updates NOW
  const set = <K extends keyof UserPreferences>(key: K, val: UserPreferences[K]) => {
    updatePreferences({ [key]: val });
    if (key === "language") {
      setLanguage(val as "en" | "ar");
    }
    const msgs: Record<string, string> = {
      theme:      `Theme → ${String(val)}`,
      language:   val === "ar" ? "Switched to Arabic (RTL applied) / تم تفعيل العربية" : "Switched to English (LTR applied)",
      density:    `Density → ${String(val)}`,
      timezone:   `Timezone updated`,
      dateFormat: `Date format → ${String(val)}`,
      currency:   `Currency → ${String(val)}`,
    };
    onToast(msgs[key] || "Updated");
  };

  const themes: { id: UserPreferences["theme"]; label: string; labelAr: string; icon: React.ComponentType<{ className?: string }>; bg: string; iconColor: string }[] = [
    { id: "light", label: "Light", labelAr: "فاتح", icon: Sun,     bg: "bg-yellow-100",                           iconColor: "text-yellow-500" },
    { id: "dark",  label: "Dark",  labelAr: "داكن", icon: Moon,    bg: "bg-slate-800",                            iconColor: "text-blue-300"   },
    { id: "auto",  label: "Auto",  labelAr: "تلقائي", icon: Monitor, bg: "bg-gradient-to-br from-yellow-100 to-slate-700", iconColor: "text-gray-500" },
  ];

  const densities: { id: UserPreferences["density"]; label: string; labelAr: string; desc: string; descAr: string; bars: number }[] = [
    { id: "compact",     label: "Compact",     labelAr: "مضغوط",     desc: "Smaller font & tighter spacing — show more on screen",  descAr: "خط أصغر ومسافات متقاربة — عرض مزيد من المحتوى على الشاشة",  bars: 5 },
    { id: "comfortable", label: "Comfortable", labelAr: "مريح",      desc: "Default balanced layout (16 px base)",                  descAr: "التخطيط المتوازن الافتراضي (16 بكسل أساسي)",                  bars: 3 },
    { id: "spacious",    label: "Spacious",    labelAr: "متسع",      desc: "Larger font & generous spacing — easier to read",       descAr: "خط أكبر ومسافات رحبة — قراءة أكثر سهولة ووضوحاً",       bars: 2 },
  ];

  return (
    <div className="space-y-6">
      {/* Live badge */}
      <div className="flex items-center gap-2 px-4 py-3 bg-green-50 border border-green-200 rounded-xl text-green-800 text-sm">
        <div className="w-2 h-2 rounded-full bg-green-500 animate-pulse flex-shrink-0" />
        <span>
          {isRTL
            ? "تُطبق جميع التغييرات فوراً — يتم تحديث النظام والتطبيق بالكامل بمجرد النقر."
            : "Every change applies immediately — the whole app updates as you click."}
        </span>
      </div>

      {/* ── Theme ── */}
      <div className="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div className="flex items-center gap-3 mb-5">
          <div className="w-10 h-10 bg-violet-50 dark:bg-violet-900/30 rounded-xl flex items-center justify-center">
            <Palette className="w-5 h-5 text-violet-600" />
          </div>
          <div>
            <h2 className="font-bold text-gray-900 dark:text-gray-100">{isRTL ? "المظهر والشكل" : "Theme"}</h2>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {isRTL ? "المظهر النشط: " : "Active: "}
              <span className="font-semibold text-violet-600 capitalize">
                {preferences.theme === "light" ? (isRTL ? "فاتح" : "Light") : preferences.theme === "dark" ? (isRTL ? "داكن" : "Dark") : (isRTL ? "تلقائي" : "Auto")}
              </span>
            </p>
          </div>
        </div>
        <div className="grid grid-cols-3 gap-3">
          {themes.map((t) => {
            const Icon = t.icon;
            const active = preferences.theme === t.id;
            return (
              <button
                key={t.id}
                onClick={() => set("theme", t.id)}
                className={`relative flex flex-col items-center gap-3 py-5 px-3 border-2 rounded-xl transition-all ${
                  active ? "border-blue-600 bg-blue-50 dark:bg-blue-900/30" : "border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-500"
                }`}
              >
                {active && (
                  <span className={`absolute top-2 ${isRTL ? "left-2" : "right-2"} w-5 h-5 bg-blue-600 rounded-full flex items-center justify-center`}>
                    <Check className="w-3 h-3 text-white" />
                  </span>
                )}
                <div className={`w-12 h-12 rounded-full flex items-center justify-center ${t.bg}`}>
                  <Icon className={`w-6 h-6 ${t.iconColor}`} />
                </div>
                <span className={`text-sm font-semibold ${active ? "text-blue-700 dark:text-blue-300" : "text-gray-700 dark:text-gray-300"}`}>
                  {isRTL ? t.labelAr : t.label}
                </span>
              </button>
            );
          })}
        </div>
      </div>

      {/* ── Language ── */}
      <div className="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div className="flex items-center gap-3 mb-5">
          <div className="w-10 h-10 bg-indigo-50 dark:bg-indigo-900/30 rounded-xl flex items-center justify-center">
            <Globe className="w-5 h-5 text-indigo-600" />
          </div>
          <div>
            <h2 className="font-bold text-gray-900 dark:text-gray-100">{isRTL ? "اللغة واتجاه النصوص" : "Language & Text Direction"}</h2>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {isRTL ? "المفعل حالياً: " : "Active: "}
              <span className="font-semibold text-indigo-600">{preferences.language === "ar" ? "العربية — من اليمين لليسار (RTL)" : "English — Left-to-Right (LTR)"}</span>
            </p>
          </div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          {[
            { id: "en" as const, label: "English",  native: "English",  flag: "🇬🇧", dir: "LTR" },
            { id: "ar" as const, label: "Arabic",   native: "العربية",   flag: "🇸🇦", dir: "RTL" },
          ].map((lang) => {
            const active = preferences.language === lang.id;
            return (
              <button
                key={lang.id}
                onClick={() => set("language", lang.id)}
                className={`flex items-center gap-3 px-4 py-4 border-2 rounded-xl transition-all text-left rtl:text-right ${
                  active ? "border-blue-600 bg-blue-50 dark:bg-blue-900/30" : "border-gray-200 dark:border-gray-700 hover:border-gray-300"
                }`}
              >
                <span className="text-3xl">{lang.flag}</span>
                <div className="flex-1 min-w-0">
                  <p className={`font-semibold text-sm ${active ? "text-blue-700 dark:text-blue-300" : "text-gray-900 dark:text-gray-100"}`}>
                    {lang.native}
                  </p>
                  <span className={`inline-block mt-1 text-[10px] font-bold px-1.5 py-0.5 rounded ${active ? "bg-blue-200 dark:bg-blue-800 text-blue-800 dark:text-blue-200" : "bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400"}`}>
                    {lang.dir}
                  </span>
                </div>
                {active && <Check className="w-5 h-5 text-blue-600 flex-shrink-0" />}
              </button>
            );
          })}
        </div>
        {preferences.language === "ar" && (
          <div className="mt-3 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg text-xs text-blue-700 dark:text-blue-300 flex items-center gap-2">
            <Check className="w-3.5 h-3.5 flex-shrink-0" />
            تخطيط RTL نشط حالياً — تم توجيه كافة شاشات وأدوات النظام من اليمين لليسار.
          </div>
        )}
      </div>

      {/* ── Density ── */}
      <div className="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div className="flex items-center gap-3 mb-5">
          <div className="w-10 h-10 bg-teal-50 dark:bg-teal-900/30 rounded-xl flex items-center justify-center">
            <Monitor className="w-5 h-5 text-teal-600" />
          </div>
          <div>
            <h2 className="font-bold text-gray-900 dark:text-gray-100">{isRTL ? "كثافة العرض" : "Display Density"}</h2>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {isRTL ? "التحكم في حجم الخط والمسافات عبر جميع شاشات التطبيق" : "Controls font size and spacing across the whole app"}
            </p>
          </div>
        </div>
        <div className="space-y-2">
          {densities.map((d) => {
            const active = preferences.density === d.id;
            return (
              <button
                key={d.id}
                onClick={() => set("density", d.id)}
                className={`w-full flex items-center gap-4 px-4 py-4 border-2 rounded-xl transition-all text-left rtl:text-right ${
                  active ? "border-blue-600 bg-blue-50 dark:bg-blue-900/30" : "border-gray-200 dark:border-gray-700 hover:border-gray-300"
                }`}
              >
                <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0 ${active ? "border-blue-600" : "border-gray-400"}`}>
                  {active && <div className="w-2.5 h-2.5 rounded-full bg-blue-600" />}
                </div>
                <div className="flex-1">
                  <div className="flex items-center gap-2">
                    <p className={`text-sm font-semibold ${active ? "text-blue-700 dark:text-blue-300" : "text-gray-900 dark:text-gray-100"}`}>
                      {isRTL ? d.labelAr : d.label}
                    </p>
                    {active && <span className="text-[10px] font-bold bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 px-2 py-0.5 rounded-full">{isRTL ? "مفعل" : "ACTIVE"}</span>}
                  </div>
                  <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{isRTL ? d.descAr : d.desc}</p>
                </div>
                {/* Visual bar preview */}
                <div className="flex flex-col gap-1 flex-shrink-0">
                  {Array.from({ length: d.bars }).map((_, i) => (
                    <div key={i} className={`h-1.5 rounded-full ${active ? "bg-blue-400" : "bg-gray-300 dark:bg-gray-600"}`}
                      style={{ width: `${28 - i * 4}px` }} />
                  ))}
                </div>
              </button>
            );
          })}
        </div>
      </div>

      {/* ── Region ── */}
      <div className="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div className="flex items-center gap-3 mb-5">
          <div className="w-10 h-10 bg-orange-50 dark:bg-orange-900/30 rounded-xl flex items-center justify-center">
            <Globe className="w-5 h-5 text-orange-600" />
          </div>
          <h2 className="font-bold text-gray-900 dark:text-gray-100">{isRTL ? "المنطقة والتنسيقات الإقليمية" : "Region & Formats"}</h2>
        </div>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">{isRTL ? "المنطقة الزمنية" : "Timezone"}</label>
            <select
              value={preferences.timezone}
              onChange={(e) => set("timezone", e.target.value)}
              className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
            >
              <option value="UTC-08:00">{isRTL ? "UTC-08:00 — توقيت المحيط الهادئ (US)" : "UTC-08:00 — Pacific Time (US)"}</option>
              <option value="UTC-05:00">{isRTL ? "UTC-05:00 — التوقيت الشرقي (US)" : "UTC-05:00 — Eastern Time (US)"}</option>
              <option value="UTC+00:00">{isRTL ? "UTC+00:00 — توقيت غرينتش / لندن (GMT)" : "UTC+00:00 — GMT / London"}</option>
              <option value="UTC+01:00">{isRTL ? "UTC+01:00 — وسط أوروبا (CET)" : "UTC+01:00 — Central Europe"}</option>
              <option value="UTC+03:00">{isRTL ? "UTC+03:00 — توقيت مكة والرياض الرسمي (AST)" : "UTC+03:00 — Arabia Standard Time"}</option>
              <option value="UTC+04:00">{isRTL ? "UTC+04:00 — توقيت الإمارات والخليج (GST)" : "UTC+04:00 — Gulf Standard Time (UAE)"}</option>
              <option value="UTC+05:30">{isRTL ? "UTC+05:30 — توقيت الهند القياسي (IST)" : "UTC+05:30 — India (IST)"}</option>
              <option value="UTC+08:00">{isRTL ? "UTC+08:00 — سنغافورة والصين" : "UTC+08:00 — China / Singapore"}</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{isRTL ? "تنسيق التاريخ" : "Date Format"}</label>
            <div className="flex gap-3 flex-wrap">
              {["MM/DD/YYYY", "DD/MM/YYYY", "YYYY-MM-DD"].map((fmt) => (
                <button
                  key={fmt}
                  onClick={() => set("dateFormat", fmt)}
                  className={`px-4 py-2 border-2 rounded-lg text-sm font-mono transition-all ${
                    preferences.dateFormat === fmt
                      ? "border-blue-600 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300"
                      : "border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:border-gray-300"
                  }`}
                >
                  {fmt}
                </button>
              ))}
            </div>
            <p className="text-xs text-gray-500 dark:text-gray-400 mt-2">
              {isRTL ? "معاينة: " : "Preview: "}
              <span className="font-semibold text-gray-800 dark:text-gray-200">{formatDate(new Date(), preferences.dateFormat)}</span>
            </p>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">{isRTL ? "العملة الافتراضية" : "Default Currency"}</label>
            <select
              value={preferences.currency}
              onChange={(e) => set("currency", e.target.value)}
              className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
            >
              <option value="USD">{isRTL ? "USD — دولار أمريكي ($)" : "USD — US Dollar ($)"}</option>
              <option value="EUR">{isRTL ? "EUR — يورو (€)" : "EUR — Euro (€)"}</option>
              <option value="GBP">{isRTL ? "GBP — جنيه إسترليني (£)" : "GBP — British Pound (£)"}</option>
              <option value="SAR">{isRTL ? "SAR — ريال سعودي (ر.س)" : "SAR — Saudi Riyal (ر.س)"}</option>
              <option value="AED">{isRTL ? "AED — درهم إماراتي (د.إ)" : "AED — UAE Dirham (د.إ)"}</option>
              <option value="KWD">{isRTL ? "KWD — دينار كويتي (د.ك)" : "KWD — Kuwaiti Dinar (د.ك)"}</option>
            </select>
            <p className="text-xs text-gray-500 dark:text-gray-400 mt-1.5">
              {isRTL ? "الرمز والعملة المعتمدة في كافة التقارير والحسابات المالية في النظام." : "Symbol shown across all financial figures in the project."}
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}

function formatDate(date: Date, fmt: string): string {
  const d = String(date.getDate()).padStart(2, "0");
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const y = date.getFullYear();
  return fmt.replace("DD", d).replace("MM", m).replace("YYYY", String(y));
}

// ═══════════════════════════════════════════════════════════════════════════════
//  INTEGRATION SETTINGS
// ═══════════════════════════════════════════════════════════════════════════════
function IntegrationSettings({ onToast }: { onToast: (m: string, t?: "success" | "error") => void }) {
  const { integrations, toggleIntegration } = useUser();
  const { isRTL } = useI18n();
  const [connecting, setConnecting] = useState<string | null>(null);

  const integrationDescAr: Record<string, string> = {
    slack: "تواصل فرق العمل والتنبيهات المباشرة",
    google: "البريد الإلكتروني والتقويم والأدوات الإنتاجية",
    salesforce: "إدارة علاقات العملاء (CRM)",
    quickbooks: "المحاسبة المالية وإدارة الرواتب",
    zapier: "أتمتة العمليات والربط بين التطبيقات",
    ms365: "حزمة أوفيس والتخزين السحابي OneDrive",
  };

  const handleToggle = (id: string, name: string, connected: boolean) => {
    setConnecting(id);
    setTimeout(() => {
      toggleIntegration(id);
      setConnecting(null);
      if (isRTL) {
        onToast(connected ? `تم إلغاء ربط ${name}` : `تم ربط ${name} بنجاح`);
      } else {
        onToast(connected ? `${name} disconnected` : `${name} connected successfully`);
      }
    }, 1200);
  };

  const connectedCount = integrations.filter(i => i.connected).length;

  return (
    <div className="space-y-6">
      <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div className="flex items-center gap-3 mb-1">
          <div className="w-10 h-10 bg-teal-50 rounded-xl flex items-center justify-center">
            <Database className="w-5 h-5 text-teal-600" />
          </div>
          <div>
            <h2 className="font-bold text-gray-900">{isRTL ? "التكاملات والأنظمة المتصلة" : "Connected Integrations"}</h2>
            <p className="text-xs text-gray-500">
              {isRTL ? `${connectedCount} من أصل ${integrations.length} متصل` : `${connectedCount} of ${integrations.length} connected`}
            </p>
          </div>
        </div>

        {/* Progress bar */}
        <div className="mt-4 mb-6">
          <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
            <div
              className="h-full bg-gradient-to-r from-teal-500 to-blue-500 rounded-full transition-all duration-500"
              style={{ width: `${(connectedCount / integrations.length) * 100}%` }}
            />
          </div>
        </div>

        <div className="space-y-3">
          {integrations.map((integration) => {
            const isConnecting = connecting === integration.id;
            return (
              <div
                key={integration.id}
                className={`flex items-center justify-between p-4 border rounded-xl transition-all ${integration.connected ? "border-green-200 bg-green-50/40" : "border-gray-200 hover:border-gray-300 hover:bg-gray-50"}`}
              >
                <div className="flex items-center gap-4">
                  <div className="text-3xl w-12 h-12 flex items-center justify-center bg-white border border-gray-200 rounded-xl shadow-sm">
                    {integration.logo}
                  </div>
                  <div>
                    <div className="flex items-center gap-2">
                      <p className="font-semibold text-gray-900 text-sm">{integration.name}</p>
                      {integration.connected && (
                        <span className="flex items-center gap-1 text-[10px] font-bold text-green-700 bg-green-100 px-2 py-0.5 rounded-full">
                          <div className="w-1.5 h-1.5 rounded-full bg-green-500" />
                          {isRTL ? "مباشر" : "LIVE"}
                        </span>
                      )}
                    </div>
                    <p className="text-xs text-gray-500">
                      {isRTL && integrationDescAr[integration.id] ? integrationDescAr[integration.id] : integration.description}
                    </p>
                    {integration.connected && integration.connectedAt && (
                      <p className="text-[11px] text-gray-400 mt-0.5 flex items-center gap-1">
                        <Link2 className="w-3 h-3" />
                        {isRTL ? `متصل منذ ${integration.connectedAt}` : `Connected since ${integration.connectedAt}`}
                      </p>
                    )}
                  </div>
                </div>
                <div className="flex items-center gap-3 flex-shrink-0">
                  <span className={`px-3 py-1 rounded-full text-xs font-medium ${integration.connected ? "bg-green-100 text-green-700" : "bg-gray-100 text-gray-600"}`}>
                    {integration.connected ? (isRTL ? "متصل" : "Connected") : (isRTL ? "غير متصل" : "Not Connected")}
                  </span>
                  <button
                    onClick={() => handleToggle(integration.id, integration.name, integration.connected)}
                    disabled={isConnecting}
                    className={`flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium transition-colors disabled:opacity-60 ${
                      integration.connected
                        ? "border border-red-200 text-red-600 hover:bg-red-50"
                        : "bg-blue-600 text-white hover:bg-blue-700"
                    }`}
                  >
                    {isConnecting
                      ? <RefreshCw className="w-4 h-4 animate-spin" />
                      : integration.connected
                        ? <><Unlink className="w-4 h-4" /> {isRTL ? "إلغاء الربط" : "Disconnect"}</>
                        : <><Link2 className="w-4 h-4" /> {isRTL ? "ربط النظام" : "Connect"}</>
                    }
                  </button>
                </div>
              </div>
            );
          })}
        </div>

        {connectedCount > 0 && (
          <div className="mt-6 p-4 bg-amber-50 border border-amber-200 rounded-xl flex items-start gap-3">
            <AlertTriangle className="w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5" />
            <div>
              <p className="text-sm font-medium text-amber-900">{isRTL ? "إدارة التوصيلات بحذر" : "Manage with care"}</p>
              <p className="text-xs text-amber-700 mt-0.5">
                {isRTL ? "قد يؤدي فصل أي تكامل نشط إلى توقف سير العمل الآلي ومزامنة البيانات في الوقت الفعلي." : "Disconnecting an active integration may interrupt automated workflows and data sync."}
              </p>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
