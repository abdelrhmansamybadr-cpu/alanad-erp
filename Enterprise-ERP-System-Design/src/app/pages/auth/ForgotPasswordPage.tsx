import { useState } from "react";
import { Link } from "react-router";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "../../components/ui/card";
import { Alert, AlertDescription } from "../../components/ui/alert";
import { Check } from "lucide-react";
import { useI18n } from "../../context/I18nContext";

export function ForgotPasswordPage() {
  const { isRTL } = useI18n();
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSent(true);
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>{isRTL ? "إعادة تعيين كلمة المرور" : "Reset Password"}</CardTitle>
        <CardDescription>
          {isRTL
            ? "أدخل عنوان بريدك الإلكتروني المسجل لاستلام رابط إعادة التعيين"
            : "Enter your email to receive reset instructions"}
        </CardDescription>
      </CardHeader>
      <form onSubmit={handleSubmit}>
        <CardContent className="space-y-4">
          {sent ? (
            <Alert className="border-green-200 bg-green-50">
              <Check className="h-4 w-4 text-green-600" />
              <AlertDescription className="text-green-800">
                {isRTL
                  ? `تم إرسال رابط إعادة تعيين كلمة المرور إلى ${email}. يرجى مراجعة بريدك الوارد.`
                  : `Password reset link sent to ${email}. Please check your inbox.`}
              </AlertDescription>
            </Alert>
          ) : (
            <div className="space-y-2">
              <Label htmlFor="email">{isRTL ? "البريد الإلكتروني" : "Email Address"}</Label>
              <Input
                id="email"
                type="email"
                placeholder="admin@company.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                className="text-left"
                dir="ltr"
              />
            </div>
          )}
        </CardContent>
        <CardFooter className="flex flex-col space-y-4">
          {!sent && (
            <Button type="submit" className="w-full bg-blue-600 hover:bg-blue-700">
              {isRTL ? "إرسال رابط إعادة التعيين" : "Send Reset Link"}
            </Button>
          )}
          <Link to="/auth/login" className="text-sm text-center text-blue-600 hover:text-blue-700">
            {isRTL ? "← العودة إلى تسجيل الدخول" : "Back to Sign In"}
          </Link>
        </CardFooter>
      </form>
    </Card>
  );
}
