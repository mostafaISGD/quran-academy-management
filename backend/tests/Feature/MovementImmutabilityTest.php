<?php

namespace Tests\Feature;

use App\Models\AccountMovement;
use App\Models\FinancialAccount;
use App\Services\FinancialLedgerService;
use RuntimeException;
use Tests\TestCase;

/**
 * ⭐ PR 2.4 — الحركات المالية **مالهاش تُعديل ولا حذف**.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ القاعدة 7 من `AGENTS.md`:
 *   «السجلات المالية ما بتتعدّلش ولا بتتحذفش — التصحيح قيد
 *   عكسي.».
 *
 * ⭐ وليه بالذات؟ لأن السطر ده **مش بيانات** — ده تاريخ. ولو
 * حد قدر يعدّله، الدفاتر اللي الإدارة وقّعتها بقت قابلة للتغيير
 * بهدوء. والمصحّح الوحيد = **قيد عكسي** سطر جديد.
 */
class MovementImmutabilityTest extends TestCase
{
    private function ledger(): FinancialLedgerService
    {
        return app(FinancialLedgerService::class);
    }

    private function account(float $opening = 0.0): FinancialAccount
    {
        return FinancialAccount::create([
            'organization_id' => $this->org->id,
            'name' => 'صندوق',
            'type' => 'cash',
            'currency' => 'EGP',
            'opening_balance' => $opening,
            'is_active' => true,
        ]);
    }

    private function movement(FinancialAccount $account, float $amount, string $direction = 'in'): AccountMovement
    {
        return $this->ledger()->record(
            $account,
            $direction,
            $direction === 'in' ? 'income' : 'expense',
            $amount,
            now()->toDateString(),
        );
    }

    // ═══════════════════════════════════════════════════════════
    // ① التعديل ممنوع
    // ═══════════════════════════════════════════════════════════

    public function test_updating_a_movement_throws(): void
    {
        $movement = $this->movement($this->account(), 100.00);

        $this->expectException(RuntimeException::class);

        $movement->amount = 999999;
        $movement->save();
    }

    public function test_changing_only_the_direction_throws_too(): void
    {
        $movement = $this->movement($this->account(), 50.00);

        // ⭐ تغيير الاتجاه أخطر تعديل ممكن — بيقلب الحساب كله
        $this->expectException(RuntimeException::class);

        $movement->direction = 'out';
        $movement->save();
    }

    // ═══════════════════════════════════════════════════════════
    // ② الحذف ممنوع
    // ═══════════════════════════════════════════════════════════

    public function test_deleting_a_movement_throws(): void
    {
        $movement = $this->movement($this->account(), 100.00);

        $this->expectException(RuntimeException::class);

        $movement->delete();
    }

    // ═══════════════════════════════════════════════════════════
    // ③ الاستثناء الوحيد: العكسي
    // ═══════════════════════════════════════════════════════════

    public function test_the_only_correction_is_a_reversing_entry(): void
    {
        $account = $this->account(1000.00);

        $movement = $this->movement($account, 100.00);

        $this->ledger()->reverse($movement, 'غلط في المبلغ');

        // ⭐ الأصل **ما اتغيّرش**
        $this->assertSame(100.00, (float) $movement->fresh()->amount);
        $this->assertSame('in', $movement->fresh()->direction);

        // ⭐ والعكسي سطر **جديد**
        $reversal = AccountMovement::where('reverses_movement_id', $movement->id)->firstOrFail();

        $this->assertSame('out', $reversal->direction);
        $this->assertEquals(100.00, (float) $reversal->amount);

        // ⭐ والرصيد رجع لأصله بالظبط
        $this->assertEquals(
            1000.00,
            (float) $account->fresh()->balance(),
            'العكسي لازم يرجّع الرصيد لأصله بالظبط'
        );
    }

    // ═══════════════════════════════════════════════════════════
    // ④ الرصيد
    // ═══════════════════════════════════════════════════════════

    public function test_the_balance_is_opening_plus_movements(): void
    {
        $account = $this->account(1000.00);

        $this->movement($account, 500.00, 'in');
        $this->movement($account, 300.00, 'out');

        // ⭐ 1000.00 + 500.00 - 300.00 = 1200.00
        $this->assertEquals(1200.00, (float) $account->fresh()->balance());
    }

    public function test_a_movement_in_another_currency_is_rejected(): void
    {
        $account = $this->account();

        // ⭐ الحساب بالجنيه — الدولار مرفوض. ولو سمحنا، الرصيد
        // هيبقى خليط ماينفعش يتفسّر.
        $this->expectException(RuntimeException::class);

        $this->ledger()->record(
            $account,
            'in',
            'income',
            100.00,
            now()->toDateString(),
            currency: 'USD',
        );
    }
}