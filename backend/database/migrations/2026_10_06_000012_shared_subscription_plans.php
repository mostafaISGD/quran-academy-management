<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ الباقات بقت **مشتركة** بين البرامج.
 *
 * السبب: الباقة مبنية على **مدة الحصة** (٣٠/٤٥/٦٠ دقيقة) وعدد
 * الحصص (٤/٨/١٢/١٦) — مش على البرنامج. فلو كل باقة مربوطة ببرنامج،
 * الـ ٢٨ باقة هتتكرر ٦ مرات = ١٦٨ باقة، والطالب هيلاقي نفس
 * الباقة بالاسم بأسعار مختلفة حسب البرنامج.
 *
 * الربط بالبرنامج بيحصل عند **الاشتراك** (`subscriptions.program_id`)،
 * مش عند الباقة. وده الصح: «٤ حصص × ٣٠ دقيقة» معناها أربع حصص نص
 * ساعة، سواء تحفيظ ولا تجويد ولا تلاوة.
 *
 * ─────────────────────────────────────────────────────────────
 * ⚠️ ليه إعادة بناء الجدول؟
 *
 * `program_id` كان `NOT NULL`. في SQLite تغييره لـ nullable محتاج
 * إعادة بناء الجدول كامل (مفيش `ALTER COLUMN`).
 *
 * إعادة البناء آمنة هنا لأن:
 *   - الجدول صغير (١٠ صفوف)
 *   - `subscriptions` بيقرأ منه بـ FK، فنقل البيانات **قبل** الحذف
 *   - بنقفل الـ foreign_keys وقت الحذف، وبعدين بنرجّعها
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // ① أعمدة جديدة على الجدول القديم (program_id لسه NOT NULL)
        // ============================================================
        Schema::table('subscription_plans', function (Blueprint $table) {
            // traditional = تقليدي · golden = ذهبي
            // group = مجموعات · single = الحصة الواحدة (per_lesson)
            $table->enum('category', [
                'traditional', 'golden', 'group', 'single',
            ])->default('traditional')->after('billing_type');

            // الترتيب: ٣٠ قبل ٤٥ قبل ٦٠، تقليدي قبل ذهبي قبل مجموعات
            $table->unsignedSmallInteger('sort_order')->default(0)->after('category');
        });

        // ============================================================
        // ② إعادة بناء الجدول — `program_id` يصير nullable
        // ============================================================
        //
        // ⚠️ نقفل الـ foreign_keys قبل الحذف: `subscriptions` عندها
        // FK على `subscription_plans`، وSQLite مابيدعش يحذف جدول عليه
        // FK نشطة.
        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::create('subscription_plans_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // ⭐ بقى اختياري — الباقة مش بتتبع برنامج
            $table->foreignId('program_id')->nullable()->constrained('programs')->cascadeOnDelete();

            $table->string('name');
            $table->enum('billing_type', ['monthly', 'per_lesson', 'custom']);
            $table->decimal('price', 12, 2);
            $table->char('currency', 3);
            $table->unsignedSmallInteger('lessons_count')->nullable();
            $table->unsignedSmallInteger('lesson_duration_minutes')->default(30);
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('category', ['traditional', 'golden', 'group', 'single'])->default('traditional');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // نقل البيانات القديمة — الـ `category` بتتقفّر من `billing_type`
        DB::statement('
            INSERT INTO subscription_plans_new
                (id, organization_id, program_id, name, billing_type, price,
                 currency, lessons_count, lesson_duration_minutes, duration_days,
                 status, category, sort_order, description,
                 created_at, updated_at, deleted_at)
            SELECT
                id, organization_id, program_id, name, billing_type, price,
                currency, lessons_count, lesson_duration_minutes, duration_days,
                status,
                CASE WHEN billing_type = \'per_lesson\' THEN \'single\' ELSE \'traditional\' END,
                0,
                description, created_at, updated_at, deleted_at
            FROM subscription_plans
        ');

        Schema::drop('subscription_plans');
        Schema::rename('subscription_plans_new', 'subscription_plans');

        DB::statement('PRAGMA foreign_keys = ON');

        // ============================================================
        // ③ الفهارس (الـ rename بيضيّعها)
        // ============================================================
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->index(['category', 'sort_order'], 'plans_category_sort_idx');
            $table->index(['status', 'program_id'], 'plans_status_program_idx');
        });

        // ============================================================
        // ④ الباقات القديمة → `inactive`
        // ============================================================
        //
        // الـ ١٠ باقات القديمة (٤٠٠، ٦٥٠، ٤٥٠، ٧٥٠...) ليها ١١٩
        // اشتراك مربوطين بيها. لو حذفناها، الـ FK cascade هيمسح
        // الاشتراكات. بنخليها `inactive` — بتظهر بحالة «متوقفة».
        DB::table('subscription_plans')
            ->whereNotNull('program_id')
            ->update(['status' => 'inactive']);

        // ============================================================
        // ⑤ البيانات: ٢٨ باقة جديدة مشتركة
        // ============================================================
        //
        // ⭐ الأرقام دي **ثابتة في الكود** — مش في ملف `data/`.
        // السبب: ده **جدول أسعار** (قرار تجاري)، والأسعار اللي في
        // ملف البيانات تُنسى بعد سنة. هنا واضحة ومقصودة.
        //
        //   | الفئة   | المدة | ٤ حصص | ٨ حصص | ١٢ حصة | ١٦ حصة |
        //   |---------|-------|-------|-------|--------|--------|
        //   | تقليدي | ٣٠ د  | ٢٠٠  | ٣٥٠  | ٥٠٠    | ٦٥٠    |
        //   | تقليدي | ٤٥ د  | ٢٨٠  | ٥٠٠  | ٧٠٠    | ٩٠٠    |
        //   | تقليدي | ٦٠ د  | ٣٥٠  | ٦٢٠  | ٨٨٠    | ١١٠٠   |
        //   | ذهبي    | ٣٠ د  | ٤٠٠  | ٧٠٠  | ١٠٠٠   | ١٣٠٠   |
        //   | ذهبي    | ٤٥ د  | ٦٠٠  | ١٠٠٠ | ١٤٠٠   | ١٨٠٠   |
        //   | ذهبي    | ٦٠ د  | ٧٠٠  | ١٢٠٠ | ١٨٠٠   | ٢٢٠٠   |
        //   | مجموعات | ٦٠ د  | ١٥٠  | ٢٥٠  | ٣٥٠    | ٤٥٠    |
        //
        // سعر المجموعات أرخص لأن الحصة **جماعية** — الـ
        // `category = 'group'` هو اللي بيقول للواجهة تعرضها كده،
        // مش السعر نفسه.

        $prices = [
            'traditional' => [
                30 => [4 => 200, 8 => 350, 12 => 500, 16 => 650],
                45 => [4 => 280, 8 => 500, 12 => 700, 16 => 900],
                60 => [4 => 350, 8 => 620, 12 => 880, 16 => 1100],
            ],
            'golden' => [
                30 => [4 => 400, 8 => 700, 12 => 1000, 16 => 1300],
                45 => [4 => 600, 8 => 1000, 12 => 1400, 16 => 1800],
                60 => [4 => 700, 8 => 1200, 12 => 1800, 16 => 2200],
            ],
            'group' => [
                60 => [4 => 150, 8 => 250, 12 => 350, 16 => 450],
            ],
        ];

        $labels = [
            'traditional' => 'تقليدي',
            'golden' => 'ذهبي',
            'group' => 'مجموعات',
        ];

        $categorySort = ['traditional' => 1, 'golden' => 2, 'group' => 3];
        $durationSort = [30 => 1, 45 => 2, 60 => 3];

        $rows = [];
        $orgId = DB::table('organizations')->value('id') ?? 1;
        $now = now();

        // ⚠️ لو القاعدة فاضية (migrations بتتحمل **قبل** أي seed)،
        // `organizations` مافيهاش صفوف → الـ FK هترفض الـ ٢٨ سطر.
        //
        // في الـ production فيه أكاديمية، بس في بيئة الاختبار
        // (`:memory:`) الـ migrations بتتحمل لحالها. فبنضمن إن
        // organization موجودة.
        if (! DB::table('organizations')->where('id', $orgId)->exists()) {
            DB::table('organizations')->insert([
                'id' => $orgId,
                'name' => 'الأكاديمية',
                'slug' => 'academy',
                'default_currency' => 'EGP',
                'default_timezone' => 'Africa/Cairo',
                'status' => 'active',
            ]);
        }

        foreach ($prices as $category => $durations) {
            foreach ($durations as $duration => $byCount) {
                foreach ($byCount as $count => $price) {
                    $rows[] = [
                        'organization_id' => $orgId,
                        'program_id' => null,  // ⭐ مشتركة
                        'name' => "{$duration} دقيقة - {$count} حصص ({$labels[$category]})",
                        'billing_type' => 'monthly',
                        'price' => $price,
                        'currency' => 'EGP',
                        'lessons_count' => $count,
                        'lesson_duration_minutes' => $duration,
                        'status' => 'active',
                        'category' => $category,
                        'sort_order' => $categorySort[$category] * 10 + $durationSort[$duration],
                        'description' => $category === 'group' ? 'حصة جماعية بسعر مخفّض' : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        DB::table('subscription_plans')->insert($rows);
    }

    public function down(): void
    {
        // ⚠️ لازم نشيل الفهارس **قبل** الأعمدة، وبـ try/catch.
        //
        // في SQLite الـ index بيولّد trigger بيشير للعمود. لو نضّينا
        // العمود والـ index لسه موجود: «no such column: category».
        //
        // والـ `try`؟ لأن الـ `rename` في `up` كان بيمسح الفهارس
        // القديمة ونعيد بناءها — فلو الـ rollback اتنفّذ مرتين (أو
        // بعد `up` جزئي) مش لازم يطيح. الفهرس لو مش موجود معناه
        // إننا خلصنا شغله.
        foreach (['plans_category_sort_idx', 'plans_status_program_idx'] as $index) {
            try {
                Schema::table('subscription_plans', fn (Blueprint $t) => $t->dropIndex($index));
            } catch (\Throwable) {
                // الفهرس مش موجود — تمام، اللي مهم هو العمود
            }
        }

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['category', 'sort_order']);
        });

        // ⚠️⭐ الباقات الـ ٢٨ اللي `up` أنشأها لازم تترجع.
        //
        // من غير ده: `rollback` بعدين `migrate` تاني = ٥٦ باقة
        // مشتركة (٢٨ + ٢٨). والـ prices API هيبعت تكرار.
        //
        // التعرّف عليها: `program_id IS NULL` — دي الباقات
        // المشتركة. الباقات القديمة ليها برنامج، فمفيش خطر
        // نمسح واحدة منها.
        //
        // ⚠️ `lessons_count IS NOT NULL` كشرط إضافي احتياطي: لو
        // لحد ما عمل باقة مشتركة بـ `lessons_count = null`،
        // ما نمسحهاش — دي مش من بتوعنا.
        DB::table('subscription_plans')
            ->whereNull('program_id')
            ->whereNotNull('lessons_count')
            ->delete();

        // الباقات القديمة رجعت `active` زي ما كانت قبل الترحيل
        DB::table('subscription_plans')
            ->whereNotNull('program_id')
            ->update(['status' => 'active']);
    }
};