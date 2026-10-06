<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ باقات **الحصة الواحدة** — ٣ مدد (٣٠/٤٥/٦٠ دقيقة).
 *
 * الباقات دي نظام مختلف عن الـ ٢٨: بتتشرى **حصة واحدة** مش عدد
 * حصص. عشان كده `lessons_count = 1` و `billing_type = per_lesson`.
 *
 * ليه جدول رابع في صفحة الأسعار؟ لأن الأدمن طلب يقدر يعدّل سعر
 * الحصة الواحدة على طول — ومكانش هيعرف لو مش ظاهر.
 *
 * ⚠️ **الأسعار دي أرقام مؤقتة** — الأدمن هيعدّلها من زرار التعديل
 * على نفس الصفحة. سبناها قريبة من الباقتين القدامى (٦٠ ج للـ ٣٠
 * دقيقة، ٩٠ ج للـ ٤٥) والـ ٦٠ دقيقة اتقدّر مؤقتًا بـ ١٢٠ ج.
 *
 * الباقتان القدامى اللي ليهما برنامج (`#7` و `#8`) سايبين زي ما
 * هما — متوقفين ومش بيظهروا. دول داتا تجريبية ليها اشتراكات.
 */
return new class extends Migration
{
    /** @var array<int, int> المدة ← السعر المؤقت */
    private const PRICES = [30 => 60, 45 => 90, 60 => 120];

    public function up(): void
    {
        $orgId = DB::table('organizations')->orderBy('id')->value('id');

        // القاعدة لسه فاضية — مفيش أكاديمية نضيفلها
        if (! $orgId) {
            return;
        }

        $now = now();

        foreach (self::PRICES as $duration => $price) {
            DB::table('subscription_plans')->insert([
                'organization_id' => $orgId,
                // ⭐ null = مشتركة — زي الـ ٢٨
                'program_id' => null,
                'name' => "{$duration} دقيقة - حصة واحدة",
                'billing_type' => 'per_lesson',
                'price' => $price,
                'currency' => 'EGP',
                'lessons_count' => 1,
                'lesson_duration_minutes' => $duration,
                'status' => 'active',
                'category' => 'single',
                // ٤٠٠٠ = بعد الـ ٢٨ (أعلى sort_order = ٣٣) وبفرق
                // واضح، فالترتيب يفضل: تقليدي ← ذهبي ← مجموعات ← مفردة
                'sort_order' => 4000 + $duration,
                'description' => 'حصة واحدة منفردة',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // ⭐ المسار آمن: `category = 'single'` + `program_id IS NULL`
        // = الباقات اللي إننا أنشأناها في الـ `up` بالظبط.
        // الباقتان القدامى ليهما برنامج فمش هتتلمس.
        DB::table('subscription_plans')
            ->whereNull('program_id')
            ->where('category', 'single')
            ->where('billing_type', 'per_lesson')
            ->delete();
    }
};
