import { useState } from "react";
import { Link } from "react-router";
import { Button } from "../../components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "../../components/ui/card";
import { Switch } from "../../components/ui/switch";
import { Label } from "../../components/ui/label";
import { InputOTP, InputOTPGroup, InputOTPSlot } from "../../components/ui/input-otp";
import { Shield, Smartphone } from "lucide-react";
import { useI18n } from "../../context/I18nContext";

export function TwoFactorPage() {
  const { isRTL } = useI18n();
  const [enabled, setEnabled] = useState(false);
  const [code, setCode] = useState("");

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Shield className="h-5 w-5 text-blue-600" />
          <span>{isRTL ? "المصادقة الثنائية (2FA)" : "Two-Factor Authentication"}</span>
        </CardTitle>
        <CardDescription>
          {isRTL
            ? "إضافة طبقة أمان وحماية إضافية لحسابك المؤسسي"
            : "Add an extra layer of security to your account"}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="flex items-center justify-between p-4 border rounded-lg">
          <div className="flex items-center gap-3">
            <Smartphone className="h-5 w-5 text-blue-600" />
            <div>
              <Label className="font-medium">
                {isRTL ? "تفعيل المصادقة الثنائية" : "Enable 2FA"}
              </Label>
              <p className="text-sm text-muted-foreground">
                {isRTL ? "باستخدام تطبيق المصادقة" : "Use authenticator app"}
              </p>
            </div>
          </div>
          <Switch checked={enabled} onCheckedChange={setEnabled} />
        </div>

        {enabled && (
          <div className="space-y-4 pt-4 border-t">
            <div className="bg-gray-50 p-4 rounded-lg text-center">
              <div className="w-32 h-32 bg-white border-2 border-gray-200 mx-auto mb-3 flex items-center justify-center">
                <p className="text-xs text-muted-foreground">
                  {isRTL ? "رمز QR" : "QR Code"}
                </p>
              </div>
              <p className="text-sm text-muted-foreground">
                {isRTL
                  ? "امسح الرمز بواسطة Google Authenticator أو Authy"
                  : "Scan with Google Authenticator or Authy"}
              </p>
            </div>

            <div className="space-y-2">
              <Label className="block text-center">
                {isRTL ? "أدخل رمز التحقق المكون من 6 أرقام" : "Enter Verification Code"}
              </Label>
              <div className="flex justify-center" dir="ltr">
                <InputOTP maxLength={6} value={code} onChange={setCode}>
                  <InputOTPGroup>
                    <InputOTPSlot index={0} />
                    <InputOTPSlot index={1} />
                    <InputOTPSlot index={2} />
                    <InputOTPSlot index={3} />
                    <InputOTPSlot index={4} />
                    <InputOTPSlot index={5} />
                  </InputOTPGroup>
                </InputOTP>
              </div>
            </div>
          </div>
        )}
      </CardContent>
      <CardFooter className="flex flex-col space-y-4">
        {enabled && (
          <Button className="w-full bg-blue-600 hover:bg-blue-700">
            {isRTL ? "تأكيد وتفعيل الحماية" : "Verify and Enable"}
          </Button>
        )}
        <Link to="/auth/login" className="text-sm text-center text-blue-600 hover:text-blue-700">
          {isRTL ? "← العودة إلى تسجيل الدخول" : "Back to Sign In"}
        </Link>
      </CardFooter>
    </Card>
  );
}
