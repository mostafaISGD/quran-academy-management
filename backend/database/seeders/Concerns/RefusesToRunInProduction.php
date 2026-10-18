<?php

namespace Database\Seeders\Concerns;

/**
 * ⭐⭐ حارس الإنتاج — **بتركبه في كل seeder تجريبي**.
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه ده مهم
 *
 * كل الـdemo seeders بتعمل كلمات مرور ثابتة `'password'` وبيانات
 * وهمية (أحمد محمد، ٠١٠٠٠٠٠٠٠٠). لو واحدة فيهم اشتغلت على
 * الإنتاج بالغلط:
 *
 *   • كل الحسابات بقت بكلمة مرور **معروفة** → أي حد يدخل
 *   • بيانات فوضوية في جداول حقيقية
 *
 * ⭐ والـseeder اللي بيرفض يthrow مش بيحذف حاجة — بس بيمنع
 * الكتابة من الأساس. أأمن بكتير من التنظيف بعد الوقوع.
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه trait مش تكرار
 *
 * ٨ seeders. لو كل واحد عنده السطر، يبقى لازم نحدّث ٨ places
 * كل مرة نغيّر الرسالة. الـtrait = **مكان واحد** للحقيقة.
 *
 * ═══════════════════════════════════════════════════════════════
 * الاستخدام
 *
 * ```php
 * class TestdataSeeder extends Seeder
 * {
 *     use RefusesToRunInProduction;
 *
 *     public function run(): void { … }
 * }
 * ```
 *
 * @see \Tests\Feature\PasswordPolicyTest::test_every_demo_seeder_refuses_to_run_in_production
 */
trait RefusesToRunInProduction
{
    /**
     * ⭐ يناديها **أول سطر** في `run()`.
     *
     * @throws \RuntimeException في الإنتاج
     */
    protected function guardProduction(string $seederName): void
    {
        if (! app()->isProduction()) {
            return;
        }

        throw new \RuntimeException(
            "⛔ Seeder «{$seederName}» مرفوض في الإنتاج.\n"
            ."\n"
            ."الـseeders دي بتعمل بيانات تجريبية بكلمات مرور ثابتة "
            ."('password') وبيانات وهمية. تشغيلها على الإنتاج معناه:\n"
            ."  • حسابات بكلمة مرور معروفة\n"
            ."  • بيانات فوضوية في جداول حقيقية\n"
            ."\n"
            ."لو محتاج بيانات فعلاً على الإنتاج، استخدم الـmigrations "
            ."عادي وابدأ بـ`php artisan migrate --force` من غير seeders."
        );
    }
}