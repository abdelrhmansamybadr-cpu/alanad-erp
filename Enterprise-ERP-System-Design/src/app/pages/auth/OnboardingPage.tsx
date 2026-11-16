import { useState } from "react";
import { useNavigate } from "react-router";
import { Button } from "../../components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "../../components/ui/card";
import { Checkbox } from "../../components/ui/checkbox";
import { Label } from "../../components/ui/label";
import { RadioGroup, RadioGroupItem } from "../../components/ui/radio-group";
import { Progress } from "../../components/ui/progress";
import { Check, Package, ShoppingCart, FileText, Briefcase, Users, Cloud, Server, Layers } from "lucide-react";
import { useI18n } from "../../context/I18nContext";

const ONBOARDING_STEPS_EN = [
  { id: 1, title: "Company Information", description: "Basic details confirmed" },
  { id: 2, title: "Choose Modules", description: "Select ERP features" },
  { id: 3, title: "Deployment Type", description: "Cloud or On-Premise" },
  { id: 4, title: "Setup Complete", description: "Ready to launch" },
];

const ONBOARDING_STEPS_AR = [
  { id: 1, title: "بيانات المؤسسة", description: "تأكيد المعلومات الأساسية" },
  { id: 2, title: "اختيار الوحدات البرمجية", description: "تحديد وحدات وميزات ERP" },
  { id: 3, title: "طريقة النشر والاستضافة", description: "سحابي أو خادم داخلي محلي" },
  { id: 4, title: "اكتمل الإعداد بنجاح", description: "النظام جاهز للإطلاق والعمل" },
];

const MODULES_DEF = [
  { id: "inventory", nameEn: "Inventory Management", nameAr: "إدارة المخزون والمستودعات", icon: Package, required: true },
  { id: "procurement", nameEn: "Procurement", nameAr: "المشتريات والتوريد", icon: ShoppingCart, required: true },
  { id: "sales", nameEn: "Sales Orders", nameAr: "المبيعات والعملاء", icon: FileText, required: true },
  { id: "projects", nameEn: "Projects", nameAr: "إدارة المشاريع والعمليات", icon: Briefcase, required: false },
  { id: "hr", nameEn: "Human Resources", nameAr: "الموارد البشرية وشؤون الموظفين", icon: Users, required: false },
];

