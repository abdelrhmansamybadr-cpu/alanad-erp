import { Download, Package, Server, Cloud, HardDrive, CheckCircle, AlertCircle, Code, Database, FileText } from "lucide-react";
import { useI18n } from "../context/I18nContext";
import { exportToPDF } from "../services/exportService";
import { toast } from "sonner";

export function DownloadCenter() {
  const { isRTL, t } = useI18n();

  const deploymentPackages = [
    {
      id: "cloud",
      name: isRTL ? "النسخة السحابية المدارة (SaaS)" : "Cloud Edition",
      icon: Cloud,
      description: isRTL ? "نشر سحابي مُدار بالكامل على AWS و Azure مع توفر عالي" : "Fully managed SaaS deployment on AWS/Azure",
      version: "v3.2.0",
      size: isRTL ? "مستضاف سحابياً" : "N/A - Hosted",
      status: "active",
      features: isRTL
        ? ["توسع تلقائي للموارد", "اتفاقية مستوى الخدمة 99.9% Uptime", "نسخ احتياطي تلقائي مشفر", "شبكة توزيع محتوى CDN عالمية"]
        : ["Auto-scaling", "99.9% Uptime SLA", "Automatic backups", "Global CDN"],
    },
    {
      id: "docker",
      name: isRTL ? "حاوية دوكر وكوبرنيتس (Docker)" : "Docker Container",
      icon: Package,
      description: isRTL ? "حزمة حاويات جاهزة للنشر الفوري على Docker Compose و Kubernetes" : "Containerized deployment for Docker & Kubernetes",
      version: "v3.2.0",
      size: "2.4 GB",
      status: "stable",
      features: isRTL
        ? ["تثبيت بأمر واحد", "توسع أفقي للميكروسيرفيس", "كفاءة عالية في استهلاك الموارد", "جاهز لأنظمة CI/CD"]
        : ["Easy deployment", "Horizontal scaling", "Resource efficient", "CI/CD ready"],
      downloadFilename: "enterprise-erp-docker-v3.2.0.yml",
      downloadType: "yaml",
    },
    {
      id: "on-premise",
      name: isRTL ? "حزمة النشر المحلي (On-Premise)" : "On-Premise Package",
      icon: Server,
      description: isRTL ? "نشر مؤسسي على الخوادم الخاصة مع دعم البيئات المعزولة Air-Gapped" : "Self-hosted enterprise deployment",
      version: "v3.2.0",
      size: "4.8 GB",
      status: "stable",
      features: isRTL
        ? ["تحكم كامل في البيانات", "دعم الشبكات المعزولة", "تخصيص كامل للتكوينات", "دعم فني هندسي مخصص"]
        : ["Full control", "Air-gapped support", "Custom configurations", "Dedicated support"],
      downloadFilename: "enterprise-erp-onprem-v3.2.0.sh",
      downloadType: "script",
    },
    {
      id: "vm",
      name: isRTL ? "صورة الآلة الافتراضية (VM Image)" : "Virtual Machine Image",
      icon: HardDrive,
      description: isRTL ? "آلة افتراضية مهيأة مسبقاً لمنصات VMware ESXi و Hyper-V و Proxmox" : "Pre-configured VM for VMware/Hyper-V",
      version: "v3.2.0",
      size: "8.5 GB",
      status: "stable",
      features: isRTL
        ? ["جاهزة للتشغيل الفوري", "نظام تشغيل مهيأ ومحمى", "قاعدة بيانات مدمجة", "إعداد في 5 دقائق"]
        : ["Ready to deploy", "Pre-configured", "Database included", "Quick setup"],
      downloadFilename: "enterprise-erp-vm-appliance.ovf",
      downloadType: "ovf",
    },
  ];

  const technicalDocuments = [
    {
      name: isRTL ? "دليل التثبيت والتشغيل المؤسسي" : "Installation Guide",
      type: "PDF",
      size: "8.2 MB",
      version: "v3.2",
      action: "pdf_install",
    },
    {
      name: isRTL ? "توثيق واجهات برمجة التطبيقات (API Reference)" : "API Documentation",
      type: "PDF",
      size: "12.5 MB",
      version: "v3.2",
      action: "pdf_api",
    },
    {
      name: isRTL ? "مخطط قاعدة البيانات وجداول النظام (Database Schema)" : "Database Schema",
      type: "SQL",
      size: "245 KB",
      version: "v3.2",
      action: "sql_schema",
    },
    {
      name: isRTL ? "قوالب التكوين والإعدادات (Configuration Templates)" : "Configuration Templates",
      type: "YAML",
      size: "1.8 MB",
      version: "v3.2",
      action: "config_templates",
    },
  ];

  const systemRequirements = {
    minimum: {
      cpu: isRTL ? "4 أنوية معالجة (2.5 جيجاهرتز)" : "4 cores (2.5 GHz)",
      ram: isRTL ? "8 جيجابايت" : "8 GB",
      storage: isRTL ? "50 جيجابايت SSD" : "50 GB SSD",
      os: "Ubuntu 20.04+ / CentOS 8+ / Windows Server 2019+",
    },
    recommended: {
      cpu: isRTL ? "8 أنوية أو أكثر (3.0 جيجاهرتز)" : "8+ cores (3.0 GHz)",
      ram: isRTL ? "16 جيجابايت فأكثر" : "16 GB+",
      storage: isRTL ? "100 جيجابايت NVMe SSD" : "100 GB+ NVMe SSD",
      os: "Ubuntu 22.04 LTS / RHEL 8+",
    },
  };

  // ── Document Downloader ──────────────────────────────────────────
  const handleDownloadDoc = (doc: typeof technicalDocuments[0]) => {
    if (doc.action === "pdf_install") {
      const headers = isRTL
        ? ["الخطوة", "الوصف التقني", "الأمر البرمجي / المسار", "الحالة الموصى بها"]
        : ["Step", "Technical Description", "Command / Path", "Recommended Status"];
      const rows = [
        ["1", isRTL ? "تحديث حزم نظام التشغيل" : "System Package Updates", "sudo apt update && sudo apt upgrade -y", "Required"],
        ["2", isRTL ? "تثبيت محرك Docker و Docker Compose" : "Install Docker Engine & Compose", "curl -fsSL https://get.docker.com | sh", "Required"],
        ["3", isRTL ? "استنساخ ملفات الإعداد ونشر الحاويات" : "Deploy ERP Docker Containers", "docker compose up -d", "Active"],
        ["4", isRTL ? "تهيئة قاعدة البيانات والمستخدم الإداري" : "Initialize Database & Admin User", "npm run server:setup", "Completed"],
        ["5", isRTL ? "تفعيل شهادة الأمان SSL وموازن الأحمال" : "Configure SSL Certificate & Nginx", "certbot --nginx -d erp.company.com", "Encrypted"],
      ];
      exportToPDF(
        "Enterprise_ERP_Installation_Guide_v3.2.pdf",
        isRTL ? "دليل التثبيت والنشر الفني لنظام ERP" : "Enterprise ERP Installation & Deployment Guide",
        isRTL ? "الإصدار الرسمي v3.2.0 • بيئة الإنتاج" : "Official Release v3.2.0 • Production Environment",
        headers,
        rows,
        { isRTL, date: "2026-02-10", category: "Technical Guide" }
      );
    } else if (doc.action === "pdf_api") {
      const headers = isRTL
        ? ["نقطة النهاية API", "طريقة الطلب", "الوظيفة والوصف", "المصادقة"]
        : ["API Endpoint", "Method", "Description", "Auth Required"];
      const rows = [
        ["/api/auth/login", "POST", isRTL ? "مصادقة المستخدم وتوليد رمز JWT" : "User login & JWT token generation", "Public"],
        ["/api/inventory/products", "GET", isRTL ? "استعلام كتالوج المنتجات والأرصدة" : "Fetch product catalog and inventory", "Bearer Token"],
        ["/api/procurement/purchase-orders", "POST", isRTL ? "إنشاء أمر شراء جديد وتوجيهه للاعتماد" : "Create PO and route for approval", "Bearer Token"],
        ["/api/sales/orders", "GET", isRTL ? "استعلام أوامر البيع والفواتير" : "Fetch sales orders and customer invoices", "Bearer Token"],
        ["/api/admin/tenants", "GET", isRTL ? "إدارة تراخيص المستأجرين" : "Manage multi-tenant licenses", "Admin Role"],
      ];
      exportToPDF(
        "Enterprise_ERP_API_Documentation_v3.2.pdf",
        isRTL ? "توثيق واجهات البرمجة REST API" : "Enterprise ERP REST API Documentation",
        isRTL ? "المواصفات الفنية ونقاط النهاية v3.2.0" : "Technical Endpoints Specification v3.2.0",
        headers,
        rows,
        { isRTL, date: "2026-02-10", category: "API Documentation" }
      );
    } else if (doc.action === "sql_schema") {
      const sqlContent = `-- Enterprise ERP System Database Schema v3.2
-- Generated on 2026-02-10

CREATE TABLE IF NOT EXISTS tenants (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  subdomain TEXT UNIQUE NOT NULL,
  plan TEXT DEFAULT 'enterprise',
  status TEXT DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  tenant_id TEXT REFERENCES tenants(id),
  email TEXT UNIQUE NOT NULL,
  first_name TEXT NOT NULL,
  last_name TEXT NOT NULL,
  role TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
  id TEXT PRIMARY KEY,
  tenant_id TEXT REFERENCES tenants(id),
  sku TEXT UNIQUE NOT NULL,
  name TEXT NOT NULL,
  category TEXT NOT NULL,
  unit_price REAL NOT NULL,
  in_stock INTEGER DEFAULT 0,
  reorder_point INTEGER DEFAULT 10
);
`;
      const blob = new Blob([sqlContent], { type: "text/plain;charset=utf-8" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = "enterprise_erp_schema_v3.2.sql";
      a.click();
      URL.revokeObjectURL(url);
      toast.success(isRTL ? "تم تحميل ملف مخطط قاعدة البيانات SQL بنجاح" : "Database schema downloaded successfully");
    } else {
      // Configuration templates
      const yamlContent = `version: '3.8'

services:
  erp-web:
    image: enterprise-erp/web:v3.2.0
    restart: always
    ports:
      - "80:80"
      - "443:443"
    environment:
      - NODE_ENV=production
      - API_URL=http://erp-api:8080
      - DEFAULT_LANG=${isRTL ? "ar" : "en"}
    depends_on:
      - erp-api

  erp-api:
    image: enterprise-erp/backend:v3.2.0
    restart: always
    ports:
      - "8080:8080"
    environment:
      - DB_TYPE=sqlite
      - DB_PATH=/app/data/erp.sqlite
      - JWT_SECRET=super_secret_jwt_key_2026
    volumes:
      - erp-data:/app/data

volumes:
  erp-data:
`;
      const blob = new Blob([yamlContent], { type: "text/yaml;charset=utf-8" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = "docker-compose.enterprise-erp.yml";
      a.click();
      URL.revokeObjectURL(url);
      toast.success(isRTL ? "تم تحميل ملف التكوين Docker Compose بنجاح" : "Configuration templates downloaded successfully");
    }
  };

  const handleDownloadPackage = (pkg: typeof deploymentPackages[0]) => {
    if (!pkg.downloadFilename) return;
    const content = `# Enterprise ERP Deployment Script - ${pkg.name}\n# Version ${pkg.version}\n# Date: 2026-02-10\necho "Initializing ${pkg.name} deployment..."\n`;
    const blob = new Blob([content], { type: "text/plain;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = pkg.downloadFilename;
    a.click();
    URL.revokeObjectURL(url);
    toast.success(isRTL ? `تم بدء تنزيل ${pkg.name}` : `Downloading ${pkg.name}`);
  };

  return (
    <div className="p-8 space-y-8">
      {/* Header */}
      <div>
        <h1 className="text-3xl font-bold text-gray-900 mb-2">{t("downloads.title")}</h1>
        <p className="text-gray-600">{t("downloads.subtitle")}</p>
      </div>

      {/* System Status Banner */}
      <div className="bg-gradient-to-r from-green-50 to-emerald-100 border border-green-200 rounded-xl p-6 shadow-sm">
        <div className="flex items-start gap-4">
          <CheckCircle className="w-6 h-6 text-green-600 mt-1 flex-shrink-0" />
          <div className="flex-1">
            <h3 className="font-bold text-green-900 mb-1">{t("downloads.stableRelease")}</h3>
            <p className="text-green-800 text-sm">
              {t("downloads.releaseDetails")} •{" "}
              <a href="#" className="underline font-semibold hover:text-green-950">
                {t("downloads.viewChangelog")}
              </a>
            </p>
          </div>
        </div>
      </div>

      {/* Deployment Packages */}
      <div>
        <h2 className="text-2xl font-bold text-gray-900 mb-4">{t("downloads.deploymentPackages")}</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {deploymentPackages.map((pkg) => {
            const Icon = pkg.icon;
            return (
              <div key={pkg.id} className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col justify-between">
                <div>
                  <div className="bg-gradient-to-r from-blue-700 to-blue-800 px-6 py-4 text-white">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-3">
                        <Icon className="w-6 h-6" />
                        <h3 className="font-bold text-lg">{pkg.name}</h3>
                      </div>
                      <span className="text-xs bg-blue-900/60 text-blue-100 px-2.5 py-1 rounded-full font-medium">
                        {pkg.version}
                      </span>
                    </div>
                  </div>

                  <div className="p-6">
                    <p className="text-gray-600 text-sm mb-4 leading-relaxed">{pkg.description}</p>
                    <div className="flex items-center gap-4 text-xs text-gray-500 mb-4 pb-4 border-b">
                      <span>{isRTL ? "الحجم:" : "Size:"} {pkg.size}</span>
                      <span>•</span>
                      <span className="text-green-600 font-medium">{isRTL ? "إصدار مستقر" : "Production Ready"}</span>
                    </div>

                    <ul className="space-y-2 mb-6">
                      {pkg.features.map((feature, idx) => (
                        <li key={idx} className="text-xs text-gray-700 flex items-center gap-2">
                          <CheckCircle className="w-4 h-4 text-blue-600 flex-shrink-0" />
                          <span>{feature}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                </div>

                <div className="px-6 pb-6">
                  {pkg.downloadFilename ? (
                    <button
                      onClick={() => handleDownloadPackage(pkg)}
                      className="w-full px-4 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold flex items-center justify-center gap-2 text-sm cursor-pointer shadow-sm"
                    >
                      <Download className="w-4 h-4" />
                      {t("downloads.downloadPackage")}
                    </button>
                  ) : (
                    <button className="w-full px-4 py-2.5 bg-gray-100 text-gray-500 rounded-lg cursor-not-allowed font-medium text-sm">
                      {isRTL ? "خدمة سحابية مدارة (لا تتطلب تنزيل)" : "Cloud-Only Service"}
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* System Requirements */}
      <div>
        <h2 className="text-2xl font-bold text-gray-900 mb-4">{t("downloads.systemRequirements")}</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div className="flex items-center gap-2 mb-4">
              <AlertCircle className="w-5 h-5 text-amber-500" />
              <h3 className="text-base font-bold text-gray-900">{t("downloads.minimum")}</h3>
            </div>
            <div className="space-y-3 text-sm">
              <div className="flex justify-between py-1 border-b border-gray-100">
                <span className="text-gray-500">CPU</span>
                <span className="font-semibold text-gray-900">{systemRequirements.minimum.cpu}</span>
              </div>
              <div className="flex justify-between py-1 border-b border-gray-100">
                <span className="text-gray-500">RAM</span>
                <span className="font-semibold text-gray-900">{systemRequirements.minimum.ram}</span>
              </div>
              <div className="flex justify-between py-1 border-b border-gray-100">
                <span className="text-gray-500">{isRTL ? "التخزين" : "Storage"}</span>
                <span className="font-semibold text-gray-900">{systemRequirements.minimum.storage}</span>
              </div>
              <div className="flex justify-between py-1">
                <span className="text-gray-500">{isRTL ? "نظام التشغيل" : "Operating System"}</span>
                <span className="font-semibold text-gray-900 text-xs">{systemRequirements.minimum.os}</span>
              </div>
            </div>
          </div>

          <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div className="flex items-center gap-2 mb-4">
              <CheckCircle className="w-5 h-5 text-green-600" />
              <h3 className="text-base font-bold text-gray-900">{t("downloads.recommended")}</h3>
            </div>
            <div className="space-y-3 text-sm">
              <div className="flex justify-between py-1 border-b border-gray-100">
                <span className="text-gray-500">CPU</span>
                <span className="font-semibold text-gray-900">{systemRequirements.recommended.cpu}</span>
              </div>
              <div className="flex justify-between py-1 border-b border-gray-100">
                <span className="text-gray-500">RAM</span>
                <span className="font-semibold text-gray-900">{systemRequirements.recommended.ram}</span>
              </div>
              <div className="flex justify-between py-1 border-b border-gray-100">
                <span className="text-gray-500">{isRTL ? "التخزين" : "Storage"}</span>
                <span className="font-semibold text-gray-900">{systemRequirements.recommended.storage}</span>
              </div>
              <div className="flex justify-between py-1">
                <span className="text-gray-500">{isRTL ? "نظام التشغيل" : "Operating System"}</span>
                <span className="font-semibold text-gray-900 text-xs">{systemRequirements.recommended.os}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Technical Documentation */}
      <div>
        <h2 className="text-2xl font-bold text-gray-900 mb-4">{t("downloads.technicalDocs")}</h2>
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden divide-y divide-gray-200">
          {technicalDocuments.map((doc, idx) => (
            <div key={idx} className="p-4 hover:bg-gray-50 transition-colors flex items-center justify-between flex-wrap gap-4">
              <div className="flex items-center gap-4">
                <div className="w-11 h-11 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center flex-shrink-0">
                  {doc.type === "PDF" ? <FileText className="w-5 h-5" /> : doc.type === "SQL" ? <Database className="w-5 h-5" /> : <Code className="w-5 h-5" />}
                </div>
                <div>
                  <div className="font-semibold text-sm text-gray-900">{doc.name}</div>
                  <div className="text-xs text-gray-500 mt-0.5">
                    {doc.type} • {doc.size} • {isRTL ? "الإصدار" : "Version"} {doc.version}
                  </div>
                </div>
              </div>
              <button
                onClick={() => handleDownloadDoc(doc)}
                className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium flex items-center gap-2 text-xs cursor-pointer shadow-sm"
              >
                <Download className="w-3.5 h-3.5" />
                {isRTL ? `تحميل ${doc.type}` : `Download ${doc.type}`}
              </button>
            </div>
          ))}
        </div>
      </div>

      {/* Additional Resources */}
      <div className="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-xl p-6 shadow-sm">
        <h3 className="font-bold text-blue-950 mb-2">{t("downloads.needHelp")}</h3>
        <p className="text-blue-900 text-sm mb-4 leading-relaxed max-w-2xl">{t("downloads.helpDesc")}</p>
        <div className="flex gap-3 flex-wrap">
          <button className="px-4 py-2 bg-blue-700 text-white rounded-lg hover:bg-blue-800 transition-colors font-semibold text-xs cursor-pointer">
            {t("downloads.contactSupport")}
          </button>
          <button className="px-4 py-2 bg-white text-blue-700 border border-blue-300 rounded-lg hover:bg-blue-50 transition-colors font-semibold text-xs cursor-pointer">
            {t("downloads.viewDocs")}
          </button>
        </div>
      </div>
    </div>
  );
}
