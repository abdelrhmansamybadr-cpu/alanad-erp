import { useState } from "react";
import { useLocation, useNavigate } from "react-router";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "../../components/ui/card";
import { AlertCircle } from "lucide-react";
import { Alert, AlertDescription } from "../../components/ui/alert";
import { toast } from "sonner";
import { api } from "../../services/api";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";

// Server messages -> Arabic
const AR_ERRORS: Record<string, string> = {
  "Invalid email or password": "البريد الإلكتروني أو كلمة المرور غير صحيحة",
  "Email and password are required": "أدخل البريد الإلكتروني وكلمة المرور",
  "Cannot reach the server. Check your internet connection.": "تعذّر الاتصال بالخادم. تحقق من الإنترنت.",
};

export function LoginPage() {
  const { isRTL } = useI18n();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();
  const location = useLocation();
  const { reload } = useAuth();

  const translate = (msg: string) => {
    if (!isRTL) return msg;
    if (AR_ERRORS[msg]) return AR_ERRORS[msg];
    const m = msg.match(/Try again in (\d+) minutes/);
    if (msg.startsWith("Too many failed attempts") && m) return `محاولات فاشلة كثيرة. حاول بعد ${m[1]} دقيقة.`;
    return msg;
  };

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setLoading(true);
    try {
      const res = await api.auth.login({ email: email.trim(), password });
      await reload();
      toast.success(
        isRTL ? `أهلاً بك، ${res.user.firstName}` : `Welcome back, ${res.user.firstName}`
      );
      const from = (location.state as { from?: string } | null)?.from;
      navigate(res.me.must_change_password ? "/auth/change-password" : from && from !== "/auth/login" ? from : "/", { replace: true });
    } catch (err: any) {
      setError(translate(err.message || "Invalid email or password"));
    } finally {
      setLoading(false);
    }
  };

  return (
    <Card className="shadow-lg border-gray-200">
      <CardHeader>
        <CardTitle className="text-2xl font-bold">{isRTL ? "تسجيل الدخول" : "Sign In"}</CardTitle>
        <CardDescription>
          {isRTL ? "ادخل إلى نظام العناد لإدارة المصنع" : "Access your Alanad ERP workspace"}
        </CardDescription>
      </CardHeader>
      <form onSubmit={handleLogin} className="contents">
        <CardContent className="space-y-4">
          {error && (
            <Alert variant="destructive">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          <div className="space-y-2">
            <Label htmlFor="email">{isRTL ? "البريد الإلكتروني" : "Email Address"}</Label>
            <Input
              id="email"
              type="email"
              autoComplete="username"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              dir="ltr"
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="password">{isRTL ? "كلمة المرور" : "Password"}</Label>
            <Input
              id="password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              dir="ltr"
            />
          </div>
        </CardContent>

        <CardFooter className="flex flex-col space-y-3">
          <Button type="submit" className="w-full font-semibold" disabled={loading}>
            {loading ? (isRTL ? "جاري التحقق من الحساب..." : "Signing in...") : isRTL ? "دخول" : "Sign In"}
          </Button>
          <p className="text-xs text-center text-muted-foreground">
            {isRTL ? "الحسابات يُنشئها مدير النظام. نسيت كلمة المرور؟ تواصل مع المدير." : "Accounts are created by your administrator. Forgot your password? Ask your administrator."}
          </p>
        </CardFooter>
      </form>
    </Card>
  );
}
