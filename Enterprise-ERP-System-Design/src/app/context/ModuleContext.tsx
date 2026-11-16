import { createContext, useContext, ReactNode } from "react";
import { useAuth, UI_MODULE_TO_API } from "./AuthContext";

export interface Module {
  id: string;
  name: string;
  required: boolean;
  enabled: boolean;
  users: number;
}

const UI_MODULES: { id: string; name: string }[] = [
  { id: "inventory", name: "Inventory Management" },
  { id: "procurement", name: "Procurement" },
  { id: "production", name: "Production" },
  { id: "accounting", name: "Accounting & Finance" },
  { id: "sales", name: "Sales Orders" },
  { id: "projects", name: "Projects" },
  { id: "hr", name: "Human Resources" },
];

interface ModuleContextType {
  modules: Module[];
  isEnabled: (id: string) => boolean;
}

const ModuleContext = createContext<ModuleContextType | null>(null);

/** Module switches come from the server (/me.modules); change them on the Modules admin page. */
export function ModuleProvider({ children }: { children: ReactNode }) {
  const { me } = useAuth();
  const modules: Module[] = UI_MODULES.map((m) => ({
    ...m,
    required: false,
    enabled: me ? me.modules[UI_MODULE_TO_API[m.id]] !== false : true,
    users: 0,
  }));
  const isEnabled = (id: string) => modules.find((m) => m.id === id)?.enabled ?? false;
  return <ModuleContext.Provider value={{ modules, isEnabled }}>{children}</ModuleContext.Provider>;
}

export function useModuleContext() {
  const ctx = useContext(ModuleContext);
  if (!ctx) throw new Error("useModuleContext must be used within ModuleProvider");
  return ctx;
}
