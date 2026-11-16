import { Navigate, Outlet, useLocation } from "react-router";
import { useAuth } from "../context/AuthContext";
import { useI18n } from "../context/I18nContext";

/**
 * Everything behind the sign-in: no session -> login page; a temporary password must be changed first;
 * the page is only shown once the server has confirmed who the user is.
 */
export function RequireAuth() {
  const { me, loading, hasSession } = useAuth();
  const { isRTL } = useI18n();
  const location = useLocation();

  if (!hasSession) return <Navigate to="/auth/login" replace state={{ from: location.pathname }} />;
  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center text-gray-500 text-sm">
        {isRTL ? "جاري التحميل…" : "Loading…"}
      </div>
    );
  }
  if (!me) return <Navigate to="/auth/login" replace />;
  if (me.must_change_password) return <Navigate to="/auth/change-password" replace />;
  return <Outlet />;
}
