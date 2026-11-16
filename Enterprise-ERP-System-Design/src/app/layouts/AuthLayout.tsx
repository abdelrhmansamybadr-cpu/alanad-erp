import { Outlet } from "react-router";
import { useI18n } from "../context/I18nContext";
import { Globe } from "lucide-react";
import { Button } from "../components/ui/button";

export function AuthLayout() {
  const { direction, isRTL, toggleLanguage } = useI18n();

  return (
    <div dir={direction} className="min-h-screen bg-gradient-to-br from-blue-50 via-white to-blue-50 flex flex-col items-center justify-center p-4 relative">
      {/* Top Language Toggle */}
      <div className="absolute top-4 right-4 rtl:right-auto rtl:left-4">
        <Button
          variant="outline"
          size="sm"
          onClick={toggleLanguage}
          className="gap-2 bg-white/90 backdrop-blur shadow-sm border border-gray-200 text-gray-800 hover:bg-blue-50 hover:text-blue-700 hover:border-blue-300 transition-colors"
        >
          <Globe className="w-4 h-4 text-blue-600" />
          <span className="font-semibold text-gray-800 hover:text-blue-700">{isRTL ? "English" : "العربية"}</span>
        </Button>
      </div>

      <div className="w-full max-w-md">
        <div className="text-center mb-8">
          <img
            src={`${import.meta.env.BASE_URL}brand/logo-square.png`}
            alt="Alanad"
            className="w-20 h-20 rounded-2xl mx-auto mb-4 shadow-md object-cover"
          />
          <h1 className="text-3xl font-bold text-gray-900">Alanad ERP</h1>
          <p className="text-gray-600 mt-2">
            {isRTL ? "العناد — إدارة مصنع الخضار والفاكهة" : "Fresh produce factory management"}
          </p>
        </div>
        <Outlet />
      </div>
    </div>
  );
}

