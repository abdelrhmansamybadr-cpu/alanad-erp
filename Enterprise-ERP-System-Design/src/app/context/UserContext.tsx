import { createContext, useContext, useState, useCallback, useEffect, ReactNode } from "react";

// ─── Types ────────────────────────────────────────────────────────────────────
export interface UserProfile {
  firstName: string;
  lastName: string;
  email: string;
  jobTitle: string;
  department: string;
  phone: string;
  avatar: string | null; // base64 or null
}

export interface NotificationPrefs {
  emailOrders: boolean;
  emailInventory: boolean;
  emailReports: boolean;
  pushApprovals: boolean;
  pushMentions: boolean;
  pushUpdates: boolean;
}

export interface UserPreferences {
  theme: "light" | "dark" | "auto";
  language: "en" | "ar";
  timezone: string;
  dateFormat: string;
  density: "comfortable" | "compact" | "spacious";
  currency: string;
}

export interface Integration {
  id: string;
  name: string;
  description: string;
  logo: string;
  connected: boolean;
  connectedAt?: string;
}

export interface Session {
  id: string;
  device: string;
  browser: string;
  location: string;
  ip: string;
  lastActive: string;
  isCurrent: boolean;
}

export interface TwoFactorState {
  enabled: boolean;
  method: "app" | "sms";
  phone?: string;
}

interface UserContextType {
  profile: UserProfile;
  updateProfile: (p: Partial<UserProfile>) => void;

  notifications: NotificationPrefs;
  updateNotifications: (n: Partial<NotificationPrefs>) => void;

  preferences: UserPreferences;
  updatePreferences: (p: Partial<UserPreferences>) => void;

  integrations: Integration[];
  toggleIntegration: (id: string) => void;

  sessions: Session[];
  revokeSession: (id: string) => void;

  twoFactor: TwoFactorState;
  setTwoFactor: (s: TwoFactorState) => void;
}

// ─── Defaults ─────────────────────────────────────────────────────────────────
const defaultProfile: UserProfile = {
  firstName: "John",
  lastName: "Doe",
  email: "john.doe@company.com",
  jobTitle: "System Administrator",
  department: "IT Operations",
  phone: "+1 (555) 000-0000",
  avatar: null,
};

const defaultNotifications: NotificationPrefs = {
  emailOrders: true,
  emailInventory: true,
  emailReports: false,
  pushApprovals: true,
  pushMentions: true,
  pushUpdates: false,
};

const defaultPreferences: UserPreferences = {
  theme: "light",
  language: (typeof window !== "undefined" && (localStorage.getItem("erp_language") as "en" | "ar")) || "en",
  timezone: "UTC-05:00",
  dateFormat: "MM/DD/YYYY",
  density: "comfortable",
  currency: "USD",
};

const defaultIntegrations: Integration[] = [
  { id: "slack",     name: "Slack",             description: "Team communication & alerts",  logo: "💬", connected: true,  connectedAt: "2026-01-15" },
  { id: "google",    name: "Google Workspace",  description: "Email, calendar & productivity",logo: "📧", connected: true,  connectedAt: "2025-11-03" },
  { id: "salesforce",name: "Salesforce",        description: "CRM integration",               logo: "☁️", connected: false },
  { id: "quickbooks",name: "QuickBooks",        description: "Accounting & payroll",          logo: "📊", connected: false },
  { id: "zapier",    name: "Zapier",            description: "Workflow automation",           logo: "⚡", connected: false },
  { id: "ms365",     name: "Microsoft 365",     description: "Office suite & OneDrive",       logo: "🪟", connected: false },
];

const defaultSessions: Session[] = [
  { id: "s1", device: "Windows 11 PC",   browser: "Chrome 122",  location: "New York, USA",    ip: "192.168.1.1",  lastActive: "Active now",    isCurrent: true  },
  { id: "s2", device: "iPhone 15 Pro",   browser: "Safari 17",   location: "New York, USA",    ip: "192.168.1.45", lastActive: "2 hours ago",   isCurrent: false },
  { id: "s3", device: "MacBook Pro",     browser: "Firefox 123", location: "Boston, USA",      ip: "10.0.0.22",    lastActive: "Yesterday",     isCurrent: false },
];

const defaultTwoFactor: TwoFactorState = { enabled: true, method: "app" };

// ─── Context ──────────────────────────────────────────────────────────────────
const UserContext = createContext<UserContextType | null>(null);

export function UserProvider({ children }: { children: ReactNode }) {
  const [profile, setProfile]             = useState<UserProfile>(defaultProfile);
  const [notifications, setNotifications] = useState<NotificationPrefs>(defaultNotifications);
  const [preferences, setPreferences]     = useState<UserPreferences>(defaultPreferences);
  const [integrations, setIntegrations]   = useState<Integration[]>(defaultIntegrations);
  const [sessions, setSessions]           = useState<Session[]>(defaultSessions);
  const [twoFactor, setTwoFactor]         = useState<TwoFactorState>(defaultTwoFactor);

  // ── Apply preferences to the DOM whenever they change ──────────
  useEffect(() => {
    const root = document.documentElement;

    // ① Theme — toggle the `.dark` class (already defined in theme.css)
    if (preferences.theme === "dark") {
      root.classList.add("dark");
    } else if (preferences.theme === "light") {
      root.classList.remove("dark");
    } else {
      // "auto" — follow OS preference
      const mq = window.matchMedia("(prefers-color-scheme: dark)");
      root.classList.toggle("dark", mq.matches);
      const handler = (e: MediaQueryListEvent) => root.classList.toggle("dark", e.matches);
      mq.addEventListener("change", handler);
      return () => mq.removeEventListener("change", handler);
    }
  }, [preferences.theme]);

  useEffect(() => {
    const root = document.documentElement;
    // ② Language / RTL direction
    root.setAttribute("dir",  preferences.language === "ar" ? "rtl" : "ltr");
    root.setAttribute("lang", preferences.language);
    try {
      localStorage.setItem("erp_language", preferences.language);
    } catch {
      // ignore
    }
  }, [preferences.language]);

  useEffect(() => {
    // ③ Density — drives --font-size via fonts.css selector
    document.documentElement.setAttribute("data-density", preferences.density);
  }, [preferences.density]);

  // ── Helpers ────────────────────────────────────────────────────
  const updateProfile = useCallback((p: Partial<UserProfile>) =>
    setProfile(prev => ({ ...prev, ...p })), []);

  const updateNotifications = useCallback((n: Partial<NotificationPrefs>) =>
    setNotifications(prev => ({ ...prev, ...n })), []);

  const updatePreferences = useCallback((p: Partial<UserPreferences>) =>
    setPreferences(prev => ({ ...prev, ...p })), []);

  const toggleIntegration = useCallback((id: string) =>
    setIntegrations(prev => prev.map(i =>
      i.id === id
        ? { ...i, connected: !i.connected, connectedAt: !i.connected ? new Date().toISOString().split("T")[0] : undefined }
        : i
    )), []);

  const revokeSession = useCallback((id: string) =>
    setSessions(prev => prev.filter(s => s.id !== id)), []);

  return (
    <UserContext.Provider value={{
      profile, updateProfile,
      notifications, updateNotifications,
      preferences, updatePreferences,
      integrations, toggleIntegration,
      sessions, revokeSession,
      twoFactor, setTwoFactor,
    }}>
      {children}
    </UserContext.Provider>
  );
}

export function useUser(): UserContextType {
  const ctx = useContext(UserContext);
  if (!ctx) throw new Error("useUser must be used inside <UserProvider>");
  return ctx;
}