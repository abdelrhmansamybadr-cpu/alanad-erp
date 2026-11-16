import { ReactNode } from "react";
import { Badge } from "../ui/badge";
import { Label } from "../ui/label";

export function ErrorBox({ message }: { message: string }) {
  if (!message) return null;
  return (
    <div className="p-3 rounded-lg bg-red-50 text-red-700 text-sm" role="alert">
      {message}
    </div>
  );
}

/** Label + control. */
export function Field({ id, label, children, className = "" }: { id: string; label: string; children: ReactNode; className?: string }) {
  return (
    <div className={`space-y-1 ${className}`}>
      <Label htmlFor={id}>{label}</Label>
      {children}
    </div>
  );
}

export const selectClass = "w-full border rounded-md px-2 py-2 text-sm bg-background h-9";

/** Days to expiry as a coloured badge. */
export function DaysBadge({ days, isRTL }: { days: number; isRTL: boolean }) {
  if (days < 0) return <Badge variant="destructive">{isRTL ? `منتهي منذ ${-days} يوم` : `Expired ${-days}d ago`}</Badge>;
  if (days <= 3) return <Badge className="bg-amber-500 hover:bg-amber-500">{isRTL ? `${days} يوم` : `${days}d`}</Badge>;
  return <Badge variant="secondary">{isRTL ? `${days} يوم` : `${days}d`}</Badge>;
}

export const fmtKg = (n: number) => (Math.round(n * 1000) / 1000).toLocaleString("en-US", { maximumFractionDigits: 3 });

export interface Item {
  id: number; code: string | null; name: string; name_ar: string | null; item_group: string | null; uom: string;
  shelf_life_days: number | null; storage_temp_min: number | null; storage_temp_max: number | null;
  compat_group: string | null; barcode: string | null; reorder_level: number | null; is_active: number | boolean;
}
export interface Warehouse { id: number; code: string; name: string; name_ar: string | null; type: string; is_active: number | boolean; branch_id?: number }
export interface ColdRoom {
  id: number; warehouse_id: number; code: string; name: string; name_ar: string | null; temp_min: number; temp_max: number;
  humidity_min: number | null; humidity_max: number | null; capacity_kg: number | null; compat_group: string | null;
  status: "ok" | "alarm" | "hold"; is_active: number | boolean;
}
export interface Balance {
  item_id: number; item_name: string; item_name_ar: string | null; batch_id: number; batch_no: string; expiry_date: string;
  arrival_date: string; grade: string | null; warehouse_id: number; cold_room_id: number | null; cold_room_name: string | null;
  rack: string | null; position: string | null; qty: number; expired: boolean; days_left: number;
}

export const nm = (isRTL: boolean, en: string | null | undefined, ar: string | null | undefined) => (isRTL ? ar || en || "" : en || ar || "");
