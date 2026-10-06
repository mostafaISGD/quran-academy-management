<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use Tests\TestCase;

/**
 * ⭐ جدول الأسعار — ٢٨ باقة مشتركة.
 *
 * الباقات مبنية على **مدة الحصة** (٣٠/٤٥/٦٠) و**عدد الحصص**
 * (٤/٨/١٢/١٦)، مش على البرنامج. فالربط بالبرنامج بيحصل عند
 * الاشتراك.
 *
 * ⭐ الباقات دي بتيجي من **الـ migration نفسها** — مش من `setUp`.
 *
 * السبب: لو الاختبار زرعها بنفسه، هيبقى يختبرنسخة تانية مش اللي
 * هيشتغل في الـ production. هنا بنقرا اللي الـ migration حطته فعلاً.
 *
 * يعني الاختبار ده بيمسك **الأسعار الحقيقية** — لو حد عدّل رقم في
 * الـ migration، الاختبار بيفشل فوراً.
 */
class PricingPlansTest extends TestCase
{
    /**
     * ⭐ الجدول المطلوب بالظبط.
     *
     * ١٢ تقليدي + ١٢ ذهبي + ٤ مجموعات = ٢٨.
     */
    private const EXPECTED = [
        // traditional
        ['traditional', 30, 4, 200],  ['traditional', 30, 8, 350],
        ['traditional', 30, 12, 500], ['traditional', 30, 16, 650],
        ['traditional', 45, 4, 280],  ['traditional', 45, 8, 500],
        ['traditional', 45, 12, 700], ['traditional', 45, 16, 900],
        ['traditional', 60, 4, 350],  ['traditional', 60, 8, 620],
        ['traditional', 60, 12, 880], ['traditional', 60, 16, 1100],
        // golden
        ['golden', 30, 4, 400],  ['golden', 30, 8, 700],
        ['golden', 30, 12, 1000], ['golden', 30, 16, 1300],
        ['golden', 45, 4, 600],  ['golden', 45, 8, 1000],
        ['golden', 45, 12, 1400], ['golden', 45, 16, 1800],
        ['golden', 60, 4, 700],  ['golden', 60, 8, 1200],
        ['golden', 60, 12, 1800], ['golden', 60, 16, 2200],
        // group
        ['group', 60, 4, 150],  ['group', 60, 8, 250],
        ['group', 60, 12, 350], ['group', 60, 16, 450],
    ];

    /**
     * كل الباقات المشتركة النشطة — ٢٨ باقة + ٣ حصة مفردة.
     */
    private function sharedPlans()
    {
        return SubscriptionPlan::whereNull('program_id')
            ->where('status', 'active')
            ->get();
    }

    /**
     * ⭐ باقات عدد الحصص بس (٢٨) — **من غير** الحصة المفردة.
     *
     * ليش منفصلة؟ لأن «مفيش زيادة عن الجدول» لازم يقارن بالجدول
     * الصح. الحصة المفردة نظام مختلف، فلو دخلت في المقارنة لكان
     * الاختبار بيقول «في زيادة» وهي زيادة مقصودة.
     */
    private function packages()
    {
        return $this->sharedPlans()->where('category', '!=', 'single');
    }

    /** ⭐ باقات الحصة الواحدة (٣ مدد) */
    private function singleLessonPlans()
    {
        return $this->sharedPlans()->where('category', 'single');
    }

    // ============================================================
    // ⭐ كل سعر مطابق للجدول
    // ============================================================

    /**
     * ⭐ كل صف في الجدول موجود بنفس السعر بالظبط.
     *
     * ده أهم اختبار في الملف: لو حد غيّر سعر في الـ migration،
     * الاختبار بيفشل فورًا.
     */
    public function test_every_expected_price_exists_exactly(): void
    {
        $plans = $this->packages()->keyBy(
            fn ($p) => "{$p->category}:{$p->lesson_duration_minutes}:{$p->lessons_count}"
        );

        foreach (self::EXPECTED as [$category, $duration, $count, $price]) {
            $key = "{$category}:{$duration}:{$count}";

            $this->assertTrue(
                $plans->has($key),
                "الباقة مفقودة: {$category} {$duration}د {$count} حصص"
            );

            $this->assertEquals(
                $price,
                (float) $plans[$key]->price,
                "السعر غلط لـ {$category} {$duration}د {$count} حصص"
            );
        }
    }

