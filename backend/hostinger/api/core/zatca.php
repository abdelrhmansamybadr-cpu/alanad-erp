<?php
// ─── ZATCA e-invoice building blocks (Saudi Arabia) ───
// What this file produces: the TLV QR code (tags 1-5), the invoice counter and the hash chain (ICV / PIH), and a UBL 2.1 invoice document.
// What it does NOT do: sign the invoice, embed the cryptographic stamp (QR tags 6-9), validate against ZATCA's SDK, or clear / report the invoice.
// Those steps need the company's onboarding with ZATCA (Fatoora portal OTP -> compliance and production CSID).

/** TLV QR as base64: [tag byte][length byte][value] for each field */
function zatca_tlv(array $fields): string {
    $out = '';
    foreach ($fields as $tag => $value) {
        $value = (string)$value;
        if (strlen($value) > 255) error_response('A QR field is longer than 255 bytes (' . $tag . ')', 422);
        $out .= chr((int)$tag) . chr(strlen($value)) . $value;
    }
    return base64_encode($out);
}
function zatca_qr(string $seller, string $vat, string $isoTime, float $total, float $vatAmount): string {
    return zatca_tlv([1 => $seller, 2 => $vat, 3 => $isoTime, 4 => number_format($total, 2, '.', ''), 5 => number_format($vatAmount, 2, '.', '')]);
}
/** the previous-invoice hash of the very first invoice: base64 of the hex SHA-256 of "0" (the value ZATCA publishes) */
function zatca_initial_pih(): string { return base64_encode(hash('sha256', '0')); }
function zatca_hash(string $xml): string { return base64_encode(hash('sha256', $xml, true)); }
function zatca_uuid(): string {
    $d = random_bytes(16); $d[6] = chr(ord($d[6]) & 0x0f | 0x40); $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}
function zatca_x(mixed $v): string { return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

/** UBL 2.1 invoice document (unsigned). $inv: number, uuid, issued_at (Y-m-d H:i:s), invoice_type, subtotal, vat_rate, vat_amount, total, icv, pih. */
function zatca_xml(array $inv, array $lines, array $seller, array $buyer): string {
    $d = substr($inv['issued_at'], 0, 10); $t = substr($inv['issued_at'], 11, 8);
    $type = $inv['invoice_type'] === 'standard' ? '0100000' : '0200000';
    $m = fn($v) => number_format((float)$v, 2, '.', '');
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">';
    $x .= '<cbc:ProfileID>reporting:1.0</cbc:ProfileID><cbc:ID>' . zatca_x($inv['number']) . '</cbc:ID><cbc:UUID>' . zatca_x($inv['uuid']) . '</cbc:UUID><cbc:IssueDate>' . $d . '</cbc:IssueDate><cbc:IssueTime>' . $t . '</cbc:IssueTime>';
    $x .= '<cbc:InvoiceTypeCode name="' . $type . '">388</cbc:InvoiceTypeCode><cbc:DocumentCurrencyCode>SAR</cbc:DocumentCurrencyCode><cbc:TaxCurrencyCode>SAR</cbc:TaxCurrencyCode>';
    $x .= '<cac:AdditionalDocumentReference><cbc:ID>ICV</cbc:ID><cbc:UUID>' . (int)$inv['icv'] . '</cbc:UUID></cac:AdditionalDocumentReference>';
    $x .= '<cac:AdditionalDocumentReference><cbc:ID>PIH</cbc:ID><cac:Attachment><cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">' . zatca_x($inv['pih']) . '</cbc:EmbeddedDocumentBinaryObject></cac:Attachment></cac:AdditionalDocumentReference>';
    $party = fn($tag, $p) => '<cac:' . $tag . '><cac:Party><cac:PartyIdentification><cbc:ID schemeID="CRN">' . zatca_x($p['cr'] ?? '') . '</cbc:ID></cac:PartyIdentification><cac:PostalAddress><cbc:StreetName>' . zatca_x($p['address'] ?? '') . '</cbc:StreetName><cac:Country><cbc:IdentificationCode>SA</cbc:IdentificationCode></cac:Country></cac:PostalAddress>'
        . '<cac:PartyTaxScheme><cbc:CompanyID>' . zatca_x($p['vat'] ?? '') . '</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme><cac:PartyLegalEntity><cbc:RegistrationName>' . zatca_x($p['name']) . '</cbc:RegistrationName></cac:PartyLegalEntity></cac:Party></cac:' . $tag . '>';
    $x .= $party('AccountingSupplierParty', $seller) . $party('AccountingCustomerParty', $buyer);
    $x .= '<cac:TaxTotal><cbc:TaxAmount currencyID="SAR">' . $m($inv['vat_amount']) . '</cbc:TaxAmount></cac:TaxTotal>';
    $x .= '<cac:TaxTotal><cbc:TaxAmount currencyID="SAR">' . $m($inv['vat_amount']) . '</cbc:TaxAmount><cac:TaxSubtotal><cbc:TaxableAmount currencyID="SAR">' . $m($inv['subtotal']) . '</cbc:TaxableAmount><cbc:TaxAmount currencyID="SAR">' . $m($inv['vat_amount']) . '</cbc:TaxAmount><cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>' . $m($inv['vat_rate']) . '</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal></cac:TaxTotal>';
    $x .= '<cac:LegalMonetaryTotal><cbc:LineExtensionAmount currencyID="SAR">' . $m($inv['subtotal']) . '</cbc:LineExtensionAmount><cbc:TaxExclusiveAmount currencyID="SAR">' . $m($inv['subtotal']) . '</cbc:TaxExclusiveAmount><cbc:TaxInclusiveAmount currencyID="SAR">' . $m($inv['total']) . '</cbc:TaxInclusiveAmount><cbc:PayableAmount currencyID="SAR">' . $m($inv['total']) . '</cbc:PayableAmount></cac:LegalMonetaryTotal>';
    foreach ($lines as $i => $l) {
        $vat = round($l['amount'] * $inv['vat_rate'] / 100, 2);
        $x .= '<cac:InvoiceLine><cbc:ID>' . ($i + 1) . '</cbc:ID><cbc:InvoicedQuantity unitCode="KGM">' . number_format((float)$l['kg'], 3, '.', '') . '</cbc:InvoicedQuantity><cbc:LineExtensionAmount currencyID="SAR">' . $m($l['amount']) . '</cbc:LineExtensionAmount>'
            . '<cac:TaxTotal><cbc:TaxAmount currencyID="SAR">' . $m($vat) . '</cbc:TaxAmount><cbc:RoundingAmount currencyID="SAR">' . $m($l['amount'] + $vat) . '</cbc:RoundingAmount></cac:TaxTotal>'
            . '<cac:Item><cbc:Name>' . zatca_x($l['item_name'] . ($l['grade'] ? ' (' . $l['grade'] . ')' : '')) . '</cbc:Name><cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>' . $m($inv['vat_rate']) . '</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:ClassifiedTaxCategory></cac:Item>'
            . '<cac:Price><cbc:PriceAmount currencyID="SAR">' . number_format((float)$l['price_per_kg'], 4, '.', '') . '</cbc:PriceAmount></cac:Price></cac:InvoiceLine>';
    }
    return $x . '</Invoice>';
}
