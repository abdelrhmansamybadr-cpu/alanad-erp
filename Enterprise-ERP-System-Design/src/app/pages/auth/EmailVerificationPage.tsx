import { Link } from "react-router";
import { Button } from "../../components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "../../components/ui/card";
import { Mail, Check } from "lucide-react";
import { useI18n } from "../../context/I18nContext";

export function EmailVerificationPage() {
  const { isRTL } = useI18n();

  return (
    <Card>
      <CardHeader>
        <div className="flex justify-center mb-4">
          <div className="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center">
            <Mail className="h-8 w-8 text-blue-600" />
          </div>
        </div>
        <CardTitle className="text-center">
          {isRTL ? "تأكيد بريدك الإلكتروني" : "Verify Your Email"}
        </CardTitle>
        <CardDescription className="text-center">
          {isRTL
            ? "لقد أرسلنا رابط التحقق إلى بريدك الإلكتروني admin@acme.com"
            : "We've sent a verification link to admin@acme.com"}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="bg-gray-50 rounded-lg p-4">
          <h4 className="font-medium mb-2">{isRTL ? "الخطوات التالية:" : "Next Steps:"}</h4>
          <ol className="space-y-2 text-sm text-muted-foreground">
            <li className="flex items-start gap-2">
              <Check className="h-4 w-4 text-green-600 mt-0.5 shrink-0" />
              <span>{isRTL ? "افتح صندوق بريدك الوارد وابحث عن رسالة التحقق" : "Check your inbox for the verification email"}</span>
            </li>
            <li className="flex items-start gap-2">
              <Check className="h-4 w-4 text-green-600 mt-0.5 shrink-0" />
              <span>{isRTL ? "انقر فوق رابط التأكيد المرفق بالرسالة" : "Click the verification link"}</span>
            </li>
            <li className="flex items-start gap-2">
              <Check className="h-4 w-4 text-green-600 mt-0.5 shrink-0" />
              <span>{isRTL ? "عد إلى هذه الشاشة لتسجيل الدخول" : "Return to sign in"}</span>
            </li>
          </ol>
        </div>
        <p className="text-sm text-center text-muted-foreground">
          {isRTL ? "لم تستلم الرسالة بعد؟ " : "Didn't receive the email? "}
          <button className="text-blue-600 hover:text-blue-700 font-medium">
            {isRTL ? "إعادة الإرسال" : "Resend"}
          </button>
        </p>
      </CardContent>
      <CardFooter>
        <Link to="/auth/login" className="w-full">
          <Button variant="outline" className="w-full">
            {isRTL ? "العودة إلى تسجيل الدخول" : "Back to Sign In"}
          </Button>
        </Link>
      </CardFooter>
    </Card>
  );
}