    /** ⭐ ٢٨ باقة + ٣ حصة مفردة = ٣١ */
    public function test_there_are_exactly_28_packages_and_3_single_lesson_plans(): void
    {
        $this->assertCount(28, self::EXPECTED, 'الجدول نفسه ٢٨ سطر');
        $this->assertCount(28, $this->packages());
        $this->assertCount(3, $this->singleLessonPlans());
        $this->assertCount(31, $this->sharedPlans());
    }

    public function test_no_extra_plans_beyond_the_table(): void
    {
        $expected = collect(self::EXPECTED)->map(
            fn ($e) => "{$e[0]}:{$e[1]}:{$e[2]}"
        );

        $actual = $this->packages()->map(
            fn ($p) => "{$p->category}:{$p->lesson_duration_minutes}:{$p->lessons_count}"
        );

        $extra = $actual->diff($expected);

        $this->assertCount(0, $extra, 'في باقات زيادة مش في الجدول: '.$extra->implode(', '));
    }

    // ============================================================
    // ⭐ باقات الحصة الواحدة
    // ===========================================================

    /**
     * ⭐ الحصة الواحدة: **٣ مدد**، وكل واحدة حصة واحدة بس.
     *
     * دي نظام مختلف عن الـ ٢٨ — الـ `billing_type` بتاعها
     * `per_lesson` مش `monthly`. لو نسينا الـ flag ده، الاشتراك
     * بيتحسب على أنه شهري وده غلط.
     */
    public function test_single_lesson_plans_cover_all_three_durations(): void
    {
        $durations = $this->singleLessonPlans()
            ->pluck('lesson_duration_minutes')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([30, 45, 60], $durations);
    }

    public function test_single_lesson_plans_are_one_lesson_and_per_lesson_billing(): void
    {
        foreach ($this->singleLessonPlans() as $p) {
            $this->assertSame(1, $p->lessons_count, 'الحصة الواحدة = حصة واحدة');
            $this->assertSame(
                'per_lesson', $p->billing_type,
                'نظام الحصة الواحدة لازم يفضل per_lesson'
            );
            $this->assertNull($p->program_id, 'ومشيرة لبرنامج — زي الباقي');
        }
    }

    /** ⭐ الحصة الواحدة **أرخص** من أي باقة عدد حصص لنفس المدة */
    public function test_single_lesson_is_cheaper_than_any_package_of_same_duration(): void
    {
        $single = $this->singleLessonPlans()->keyBy('lesson_duration_minutes');

        foreach ($this->packages() as $p) {
            $other = $single[$p->lesson_duration_minutes] ?? null;

            if ($other === null) {
                continue;
            }

            $this->assertTrue(
                (float) $other->price < (float) $p->price,
                "حصة واحدة {$p->lesson_duration_minutes}د لازم أرخص من {$p->name}"
            );
        }
    }

    /** ⭐ الأسعار المؤقتة — لو الأدمن عدّلها الاختبار يفشل */
    public function test_single_lesson_prices_start_at_the_temporary_values(): void
    {
        $prices = $this->singleLessonPlans()
            ->mapWithKeys(fn ($p) => [$p->lesson_duration_minutes => (float) $p->price])
            ->all();

        $this->assertSame([30 => 60.0, 45 => 90.0, 60 => 120.0], $prices);
    }

    // ============================================================
    // ⭐ الباقات مشتركة (program_id = null)
    // ============================================================

    /**
     * ⭐ الباقات الجديدة **مش مربوطة ببرنامج**.
     *
     * السبب: الباقة معناها «٤ حصص × ٣٠ دقيقة» — مش
     * «٤ حصص تحفيظ». الربط بالبرنامج بيحصل عند الاشتراك.
     */
    public function test_shared_plans_are_not_attached_to_a_program(): void
    {
        $this->assertSame(
            31,
            $this->sharedPlans()->whereNull('program_id')->count(),
            'الباقات المشتركة program_id = null'
        );
    }

