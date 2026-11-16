import { useState } from "react";
import { Link, useNavigate } from "react-router";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "../../components/ui/card";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "../../components/ui/select";
import { AlertCircle } from "lucide-react";
import { Alert, AlertDescription } from "../../components/ui/alert";
import { toast } from "sonner";
import { api, tokenStorage } from "../../services/api";
import { useI18n } from "../../context/I18nContext";

export function RegisterPage() {
  const { isRTL } = useI18n();
  const [formData, setFormData] = useState({
    companyName: "",
    email: "",
    password: "",
    confirmPassword: "",
    industry: "manufacturing",
    employees: "51-200",
  });
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");

    if (formData.password !== formData.confirmPassword) {
      setError(isRTL ? "كلمتا المرور غير متطابقتين" : "Passwords do not match");
      return;
    }

    setLoading(true);

    try {
      const res = await api.auth.register(formData);
      tokenStorage.set(res.token);
      tokenStorage.setUser(res.user);
      toast.success(
        isRTL
          ? `تم تسجيل مؤسسة ${formData.companyName} بنجاح!`
          : `Organization ${formData.companyName} registered!`
      );
      navigate("/auth/onboarding");
    } catch (err: any) {
      setError(err.message || (isRTL ? "فشلت عملية التسجيل" : "Registration failed"));
    } finally {
      setLoading(false);
    }
  };

  return (
    <Card className="shadow-lg border-gray-200">
      <CardHeader>
        <CardTitle className="text-2xl font-bold">
          {isRTL ? "تسجيل مؤسسة جديدة" : "Register Your Organization"}
        </CardTitle>
        <CardDescription>
          {isRTL
            ? "إنشاء مساحة عمل سحابية خاصة بمؤسستك في نظام ERP متعدد المستأجرين"
            : "Create your multi-tenant enterprise ERP workspace"}
        </CardDescription>
      </CardHeader>
      <form onSubmit={handleSubmit}>
        <CardContent className="space-y-4">
          {error && (
            <Alert variant="destructive">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          <div className="space-y-2">
            <Label htmlFor="companyName">
              {isRTL ? "اسم المؤسسة / الشركة *" : "Company / Organization Name *"}
            </Label>
            <Input
              id="companyName"
              placeholder={isRTL ? "مثال: شركة الرواد للصناعة المتقدمة" : "e.g. Apex Global Industries"}
              value={formData.companyName}
              onChange={(e) => setFormData({ ...formData, companyName: e.target.value })}
              required
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="email">
              {isRTL ? "البريد الإلكتروني للمسؤول *" : "Admin Email *"}
            </Label>
            <Input
              id="email"
              type="email"
              placeholder="admin@company.com"
              value={formData.email}
              onChange={(e) => setFormData({ ...formData, email: e.target.value })}
              required
              className="text-left"
              dir="ltr"
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="industry">{isRTL ? "مجال العمل" : "Industry"}</Label>
              <Select
                value={formData.industry}
                onValueChange={(value) => setFormData({ ...formData, industry: value })}
              >
                <SelectTrigger id="industry">
                  <SelectValue placeholder={isRTL ? "اختر" : "Select"} />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="manufacturing">{isRTL ? "الصناعة والإنتاج" : "Manufacturing"}</SelectItem>
                  <SelectItem value="retail">{isRTL ? "تجارة التجزئة" : "Retail"}</SelectItem>
                  <SelectItem value="wholesale">{isRTL ? "تجارة الجملة" : "Wholesale"}</SelectItem>
                  <SelectItem value="services">{isRTL ? "الخدمات المهنية" : "Services"}</SelectItem>
                  <SelectItem value="technology">{isRTL ? "التكنولوجيا والبرمجيات" : "Technology"}</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="employees">{isRTL ? "عدد الموظفين" : "Team Size"}</Label>
              <Select
                value={formData.employees}
                onValueChange={(value) => setFormData({ ...formData, employees: value })}
              >
                <SelectTrigger id="employees">
                  <SelectValue placeholder={isRTL ? "اختر" : "Select"} />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="1-10">1-10</SelectItem>
                  <SelectItem value="11-50">11-50</SelectItem>
                  <SelectItem value="51-200">51-200</SelectItem>
                  <SelectItem value="201-500">201-500</SelectItem>
                  <SelectItem value="500+">500+</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="password">{isRTL ? "كلمة المرور *" : "Password *"}</Label>
            <Input
              id="password"
              type="password"
              placeholder="••••••••"
              value={formData.password}
              onChange={(e) => setFormData({ ...formData, password: e.target.value })}
              required
              className="text-left"
              dir="ltr"
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="confirmPassword">
              {isRTL ? "تأكيد كلمة المرور *" : "Confirm Password *"}
            </Label>
            <Input
              id="confirmPassword"
              type="password"
              placeholder="••••••••"
              value={formData.confirmPassword}
              onChange={(e) => setFormData({ ...formData, confirmPassword: e.target.value })}
              required
              className="text-left"
              dir="ltr"
            />
          </div>

          <p className="text-xs text-muted-foreground">
            {isRTL
              ? "عند التسجيل، يتم تلقائياً تهيئة مساحة بيانات معزولة بالكامل وقواعد حماية مخصصة لمؤسستك."
              : "By registering, an isolated tenant schema and security partition are automatically provisioned."}
          </p>
        </CardContent>

        <CardFooter className="flex flex-col space-y-4">
          <Button type="submit" className="w-full bg-blue-600 hover:bg-blue-700 font-semibold" disabled={loading}>
            {loading
              ? isRTL
                ? "جاري تهيئة مساحة المؤسسة..."
                : "Provisioning Tenant..."
              : isRTL
              ? "تسجيل المؤسسة"
              : "Register Organization"}
          </Button>

          <div className="text-center text-sm">
            {isRTL ? "لديك حساب بالفعل؟ " : "Already have an account? "}
            <Link to="/auth/login" className="text-blue-600 hover:text-blue-700 font-semibold">
              {isRTL ? "تسجيل الدخول" : "Sign In"}
            </Link>
          </div>
        </CardFooter>
      </form>
    </Card>
  );
}
