<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Support\Money;
use RuntimeException;
use Tests\TestCase;

/**
 * ⭐ PR 2.5 — أسعار الصرف.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ القاعدة 3: «كل مبلغ يتخزن بعملته الأصلية ومعاه سعر الصرف
 * وقت العملية، ولا نعيد الحساب بأثر رجعي».
 *
 * ⭐原因的: تقرير مارس يستعمل سعر مارس. فلو غيّرنا سعر النهارده،
 * كل التقارير القديمة بتتغيّر — وده **إعادة حساب بأثر رجعي**.
 */
class ExchangeRatePrecisionTest extends TestCase
{
    private function rate(string $from, string $to, string $rate, string $date): ExchangeRate
    {
        return ExchangeRate::create([
            'from_currency' => $from,
            'to_currency' => $to,
            'rate' => $rate,
            'valid_date' => $date,
            'created_by' => $this->makeUserWithRole('accountant', ['finance.view'])->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // ① السعر بيتخزن كعدد صحيح (مفيش float)
    // ═══════════════════════════════════════════════════════════

    public function test_the_rate_is_stored_as_an_integer(): void
    {
        $rate = $this->rate('USD', 'EGP', '48.500000', '2026-10-01');

        $raw = \DB::table('exchange_rates')->where('id', $rate->id)->value('rate');

        $this->assertIsInt($raw, 'سعر الصرف لازم int — مش float');
    }

    // ═══════════════════════════════════════════════════════════
    // ② التحويل بمعاملات `Money` — مافيش float في التوقيع
    // ═══════════════════════════════════════════════════════════

    public function test_convert_takes_and_returns_money(): void
    {
        $this->rate('USD', 'EGP', '48.500000', '2026-10-01');

        $service = app(\App\Services\ExchangeRateService::class);

        $result = $service->convert(Money::of(10000, 'USD'), 'EGP', '2026-10-01');

        $this->assertInstanceOf(Money::class, $result);
        $this->assertSame('EGP', $result->currency);
        // ⭐ 100 دولار × 48.5 = 4850 جنيه = 485000 قرش
        $this->assertSame(485000, $result->amount);
    }

    public function test_same_currency_returns_the_same_money_untouched(): void
    {
        $service = app(\App\Services\ExchangeRateService::class);

        $result = $service->convert(Money::of(12345, 'EGP'), 'EGP');

        $this->assertSame(12345, $result->amount);
        $this->assertSame('EGP', $result->currency);
    }

    // ═══════════════════════════════════════════════════════════
    // ③ التحويل يدوّر بــ `Money::multiply` (half-up، int)
    // ═══════════════════════════════════════════════════��═══════

    public function test_the_rounding_is_half_up_on_integers(): void
    {
        // ⭐ 33.33 دولار × 48.5 = 1616.505 → 1617 جنيه (قرش: 161650.5
        // → half-up = 161651). ده بيثبت إن التقريب **محدّد** ومش
        // بيتبخّر من float.
        $this->rate('USD', 'EGP', '48.500000', '2026-10-01');

        $result = app(\App\Services\ExchangeRateService::class)
            ->convert(Money::of(3333, 'USD'), 'EGP', '2026-10-01');

        $this->assertSame(161651, $result->amount, 'التقريب half-up لازم يكون محدّد');
    }

    // ═══════════════════════════════════════════════════════════
    // ④ السعر الساري في التاريخ (مفيش أثر رجعي)
    // ═══════════════════════════════════════════════════════════

    public function test_the_rate_in_effect_on_the_date_is_used(): void
    {
        $this->rate('USD', 'EGP', '48.000000', '2026-01-01');
        $this->rate('USD', 'EGP', '50.000000', '2026-10-01');

        $service = app(\App\Services\ExchangeRateService::class);

        // ⭐ مارس يستعمل سعر مارس (48)، مش سعر النهارده (50)
        $march = $service->convert(Money::of(10000, 'USD'), 'EGP', '2026-03-15');

        $this->assertSame(480000, $march->amount);

        // ⭐ وأكتوبر يستعمل سعر أكتوبر (50)
        $october = $service->convert(Money::of(10000, 'USD'), 'EGP', '2026-10-15');

        $this->assertSame(500000, $october->amount);
    }

    // ═══════════════════════════════════════════════════════════
    // ⑤ السعر العكسي لو الإدارة سجّلت الزوج بالعكس
    // ═══════════════════════════════════════════════════════════

    public function test_the_inverse_rate_is_used_when_only_it_is_recorded(): void
    {
        $this->rate('EGP', 'USD', '0.020000', '2026-10-01');

        $result = app(\App\Services\ExchangeRateService::class)
            ->convert(Money::of(10000, 'USD'), 'EGP', '2026-10-01');

        // ⭐ 100 دولار ÷ 0.02 = 5000 جنيه = 500000 قرش
        $this->assertSame(500000, $result->amount);
    }

    // ═══════════════════════════════════════════════════════════
    // ⑥ مفيش سعر = خطأ صريح (مش صفر)
    // ═══════════════════════════════════════════════════════════

    public function test_a_missing_rate_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);

        // ⭐ صفر هينتج فاتورة بـ 0 جنيه — أأمن من صفر صامت؟
        // لأ. الصفر الصامت هو **الأسوأ** — بيعدّي في التقارير.
        app(\App\Services\ExchangeRateService::class)
            ->convert(Money::of(10000, 'USD'), 'EUR', '2026-10-01');
    }

    // ═══════════════════════════════════════════════════════════
    // ⑦ صفر = رفض (قسمة على صفر)
    // ═══════════════════════════════════════════════════════════

    public function test_a_zero_rate_is_refused(): void
    {
        $this->rate('EGP', 'USD', '0.000000', '2026-10-01');

        $this->expectException(RuntimeException::class);

        app(\App\Services\ExchangeRateService::class)
            ->convert(Money::of(10000, 'USD'), 'EGP', '2026-10-01');
    }
}