    // ============================================================
    // الفئات
    // ============================================================

    public function test_group_plans_are_only_60_minutes(): void
    {
        $groups = $this->sharedPlans()->where('category', 'group');

        $this->assertCount(4, $groups);

        foreach ($groups as $p) {
            $this->assertSame(
                60, $p->lesson_duration_minutes,
                'المجموعات ٦٠ دقيقة بس'
            );
            $this->assertTrue($p->isGroup());
        }
    }

    /** ⭐ المجموعات **أرخص** من التقليدي لنفس المدة — لأن جماعية */
    public function test_group_plans_are_cheaper_than_traditional(): void
    {
        // ⚠️ المفتاح فيه **الفئة** — من غيرها كل الفئات هتتصادم
        // على نفس المفتاح (`60:4` مثلا) والنتايج هتبوظ.
        $byKey = $this->sharedPlans()->keyBy(
            fn ($p) => "{$p->category}:{$p->lesson_duration_minutes}:{$p->lessons_count}"
        );

        foreach ([4, 8, 12, 16] as $count) {
            $key = "60:{$count}";

            $this->assertTrue(
                (float) $byKey["group:{$key}"]->price < (float) $byKey["traditional:{$key}"]->price,
                "المجموعات ٦٠/ {$count} لازم أرخص من التقليدي"
            );
        }
    }

    /** الذهبي أغلى من التقليدي لنفس المدة والعدد */
    public function test_golden_plans_are_pricier_than_traditional(): void
    {
        $byKey = $this->sharedPlans()->keyBy(
            fn ($p) => "{$p->category}:{$p->lesson_duration_minutes}:{$p->lessons_count}"
        );

        foreach ([30, 45, 60] as $duration) {
            foreach ([4, 8, 12, 16] as $count) {
                $key = "{$duration}:{$count}";

                $this->assertTrue(
                    (float) $byKey["golden:{$key}"]->price > (float) $byKey["traditional:{$key}"]->price,
                    "الذهبي {$duration}د {$count} لازم أغلى من التقليدي"
                );
            }
        }
    }

    // ============================================================
    // ⭐ حماية الجدول من التكرار
    // ============================================================

    /**
     * ⭐ ما ينفعش يكون في **باقتين بنفس** (فئة + مدة + عدد).
     *
     * غير كده الواجهة هتعرض صفين بنفس الرقم في نفس المكان،
     * والأهل مش هيعرفوا يفرقوا.
     */
    public function test_no_duplicate_duration_and_count_within_a_category(): void
    {
        $dupes = $this->sharedPlans()
            ->groupBy(fn ($p) => "{$p->category}:{$p->lesson_duration_minutes}:{$p->lessons_count}")
            ->filter(fn ($group) => $group->count() > 1);

        $this->assertCount(
            0,
            $dupes,
            'في تكرار: '.$dupes->keys()->implode(', ')
        );
    }

    // ============================================================
    // الأسماء
    // ============================================================

    /**
     * الاسم فيه المدة والعدد — عشان يبقى واضح.
     *
     * ⚠️ الحصة المفردة استثناء: اسمها «٣٠ دقيقة - حصة واحدة» مش
     * «٣٠ دقيقة - ١ حصة». الرقم `1` في الاسم ده كان هيبقى مربك
     * («حصة واحدة» أوضح بكتير من «حصة ١»).
     */
    public function test_plan_names_are_self_describing(): void
    {
        foreach ($this->sharedPlans() as $p) {
            $this->assertStringContainsString(
                (string) $p->lesson_duration_minutes, $p->name,
                "الاسم «{$p->name}» مفقود منه المدة"
            );

            if ($p->category === 'single') {
                $this->assertStringContainsString(
                    'حصة واحدة', $p->name,
                    "باقة الحصة الواحدة «{$p->name}» لازم تقول إنها حصة واحدة"
                );
                continue;
            }

            $this->assertStringContainsString(
                (string) $p->lessons_count, $p->name,
                "الاسم «{$p->name}» مفقود منه عدد الحصص"
            );
            $this->assertStringContainsString(
                $p->category_label, $p->name,
                "الاسم «{$p->name}» مفقود منه الفئة"
            );
        }
    }

