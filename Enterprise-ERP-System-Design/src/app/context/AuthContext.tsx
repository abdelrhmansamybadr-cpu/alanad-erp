import { createContext, useCallback, useContext, useEffect, useState, ReactNode } from "react";
import { api, tokenStorage, toUiUser, Me } from "../services/api";
import { useUser } from "./UserContext";

/** Maps this UI's module ids to the backend's module keys (the admin can close a module per company). */
export const UI_MODULE_TO_API: Record<string, string> = {
  inventory: "stock",
  production: "manufacturing",
  procurement: "purchase",
  accounting: "finance",
  sales: "sales",
  projects: "projects",
  hr: "hr",
  it: "it",
};

interface AuthContextType {
  me: Me | null;
  loading: boolean;
  /** true when a session token exists (the server still has to confirm it) */
  hasSession: boolean;
  reload: () => Promise<void>;
  /** same wildcard rules as the server: *, *.view, resource.*, resource.action */
  can: (resource: string, action: string) => boolean;
  moduleOpen: (uiModuleId: string) => boolean;
  switchCompany: (id: number) => void;
  /** work in one branch (null = all my branches); reloads the page so every list refreshes */
  switchBranch: (id: number | null) => void;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const { updateProfile } = useUser();
  const [me, setMe] = useState<Me | null>(null);
  const [loading, setLoading] = useState<boolean>(!!tokenStorage.get());

  const load = useCallback(async () => {
    if (!tokenStorage.get()) {
      setMe(null);
      setLoading(false);
      return;
    }
    try {
      const m = await api.auth.me();
      setMe(m);
      const u = toUiUser(m);
      updateProfile({ firstName: u.firstName, lastName: u.lastName, email: u.email, jobTitle: u.jobTitle, department: "", phone: "" });
    } catch {
      setMe(null);
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const can = useCallback(
    (resource: string, action: string) =>
      !!me && me.permissions.some((p) => p === "*" || p === `${resource}.${action}` || p === `*.${action}` || p === `${resource}.*`),
    [me]
  );

  const moduleOpen = useCallback(
    (uiModuleId: string) => {
      const key = UI_MODULE_TO_API[uiModuleId];
      if (!key || !me) return true;
      return me.modules[key] !== false;
    },
    [me]
  );

  const switchCompany = useCallback((id: number) => {
    tokenStorage.setCompany(id);
    window.location.reload();
  }, []);

  const switchBranch = useCallback((id: number | null) => {
    tokenStorage.setBranch(id);
    window.location.reload();
  }, []);

  const logout = useCallback(async () => {
    await api.auth.logout();
    tokenStorage.clearAll();
    setMe(null);
  }, []);

  return (
    <AuthContext.Provider value={{ me, loading, hasSession: !!tokenStorage.get(), reload: load, can, moduleOpen, switchCompany, switchBranch, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextType {
  const c = useContext(AuthContext);
  if (!c) throw new Error("useAuth must be used inside AuthProvider");
  return c;
}
