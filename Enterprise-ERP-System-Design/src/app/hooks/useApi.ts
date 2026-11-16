import { useCallback, useEffect, useState } from "react";
import { apiRequest } from "../services/api";

/** Loads one GET endpoint. Pass null to skip. `reload()` fetches again. */
export function useApi<T>(path: string | null) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(!!path);

  const load = useCallback(() => {
    if (!path) return Promise.resolve();
    setLoading(true);
    return apiRequest<T>(path)
      .then((d) => {
        setData(d);
        setError("");
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [path]);

  useEffect(() => {
    load();
  }, [load]);

  return { data, error, loading, reload: load };
}

export const post = <T = any>(path: string, body: object) =>
  apiRequest<T>(path, { method: "POST", body: JSON.stringify(body) });
export const put = <T = any>(path: string, body: object) =>
  apiRequest<T>(path, { method: "PUT", body: JSON.stringify(body) });