    // ============================================================
    // ⭐ الـ API — ومتاح **من غير تسجيل دخول**
    // ============================================================

    /**
     * ⭐ الأسعار **عامة**.
     *
     * الأسعار حاجة الأهالي بيسألوا عنها. لو حطيناها جوه
     * `auth:sanctum`، اللي بيسأل هيتقابل بـ «Unauthenticated» —
     * وده جواب غلط على سؤال صحيح.
     */
    public function test_pricing_is_public(): void
    {
        $this->getJson('/api/pricing')
            ->assertOk()
            ->assertJsonPath('counts.total', 31);
    }

    /**
     * ⭐ ٤ جداول بالترتيب ده بالظبط.
     *
     * الترتيب مقصود: الأرخص/الأشهر الأول. الحصة المفردة آخر واحد
     * لأنها **استثناء** مش الباقة الأساسية.
     */
    public function test_pricing_groups_are_split_by_category(): void
    {
        $r = $this->getJson('/api/pricing')->assertOk();

        $labels = collect($r->json('groups'))->pluck('label')->all();

        $this->assertSame(['تقليدي', 'ذهبي', 'مجموعات', 'حصة مفردة'], $labels);

        // ⭐ المجموع = ٣١ (١٢ تقليدي + ١٢ ذهبي + ٤ مجموعات + ٣ مفردة)
        $total = collect($r->json('groups'))->sum(fn ($g) => count($g['plans']));
        $this->assertSame(31, $total, "المجموع طلع {$total}");
    }

    /** ⭐ الحصة المفردة جدول مستقل بـ ٣ صفوف */
    public function test_pricing_returns_the_single_lesson_group(): void
    {
        $r = $this->getJson('/api/pricing')->assertOk();

        $group = collect($r->json('groups'))->firstWhere('category', 'single');

        $this->assertNotNull($group, 'مفيش جدول للحصة الواحدة');
        $this->assertCount(3, $group['plans']);
        $this->assertSame(
            [30, 45, 60],
            collect($group['plans'])->pluck('lesson_duration_minutes')->sort()->values()->all()
        );
    }

    /**
     * ⭐ شريط الترويسة مابقاش فيه «١ حصص».
     *
     * الحصة المفردة عددها ١، فلو دخلت في شريط أعداد الحصص كان
     * هيقول «١ حصص · ٤ حصص · ٨ حصص» — كلام مالوش معنى.
     */
    public function test_the_header_lesson_counts_exclude_the_single_lesson(): void
    {
        $r = $this->getJson('/api/pricing')->assertOk();

        $this->assertSame([4, 8, 12, 16], $r->json('counts.lessons'));
        $this->assertSame([30, 45, 60], $r->json('durations'));
    }

    /** ⭐ الأرقام المعروضة **إجمالية** — مفيش سعر للحصة */
    public function test_pricing_returns_total_price_not_per_lesson(): void
    {
        $r = $this->getJson('/api/pricing')->assertOk();

        $plans = collect($r->json('groups'))
            ->flatMap(fn ($g) => $g['plans']);

        foreach ($plans as $p) {
            $this->assertArrayNotHasKey('price_per_lesson', $p);
            $this->assertArrayHasKey('price', $p);
        }
    }

    // ============================================================
    // ⭐ الاشتراكات القديمة ما اتكسّرش
    // ============================================================

    /**
     * ⭐ الباقات القديمة (اللي ليها برنامج) لازم تفضل موجودة.
     *
     * فيها ١١٩ اشتراك مربوطين بيها. لو حذفناها، الـ FK cascade
     * هيمسح الاشتراكات.
     */
    public function test_legacy_plans_with_a_program_can_still_exist(): void
    {
        // ⭐ الباقة القديمة ليها برنامج — دي بتفضل موجودة ومربوطة.
        //
        // السبب: فيها اشتراكات مربوطين بيها. لو حذفناها، الـ FK
        // cascade هيمسح الاشتراكات.
        $plan = SubscriptionPlan::create([
            'organization_id' => $this->org->id,
            'program_id' => $this->makeProgram()->id,
            'name' => 'باقة قديمة',
            'billing_type' => 'monthly',
            'price' => 400,
            'currency' => 'EGP',
            'lessons_count' => 8,
            'lesson_duration_minutes' => 30,
            'status' => 'inactive',
            'category' => 'traditional',
            'sort_order' => 0,
        ]);

        // بتظهر في شاشة الأسعار؟ لأ — مشتركة بس
        $this->assertFalse($plan->isShared());

        $r = $this->getJson('/api/pricing')->assertOk();
        $ids = collect($r->json('groups'))
            ->flatMap(fn ($g) => $g['plans'])
            ->pluck('id');

        $this->assertNotContains(
            $plan->id, $ids->all(),
            'الباقة المربوطة ببرنامج ما بتظهرش في شاشة الأسعار العامة'
        );
    }

