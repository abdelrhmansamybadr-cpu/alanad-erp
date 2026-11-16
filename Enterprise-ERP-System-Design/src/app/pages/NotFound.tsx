import { useNavigate } from "react-router";
import { Home, ArrowLeft, Search } from "lucide-react";
import { useI18n } from "../context/I18nContext";

export function NotFound() {
  const navigate = useNavigate();
  const { language } = useI18n();
  const isAr = language === "ar";

  const quickLinks = [
    { label: isAr ? "لوحة التحكم" : "Dashboard", path: "/" },
    { label: isAr ? "المخزون" : "Inventory", path: "/inventory" },
    { label: isAr ? "المبيعات" : "Sales", path: "/sales" },
    { label: isAr ? "المشتريات" : "Procurement", path: "/procurement" },
  ];

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 to-gray-100 flex items-center justify-center p-4">
      <div className="max-w-2xl w-full text-center">
        {/* 404 Illustration */}
        <div className="mb-8">
          <div className="text-9xl font-bold text-blue-600 mb-4 font-mono">404</div>
          <div className="text-2xl font-semibold text-gray-900 mb-2">
            {isAr ? "الصفحة غير موجودة" : "Page Not Found"}
          </div>
          <p className="text-gray-600">
            {isAr
              ? "الصفحة التي تبحث عنها غير متوفرة أو قد تم نقلها إلى عنوان آخر."
              : "The page you're looking for doesn't exist or has been moved."}
          </p>
        </div>

        {/* Search Box */}
        <div className="mb-8">
          <div className="max-w-md mx-auto relative">
            <Search className="absolute left-4 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400 rtl:left-auto rtl:right-4" />
            <input
              type="text"
              placeholder={isAr ? "البحث عن الصفحات..." : "Search for pages..."}
              className="w-full pl-12 pr-4 rtl:pl-4 rtl:pr-12 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
            />
          </div>
        </div>

        {/* Action Buttons */}
        <div className="flex flex-wrap gap-4 justify-center mb-8">
          <button
            onClick={() => navigate(-1)}
            className="px-6 py-3 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium flex items-center gap-2"
          >
            <ArrowLeft className="w-5 h-5 rtl:rotate-180" />
            {isAr ? "الرجوع للخلف" : "Go Back"}
          </button>
          <button
            onClick={() => navigate("/")}
            className="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium flex items-center gap-2"
          >
            <Home className="w-5 h-5" />
            {isAr ? "الرئيسية" : "Go to Dashboard"}
          </button>
        </div>

        {/* Quick Links */}
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <h3 className="font-bold text-gray-900 mb-4">{isAr ? "روابط سريعة" : "Quick Links"}</h3>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            {quickLinks.map((link) => (
              <button
                key={link.path}
                onClick={() => navigate(link.path)}
                className="px-4 py-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors font-medium text-sm"
              >
                {link.label}
              </button>
            ))}
          </div>
        </div>

        {/* Support */}
        <div className="mt-8 text-sm text-gray-600">
          {isAr ? "هل تحتاج لمساعدة؟ " : "Need help? "}
          <a href="#" className="text-blue-600 hover:underline font-medium">
            {isAr ? "تواصل مع الدعم الفني" : "Contact Support"}
          </a>
        </div>
      </div>
    </div>
  );
}
