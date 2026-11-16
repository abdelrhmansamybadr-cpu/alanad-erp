import { useRef } from "react";
import { Printer, Download, ArrowRight } from "lucide-react";
import { Link } from "react-router";

export function ClientProposalAR() {
  const printRef = useRef<HTMLDivElement>(null);

  const handlePrint = () => window.print();

  const handleDownload = () => {
    const content = printRef.current?.innerText || "";
    const blob = new Blob([content], { type: "text/plain;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = "وصف-مشروع-ERP.txt";
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="min-h-screen bg-gray-100" dir="rtl">
      {/* Top action bar — hidden on print */}
      <div className="print:hidden sticky top-0 z-10 bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between shadow-sm">
        <div className="flex items-center gap-3">
          <Link to="/" className="flex items-center gap-1.5 text-sm text-blue-600 hover:text-blue-700 transition-colors">
            <ArrowRight className="w-4 h-4 rotate-180" />
            العودة للنظام
          </Link>
          <span className="text-gray-300">|</span>
          <span className="text-sm font-medium text-gray-700">وثيقة وصف المشروع — العميل</span>
        </div>
        <div className="flex gap-2">
          <button
            onClick={handleDownload}
            className="flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium"
          >
            <Download className="w-4 h-4" />
            تنزيل نصي
          </button>
          <button
            onClick={handlePrint}
            className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium"
          >
            <Printer className="w-4 h-4" />
            طباعة / PDF
          </button>
        </div>
      </div>

      {/* Document */}
      <div ref={printRef} className="max-w-4xl mx-auto my-8 print:my-0 bg-white shadow-lg print:shadow-none rounded-2xl print:rounded-none overflow-hidden">

        {/* Cover Header */}
        <div className="bg-gradient-to-l from-blue-700 to-blue-900 px-12 py-14 text-white text-right">
          <div className="flex items-center justify-between mb-8">
            <div className="text-left opacity-70 text-sm">
              <p>Enterprise ERP System</p>
              <p>v2.4.1 — 2026</p>
            </div>
            <div className="flex items-center justify-center w-16 h-16 bg-white/20 rounded-2xl backdrop-blur-sm">
              <svg className="w-9 h-9 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8}
                  d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
              </svg>
            </div>
          </div>
          <h1 className="text-4xl font-bold leading-relaxed mb-3">
            نظام تخطيط موارد المؤسسات
          </h1>
          <h2 className="text-xl font-light text-blue-200 mb-6">
            Enterprise Resource Planning (ERP) — وثيقة الوصف التفصيلية
          </h2>
          <div className="h-px bg-white/20 mb-6" />
          <p className="text-blue-100 leading-loose text-lg">
            وثيقة شاملة تُقدِّم نظرة كاملة على وحدات النظام ووظائفه وقدراته التقنية،
            مُعدَّة خصيصاً لاطلاع العميل الكريم على جميع تفاصيل المنظومة.
          </p>
          <div className="mt-8 flex gap-4 text-sm flex-wrap">
            {["متعدد المستأجرين", "واجهة عربية وإنجليزية", "سحابي وداخلي", "وصول قائم على الأدوار"].map(tag => (
              <span key={tag} className="px-3 py-1.5 bg-white/15 rounded-full border border-white/20">{tag}</span>
            ))}
          </div>
        </div>

        <div className="px-12 py-10 space-y-12 text-gray-800">

          {/* Intro */}
          <section>
            <SectionTitle number="١" title="نظرة عامة على المشروع" />
            <p className="leading-loose text-gray-700 text-[15px]">
              يُعدّ هذا النظام منصةً متكاملة لتخطيط موارد المؤسسات (ERP) مصممةً على مستوى المؤسسات الكبرى، قابلةً للمقارنة بأنظمة عالمية مثل <span className="font-semibold text-blue-700">Odoo وSAP Cloud وOracle NetSuite</span>. يعمل النظام كحلٍّ برمجي متكامل يُغطي جميع العمليات التجارية من المخزون والمشتريات والمبيعات والمشاريع والموارد البشرية والمحاسبة، مع لوحة تحكم مركزية تتيح رؤية شاملة وفورية لحالة الأعمال.
            </p>
            <div className="mt-6 grid grid-cols-2 gap-4">
              {[
                { label: "نوع النظام", value: "SaaS متعدد المستأجرين (Multi-Tenant)" },
                { label: "اللغات المدعومة", value: "العربية (RTL) والإنجليزية" },
                { label: "أسلوب النشر", value: "سحابي أو داخلي (On-Premise)" },
                { label: "واجهة المستخدم", value: "ويب + تطبيق جوال مرافق" },
                { label: "معمارية النظام", value: "API-First، مودولار قابل للتوسع" },
                { label: "التحكم بالوصول", value: "قائم على الأدوار (RBAC)" },
              ].map(item => (
                <div key={item.label} className="bg-blue-50 rounded-xl p-4 border border-blue-100">
                  <p className="text-xs text-blue-500 font-medium mb-1">{item.label}</p>
                  <p className="font-semibold text-gray-900 text-sm">{item.value}</p>
                </div>
              ))}
            </div>
          </section>

          <Divider />

          {/* Module 1 - Dashboard */}
          <section>
            <SectionTitle number="٢" title="لوحة التحكم الرئيسية — Dashboard" />
            <ModuleIntro color="blue" description="الصفحة الرئيسية التي تُعطي مديري الأعمال نظرة فورية وشاملة على أداء المؤسسة بالكامل دون الحاجة للدخول إلى كل وحدة منفردة." />
            <FeatureList items={[
              "بطاقات مؤشرات الأداء الرئيسية (KPIs): إجمالي الإيرادات، الطلبات النشطة، المنتجات منخفضة المخزون، المستخدمون النشطون — مع نسب التغيير مقارنةً بالشهر السابق.",
              "مخطط بياني خطي (Line Chart) يُظهر تطور الإيرادات وعدد الطلبات على مدى الأشهر الستة الأخيرة.",
              "مخطط دائري (Donut Chart) يوضح توزيع حالة المخزون: متوفر / منخفض / نافد.",
              "جدول آخر الطلبات مع حالاتها (معتمد، معلق، مكتمل، مسودة) وروابط مباشرة.",
              "قسم الموافقات المعلقة: يعرض الطلبات التي تنتظر اتخاذ إجراء مع أزرار موافقة ورفض فورية.",
              "سجل النشاطات الأخيرة: جدول زمني يُظهر آخر العمليات التي جرت على النظام مع اسم المنفذ والتوقيت.",
            ]} />
          </section>

          <Divider />

          {/* Module 2 - Inventory */}
          <section>
            <SectionTitle number="٣" title="وحدة المخزون — Inventory Management" />
            <ModuleIntro color="green" description="منظومة متكاملة لإدارة المخزون تشمل المنتجات والمستودعات وحركة البضائع والجرد والمراجعة، مع تنبيهات ذكية وتقارير لحظية." />
            <FeatureList items={[
              "لوحة تحكم المخزون: نظرة شاملة على القيمة الإجمالية للمخزون، إجمالي المنتجات، المنتجات المنخفضة والنافدة، ومستوى دوران المخزون.",
              "قائمة المنتجات: عرض كامل بالصور والأكواد والفئات والأسعار ومستويات المخزون الحالية مع خاصية الفلترة والبحث.",
              "صفحة تفاصيل المنتج: بيانات شاملة، مخطط بياني لحركة المخزون، سجل المعاملات، وزر تعديل المنتج بنموذج مُعبأ مسبقاً.",
              "إدارة المستودعات: قائمة بجميع المستودعات مع عناوينها، سعتها، نسب الإشغال، وإمكانية إضافة مستودعات جديدة.",
              "حركات المخزون (Stock Movements): سجل كامل لجميع عمليات الإدخال والإخراج والتحويل بين المستودعات مع تواريخها وأسبابها.",
              "تسويات المخزون (Stock Adjustments): تعديل مستويات المخزون يدوياً مع توثيق السبب وهوية المنفذ والتوقيت.",
              "استلام البضائع (Goods Receipts): استلام البضائع الواردة من أوامر الشراء وتحديث المخزون تلقائياً عند التأكيد.",
            ]} />
          </section>

          <Divider />

          {/* Module 3 - Procurement */}
          <section>
            <SectionTitle number="٤" title="وحدة المشتريات والتوريد — Procurement" />
            <ModuleIntro color="orange" description="نظام متكامل لدورة الشراء الكاملة من طلب عروض الأسعار وحتى استلام البضاعة، مع سير عمل موافقات متعدد المستويات." />
            <FeatureList items={[
              "لوحة تحكم المشتريات: مؤشرات قيمة الطلبات المعلقة، طلبات الشراء هذا الشهر، متوسط وقت التوصيل، وأداء الموردين.",
              "طلبات عروض الأسعار (RFQ): إنشاء طلبات أسعار وإرسالها للموردين، ومقارنة العروض الواردة لاختيار الأفضل.",
              "أوامر الشراء (Purchase Orders): إنشاء أوامر الشراء برقم تسلسلي تلقائي بصيغة (PO-2026-XXXX) مع بيانات المورد والبنود والكميات والأسعار.",
              "سير عمل موافقات متعدد المستويات: المحاسبة (مراجعة أولى) ← الإدارة العليا (موافقة نهائية) ← المخزون (استلام البضاعة)، مع شارات حية في الشريط الجانبي تُظهر عدد الطلبات المعلقة.",
              "إدارة الموردين: قاعدة بيانات شاملة للموردين مع بيانات التواصل، شروط الدفع، تاريخ المعاملات، وتقييم الأداء.",
              "طلبات الشراء من المشاريع (PRs): ربط طلبات الشراء بالمشاريع واعتمادها من مدير المشروع قبل إرسالها لقسم المشتريات.",
            ]} />
          </section>

          <Divider />

          {/* Module 4 - Sales */}
          <section>
            <SectionTitle number="٥" title="وحدة المبيعات — Sales" />
            <ModuleIntro color="purple" description="إدارة دورة المبيعات الكاملة من عروض الأسعار وحتى الفاتورة، مع إدارة العملاء وتحليل الأداء." />
            <FeatureList items={[
              "لوحة تحكم المبيعات: مقاييس الإيراد، هامش الربح، معدل التحويل، وأداء فريق المبيعات مع مخططات بيانية.",
              "عروض الأسعار (Quotations): إنشاء عروض أسعار احترافية وإرسالها للعملاء مع إمكانية تتبع حالتها (مسودة، مُرسل، مقبول، مرفوض).",
              "أوامر البيع (Sales Orders): تحويل عروض الأسعار المقبولة إلى أوامر بيع تلقائياً مع ربطها بالمخزون والمحاسبة.",
              "تفاصيل أمر البيع: عرض شامل ببنود الطلب، بيانات العميل، الشروط، حالة التسليم، والمبالغ مع الضريبة.",
              "إدارة العملاء (CRM خفيف): قاعدة بيانات العملاء مع تاريخ المعاملات، إجمالي المشتريات، وتصنيف العملاء.",
            ]} />
          </section>

          <Divider />

          {/* Module 5 - Projects */}
          <section>
            <SectionTitle number="٦" title="وحدة المشاريع — Projects" />
            <ModuleIntro color="teal" description="إدارة احترافية للمشاريع مع تتبع المهام والميزانيات وطلبات الشراء وأداء الفريق." />
            <FeatureList items={[
              "لوحة تحكم المشاريع: نظرة شاملة على المشاريع النشطة، نسب الإنجاز، الميزانيات المستهلكة، والمشاريع المتأخرة.",
              "قائمة المشاريع: عرض جميع المشاريع مع حالتها، مدير المشروع، تاريخ الانتهاء، ونسبة الإنجاز عبر شريط تقدم مرئي.",
              "تفاصيل المشروع: صفحة شاملة تضم معلومات المشروع، قائمة المهام مع حالاتها، أعضاء الفريق، ملف المشروع المالي.",
              "طلبات الشراء من المشروع (PRs): إمكانية إنشاء طلب شراء مرتبط بمشروع محدد، يظهر في قسم المشتريات كإشعار معلق.",
              "ربط ذكي بالمشتريات: عند إنشاء طلب شراء من المشروع، تظهر شارة تنبيه فورية في أيقونة المشتريات بالشريط الجانبي.",
            ]} />
          </section>

          <Divider />

          {/* Module 6 - HR */}
          <section>
            <SectionTitle number="٧" title="وحدة الموارد البشرية — Human Resources" />
            <ModuleIntro color="rose" description="نظام متكامل لإدارة الموظفين والحضور والإجازات، قابل للتفعيل والتعطيل من لوحة الإدارة." />
            <FeatureList items={[
              "لوحة تحكم الموارد البشرية: إحصاءات الموظفين النشطين، طلبات الإجازات المعلقة، معدل الحضور الشهري، والتوزيع حسب القسم.",
              "قائمة الموظفين: بيانات شاملة لكل موظف (الاسم، المنصب، القسم، تاريخ التعيين، بيانات التواصل) مع خاصية البحث والفلترة.",
              "سجل الحضور والانصراف: تتبع أوقات حضور وانصراف الموظفين يومياً مع مؤشرات الحضور في الموعد، التأخر، والغياب.",
              "طلبات الإجازات: إمكانية تقديم طلبات الإجازة وتتبع حالتها (معلق، موافق، مرفوض) مع رصيد أيام الإجازة لكل موظف.",
              "التحكم في تفعيل الوحدة: يمكن لمسؤول النظام تفعيل أو تعطيل هذه الوحدة من لوحة إدارة الوحدات، وعند تعطيلها تُحذف تلقائياً من الشريط الجانبي.",
            ]} />
          </section>

          <Divider />

          {/* Module 7 - Accounting */}
          <section>
            <SectionTitle number="٨" title="وحدة المحاسبة والمالية — Accounting & Finance" />
            <ModuleIntro color="emerald" description="مركز مالي متكامل يُعطي المحاسبين رؤية كاملة على التدفقات المالية وموافقات أوامر الشراء والتقارير المالية." />
            <FeatureList items={[
              "لوحة تحكم المحاسبة: مؤشرات الإيراد، المصاريف، الأرباح، الذمم المدينة والدائنة، والتدفق النقدي الشهري.",
              "مراجعة أوامر الشراء: يرى المحاسب جميع أوامر الشراء التي تنتظر اعتماداً مالياً مع تفاصيلها وإمكانية الموافقة أو الرفض.",
              "شارات المراجعة الحية: تظهر شارة بعدد الطلبات المعلقة على أيقونة المحاسبة في الشريط الجانبي وتتحدث فورياً.",
              "التكامل مع دورة الشراء: بعد موافقة المحاسبة تنتقل الأوامر إلى المرحلة التالية (الإدارة العليا) تلقائياً دون تدخل يدوي.",
              "مخططات مالية: رسوم بيانية تفاعلية تُظهر مقارنة الإيرادات بالمصاريف وتوزيع الإنفاق حسب الفئات.",
            ]} />
          </section>

          <Divider />

          {/* Module 8 - Reports */}
          <section>
            <SectionTitle number="٩" title="وحدة التقارير والتحليلات — Reports & Analytics" />
            <ModuleIntro color="blue" description="مركز تقارير شامل يُتيح توليد وجدولة وتنزيل التقارير لجميع وحدات النظام مع مخططات بيانية تفاعلية." />
            <FeatureList items={[
              "تبويب النظرة العامة (Overview): أربعة مخططات بيانية تفاعلية تُظهر: اتجاه الإيرادات والأرباح (خطي)، المبيعات حسب الفئة (دائري)، المخزون حسب المستودع (أعمدة)، وإنفاق المشتريات فصلياً (مساحي).",
              "تبويب التقارير: 16 تقريراً جاهزاً مُوزَّعةً على أربع وحدات (مالية، مخزون، مبيعات، مشتريات) مع فلاتر حسب الوحدة والفترة الزمنية، وخيار نطاق مخصص.",
              "معاينة التقرير (Preview): قبل توليد أي تقرير يمكن معاينة بياناته في نافذة منبثقة بمخطط بياني ملائم لطبيعة التقرير.",
              "توليد التقارير: اختيار صيغة الإخراج (PDF، Excel، CSV) ثم النقر على «توليد» — يعمل بمؤشر تحميل ثم ينتقل التقرير لتبويب «الأخيرة» تلقائياً.",
              "جدولة التقارير (Scheduled): إنشاء جدولة تلقائية لأي تقرير (يومي/أسبوعي/شهري) مع تحديد الوقت وصيغة الإخراج وقائمة المستلمين بالبريد الإلكتروني.",
              "التقارير الأخيرة (Recent): قائمة ديناميكية بجميع التقارير المُوَلَّدة مع خيار تنزيل مباشر كملف حقيقي.",
            ]} />
          </section>

          <Divider />

          {/* Module 9 - Admin */}
          <section>
            <SectionTitle number="١٠" title="لوحة الإدارة — Admin Panel" />
            <ModuleIntro color="slate" description="مركز تحكم متقدم يمنح مسؤول النظام صلاحيات إدارة الوحدات والمستأجرين والأدوار وسجلات التدقيق والبنية التقنية." />
            <FeatureList items={[
              "لوحة تحكم الإدارة: مؤشرات حالة النظام، معدل الأداء، الجلسات النشطة، وإجمالي سجلات التدقيق.",
              "إدارة الوحدات (Module Management): تفعيل أو تعطيل أي وحدة بمفتاح تبديل واحد — عند تعطيل وحدة تُزال فوراً من الشريط الجانبي لجميع المستخدمين. الوحدات الأساسية (المخزون، المشتريات، المحاسبة، المبيعات) محمية ولا يمكن تعطيلها.",
              "إدارة المستأجرين (Tenant Management): إدارة الحسابات المؤسسية في النظام المتعدد المستأجرين مع بيانات الاشتراك والحالة.",
              "إدارة الأدوار والصلاحيات (Role Management): تحديد ما يستطيع كل دور (مدير، محاسب، مشتريات، مخزن...) رؤيته أو تعديله في النظام.",
              "سجلات التدقيق (Audit Logs): توثيق تلقائي لكل عملية تُجرى على النظام مع هوية المنفذ والتوقيت وتفاصيل التغيير.",
              "البنية التقنية (System Architecture): عرض مرئي لبنية النظام، الخدمات المتصلة، الخوادم، وقواعد البيانات.",
            ]} />
          </section>

          <Divider />

          {/* Module 10 - Settings */}
          <section>
            <SectionTitle number="١١" title="الإعدادات — Settings" />
            <ModuleIntro color="gray" description="مركز إعدادات شخصية ومؤسسية يُتيح تخصيص النظام وفق متطلبات المؤسسة." />
            <FeatureList items={[
              "الملف الشخصي: تحديث بيانات المستخدم (الاسم، الوظيفة، البريد الإلكتروني) ورفع صورة شخصية عبر نافذة اختيار ملفات مدمجة.",
              "إعدادات الشركة: اسم الشركة، الشعار، العنوان، العملة الافتراضية، والمنطقة الزمنية.",
              "الأمان: تغيير كلمة المرور، إدارة الجلسات النشطة، وإعدادات المصادقة الثنائية.",
              "الإشعارات: التحكم في أنواع الإشعارات التي يتلقاها المستخدم (بريد، داخل النظام، جوال).",
              "المظهر والواجهة: اختيار اللغة (عربي/إنجليزي) مع دعم كامل للاتجاه من اليمين لليسار (RTL).",
            ]} />
          </section>

          <Divider />

          {/* Module 11 - Download Center */}
          <section>
            <SectionTitle number="١٢" title="مركز التنزيلات — Download Center" />
            <ModuleIntro color="indigo" description="مستودع مركزي لجميع الملفات والوثائق والتحديثات التي يحتاجها المستخدمون." />
            <FeatureList items={[
              "تصنيف الملفات: تقارير، دليل المستخدم، نماذج Excel، ملحقات وإضافات النظام — مُنظَّمة بشكل واضح.",
              "خاصية البحث والفلترة: البحث بالاسم أو التصنيف مع فلترة حسب النوع أو تاريخ الإضافة.",
              "معلومات الملف: اسم الملف، حجمه، تاريخ الإضافة، وعدد مرات التنزيل.",
              "تنزيل فوري: زر تنزيل لكل ملف مع مؤشر تقدم.",
            ]} />
          </section>

          <Divider />

          {/* Module 12 - Mobile */}
          <section>
            <SectionTitle number="١٣" title="التطبيق المرافق للجوال — Mobile Companion App" />
            <ModuleIntro color="cyan" description="واجهة محاكاة للتطبيق المرافق على الهاتف الذكي تُتيح الوصول للوظائف الأساسية من أي مكان." />
            <FeatureList items={[
              "واجهة مُصمَّمة لشاشات الجوال: تصميم محاكي للهاتف داخل المتصفح يعرض كيف سيبدو التطبيق المرافق.",
              "وصول للمؤشرات الرئيسية: عرض إيرادات اليوم، الطلبات النشطة، التنبيهات، وآخر النشاطات.",
              "إشعارات الموافقات: استقبال إشعارات بالطلبات التي تحتاج لموافقة مع إمكانية الاعتماد من الجوال.",
              "مسح الباركود: (ضمن خارطة التطوير) مسح رموز المنتجات للتحقق من المخزون فورياً.",
            ]} />
          </section>

          <Divider />

          {/* Module 13 - Design System */}
          <section>
            <SectionTitle number="١٤" title="نظام التصميم — Design System" />
            <ModuleIntro color="violet" description="مكتبة مكونات UI الموحدة التي يقوم عليها تصميم النظام بالكامل، مرجع للمطورين والمصممين." />
            <FeatureList items={[
              "مكتبة ألوان موحدة: درجات اللون الأزرق العميق للواجهة الرئيسية، مع ألوان الحالات (نجاح، خطأ، تحذير، معلومة).",
              "مكونات الواجهة (Components): أزرار، نماذج، بطاقات، شارات، قوائم منسدلة، نوافذ منبثقة — جميعها موثقة مع أمثلة تفاعلية.",
              "الطباعة والنصوص: منظومة متسقة للأحجام والأوزان والمسافات البينية.",
              "الأيقونات: مكتبة Lucide Icons المستخدمة في جميع أنحاء النظام.",
            ]} />
          </section>

          <Divider />

          {/* Workflow */}
          <section>
            <SectionTitle number="١٥" title="سير عمل موافقات أوامر الشراء" />
            <p className="text-gray-600 leading-loose mb-6">
              يتميز النظام بسير عمل متكامل لموافقات أوامر الشراء يضمن الرقابة المالية والإدارية على جميع عمليات الشراء:
            </p>
            <div className="flex flex-col gap-3">
              {[
                { step: "١", color: "bg-blue-100 text-blue-800 border-blue-300", label: "مسودة الأمر", desc: "يُنشئ موظف المشتريات أمر الشراء ويُرسله للمراجعة" },
                { step: "٢", color: "bg-amber-100 text-amber-800 border-amber-300", label: "مراجعة المحاسبة", desc: "يراجع المحاسب الأمر مالياً ويعتمده أو يرفضه — شارة تنبيه حية" },
                { step: "٣", color: "bg-purple-100 text-purple-800 border-purple-300", label: "موافقة الإدارة", desc: "يعتمد المدير الأمر نهائياً للأوامر ذات القيمة العالية — شارة تنبيه حية" },
                { step: "٤", color: "bg-green-100 text-green-800 border-green-300", label: "إرسال للمورد", desc: "إرسال الأمر المعتمد للمورد تلقائياً مع رقم PO-2026-XXXX" },
                { step: "٥", color: "bg-teal-100 text-teal-800 border-teal-300", label: "استلام البضاعة", desc: "تسجيل استلام البضاعة في المخزون وإغلاق الأمر — شارة تنبيه حية لقسم المخزون" },
              ].map(s => (
                <div key={s.step} className={`flex items-start gap-4 p-4 rounded-xl border ${s.color}`}>
                  <div className="w-8 h-8 rounded-full bg-white flex items-center justify-center font-bold text-sm flex-shrink-0 shadow-sm">
                    {s.step}
                  </div>
                  <div>
                    <p className="font-semibold">{s.label}</p>
                    <p className="text-sm mt-0.5 opacity-80">{s.desc}</p>
                  </div>
                </div>
              ))}
            </div>
          </section>

          <Divider />

          {/* Technical */}
          <section>
            <SectionTitle number="١٦" title="المواصفات التقنية" />
            <div className="grid grid-cols-2 gap-4">
              {[
                { cat: "واجهة المستخدم", items: ["React 18 + TypeScript", "Tailwind CSS v4", "Recharts للمخططات البيانية", "Lucide Icons"] },
                { cat: "التوجيه والتنقل", items: ["React Router (Data Mode)", "تنقل متعدد الصفحات بدون تحديث", "حماية المسارات بالأدوار", "صفحة 404 مخصصة"] },
                { cat: "إدارة الحالة", items: ["React Context API", "POContext لدورة أوامر الشراء", "ProjectContext للمشاريع", "ModuleContext لإدارة الوحدات"] },
                { cat: "الأمان والوصول", items: ["تسجيل دخول بالبريد وكلمة المرور", "مصادقة ثنائية (2FA)", "إدارة الأدوار والصلاحيات", "تشفير الجلسات"] },
                { cat: "التكيف والاستجابة", items: ["تصميم متجاوب (Mobile-First)", "شريط جانبي يُطوى على الجوال", "دعم RTL كامل للعربية", "طباعة وتصدير PDF/Excel"] },
                { cat: "قابلية التوسع", items: ["معمارية مودولار قابلة للتوسع", "API-First جاهز للتكاملات", "متعدد المستأجرين (Multi-Tenant)", "نشر سحابي أو داخلي"] },
              ].map(section => (
                <div key={section.cat} className="bg-gray-50 rounded-xl p-4 border border-gray-200">
                  <p className="font-semibold text-gray-900 mb-3 text-sm border-b border-gray-200 pb-2">{section.cat}</p>
                  <ul className="space-y-1.5">
                    {section.items.map(item => (
                      <li key={item} className="flex items-center gap-2 text-sm text-gray-600">
                        <span className="w-1.5 h-1.5 bg-blue-500 rounded-full flex-shrink-0" />
                        {item}
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          </section>

          <Divider />

          {/* Auth */}
          <section>
            <SectionTitle number="١٧" title="منظومة المصادقة والدخول" />
            <ModuleIntro color="slate" description="نظام تسجيل دخول متكامل وآمن مع دعم جميع سيناريوهات المصادقة الحديثة." />
            <FeatureList items={[
              "صفحة تسجيل الدخول: واجهة احترافية بالبريد الإلكتروني وكلمة المرور مع رسائل خطأ واضحة.",
              "تسجيل حساب جديد: نموذج إنشاء حساب مع التحقق من صحة البيانات.",
              "نسيت كلمة المرور: طلب إعادة تعيين كلمة المرور عبر البريد الإلكتروني.",
              "المصادقة الثنائية (2FA): إدخال رمز التحقق المُرسَل لتأمين الدخول.",
              "التحقق من البريد الإلكتروني: تفعيل الحساب عبر رابط إلكتروني.",
              "إعداد الحساب (Onboarding): خطوات توجيهية لإعداد المؤسسة عند أول دخول.",
            ]} />
          </section>

          <Divider />

          {/* Sidebar */}
          <section>
            <SectionTitle number="١٨" title="الشريط الجانبي الذكي" />
            <p className="text-gray-600 leading-loose">
              الشريط الجانبي هو مركز التنقل الأساسي في النظام، ويتميز بعدة ميزات ذكية:
            </p>
            <FeatureList items={[
              "شارات التنبيه الحية: تظهر أرقام ملونة على أيقونات الوحدات عند وجود إجراءات معلقة (المشتريات، المحاسبة، الإدارة، المخزون) وتتحدث تلقائياً.",
              "الرابط السريع لاستلام البضاعة: يظهر في الشريط تلقائياً عند وجود بضائع تنتظر الاستلام مع عداد الكمية.",
              "ديناميكية الوحدات: الوحدات المُعطَّلة من لوحة إدارة الوحدات تُزال تلقائياً من الشريط الجانبي.",
              "قائمة المستخدم: صورة المستخدم، اسمه، دوره، مع قائمة منسدلة للإعدادات وتسجيل الخروج.",
              "متجاوب مع الجوال: ينطوي الشريط الجانبي على الشاشات الصغيرة مع زر فتح/إغلاق.",
            ]} />
          </section>

          <Divider />

          {/* Notifications */}
          <section>
            <SectionTitle number="١٩" title="منظومة الإشعارات" />
            <p className="text-gray-600 leading-loose">
              جرس الإشعارات في شريط العنوان العلوي يُجمِّع جميع الإجراءات المعلقة في مكان واحد:
            </p>
            <FeatureList items={[
              "عداد إجمالي: يُظهر مجموع جميع الإجراءات المعلقة (مراجعات محاسبة + موافقات إدارة + استلام بضاعة + طلبات شراء من مشاريع).",
              "تفاصيل مُنظَّمة: عند النقر على الجرس تُعرض الإشعارات مُقسَّمة حسب نوعها مع تفاصيل كل طلب ورابط مباشر له.",
              "تحديد كمقروء: زر «تحديد الكل كمقروء» لمسح العداد دون حذف الإشعارات.",
              "روابط سريعة: كل إشعار يقودك مباشرةً للصفحة ذات الصلة (محاسبة، إدارة، مخزون، مشتريات).",
              "شارات سياقية: تظهر في شريط العنوان بجانب الجرس ملصقات ملونة للموافقات العاجلة.",
            ]} />
          </section>

          <Divider />

          {/* Footer */}
          <section>
            <div className="bg-gradient-to-l from-blue-50 to-indigo-50 rounded-2xl border border-blue-100 p-8 text-center">
              <div className="w-14 h-14 bg-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <svg className="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8}
                    d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                </svg>
              </div>
              <h3 className="text-xl font-bold text-gray-900 mb-2">Enterprise ERP System</h3>
              <p className="text-gray-500 mb-1">وثيقة الوصف التفصيلية — سرية وخاصة بالعميل</p>
              <p className="text-gray-400 text-sm">الإصدار 2.4.1 • مارس 2026</p>
              <div className="h-px bg-blue-200 my-6" />
              <p className="text-sm text-gray-500 leading-loose">
                جميع الوحدات والوظائف الموضحة في هذه الوثيقة مُطبَّقة بالكامل في النظام الحالي.
                النظام جاهز للنشر الفوري ويدعم التخصيص الكامل وفق احتياجات المؤسسة.
              </p>
            </div>
          </section>

        </div>
      </div>

      <style>{`
        @media print {
          body { background: white; }
          .print\\:hidden { display: none !important; }
        }
      `}</style>
    </div>
  );
}

// ─── Helper Components ────────────────────────────────────────────────────────
function SectionTitle({ number, title }: { number: string; title: string }) {
  return (
    <div className="flex items-center gap-3 mb-5">
      <div className="w-9 h-9 bg-blue-600 text-white rounded-xl flex items-center justify-center font-bold text-sm flex-shrink-0">
        {number}
      </div>
      <h2 className="text-xl font-bold text-gray-900">{title}</h2>
    </div>
  );
}

const moduleColors: Record<string, string> = {
  blue: "bg-blue-50 border-blue-200 text-blue-800",
  green: "bg-green-50 border-green-200 text-green-800",
  orange: "bg-orange-50 border-orange-200 text-orange-800",
  purple: "bg-purple-50 border-purple-200 text-purple-800",
  teal: "bg-teal-50 border-teal-200 text-teal-800",
  rose: "bg-rose-50 border-rose-200 text-rose-800",
  emerald: "bg-emerald-50 border-emerald-200 text-emerald-800",
  slate: "bg-slate-50 border-slate-200 text-slate-800",
  gray: "bg-gray-50 border-gray-200 text-gray-800",
  indigo: "bg-indigo-50 border-indigo-200 text-indigo-800",
  cyan: "bg-cyan-50 border-cyan-200 text-cyan-800",
  violet: "bg-violet-50 border-violet-200 text-violet-800",
};

function ModuleIntro({ color, description }: { color: string; description: string }) {
  return (
    <div className={`rounded-xl border p-4 mb-5 ${moduleColors[color] || moduleColors.blue}`}>
      <p className="leading-loose text-[14px]">{description}</p>
    </div>
  );
}

function FeatureList({ items }: { items: string[] }) {
  return (
    <ul className="space-y-3">
      {items.map((item, i) => (
        <li key={i} className="flex items-start gap-3 text-[14px] text-gray-700">
          <span className="w-5 h-5 bg-blue-100 text-blue-700 rounded-full flex items-center justify-center text-[10px] font-bold flex-shrink-0 mt-0.5">
            {i + 1}
          </span>
          <span className="leading-relaxed">{item}</span>
        </li>
      ))}
    </ul>
  );
}

function Divider() {
  return <div className="h-px bg-gradient-to-l from-transparent via-gray-200 to-transparent" />;
}
