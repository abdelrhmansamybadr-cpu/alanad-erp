import * as XLSX from "xlsx";
import { jsPDF } from "jspdf";
import { toast } from "sonner";

export interface ExportDataOptions {
  title?: string;
  subtitle?: string;
  companyName?: string;
  category?: string;
  date?: string;
  isRTL?: boolean;
  notes?: string;
}

/**
 * Downloads a Blob object as a file in the browser
 */
function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => {
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }, 100);
}

/**
 * Generates and downloads a genuine Microsoft Excel (.xlsx) spreadsheet
 * with complete UTF-8 Arabic support, auto column widths, and RTL worksheet view.
 *
 * Supports two signatures:
 * 1) exportToExcel(filename, sheetName, headers, rows, options)
 * 2) exportToExcel(dataObjects, filename, sheetName, isRTL)
 */
export function exportToExcel(
  arg1: any,
  arg2?: any,
  arg3?: any,
  arg4?: any,
  arg5?: any
) {
  try {
    let filename = "export.xlsx";
    let sheetName = "Sheet1";
    let headers: string[] = [];
    let rows: (string | number | boolean | null | undefined)[][] = [];
    let isRTL = false;
    let title: string | undefined;
    let subtitle: string | undefined;
    let dateStr: string | undefined;

    if (Array.isArray(arg1)) {
      // Signature 2: exportToExcel(dataObjects, filename, sheetName, isRTL)
      const dataObjects = arg1 as Record<string, any>[];
      filename = typeof arg2 === "string" ? arg2 : "export";
      sheetName = typeof arg3 === "string" ? arg3 : "Sheet1";
      isRTL = typeof arg4 === "boolean" ? arg4 : (typeof arg4 === "object" ? Boolean(arg4?.isRTL) : false);

      if (dataObjects.length > 0) {
        headers = Object.keys(dataObjects[0]);
        rows = dataObjects.map((item) => headers.map((h) => item[h]));
      }
    } else {
      // Signature 1: exportToExcel(filename, sheetName, headers, rows, options)
      filename = String(arg1 || "export");
      sheetName = String(arg2 || "Sheet1");
      headers = Array.isArray(arg3) ? arg3 : [];
      rows = Array.isArray(arg4) ? arg4 : [];
      const opts: ExportDataOptions = (typeof arg5 === "object" && arg5 !== null) ? arg5 : {};
      isRTL = opts.isRTL ?? false;
      title = opts.title;
      subtitle = opts.subtitle;
      dateStr = opts.date;
    }

    const wb = XLSX.utils.book_new();

    // Prepare table data
    const tableData: (string | number | boolean | null | undefined)[][] = [];

    // Optional title header block
    if (title) {
      tableData.push([title]);
      if (subtitle) {
        tableData.push([subtitle]);
      }
      if (dateStr) {
        tableData.push([isRTL ? `التاريخ: ${dateStr}` : `Date: ${dateStr}`]);
      }
      tableData.push([]); // blank separator row
    }

    // Add main table headers and data rows
    tableData.push(headers);
    rows.forEach((r) => tableData.push(r));

    const ws = XLSX.utils.aoa_to_sheet(tableData);

    // Set RTL direction on the worksheet if in Arabic mode
    // In Microsoft Excel, this renders columns from right to left (A on the right)
    if (isRTL) {
      (ws as any)["!views"] = [{ rightToLeft: true }];
    }

    // Auto-calculate column widths
    const colWidths = headers.map((colName, idx) => {
      let maxLen = String(colName || "").length;
      rows.forEach((row) => {
        const val = row[idx];
        if (val !== undefined && val !== null) {
          const strLen = String(val).length;
          if (strLen > maxLen) maxLen = strLen;
        }
      });
      return { wch: Math.min(Math.max(maxLen + 4, 12), 50) };
    });
    ws["!cols"] = colWidths;

    // Append worksheet to workbook
    const safeSheetName = sheetName.replace(/[:\\/?*\[\]]/g, "_").slice(0, 31) || "Sheet1";
    XLSX.utils.book_append_sheet(wb, ws, safeSheetName);

    // Generate binary XLSX buffer
    const wbout = XLSX.write(wb, {
      bookType: "xlsx",
      type: "array",
    });

    const finalFilename = filename.toLowerCase().endsWith(".xlsx")
      ? filename
      : `${filename}.xlsx`;

    const blob = new Blob([wbout], {
      type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;charset=UTF-8",
    });

    downloadBlob(blob, finalFilename);
    toast.success(isRTL ? "تم تحميل ملف Excel بنجاح" : "Excel file downloaded successfully");
    return true;
  } catch (error) {
    console.error("Failed to generate Excel file:", error);
    toast.error("Failed to export Excel file");
    return false;
  }
}

