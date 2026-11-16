import { Link, useParams } from "react-router";
import { toast } from "sonner";
import { QRCodeSVG } from "qrcode.react";
import { Card } from "../../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/ui/table";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { useI18n } from "../../context/I18nContext";
import { useAuth } from "../../context/AuthContext";
import { useApi, post } from "../../hooks/useApi";
import { SalesNav } from "../../components/sales/SalesNav";
import { ErrorBox, fmtKg } from "../../components/inventory/common";
import { API_BASE, tokenStorage } from "../../services/api";

interface Row { id: number; number: string; customer_name: string; invoice_type: string; issued_at: string; subtotal: number; vat_amount: number; total: number; zatca_status: string; icv: number; shipment_number: string }
interface Inv extends Row {
  customer_vat: string | null; vat_rate: number; qr_base64: string; invoice_hash: string; pih: string; uuid: string;
  lines: { id: number; item_name: string; grade: string | null; kg: number; price_per_kg: number; amount: number }[];
  shipment: { number: string; exit_permit_no: string | null } | null; seller: { name_en: string; legal_name: string | null; vat_number: string | null; cr_number: string | null; address: string | null } | null;
}
const money = (n: number) => Number(n).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function Invoices() {
  const { isRTL } = useI18n();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const rows = useApi<Row[]>("/sales-invoices");
  const align = isRTL ? "text-right" : "text-left";
  return (
    <div className="space-y-6">
      <SalesNav />
      <div><h1 className="text-3xl font-bold">{T("Tax invoices", "الفواتير الضريبية")}</h1><p className="text-muted-foreground mt-1">{T("Made from a shipment, with VAT, the ZATCA QR code and the invoice chain.", "تُصدر من الشحنة، بالضريبة ورمز QR الخاص بهيئة الزكاة وسلسلة الفواتير.")}</p></div>
      {rows.error && <ErrorBox message={rows.error} />}
      <Card className="p-6" data-testid="invoice-list">
        <Table>
          <TableHeader><TableRow>
            <TableHead className={align}>{T("No.", "الرقم")}</TableHead><TableHead className={align}>{T("Client", "العميل")}</TableHead><TableHead className={align}>{T("Type", "النوع")}</TableHead>
            <TableHead className={align}>{T("Subtotal", "قبل الضريبة")}</TableHead><TableHead className={align}>{T("VAT", "الضريبة")}</TableHead><TableHead className={align}>{T("Total", "الإجمالي")}</TableHead><TableHead className={align}>{T("ZATCA", "الهيئة")}</TableHead>
          </TableRow></TableHeader>
          <TableBody>
            {rows.data?.map((r) => (
              <TableRow key={r.id}>
                <TableCell><Link className="font-mono text-primary underline" to={`/sales/invoices/${r.id}`}>{r.number}</Link><div className="text-xs text-muted-foreground">{r.shipment_number}</div></TableCell>
                <TableCell>{r.customer_name}</TableCell><TableCell>{r.invoice_type === "standard" ? T("Standard (B2B)", "ضريبية (شركات)") : T("Simplified", "مبسطة")}</TableCell>
                <TableCell>{money(r.subtotal)}</TableCell><TableCell>{money(r.vat_amount)}</TableCell><TableCell className="font-semibold">{money(r.total)}</TableCell>
                <TableCell><Badge variant="secondary">{r.zatca_status === "Not_submitted" ? T("not sent", "لم تُرسل") : r.zatca_status}</Badge></TableCell>
              </TableRow>
            ))}
            {rows.data && rows.data.length === 0 && <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground">{T("No invoices yet", "لا توجد فواتير")}</TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>
    </div>
  );
}

export function InvoiceDetail() {
  const { id } = useParams();
  const { isRTL } = useI18n();
  const { can } = useAuth();
  const T = (en: string, ar: string) => (isRTL ? ar : en);
  const q = useApi<Inv>(`/sales-invoices/${id}`);
  const i = q.data;
  if (q.error) return <ErrorBox message={q.error} />;
  if (!i) return <p className="text-muted-foreground">…</p>;
  const align = isRTL ? "text-right" : "text-left";
  const xml = async () => {
    const r = await fetch(`${API_BASE}/sales-invoices/${i.id}/xml`, { headers: { Authorization: `Bearer ${tokenStorage.get()}`, ...(tokenStorage.getCompany() ? { "X-Company-Id": String(tokenStorage.getCompany()) } : {}) } });
    const blob = await r.blob(); const a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = `${i.number}.xml`; a.click();
  };
  const submit = async () => { try { await post(`/sales-invoices/${i.id}/zatca-submit`, {}); toast.success("OK"); } catch (e: any) { toast.error(e.message); } };
  return (
    <div className="space-y-6">
      <div className="print:hidden"><SalesNav /></div>
      <Card className="p-8 space-y-6" data-testid="invoice-doc">
        <div className="flex flex-wrap justify-between gap-6">
          <div>
            <div className="text-sm text-muted-foreground">{i.invoice_type === "standard" ? T("Tax invoice", "فاتورة ضريبية") : T("Simplified tax invoice", "فاتورة ضريبية مبسطة")}</div>
            <h1 className="text-3xl font-bold" dir="ltr">{i.number}</h1>
            <div className="text-sm mt-1" dir="ltr">{i.issued_at}</div>
            <div className="mt-3 text-sm"><b>{i.seller?.legal_name || i.seller?.name_en}</b><div>{T("VAT no.", "الرقم الضريبي")}: <span dir="ltr">{i.seller?.vat_number}</span></div><div>{T("CR no.", "السجل التجاري")}: <span dir="ltr">{i.seller?.cr_number ?? "—"}</span></div></div>
          </div>
          <div className="text-center" data-testid="qr"><QRCodeSVG value={i.qr_base64} size={140} /><div className="text-[10px] text-muted-foreground mt-1">ZATCA QR</div></div>
        </div>
        <div className="text-sm"><b>{T("Billed to", "العميل")}:</b> {i.customer_name}{i.customer_vat ? <> · {T("VAT no.", "الرقم الضريبي")} <span dir="ltr">{i.customer_vat}</span></> : null}</div>
        <Table>
          <TableHeader><TableRow><TableHead className={align}>{T("Item", "الصنف")}</TableHead><TableHead className={align}>{T("kg", "كجم")}</TableHead><TableHead className={align}>{T("Price / kg", "السعر/كجم")}</TableHead><TableHead className={align}>{T("Amount", "القيمة")}</TableHead></TableRow></TableHeader>
          <TableBody>{i.lines.map((l) => <TableRow key={l.id}><TableCell>{l.item_name}{l.grade ? ` (${l.grade})` : ""}</TableCell><TableCell>{fmtKg(l.kg)}</TableCell><TableCell>{money(l.price_per_kg)}</TableCell><TableCell>{money(l.amount)}</TableCell></TableRow>)}</TableBody>
        </Table>
        <div className="ms-auto max-w-xs space-y-1 text-sm">
          <div className="flex justify-between"><span>{T("Subtotal", "الإجمالي قبل الضريبة")}</span><b data-testid="subtotal">{money(i.subtotal)}</b></div>
          <div className="flex justify-between"><span>{T("VAT", "ضريبة القيمة المضافة")} {i.vat_rate}%</span><b data-testid="vat">{money(i.vat_amount)}</b></div>
          <div className="flex justify-between text-lg border-t pt-1"><span>{T("Total (SAR)", "الإجمالي (ريال)")}</span><b data-testid="total">{money(i.total)}</b></div>
        </div>
      </Card>
      <Card className="p-4 text-xs space-y-1 print:hidden" data-testid="zatca-box">
        <div><b>{T("E-invoice data", "بيانات الفاتورة الإلكترونية")}</b> · ICV <span dir="ltr">{i.icv}</span></div>
        <div className="break-all" dir="ltr">UUID: {i.uuid}</div><div className="break-all" dir="ltr">PIH: {i.pih}</div><div className="break-all" dir="ltr">Hash: {i.invoice_hash}</div>
        <p className="text-amber-700 mt-2">{T("This invoice has the QR code (tags 1-5), the counter and the hash chain, but it is NOT yet signed or cleared by ZATCA: the company still has to be onboarded on the Fatoora portal.", "هذه الفاتورة فيها رمز QR (الحقول ١-٥) والعدّاد وسلسلة البصمات، لكنها لم تُوقَّع ولم تُجاز من هيئة الزكاة بعد: يلزم تسجيل الشركة في بوابة فاتورة.")}</p>
        <div className="flex gap-2 pt-2"><Button variant="outline" size="sm" data-testid="xml" onClick={xml}>{T("Download XML", "تنزيل XML")}</Button>{can("sales-invoices", "create") && <Button variant="outline" size="sm" data-testid="zatca" onClick={submit}>{T("Send to ZATCA", "إرسال للهيئة")}</Button>}<Button size="sm" onClick={() => window.print()}>{T("Print", "طباعة")}</Button></div>
      </Card>
    </div>
  );
}