    /** ⭐ اشتراك على باقة قديمة ما يتكسّرش */
    public function test_a_subscription_on_a_legacy_plan_survives(): void
    {
        $plan = SubscriptionPlan::create([
            'organization_id' => $this->org->id,
            'program_id' => $this->makeProgram()->id,
            'name' => 'باقة قديمة',
            'billing_type' => 'monthly',
            'price' => 400,
            'currency' => 'EGP',
            'lessons_count' => 8,
            'lesson_duration_minutes' => 30,
            'status' => 'inactive',
            'category' => 'traditional',
        ]);

        $student = $this->makeStudent();
        $sub = $this->makeSubscription($this->makeProgram(), $student, ['plan_id' => $plan->id]);

        $this->assertSame(
            $plan->id,
            (int) $sub->fresh()->plan_id,
            'الاشتراك لسه شغال على الباقة القديمة'
        );

        // مفيش orphan
        $orphans = \Illuminate\Support\Facades\DB::table('subscriptions')
            ->whereNotIn('plan_id', SubscriptionPlan::pluck('id'))
            ->count();

        $this->assertSame(0, $orphans);
    }

    // ============================================================
    // CSV
    // ============================================================

    /** ⭐ الـ CSV فيه BOM — من غيره Excel بيفتح العربي غلط */
    public function test_csv_export_has_a_bom_for_arabic(): void
    {
        $res = $this->get('/api/pricing/export')->assertOk();

        // ⭐ مش streamed — الـ controller بيرجّع `response($csv)` عادي
        $csv = (string) $res->getContent();

        $this->assertStringStartsWith(
            "\xEF\xBB\xBF",
            $csv,
            'من غير BOM، Excel بيفتح العربي بترميز غلط'
        );

        // ⚠️ العناوين مطابقة لـ `PricingController::export()` بالظبط.
        // لو غيّرنا عمود، الاختبار يفشل — وده المطلوب.
        $this->assertStringContainsString('مدة الحصة', $csv);
        $this->assertStringContainsString('عدد الحصص', $csv);
        $this->assertStringContainsString('السعر', $csv);

        // ⭐ ٣١ سطر + سطر العناوين
        $this->assertCount(32, array_filter(explode("\r\n", trim($csv))));
    }

    // ============================================================
    // ⭐ تعديل السعر
    // ============================================================

    /**
     * ⭐ الأدمن يقدر يعدّل السعر، والصفحة بترجع الرقم النهائي.
     *
     * بنرجع `plan` من السيرفر (مش اللي بعته العميل) عشان السيرفر
     * هو اللي بيقرّر شكل الرقم.
     */
    public function test_admin_can_change_a_price(): void
    {
        $admin = $this->makeUserWithRole('admin', ['pricing.manage']);
        $plan = $this->packages()->where('category', 'traditional')
            ->where('lesson_duration_minutes', 30)
            ->where('lessons_count', 8)
            ->firstOrFail();

        $r = $this->putJson("/api/pricing/{$plan->id}", ['price' => 375], $this->authHeaders($admin));

        $r->assertOk()->assertJsonPath('plan.price', 375);

        $this->assertSame(375.0, (float) $plan->fresh()->price);
    }