/**
 * Generates and downloads a genuine, standards-compliant PDF file (.pdf)
 * that opens cleanly in all PDF readers (Adobe Acrobat, Chrome, Edge, Safari).
 *
 * Supports two signatures:
 * 1) exportToPDF(filename, title, subtitle, headers, rows, options)
 * 2) exportToPDF(title, columns, data, filename, subtitle, isRTL)
 */
export function exportToPDF(
  arg1: any,
  arg2?: any,
  arg3?: any,
  arg4?: any,
  arg5?: any,
  arg6?: any
) {
  try {
    let filename = "document.pdf";
    let title = "Report";
    let subtitle = "";
    let headers: string[] = [];
    let rows: (string | number | boolean | null | undefined)[][] = [];
    let isRTL = false;
    let dateStr = new Date().toISOString().split("T")[0];

    if (Array.isArray(arg2)) {
      // Signature 2: exportToPDF(title, columns, data, filename, subtitle, isRTL)
      title = String(arg1 || "Report");
      const columns = arg2 as Array<{ header?: string; dataKey?: string } | string>;
      const data = (Array.isArray(arg3) ? arg3 : []) as Record<string, any>[];
      filename = typeof arg4 === "string" ? arg4 : "report";
      subtitle = typeof arg5 === "string" ? arg5 : "";
      isRTL = typeof arg6 === "boolean" ? arg6 : false;

      headers = columns.map((col) => (typeof col === "string" ? col : (col.header || "")));
      rows = data.map((item) =>
        columns.map((col) => {
          if (typeof col === "string") return item[col];
          const key = col.dataKey || col.header || "";
          return item[key];
        })
      );
    } else {
      // Signature 1: exportToPDF(filename, title, subtitle, headers, rows, options)
      filename = String(arg1 || "document");
      title = String(arg2 || "Report");
      subtitle = String(arg3 || "");
      headers = Array.isArray(arg4) ? arg4 : [];
      rows = Array.isArray(arg5) ? arg5 : [];
      const opts: ExportDataOptions = (typeof arg6 === "object" && arg6 !== null) ? arg6 : {};
      isRTL = opts.isRTL ?? false;
      if (opts.date) dateStr = opts.date;
    }

    const isLandscape = headers.length > 5;
    const doc = new jsPDF({
      orientation: isLandscape ? "landscape" : "portrait",
      unit: "pt",
      format: "a4",
    });

    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    const margin = 36;
    let currentY = margin;

    // Header banner
    doc.setFillColor(30, 58, 138); // ERP Primary Blue (#1e3a8a)
    doc.rect(0, 0, pageWidth, 56, "F");

    doc.setTextColor(255, 255, 255);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(16);
    doc.text("ENTERPRISE ERP SYSTEM", margin, 34);

    doc.setFont("helvetica", "normal");
    doc.setFontSize(10);
    doc.text(dateStr, pageWidth - margin, 34, { align: "right" });

    currentY = 80;

    // Document Title & Subtitle
    doc.setTextColor(17, 24, 39);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(15);
    doc.text(title, margin, currentY);

    if (subtitle) {
      currentY += 16;
      doc.setFont("helvetica", "normal");
      doc.setFontSize(10);
      doc.setTextColor(100, 116, 139);
      doc.text(subtitle, margin, currentY);
    }

    currentY += 24;

    // Draw Table
    const availableWidth = pageWidth - margin * 2;
    const colCount = Math.max(headers.length, 1);
    const colWidth = availableWidth / colCount;
    const rowHeight = 22;

    // Table Header Background
    doc.setFillColor(241, 245, 249); // #f1f5f9
    doc.setDrawColor(203, 213, 225); // #cbd5e1
    doc.rect(margin, currentY, availableWidth, rowHeight, "FD");

    // Table Header Text
    doc.setTextColor(30, 41, 59);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(9);

    headers.forEach((h, i) => {
      const cellX = margin + i * colWidth + 6;
      const cellY = currentY + 14;
      doc.text(String(h).slice(0, 25), cellX, cellY);
    });

    currentY += rowHeight;

    // Table Rows
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);

    rows.forEach((row, rowIndex) => {
      // Check page break
      if (currentY + rowHeight > pageHeight - margin - 30) {
        doc.addPage();
        currentY = margin + 20;

        // Re-draw header on new page
        doc.setFillColor(241, 245, 249);
        doc.rect(margin, currentY, availableWidth, rowHeight, "FD");
        doc.setFont("helvetica", "bold");
        headers.forEach((h, i) => {
          doc.text(String(h).slice(0, 25), margin + i * colWidth + 6, currentY + 14);
        });
        currentY += rowHeight;
        doc.setFont("helvetica", "normal");
      }

      // Zebra striping
      if (rowIndex % 2 === 1) {
        doc.setFillColor(248, 250, 252);
        doc.rect(margin, currentY, availableWidth, rowHeight, "F");
      }

      doc.setDrawColor(226, 232, 240);
      doc.line(margin, currentY + rowHeight, margin + availableWidth, currentY + rowHeight);

      doc.setTextColor(51, 65, 85);
      row.forEach((cell, i) => {
        const val = cell !== undefined && cell !== null ? String(cell) : "-";
        const cellX = margin + i * colWidth + 6;
        const cellY = currentY + 14;
        doc.text(val.slice(0, 30), cellX, cellY);
      });

      currentY += rowHeight;
    });

    // Footer
    const totalPages = (doc.internal as any).getNumberOfPages();
    for (let p = 1; p <= totalPages; p++) {
      doc.setPage(p);
      doc.setDrawColor(226, 232, 240);
      doc.line(margin, pageHeight - 30, pageWidth - margin, pageHeight - 30);
      doc.setFontSize(8);
      doc.setTextColor(148, 163, 184);
      doc.text("Enterprise ERP System • Confidential Business Document", margin, pageHeight - 16);
      doc.text(`Page ${p} of ${totalPages}`, pageWidth - margin, pageHeight - 16, { align: "right" });
    }

    const finalFilename = filename.toLowerCase().endsWith(".pdf") ? filename : `${filename}.pdf`;
    doc.save(finalFilename);
    toast.success(isRTL ? "تم تحميل ملف PDF بنجاح" : "PDF downloaded successfully");
    return true;
  } catch (error) {
    console.error("Failed to generate PDF:", error);
    toast.error("Failed to export PDF file");
    return false;
  }
}

