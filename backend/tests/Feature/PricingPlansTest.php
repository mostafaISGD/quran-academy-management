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

    private function sharedPlans()
    {
        return SubscriptionPlan::whereNull('program_id')
            ->where('status', 'active')
            ->get();
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
        $plans = $this->sharedPlans()->keyBy(
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

    public function test_there_are_exactly_28_shared_plans(): void
    {
        $this->assertCount(28, self::EXPECTED, 'الجدول نفسه ٢٨ سطر');
        $this->assertCount(28, $this->sharedPlans());
    }

    public function test_no_extra_plans_beyond_the_table(): void
    {
        $expected = collect(self::EXPECTED)->map(
            fn ($e) => "{$e[0]}:{$e[1]}:{$e[2]}"
        );

        $actual = $this->sharedPlans()->map(
            fn ($p) => "{$p->category}:{$p->lesson_duration_minutes}:{$p->lessons_count}"
        );

        $extra = $actual->diff($expected);

        $this->assertCount(0, $extra, 'في باقات زيادة مش في الجدول: '.$extra->implode(', '));
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
            28,
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

    /** الاسم فيه المدة والعدد والفئة — عشان يبقى واضح */
    public function test_plan_names_are_self_describing(): void
    {
        foreach ($this->sharedPlans() as $p) {
            $this->assertStringContainsString(
                (string) $p->lesson_duration_minutes, $p->name,
                "الاسم «{$p->name}» مفقود منه المدة"
            );
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
            ->assertJsonPath('counts.total', 28);
    }

    public function test_pricing_groups_are_split_by_category(): void
    {
        $r = $this->getJson('/api/pricing')->assertOk();

        $labels = collect($r->json('groups'))->pluck('label')->all();

        $this->assertSame(['تقليدي', 'ذهبي', 'مجموعات'], $labels);

        // ⭐ المجموع = ٢٨ (١٢ تقليدي + ١٢ ذهبي + ٤ مجموعات)
        $total = collect($r->json('groups'))->sum(fn ($g) => count($g['plans']));
        $this->assertSame(28, $total, "المجموع طلع {$total}");
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

        // ⭐ ٢٨ سطر + سطر العناوين
        $this->assertCount(29, array_filter(explode("\r\n", trim($csv))));
    }
}