    /** ⭐ السعر بيفضل **رقم** — مش نص ولا ٣٧٥٫٠٠ ج */
    public function test_the_returned_price_is_a_number_not_a_formatted_string(): void
    {
        $admin = $this->makeUserWithRole('admin', ['pricing.manage']);
        $plan = $this->packages()->firstOrFail();

        $r = $this->putJson("/api/pricing/{$plan->id}", ['price' => '399.5'], $this->authHeaders($admin));

        $r->assertOk();

        $this->assertIsFloat($r->json('plan.price'));
        $this->assertSame(399.5, $r->json('plan.price'));
    }

    /** ⭐ صفر مسموح — حصة مجانية ممكنة */
    public function test_zero_price_is_allowed(): void
    {
        $admin = $this->makeUserWithRole('admin', ['pricing.manage']);
        $plan = $this->packages()->firstOrFail();

        $this->putJson("/api/pricing/{$plan->id}", ['price' => 0], $this->authHeaders($admin))
            ->assertOk()
            ->assertJsonPath('plan.price', 0);
    }

    /** ⭐ السعر السالب مرفوض — برسالة عربية */
    public function test_negative_price_is_rejected_in_arabic(): void
    {
        $admin = $this->makeUserWithRole('admin', ['pricing.manage']);
        $plan = $this->packages()->firstOrFail();

        $this->putJson("/api/pricing/{$plan->id}", ['price' => -5], $this->authHeaders($admin))
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');

        $this->assertSame(
            'السعر مش بيقل عن صفر',
            $this->putJson("/api/pricing/{$plan->id}", ['price' => -5], $this->authHeaders($admin))
                ->json('errors.price.0')
        );

        // ⭐ والسعر ما اتغيّرش
        $this->assertNotSame(-5.0, (float) $plan->fresh()->price);
    }

    /** ⭐ من غير سعر خالص — مرفوض */
    public function test_price_is_required(): void
    {
        $admin = $this->makeUserWithRole('admin', ['pricing.manage']);
        $plan = $this->packages()->firstOrFail();

        $this->putJson("/api/pricing/{$plan->id}", [], $this->authHeaders($admin))
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');
    }

    // ============================================================
    // ⭐ الصلاحيات — السعر قرار تجاري
    // ============================================================

    /**
     * ⭐ العرض عام، **التعديل** محمي.
     *
     * لو الـ PUT بقى مفتوح زي الـ GET، يبقى أي حد يقدر يغيّر
     * أسعار الأكاديمية من غير حساب.
     */
    public function test_viewing_is_public_but_editing_is_not(): void
    {
        $this->getJson('/api/pricing')->assertOk();

        $plan = $this->packages()->firstOrFail();

        $this->putJson("/api/pricing/{$plan->id}", ['price' => 1])
            ->assertStatus(401);
    }

    /** ⭐ المحاسب والاستقبال **مش** يعدّلوا أسعار */
    public function test_non_admin_roles_cannot_change_a_price(): void
    {
        $plan = $this->packages()->firstOrFail();
        $before = (float) $plan->price;

        foreach (['accountant', 'reception', 'supervisor', 'teacher'] as $role) {
            $user = $this->makeUserWithRole($role, []);

            $this->putJson(
                "/api/pricing/{$plan->id}",
                ['price' => 999],
                $this->authHeaders($user)
            )->assertStatus(403);
        }

        $this->assertSame($before, (float) $plan->fresh()->price, 'السعر ما اتغيّرش');
    }

    /** ⭐ الباقة القديمة (ليها برنامج) مش بتتعدّل — فيها اشتراكات */
    public function test_a_plan_attached_to_a_program_cannot_be_edited(): void
    {
        $admin = $this->makeUserWithRole('admin', ['pricing.manage']);

        $plan = SubscriptionPlan::create([
            'organization_id' => $this->org->id,
            'program_id' => $this->makeProgram()->id,
            'name' => 'باقة قديمة',
            'billing_type' => 'monthly',
            'price' => 400,
            'currency' => 'EGP',
            'lessons_count' => 8,
            'lesson_duration_minutes' => 30,
            'status' => 'inactive',
            'category' => 'traditional',
        ]);

        $this->putJson("/api/pricing/{$plan->id}", ['price' => 1], $this->authHeaders($admin))
            ->assertStatus(422);

        $this->assertSame(400.0, (float) $plan->fresh()->price);
    }
}
