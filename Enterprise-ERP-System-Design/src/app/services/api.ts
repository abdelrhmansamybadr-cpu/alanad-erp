// Alanad ERP — centralized API service. Talks to the PHP backend (same origin: /api).

export const API_BASE: string = (import.meta.env.VITE_API_BASE as string | undefined) ?? "/api";
const APP_BASE: string = import.meta.env.BASE_URL || "/";

export interface ApiResponse<T = any> {
  data?: T;
  error?: string;
  success?: boolean;
}

/** What GET /me returns: who is signed in, what they may do, which company and which modules are open. */
export interface Me {
  id: number;
  name: string;
  email: string;
  role: string;
  lang: string;
  must_change_password: boolean;
  permissions: string[];
  company_id: number;
  companies: { id: number; name_en: string; name_ar: string | null }[];
  modules: Record<string, boolean>;
  /** branches this user may work in (the admin sees all) */
  branches?: { id: number; code: string; name_en: string; name_ar: string | null; city: string | null; is_main: number }[];
  branch_id?: number | null;
  /** this login is linked to an employee card (needed for "my leave" and "my profile") */
  has_employee?: boolean;
}

export class ApiError extends Error {
  status: number;
  constructor(message: string, status: number) {
    super(message);
    this.status = status;
  }
}

// Token helpers (no default token: an anonymous visitor has no session)
export const tokenStorage = {
  get: () => localStorage.getItem("alanad_token") || "",
  set: (token: string) => localStorage.setItem("alanad_token", token),
  remove: () => localStorage.removeItem("alanad_token"),
  getUser: () => {
    try {
      const u = localStorage.getItem("alanad_user");
      return u ? JSON.parse(u) : null;
    } catch {
      return null;
    }
  },
  setUser: (user: any) => localStorage.setItem("alanad_user", JSON.stringify(user)),
  removeUser: () => localStorage.removeItem("alanad_user"),
  getCompany: (): number | null => Number(localStorage.getItem("alanad_company")) || null,
  setCompany: (id: number) => {
    localStorage.setItem("alanad_company", String(id));
    localStorage.removeItem("alanad_branch");   // branches belong to one company
  },
  /** the branch the user is working in right now (null = all branches he may see) */
  getBranch: (): number | null => Number(localStorage.getItem("alanad_branch")) || null,
  setBranch: (id: number | null) => (id ? localStorage.setItem("alanad_branch", String(id)) : localStorage.removeItem("alanad_branch")),
  clearAll: () => {
    ["alanad_token", "alanad_user", "alanad_company", "alanad_branch"].forEach((k) => localStorage.removeItem(k));
  },
};

/** Split the single "name" of the backend into the first / last name this UI uses. */
export function toUiUser(me: Me) {
  const parts = (me.name || me.email).trim().split(/\s+/);
  return {
    id: me.id,
    firstName: parts[0] || me.email,
    lastName: parts.slice(1).join(" "),
    email: me.email,
    role: me.role,
    jobTitle: me.role,
    department: "",
    phone: "",
    permissions: me.permissions,
    companyId: me.company_id,
  };
}

// Base fetch wrapper
async function request<T = any>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const token = tokenStorage.get();
  const company = tokenStorage.getCompany();
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    ...(options.headers as Record<string, string>),
  };
  if (token) headers["Authorization"] = `Bearer ${token}`;
  if (company) headers["X-Company-Id"] = String(company);
  const branch = tokenStorage.getBranch();
  if (branch) headers["X-Branch-Id"] = String(branch);

  let res: Response;
  try {
    res = await fetch(`${API_BASE}${endpoint}`, { ...options, headers });
  } catch {
    throw new ApiError("Cannot reach the server. Check your internet connection.", 0);
  }

  let data: any = null;
  try {
    data = await res.json();
  } catch {
    // not JSON
  }

  if (res.status === 401 && token && !endpoint.startsWith("/auth/login")) {
    // session expired or revoked: back to the login page
    tokenStorage.clearAll();
    window.location.assign(`${APP_BASE}auth/login`);
    throw new ApiError("Your session has expired. Please sign in again.", 401);
  }
  if (!res.ok) {
    throw new ApiError((data && data.error) || `HTTP Error ${res.status}`, res.status);
  }
  return data as T;
}

/** Direct call to the PHP API for screens that use it without a wrapper. */
export const apiRequest = request;

const notAvailable = (what: string) => () =>
  Promise.reject(new ApiError(`${what} is managed by your administrator.`, 405));

// ─── API Client Methods ───────────────────────────────────────────────
export const api = {
  // Auth
  auth: {
    login: async (credentials: { email: string; password: string }) => {
      const r = await request<{ token: string; user: any }>("/auth/login", {
        method: "POST",
        body: JSON.stringify(credentials),
      });
      tokenStorage.set(r.token);
      const me = await request<Me>("/me");
      tokenStorage.setCompany(me.company_id);
      return { token: r.token, user: toUiUser(me), me };
    },
    me: () => request<Me>("/me"),
    logout: () => request<any>("/auth/logout", { method: "POST", body: "{}" }).catch(() => undefined),
    changePassword: (current_password: string, new_password: string) =>
      request<{ message: string }>("/auth/change-password", {
        method: "POST",
        body: JSON.stringify({ current_password, new_password }),
      }),
    register: notAvailable("Creating accounts") as (data: any) => Promise<{ token: string; user: any }>,
    updateProfile: notAvailable("Profile changes") as (data: any) => Promise<{ success: boolean }>,
    toggle2FA: notAvailable("Two-factor authentication") as (enabled: boolean) => Promise<{ success: boolean; twoFactorEnabled: boolean }>,
    forgotPassword: notAvailable("Password reset") as (email: string) => Promise<{ success: boolean; message: string }>,
  },
};
