import { useState } from "react";
import { Navigate, useNavigate } from "react-router";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "../../components/ui/card";
import { AlertCircle } from "lucide-react";
import { Alert, AlertDescription } from "../../components/ui/alert";
import { toast } from "sonner";
import { api, tokenStorage } from "../../services/api";
import { useAuth } from "../../context/AuthContext";
import { useI18n } from "../../context/I18nContext";

// Server messages -> Arabic
const AR: Record<string, string> = {
  "Current password is incorrect": "كلمة المرور الحالية غير صحيحة",
  "Password must be at least 10 characters with letters and numbers": "يجب أن تكون كلمة المرور ١٠ أحرف على الأقل بين حروف وأرقام",
  "Choose a different password": "اختر كلمة مرور مختلفة عن الحالية",
};

export function ChangePasswordPage() {
  const { isRTL } = useI18n();
  const { me, reload, hasSession } = useAuth();
  const navigate = useNavigate();
  const [cur, setCur] = useState("");
  const [next, setNext] = useState("");
  const [again, setAgain] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  if (!hasSession || !tokenStorage.get()) return <Navigate to="/auth/login" replace />;

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    if (next !== again) {
      setError(isRTL ? "كلمتا المرور غير متطابقتين" : "The new passwords do not match");
      return;
    }
    setLoading(true);
    try {
      await api.auth.changePassword(cur, next);
      await reload();
      toast.success(isRTL ? "تم تغيير كلمة المرور" : "Password changed");
      navigate("/", { replace: true });
    } catch (err: any) {
      setError(isRTL ? AR[err.message] ?? err.message : err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <Card className="shadow-lg border-gray-200">
      <CardHeader>
        <CardTitle className="text-2xl font-bold">{isRTL ? "اختر كلمة مرور جديدة" : "Choose a new password"}</CardTitle>
        <CardDescription>
          {me?.must_change_password
            ? isRTL
              ? "كلمة المرور الحالية مؤقتة. يجب تغييرها قبل المتابعة."
              : "Your current password is temporary. You must change it before you continue."
            : ""}
        </CardDescription>
      </CardHeader>
      <form onSubmit={submit} className="contents">
        <CardContent className="space-y-4">
          {error && (
            <Alert variant="destructive">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          <div className="space-y-2">
            <Label htmlFor="cur">{isRTL ? "كلمة المرور الحالية" : "Current password"}</Label>
            <Input id="cur" type="password" dir="ltr" autoComplete="current-password" value={cur} onChange={(e) => setCur(e.target.value)} required />
          </div>
          <div className="space-y-2">
            <Label htmlFor="new">{isRTL ? "كلمة المرور الجديدة" : "New password"}</Label>
            <Input id="new" type="password" dir="ltr" autoComplete="new-password" value={next} onChange={(e) => setNext(e.target.value)} required />
            <p className="text-xs text-muted-foreground">{isRTL ? "١٠ أحرف على الأقل بين حروف وأرقام." : "At least 10 characters with letters and numbers."}</p>
          </div>
          <div className="space-y-2">
            <Label htmlFor="again">{isRTL ? "أعد كتابة كلمة المرور الجديدة" : "Repeat the new password"}</Label>
            <Input id="again" type="password" dir="ltr" autoComplete="new-password" value={again} onChange={(e) => setAgain(e.target.value)} required />
          </div>
        </CardContent>
        <CardFooter>
          <Button type="submit" className="w-full font-semibold" disabled={loading}>
            {loading ? (isRTL ? "جارٍ الحفظ…" : "Saving…") : isRTL ? "حفظ" : "Save"}
          </Button>
        </CardFooter>
      </form>
    </Card>
  );
}
