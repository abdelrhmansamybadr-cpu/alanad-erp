import React, { createContext, useContext, useState, useEffect, useCallback, ReactNode } from "react";
import { translations, Language } from "../i18n/translations";

interface I18nContextType {
  language: Language;
  direction: "ltr" | "rtl";
  isRTL: boolean;
  setLanguage: (lang: Language) => void;
  toggleLanguage: () => void;
  t: (keyPath: string, fallback?: string) => string;
}

const I18nContext = createContext<I18nContextType | null>(null);

const STORAGE_KEY = "erp_language";

export function I18nProvider({ children }: { children: ReactNode }) {
  // Read persisted language from localStorage, default to 'en'
  const [language, setLanguageState] = useState<Language>(() => {
    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      if (stored === "ar" || stored === "en") return stored;
    } catch {
      // ignore
    }
    return "en";
  });

  const isRTL = language === "ar";
  const direction = isRTL ? "rtl" : "ltr";

  // Sync DOM attributes whenever language changes
  useEffect(() => {
    const root = document.documentElement;
    root.setAttribute("dir", direction);
    root.setAttribute("lang", language);
    try {
      localStorage.setItem(STORAGE_KEY, language);
    } catch {
      // ignore
    }
  }, [language, direction]);

  const setLanguage = useCallback((lang: Language) => {
    setLanguageState(lang);
  }, []);

  const toggleLanguage = useCallback(() => {
    setLanguageState((prev) => (prev === "en" ? "ar" : "en"));
  }, []);

  // Translation resolver with nested dot-notation path support (e.g., 'admin.modules.architecture.title')
  const t = useCallback(
    (keyPath: string, fallback?: string): string => {
      const keys = keyPath.split(".");
      let currentVal: any = translations[language];

      for (const k of keys) {
        if (currentVal && typeof currentVal === "object" && k in currentVal) {
          currentVal = currentVal[k];
        } else {
          // Fallback to English if missing in target language
          let enVal: any = translations.en;
          for (const ek of keys) {
            if (enVal && typeof enVal === "object" && ek in enVal) {
              enVal = enVal[ek];
            } else {
              enVal = undefined;
              break;
            }
          }
          if (typeof enVal === "string") return enVal;
          return fallback || keyPath;
        }
      }

      if (typeof currentVal === "string") {
        return currentVal;
      }

      return fallback || keyPath;
    },
    [language]
  );

  return (
    <I18nContext.Provider
      value={{
        language,
        direction,
        isRTL,
        setLanguage,
        toggleLanguage,
        t,
      }}
    >
      {children}
    </I18nContext.Provider>
  );
}

export function useI18n(): I18nContextType {
  const ctx = useContext(I18nContext);
  if (!ctx) {
    throw new Error("useI18n must be used within an <I18nProvider>");
  }
  return ctx;
}