/**
 * Opens an ultra-high-fidelity print/export modal supporting full Arabic (Cairo font)
 * and LTR English with graphics, branding, and direct Save to PDF.
 */
export function printFormattedReport(
  title: string,
  subtitle: string,
  headers: string[],
  rows: (string | number | boolean | null | undefined)[][],
  options: ExportDataOptions = {}
) {
  const isRTL = options.isRTL ?? false;
  const printWindow = window.open("", "_blank");
  if (!printWindow) {
    toast.error(isRTL ? "يرجى السماح بالنوافذ المنبثقة للطباعة" : "Please allow popups to print report");
    return;
  }

  const html = `
<!DOCTYPE html>
<html lang="${isRTL ? "ar" : "en"}" dir="${isRTL ? "rtl" : "ltr"}">
<head>
  <meta charset="UTF-8">
  <title>${title}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: ${isRTL ? "'Cairo', Tahoma, sans-serif" : "'Inter', -apple-system, sans-serif"};
      color: #1e293b;
      margin: 0;
      padding: 32px;
      background: #ffffff;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 2px solid #1e3a8a;
      padding-bottom: 16px;
      margin-bottom: 24px;
    }
    .logo-box {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .logo-badge {
      width: 44px;
      height: 44px;
      background: linear-gradient(135deg, #2563eb, #1e3a8a);
      color: white;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      font-weight: bold;
    }
    .system-title {
      font-size: 18px;
      font-weight: 700;
      color: #1e3a8a;
      margin: 0;
    }
    .meta-box {
      text-align: ${isRTL ? "left" : "right"};
      font-size: 12px;
      color: #64748b;
    }
    .doc-info {
      margin-bottom: 24px;
    }
    .doc-title {
      font-size: 22px;
      font-weight: 700;
      color: #0f172a;
      margin: 0 0 6px 0;
    }
    .doc-subtitle {
      font-size: 13px;
      color: #64748b;
      margin: 0;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 16px;
      font-size: 13px;
    }
    th {
      background-color: #f1f5f9;
      color: #1e293b;
      font-weight: 700;
      text-align: ${isRTL ? "right" : "left"};
      padding: 10px 14px;
      border-bottom: 2px solid #cbd5e1;
    }
    td {
      padding: 10px 14px;
      border-bottom: 1px solid #e2e8f0;
      text-align: ${isRTL ? "right" : "left"};
    }
    tr:nth-child(even) td {
      background-color: #f8fafc;
    }
    .footer {
      margin-top: 40px;
      padding-top: 16px;
      border-top: 1px solid #e2e8f0;
      display: flex;
      justify-content: space-between;
      font-size: 11px;
      color: #94a3b8;
    }
    @media print {
      body { padding: 16px; }
      .no-print { display: none; }
    }
    .actions-bar {
      margin-bottom: 20px;
      padding: 12px;
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 8px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .btn {
      padding: 8px 16px;
      background: #2563eb;
      color: white;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 600;
      font-size: 13px;
    }
  </style>
</head>
<body>
  <div class="actions-bar no-print">
    <span>${isRTL ? "معاينة التقرير الجاهز للطباعة والحفظ كـ PDF" : "Print Preview / Save as PDF"}</span>
    <button class="btn" onclick="window.print()">${isRTL ? "طباعة / حفظ PDF" : "Print / Save PDF"}</button>
  </div>

  <div class="header">
    <div class="logo-box">
      <div class="logo-badge">ERP</div>
      <div>
        <h1 class="system-title">Enterprise ERP System</h1>
        <span style="font-size: 11px; color: #64748b;">Enterprise Resource Planning</span>
      </div>
    </div>
    <div class="meta-box">
      <div><strong>${isRTL ? "تاريخ الإصدار:" : "Date:"}</strong> ${options.date || new Date().toLocaleDateString(isRTL ? "ar-EG" : "en-US")}</div>
      <div><strong>${isRTL ? "النوع:" : "Type:"}</strong> ${options.category || (isRTL ? "تقرير رسمي" : "Official Report")}</div>
    </div>
  </div>

  <div class="doc-info">
    <h2 class="doc-title">${title}</h2>
    ${subtitle ? `<p class="doc-subtitle">${subtitle}</p>` : ""}
  </div>

  <table>
    <thead>
      <tr>
        ${headers.map((h) => `<th>${h}</th>`).join("")}
      </tr>
    </thead>
    <tbody>
      ${rows
        .map(
          (row) => `
        <tr>
          ${row.map((cell) => `<td>${cell !== undefined && cell !== null ? cell : "-"}</td>`).join("")}
        </tr>
      `
        )
        .join("")}
    </tbody>
  </table>

  <div class="footer">
    <span>Enterprise ERP System • ${isRTL ? "وثيقة رسمية معتمدة" : "Confidential Document"}</span>
    <span>${isRTL ? "تم التوليد آلياً من النظام" : "Generated by Enterprise ERP"}</span>
  </div>

  <script>
    // Auto-open print dialog in 500ms
    setTimeout(() => { window.print(); }, 500);
  </script>
</body>
</html>
  `;

  printWindow.document.open();
  printWindow.document.write(html);
  printWindow.document.close();
}
