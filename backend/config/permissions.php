<?php

/**
 * ⭐⭐⭐ كتالوج الصلاحيات — **المصدر الرسمي الوحيد**.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ ليه `config/` مش الـseeder
 *
 * ⭐ الكتالوج **بيصف** النظام، والـseeder **بينكتب** الداتابيز. خلط
 * الاتنين معناه:
 *   • مافيش طريقة نقرا الكتالوج من غير ما نشغّل seeder
 *   • و`config:` بتتقدّم على أي كود تاني — الاختبارات كمان
 *   • والوصف العربي مالوش مكان غير هنا
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ القاعدة الحاكمة: **اسم واحد = صلاحية واحدة = وظيفة واحدة**
 *
 * ⭐ `settings.manage` كان كسرها: اللي عنده صلاحية واحدة يقدر يغيّر
 * التسعير **و** يشوف سجل العمليات **و** يعدّل أدوار. فأي مراجعة
 * لصلاحية «الإعدادات» مش مراجعة لسلطة حقيقية — وده بالظبط اللي
 * خلّى `RoleIsolationTest` يكتشف أدوار متسرّبة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ شكل الاسم: `module.resource.action`
 *
 * ⭐ والتجزئة لـ**3** مقاطع مطلوبة **بس لما الوحدة فيها أكتر من
 * مورد**:
 *
 *   • `students.view`         — وحدة «الطلاب» موردها واحد
 *   • `finance.payments.refund` — وحدة «المالية» فيها مصادر
 *
 * ⭐ ⭐ **`module.action` مش قصر** — معناه إن الوحدة كلها مورد
 * واحد. `students.view` و `teachers.view` **صحّان** زي ما هما،
 * والقاعدة مش بتطلب تضخيم اسم كل صلاحية بـ4 حروف.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ الأفعال المتاحة — 6، مش مفتوحة
 *
 * ⭐ `view` · `create` · `update` · `delete` · `cancel` · `manage`
 *
 * ⭐ **`cancel` للحصص والفواتير** — ليها حالة نهائية مش بتمسح سجل.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ خريطة التحويل: **قيمة واحدة = قائمة**
 *
 * ⭐ `migration` بتربط **قائمة** قديمة بقائمة جديدة، مش اسم بـاسم.
 * والسبب إن `settings.manage` كانت بتغطي **خمس** وظائف — ولو
 * الخريطة بتوعد «كل الصلاحيات» وبتدي صلاحية واحدة، الخريطة نفسها
 * بقت المشكلة اللي جاي We'd حلّها.
 *
 * ⭐ والـراوتات لسه بالأسماء القديمة — الـ`PR 1.7` هو اللي يطبّقها.
 *
 * @see \Database\Seeders\SeedRolesPermissions::permissions()
 * @see \App\Console\Commands\SyncPermissions
 * @see \Tests\Feature\PermissionCatalogTest
 * @see database/migrations/2026_10_15_000004_add_metadata_to_permissions_table.php
 */

