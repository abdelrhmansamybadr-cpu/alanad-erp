import { useCallback, useEffect, useState } from "react";
import { apiRequest } from "../services/api";
import { useAuth } from "../context/AuthContext";

// Shapes the sidebar / bell already render
export interface QueuePO { id: number; poNumber: string; vendor: string; grandTotal: number }
export interface QueuePR { id: number; prNumber: string; projectName: string; totalEstimatedValue: number; lines: number }

/** Real work waiting for the signed-in user: purchase requests / orders to approve and approved orders to receive. */
export function useWorkQueue() {
  const { me, moduleOpen, can } = useAuth();
  const [prs, setPrs] = useState<QueuePR[]>([]);
  const [approvePO, setApprovePO] = useState<QueuePO[]>([]);
  const [receivePO, setReceivePO] = useState<QueuePO[]>([]);
  const [finance, setFinance] = useState(0);
  const open = !!me && moduleOpen("procurement") && can("purchase-orders", "view") && can("purchase-requests", "view");

  const load = useCallback(async () => {
    if (!me) return;
    if (open) try {
      const [p, o] = await Promise.all([apiRequest<any[]>("/purchase-requests?status=Pending"), apiRequest<any[]>("/purchase-orders")]);
      setPrs(p.filter((r) => r.requested_by !== me!.id).map((r) => ({ id: r.id, prNumber: r.number, projectName: r.requested_by_name ?? "", totalEstimatedValue: 0, lines: Number(r.line_count) })));
      const map = (r: any): QueuePO => ({ id: r.id, poNumber: r.number, vendor: r.supplier_name ?? "", grandTotal: Number(r.total) });
      setApprovePO(o.filter((r) => r.status === "Submitted" && r.created_by !== me!.id).map(map));
      setReceivePO(o.filter((r) => r.status === "Approved").map(map));
    } catch {
      /* a user without purchasing rights simply has an empty queue */
    }
    if (!moduleOpen("accounting") || !can("ap-invoices", "view") || !can("ap-payments", "view")) return;
    try {
      const [i, p] = await Promise.all([apiRequest<any[]>("/ap-invoices"), apiRequest<any[]>("/ap-payments?status=Pending")]);
      setFinance(i.filter((r) => ["Pending", "On_Hold"].includes(r.status) && r.created_by !== me!.id).length + p.filter((r) => r.created_by !== me!.id).length);
    } catch {
      /* no finance rights: nothing waiting */
    }
  }, [open, me, moduleOpen, can]);

  useEffect(() => {
    load();
    const t = setInterval(load, 60000);
    return () => clearInterval(t);
  }, [load]);

  return { pendingFinance: finance, pendingPRs: prs, pendingApprovalPOs: approvePO, pendingReceivePOs: receivePO, reload: load };
}