export function OnboardingPage() {
  const { isRTL } = useI18n();
  const [currentStep, setCurrentStep] = useState(1);
  const [selectedModules, setSelectedModules] = useState<string[]>(["inventory", "procurement", "sales"]);
  const [deploymentType, setDeploymentType] = useState("cloud");
  const navigate = useNavigate();

  const steps = isRTL ? ONBOARDING_STEPS_AR : ONBOARDING_STEPS_EN;
  const progress = (currentStep / steps.length) * 100;

  const toggleModule = (moduleId: string) => {
    const module = MODULES_DEF.find((m) => m.id === moduleId);
    if (module?.required) return;

    setSelectedModules((prev) =>
      prev.includes(moduleId)
        ? prev.filter((id) => id !== moduleId)
        : [...prev, moduleId]
    );
  };

  const handleNext = () => {
    if (currentStep < steps.length) {
      setCurrentStep(currentStep + 1);
    } else {
      navigate("/auth/login");
    }
  };

  const handleBack = () => {
    if (currentStep > 1) {
      setCurrentStep(currentStep - 1);
    }
  };

  return (
    <div className="w-full max-w-3xl mx-auto">
      <Card>
        <CardHeader>
          <div className="mb-6">
            <div className="flex justify-between mb-2">
              {steps.map((step, index) => (
                <div key={step.id} className="flex items-center">
                  <div className={`flex items-center ${index > 0 ? "ml-2 rtl:ml-0 rtl:mr-2" : ""}`}>
                    <div
                      className={`flex items-center justify-center w-8 h-8 rounded-full font-bold text-sm ${
                        currentStep > step.id
                          ? "bg-green-500 text-white"
                          : currentStep === step.id
                          ? "bg-blue-600 text-white"
                          : "bg-gray-200 text-gray-600"
                      }`}
                    >
                      {currentStep > step.id ? <Check className="h-4 w-4" /> : step.id}
                    </div>
                    {index < steps.length - 1 && (
                      <div className={`w-12 sm:w-16 h-1 mx-2 ${currentStep > step.id ? "bg-green-500" : "bg-gray-200"}`} />
                    )}
                  </div>
                </div>
              ))}
            </div>
            <Progress value={progress} className="h-2" />
          </div>
          <CardTitle>
            {steps[currentStep - 1].title}
          </CardTitle>
          <CardDescription>
            {steps[currentStep - 1].description}
          </CardDescription>
        </CardHeader>

        <CardContent className="min-h-[300px]">
          {currentStep === 1 && (
            <div className="space-y-4">
              <div className="bg-green-50 border border-green-200 rounded-lg p-4">
                <div className="flex items-center gap-3">
                  <Check className="h-5 w-5 text-green-600 shrink-0" />
                  <div>
                    <p className="font-medium text-green-900">
                      {isRTL ? "تم إنشاء وتخصيص بيئة المؤسسة بنجاح" : "Organization Created Successfully"}
                    </p>
                    <p className="text-sm text-green-700">
                      {isRTL ? "معرف المستأجر: " : "Tenant ID: "}
                      <span className="font-mono">TENANT-2024-0147</span>
                    </p>
                  </div>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4 mt-6">
                <div className="p-4 border rounded-lg">
                  <p className="text-sm text-muted-foreground">{isRTL ? "اسم المؤسسة" : "Company Name"}</p>
                  <p className="font-medium mt-1">Acme Corporation</p>
                </div>
                <div className="p-4 border rounded-lg">
                  <p className="text-sm text-muted-foreground">{isRTL ? "البريد الإلكتروني للمسؤول" : "Admin Email"}</p>
                  <p className="font-medium mt-1">admin@acme.com</p>
                </div>
                <div className="p-4 border rounded-lg">
                  <p className="text-sm text-muted-foreground">{isRTL ? "مجال العمل" : "Industry"}</p>
                  <p className="font-medium mt-1">{isRTL ? "الصناعة والإنتاج" : "Manufacturing"}</p>
                </div>
                <div className="p-4 border rounded-lg">
                  <p className="text-sm text-muted-foreground">{isRTL ? "حجم الفريق" : "Employees"}</p>
                  <p className="font-medium mt-1">51-200</p>
                </div>
              </div>
            </div>
          )}

          {currentStep === 2 && (
            <div className="space-y-4">
              <p className="text-sm text-muted-foreground">
                {isRTL
                  ? "حدد الوحدات البرمجية التي ترغب في تفعيلها. الوحدات الأساسية مفعلة إجبارياً لضمان سلامة العمليات."
                  : "Select the modules you want to activate. Core modules are required and cannot be disabled."}
              </p>
              {MODULES_DEF.map((module) => {
                const Icon = module.icon;
                const isSelected = selectedModules.includes(module.id);
                return (
                  <div
                    key={module.id}
                    className={`flex items-center justify-between p-4 border rounded-lg cursor-pointer transition-colors ${
                      isSelected ? "border-blue-500 bg-blue-50" : "border-border hover:border-gray-300"
                    } ${module.required ? "opacity-100" : ""}`}
                    onClick={() => toggleModule(module.id)}
                  >
                    <div className="flex items-center gap-4">
                      <Checkbox
                        checked={isSelected}
                        disabled={module.required}
                        onCheckedChange={() => toggleModule(module.id)}
                      />
                      <Icon className="h-6 w-6 text-blue-600 shrink-0" />
                      <div>
                        <p className="font-medium">{isRTL ? module.nameAr : module.nameEn}</p>
                        <p className="text-sm text-muted-foreground">
                          {module.required
                            ? isRTL
                              ? "وحدة أساسية (مطلوبة)"
                              : "Core Module (Required)"
                            : isRTL
                            ? "وحدة اختيارية"
                            : "Optional Module"}
                        </p>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}

          {currentStep === 3 && (
            <div className="space-y-4">
              <p className="text-sm text-muted-foreground mb-4">
                {isRTL ? "اختر أسلوب نشر وتشغيل نظام ERP المناسب لمؤسستك" : "Choose how you want to deploy your ERP system"}
              </p>
              <RadioGroup value={deploymentType} onValueChange={setDeploymentType}>
                <div
                  className={`flex items-start gap-4 p-4 border rounded-lg cursor-pointer ${
                    deploymentType === "cloud" ? "border-blue-500 bg-blue-50" : "border-border"
                  }`}
                  onClick={() => setDeploymentType("cloud")}
                >
                  <RadioGroupItem value="cloud" id="cloud" />
                  <div className="flex-1">
                    <div className="flex items-center gap-2 mb-2">
                      <Cloud className="h-5 w-5 text-blue-600 shrink-0" />
                      <Label htmlFor="cloud" className="font-medium cursor-pointer">
                        {isRTL ? "سحابي مدار بالكامل (Cloud Hosted)" : "Cloud Hosted"}
                      </Label>
                    </div>
                    <p className="text-sm text-muted-foreground">
                      {isRTL
                        ? "بنية تحتية سحابية تدار آلياً بالكامل. تحديثات ونسخ احتياطي تلقائي ونسبة جاهزية 99.9%. خيار موصى به."
                        : "Fully managed cloud infrastructure. Automatic updates, backups, and 99.9% uptime SLA. Recommended for most organizations."}
                    </p>
                    <div className="mt-2 inline-block px-2 py-1 bg-green-100 text-green-800 text-xs rounded font-semibold">
                      {isRTL ? "موصى به" : "Recommended"}
                    </div>
                  </div>
                </div>

                <div
                  className={`flex items-start gap-4 p-4 border rounded-lg cursor-pointer ${
                    deploymentType === "on-premise" ? "border-blue-500 bg-blue-50" : "border-border"
                  }`}
                  onClick={() => setDeploymentType("on-premise")}
                >
                  <RadioGroupItem value="on-premise" id="on-premise" />
                  <div className="flex-1">
                    <div className="flex items-center gap-2 mb-2">
                      <Server className="h-5 w-5 text-blue-600 shrink-0" />
                      <Label htmlFor="on-premise" className="font-medium cursor-pointer">
                        {isRTL ? "خادم داخلي محلي (On-Premise)" : "On-Premise"}
                      </Label>
                    </div>
                    <p className="text-sm text-muted-foreground">
                      {isRTL
                        ? "نشر على خوادمك الخاصة مع حزمة Docker كاملة. تحكم بنسبة 100% في البيانات والبنية التحتية."
                        : "Deploy on your own infrastructure. Includes Docker deployment package. Full control over data and infrastructure."}
                    </p>
                  </div>
                </div>

                <div
                  className={`flex items-start gap-4 p-4 border rounded-lg cursor-pointer ${
                    deploymentType === "hybrid" ? "border-blue-500 bg-blue-50" : "border-border"
                  }`}
                  onClick={() => setDeploymentType("hybrid")}
                >
                  <RadioGroupItem value="hybrid" id="hybrid" />
                  <div className="flex-1">
                    <div className="flex items-center gap-2 mb-2">
                      <Layers className="h-5 w-5 text-blue-600 shrink-0" />
                      <Label htmlFor="hybrid" className="font-medium cursor-pointer">
                        {isRTL ? "نشر هجين (Hybrid Cloud)" : "Hybrid"}
                      </Label>
                    </div>
                    <p className="text-sm text-muted-foreground">
                      {isRTL
                        ? "الجمع بين الاستضافة السحابية والمحلية لتأمين البيانات الحرجة محلياً وتوسيع الأحمال سحابياً."
                        : "Combine cloud and on-premise deployment. Critical data on-premise, scalable workloads in the cloud."}
                    </p>
                  </div>
                </div>
              </RadioGroup>
            </div>
          )}

          {currentStep === 4 && (
            <div className="text-center space-y-6 py-8">
              <div className="flex justify-center">
                <div className="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center">
                  <Check className="h-10 w-10 text-green-600" />
                </div>
              </div>
              <div>
                <h3 className="text-xl font-semibold mb-2">
                  {isRTL ? "اكتمل إعداد النظام بنجاح!" : "Setup Complete!"}
                </h3>
                <p className="text-muted-foreground">
                  {isRTL ? "نظام إدارة المؤسسات جاهز الآن للاستخدام الفعلي" : "Your ERP system is ready to use"}
                </p>
              </div>
              <div className="bg-gray-50 rounded-lg p-6 text-left rtl:text-right max-w-md mx-auto">
                <h4 className="font-medium mb-3">
                  {isRTL ? "ما تم ضبطه وتهيئته:" : "What's Been Configured:"}
                </h4>
                <ul className="space-y-2 text-sm">
                  <li className="flex items-center gap-2">
                    <Check className="h-4 w-4 text-green-600 shrink-0" />
                    <span>{isRTL ? "تم إنشاء بيئة المستأجر المعزولة" : "Multi-tenant organization created"}</span>
                  </li>
                  <li className="flex items-center gap-2">
                    <Check className="h-4 w-4 text-green-600 shrink-0" />
                    <span>{isRTL ? "تم تكوين حساب المسؤول بصلاحيات كاملة" : "Admin user configured"}</span>
                  </li>
                  <li className="flex items-center gap-2">
                    <Check className="h-4 w-4 text-green-600 shrink-0" />
                    <span>
                      {isRTL ? `تم تفعيل ${selectedModules.length} وحدات برمجية` : `${selectedModules.length} modules activated`}
                    </span>
                  </li>
                  <li className="flex items-center gap-2">
                    <Check className="h-4 w-4 text-green-600 shrink-0" />
                    <span>
                      {isRTL
                        ? `تم ضبط نمط النشر (${deploymentType === "cloud" ? "سحابي" : deploymentType === "on-premise" ? "محلي" : "هجين"})`
                        : `${deploymentType === "cloud" ? "Cloud" : deploymentType === "on-premise" ? "On-Premise" : "Hybrid"} deployment configured`}
                    </span>
                  </li>
                  <li className="flex items-center gap-2">
                    <Check className="h-4 w-4 text-green-600 shrink-0" />
                    <span>{isRTL ? "تمت تهيئة دورات العمل والموافقات الافتراضية" : "Default workflows initialized"}</span>
                  </li>
                </ul>
              </div>
              <div className="text-sm text-muted-foreground">
                {isRTL ? "مفتاح الترخيص: " : "License Key: "}
                <span className="font-mono font-medium">ERPV2-8KJ4-92HF-VN3M-X7PQ</span>
              </div>
            </div>
          )}
        </CardContent>

        <CardFooter className="flex justify-between">
          <Button
            variant="outline"
            onClick={handleBack}
            disabled={currentStep === 1}
          >
            {isRTL ? "السابق" : "Back"}
          </Button>
          <Button onClick={handleNext} className="bg-blue-600 hover:bg-blue-700">
            {currentStep === steps.length
              ? isRTL
                ? "الانتقال لتسجيل الدخول"
                : "Go to Login"
              : isRTL
              ? "متابعة"
              : "Continue"}
          </Button>
        </CardFooter>
      </Card>
    </div>
  );
}