return [

    // ═══════════════════════════════════════════════════════════
    // الصلاحيات
    // ═══════════════════════════════════════════════════════════

    'permissions' => [

        // ═══════════ الطلاب ═══════════
        'students.view' => [
            'module' => 'students',
            'group' => 'الطلاب',
            'description' => 'شوف ملف الطالب والقوائم والاشتراكات',
        ],
        'students.create' => [
            'module' => 'students',
            'group' => 'الطلاب',
            'description' => 'تسجيل طالب جديد',
        ],
        'students.update' => [
            'module' => 'students',
            'group' => 'الطلاب',
            'description' => 'تعديل بيانات الطالب أو اشتراكاته',
        ],
        'students.delete' => [
            'module' => 'students',
            'group' => 'الطلاب',
            'description' => 'حذف طالب نهائي — قرار إدارة',
        ],
        'students.phones.manage' => [
            'module' => 'students',
            'group' => 'الطلاب',
            'description' => 'إضافة وتعديل أرقام هاتف ولي الأمر',
        ],

        // ═══════════ المعلّمون ═══════════
        'teachers.view' => [
            'module' => 'teachers',
            'group' => 'المعلّمون',
            'description' => 'شوف ملف المعلّم وجدوله وحصصه',
        ],
        'teachers.create' => [
            'module' => 'teachers',
            'group' => 'المعلّمون',
            'description' => 'تسجيل معلّم جديد',
        ],
        'teachers.update' => [
            'module' => 'teachers',
            'group' => 'المعلّمون',
            'description' => 'تعديل بيانات المعلّم أو جدوله',
        ],
        'teachers.delete' => [
            'module' => 'teachers',
            'group' => 'المعلّمون',
            'description' => 'حذف معلّم نهائي — قرار إدارة',
        ],
        'teacher.portal' => [
            'module' => 'teacher',
            'group' => 'المعلّمون',
            'description' => 'الدخول إلى بوابة المعلم — حصصه هو وحضوره وكشف مستحقاته وجدول تواجده',
        ],

        // ═══════════ الطالب ═══════════
        //
        // ⭐ PR 4.6 — بوابة الطالب: قراءة **نفسه فقط**. الملكية
        // من بروفايل الطالب المرتبط بالحساب، مش من `student_id`
        // جاي في الرابط. والصلاحية دي لا تعطي أي مسار مالي.
        'student.portal' => [
            'module' => 'student',
            'group' => 'الطلاب',
            'description' => 'الدخول إلى بوابة الطالب — جدوله وحضوره وتقدمه وحفظه، لنفسه فقط',
        ],

        // ═══════════ الحصص ═══════════
        'lessons.view' => [
            'module' => 'lessons',
            'group' => 'الحصص',
            'description' => 'شوف جدول الحصص',
        ],
        'lessons.create' => [
            'module' => 'lessons',
            'group' => 'الحصص',
            'description' => 'حجز حصة جديدة',
        ],
        'lessons.update' => [
            'module' => 'lessons',
            'group' => 'الحصص',
            'description' => 'تعديل حالة الحصة وتسجيل الحضور والمحفوظات',
        ],
        'lessons.cancel' => [
            'module' => 'lessons',
            'group' => 'الحصص',
            'description' => 'إلغاء حصة — بتاخد حالة نهائية مش حذف',
        ],
        // ⭐ PR 3.2 — سياسات الحصص: اللي بتحدّد مين بيخسر حصة ومين
        // بيتقاضى. قراءة وكتابة منفصلتين: الكتابة قرار إدارة.
        'lessons.policies.view' => [
            'module' => 'lessons',
            'group' => 'الحصص',
            'description' => 'شوف سياسات الحصص (من يُخصم منه، ومين يتقاضى)',
        ],
        'lessons.policies.manage' => [
            'module' => 'lessons',
            'group' => 'الحصص',
            'description' => 'تعديل سياسات الحصص — قرار إدارة مالي',
        ],

        // ═══════════ العملاء المحتملون ═══════════
        'leads.view' => [
            'module' => 'leads',
            'group' => 'العملاء المحتملون',
            'description' => 'شوف العملاء المحتملين',
        ],
        'leads.create' => [
            'module' => 'leads',
            'group' => 'العملاء المحتملين',
            'description' => 'تسجيل عميل محتمل جديد',
        ],
        'leads.update' => [
            'module' => 'leads',
            'group' => 'العملاء المحتملين',
            'description' => 'تعديل بيانات العميل المحتمل أو حالته',
        ],

        // ═══════════ أولياء الأمور ═══════════
        'parents.view' => [
            'module' => 'parents',
            'group' => 'أولياء الأمور',
            'description' => 'شوف ملفات أولياء الأمور وربطهم بالطلاب',
        ],
        'parents.create' => [
            'module' => 'parents',
            'group' => 'أولياء الأمور',
            'description' => 'إنشاء ملف ولي أمر أو ربطه بطلاب',
        ],

        // ═══════════ الحضور ═══════════
        'attendance.view' => [
            'module' => 'attendance',
            'group' => 'الحضور',
            'description' => 'شوف سجلات الحضور',
        ],
        'attendance.update' => [
            'module' => 'attendance',
            'group' => 'الحضور',
            'description' => 'تسجيل حضور أو غياب أو تأخير',
        ],

        // ═══════════ المجموعات ═══════════
        'groups.view' => [
            'module' => 'groups',
            'group' => 'المجموعات',
            'description' => 'شوف المجموعات وأعضائها والطابور',
        ],
        'groups.manage' => [
            'module' => 'groups',
            'group' => 'المجموعات',
            'description' => 'إنشاء وتعديل وأرشفة المجموعات وقبول الأعضاء',
        ],

        // ═══════════ البرامج ═══════════
        'programs.view' => [
            'module' => 'programs',
            'group' => 'البرامج',
            'description' => 'شوف كتالوج البرامج — مفتوح للمعلم وولي الأمر',
        ],
        'programs.update' => [
            'module' => 'programs',
            'group' => 'البرامج',
            'description' => 'إنشاء وتعديل وحذف البرامج',
        ],
        'programs.manage' => [
            'module' => 'programs',
            'group' => 'البرامج',
            'description' => 'مستويات البرامج وربط المعلّمين بها',
        ],

        // ═══════════ التسعير ═══════════
        'pricing.view' => [
            'module' => 'pricing',
            'group' => 'التسعير',
            'description' => 'شوف الباقات والأسعار — عام',
        ],
        'pricing.plans.manage' => [
            'module' => 'pricing',
            'group' => 'التسعير',
            'description' => 'إنشاء وتعديل الباقات وأسعارها',
        ],

        // ═══════════ المالية ═══════════
        'finance.accounts.view' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'شوف الأرصدة والحسابات — صلاحية القراءة',
        ],
        'finance.accounts.manage' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'إنشاء حسابات وخزائن وتعديلها',
        ],
        'finance.movements.create' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'تسجيل تحصيل أو مصروف أو تحويل بين الحسابات',
        ],
        'finance.movements.reverse' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'عكس حركة مالية — ماالهاش رجعة، محتاج مدير',
        ],
        'finance.exchange_rates.manage' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'تسجيل سعر الصرف',
        ],
        'finance.periods.lock' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'قفل فترة مالية — بيمنع أي كتابة جديدة بعدها',
        ],
        'finance.periods.unlock' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'فتح فترة مقفلة — قرار الإدارة',
        ],
        'finance.payments.view' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'شوف المدفوعات',
        ],
        'finance.payments.create' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'تسجيل دفعة',
        ],
        'finance.payments.update' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'تعديل بيانات دفعة أو توزيعها على فواتير',
        ],
        'finance.payments.refund' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'عمل استرداد — بيلغي دفعة بتبقى معتمدة',
        ],
        'finance.refunds.approve' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'اعتماد الاسترداد فوق سقف الطلب — طالب الطلب مرفوض، أساليب، الموافقة التانية',
        ],
        'finance.expenses.view' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'شوف المصروفات — قراءة بس',
        ],
        'finance.expenses.create' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'تسجيل مصروف',
        ],
        // ⭐ PR 2.12 — اعتماد وصرف المصروف مرحلتين منفصلتين —
        // ومنشئ المصروف محروم من الاعتماد (حتى لو معاه الصلاحيتين)
        'finance.expenses.approve' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'اعتماد مصروف مُرسَل',
        ],
        'finance.expenses.pay' => [
            'module' => 'finance',
            'group' => 'المالية',
            'description' => 'صرف مصروف معتمد — بتسجل حركة مالية خارج',
        ],

        // ═══════════ المرتبات ═══════════
        'payroll.view' => [
            'module' => 'payroll',
            'group' => 'المرتبات',
            'description' => 'شوف كشوف المرتبات',
        ],
        'payroll.periods.manage' => [
            'module' => 'payroll',
            'group' => 'المرتبات',
            'description' => 'توليد واعتماد وإغلاق وفتح فترات المرتبات',
        ],
        'payroll.payments.create' => [
            'module' => 'payroll',
            'group' => 'المرتبات',
            'description' => 'صرف سطر مرتب — ماالهوش رجعة، محتاج مدير',
        ],
        // ⭐⭐ PR 2.10 — الاعتماد بيحوّل المستحق لكشف المرتبات
        'payroll.earnings.approve' => [
            'module' => 'payroll',
            'group' => 'المرتبات',
            'description' => 'اعتماد مستحقات المعلمين (فردي أو جماعي)',
        ],
        // ⭐⭐ PR 2.11 — تسويات دفتر المعلم: صلاحية لكل نوع
        'payroll.bonuses.create' => [
            'module' => 'payroll',
            'group' => 'المرتبات',
            'description' => 'تسجيل مكافأة لمعلم',
        ],
        'payroll.deductions.create' => [
            'module' => 'payroll',
            'group' => 'المرتبات',
            'description' => 'تسجيل خصم على معلم',
        ],
        'payroll.advances.create' => [
            'module' => 'payroll',
            'group' => 'المرتبات',
            'description' => 'تسجيل سلفة على المرتب',
        ],

        // ═══════════ التقارير ═══════════
        'reports.view' => [
            'module' => 'reports',
            'group' => 'التقارير',
            'description' => 'شوف التقارير الأكاديمية والمالية والحضور',
        ],

        // ⭐ PR 2.16 — التصدير صلاحية منفصلة عن المشاهدة: اللي بيتابع
        // الأرقام على الشاشة مش بالضرورة ياخد ملف كامل بالبيانات
        // (تصدير = نسخة خارجية ممكن تتسرّب).
        'reports.export' => [
            'module' => 'reports',
            'group' => 'التقارير',
            'description' => 'تصدير التقارير والفواتير والمدفوعات والمصروفات Excel/CSV',
        ],

        // ═══════════ الإعدادات ═══════════
        'settings.view' => [
            'module' => 'settings',
            'group' => 'الإعدادات',
            'description' => 'شوف إعدادات الأكاديمية',
        ],
        'settings.update' => [
            'module' => 'settings',
            'group' => 'الإعدادات',
            'description' => 'تعديل إعدادات الأكاديمية',
        ],
        'roles.view' => [
            'module' => 'roles',
            'group' => 'الأدوار',
            'description' => 'شوف الأدوار وصلاحياتها',
        ],
        'roles.update' => [
            'module' => 'roles',
            'group' => 'الأدوار',
            'description' => 'إنشاء أدوار وتعديل صلاحياتها',
        ],
        'audit.view' => [
            'module' => 'audit',
            'group' => 'السجل',
            'description' => 'شوف سجل العمليات — مين عمل إيه على مين',
        ],

        // ═══════════ الحسابات ═══════════
        'users.view' => [
            'module' => 'users',
            'group' => 'الحسابات',
            'description' => 'شوف الحسابات وأدوارها',
        ],
        'users.invite' => [
            'module' => 'users',
            'group' => 'الحسابات',
            'description' => 'دعوة حساب جديد — كلمة المرور بيختارها المدعو',
        ],
        'users.suspend' => [
            'module' => 'users',
            'group' => 'الحسابات',
            'description' => 'إيقاف حساب مؤقت أو طلب تغيير كلمة مروره',
        ],
        'users.archive' => [
            'module' => 'users',
            'group' => 'الحسابات',
            'description' => 'أرشفة حساب — نهائية',
        ],
        'users.roles.assign' => [
            'module' => 'users',
            'group' => 'الحسابات',
            'description' => 'تغيير دور حساب — ممنوع تسليم أقوى من اللي ماسكه',
        ],
    ],

    // ═══════════════════════════════════════════════════════════
    // خريطة التحويل: القديم (قائمة) → الجديد (قائمة)
    // ═══════════════════════════════════════════════════════════

    'migration' => [

        // ═══════════ طلاب ═══════════
        'students.view' => ['students.view'],
        'students.create' => ['students.create'],
        'students.edit' => ['students.update'],
        'students.delete' => ['students.delete'],

        // ═══════════ معلمون ═══════════
        'teachers.view' => ['teachers.view'],
        'teachers.create' => ['teachers.create'],
        'teachers.edit' => ['teachers.update'],
        'teachers.delete' => ['teachers.delete'],

        // ═══════════ حصص ═══════════
        'lessons.view' => ['lessons.view'],
        'lessons.create' => ['lessons.create'],
        'lessons.edit' => ['lessons.update'],
        'lessons.cancel' => ['lessons.cancel'],

        // ═══════════ عملاء محتملون ═══════════
        'leads.view' => ['leads.view'],
        'leads.create' => ['leads.create'],
        'leads.edit' => ['leads.update'],

        // ═══════════ أولياء أمور ═══════════
        'parents.view' => ['parents.view'],
        'parents.create' => ['parents.create'],

        // ═══════════ حضور ═══════════
        'attendance.view' => ['attendance.view'],
        'attendance.manage' => ['attendance.update'],

        // ═══════════ مجموعات ═══════════
        //
        // ⭐⭐ `groups.manage` كانت بتغطي **شوف** و **أدِر** بنفس
        // الاسم. موظف بيقرأ حالة المجموعة كان عنده نفس صلاحية اللي
        // بتسمح تمسح المجموعة.
        'groups.manage' => ['groups.view', 'groups.manage'],

        // ═══════════ برامج ═══════════
        'programs.view' => ['programs.view'],

        // ═══════════ تسعير ═══════════
        'pricing.view' => ['pricing.view'],
        'pricing.manage' => ['pricing.plans.manage'],

        // ═══════════ مالية ═══════════
        //
        // ⭐⭐ `finance.view` كانت **يتيمة** — في الكتالوج من أول يوم
        // ومش في أي راو. المقصود بيها كان `finance.accounts.view`.
        'finance.view' => ['finance.accounts.view'],

        'finance.accounts' => ['finance.accounts.manage'],

        // ⭐⭐ `finance.movements` كانت **بتعمل تسجيل وعكس** — وهي
        // أهم عملية في الفصل كله. الفصل لازم يكون في الخريطة.
        'finance.movements' => ['finance.movements.create'],
        'finance.reverse' => ['finance.movements.reverse'],
        'finance.exchange_rates' => ['finance.exchange_rates.manage'],
        'finance.periods.lock' => ['finance.periods.lock'],
        'finance.periods.unlock' => ['finance.periods.unlock'],

        // ⭐⭐ المدفوعات **مالية** — كانت `payments.*` جوه وحدة
        // مستقلة، فالمحاسب كان بيلاقي `payments.create` و
        // `finance.movements` في وحدتين مختلفتين ومحدش بيعرف إنهم
        // نفس العائلة.
        'payments.view' => ['finance.payments.view'],
        'payments.create' => ['finance.payments.create'],
        'payments.edit' => ['finance.payments.update'],
        'payments.refund' => ['finance.payments.refund'],
        // ⭐ PR 2.8 — الموافقة التانية على الاسترداد الكبير
        'refunds.approve' => ['finance.refunds.approve'],
        // ⭐ المصروفات مالية كمان — نفس السبب
        'expenses.view' => ['finance.expenses.view'],
        'expenses.create' => ['finance.expenses.create'],
        // ⭐ PR 2.12 — الاعتماد والصرف أسمائهم الأصلية finance.*
        'expenses.approve' => ['finance.expenses.approve'],
        'expenses.pay' => ['finance.expenses.pay'],

        // ═══════════ مرتبات ═══════════
        //
        // ⭐⭐ `payroll.manage` كانت **بتعمل كل حاجة**: توليد واعتماد
        // وتقفيل **وصرف**. والصرف وحده هو العملية اللي ماالهاش
        // رجعة — فلازم صلاحية لوحدها.
        'payroll.view' => ['payroll.view'],
        'payroll.manage' => ['payroll.periods.manage', 'payroll.payments.create'],

        // ═══════════ تقارير ═══════════
        'reports.view' => ['reports.view'],

        // ═══════════ إعدادات + أدوار + سجل ═══════════
        //
        // ⭐⭐⭐ **أكبر split في المشروع.** `settings.manage` كانت
        // بتغطي **خمس** وظائف: إعدادات + أدوار + سجل عمليات + تسعير +
        // تذكيرات.
        //
        // ⭐ والراوت الوحيد اللي كان بيطلبها كان **سجل العمليات** —
        // يعني أي حد يقدر يعدّل الأدوار كان بيقدر يشوف كل العمليات
        // بتاعت الأكاديمية. وده بالظبط اللي خلّى
        // `RoleIsolationTest` يفشل في سيناريو الإعداد.
        'settings.manage' => [
            'settings.view',
            'settings.update',
            'roles.view',
            'roles.update',
            'audit.view',
        ],

        // ═══════════ أرقام الهواتف ═══════════
        //
        // ⭐ `phones.manage` كانت **بلا وحدة** خالص — الاسم بيقول
        // «إphones؟». وهي `StudentPhoneController`، يعني ملك الطلاب.
        'phones.manage' => ['students.phones.manage'],

        // ═══════════ الحسابات ═══════════
        'users.view' => ['users.view'],
        'users.invite' => ['users.invite'],
        'users.suspend' => ['users.suspend'],
        'users.archive' => ['users.archive'],
        'users.roles.assign' => ['users.roles.assign'],
    ],

    // ═══════════════════════════════════════════════════════════
    // صلاحيات **مش متحوّلة** من حاجة — جديدة بالكامل
    // ═══════════════════════════════════════════════════════════

    /*
    | ⭐⭐⭐ ليه القائمة دي موجودة أصلاً
    |
    | ⭐ اختبار «مافيش صلاحية يتيمة» بيفرّق بين نوعين:
    |
    |   • صلاحية **موجودة** واللي بيحميها راو من زمان
    |   • صلاحية **لسه مفيش حاجة بتحميها**
    |
    | ⭐ النوع التاني مش غلط — شرط إنه يكون **مكتوب**. من غير
    | القائمة دي، أي صلاحية جديدة بتعدّي والـPR 1.7 بيلاقي راو
    | محمي بصلاحية مش بتاعته، ومحدش يعرف إن اللي ناقص.
    |
    | ⭐⇡⇡⇡ **الحالة الحقيقية اللي كشفتها:** راوت تعديل البرامج
    | محمية بصلاحيات **وحدات تانية خالص**:
    |
    |   POST/PUT/DELETE /programs          → `settings.manage`
    |   POST/PUT/DELETE /programs/{id}/levels → `lessons.edit`   ⭐
    |
    | ⭐ يعني عشان تعدّل مستوى برنامج، لازم يكون عندك صلاحية
    | **حصص**. ده بالظبط اللي «اسم واحد = صلاحية واحدة = وظيفة
    | واحدة» بيمنعه — والـPR 1.7 هو اللي ينقل الراوتين دول
    | لـ`programs.update` و`programs.manage`.
    |
    | ⭐ فالتسجيل هنا بيقول: «دي مش متحوّلة من `lessons.edit` —
    | الراوت كان غلط، والصلاحية الجديدة اسمها صح».
    */
    'introduced' => [
        'programs.update' => 'راو تعديل البرامج كان محمي بـ`settings.manage` — صلاحية من وحدة تانية',
        'programs.manage' => 'راو مستويات البرنامج كان محمي بـ`lessons.edit` — صلاحية من وحدة تانية',
    ],

    // ═══════════════════════════════════════════════════════════
    // مجموعات الوحدات — للعرض في شاشة الأدوار
    // ═══════════════════════════════════════════════════════════

    'groups' => [
        'الطلاب' => ['students', 'parents', 'leads'],
        'التعليم' => ['teachers', 'lessons', 'programs', 'groups'],
        'المالية' => ['finance', 'payroll', 'pricing'],
        'الإدارة' => ['attendance', 'reports', 'settings', 'roles', 'audit', 'users'],
    ],
];
