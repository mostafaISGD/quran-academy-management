<?php

namespace Tests\Feature;

use App\Models\AccountMovement;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\ExpenseCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * PR 2.12 — دورة المصروفات الكاملة.
 *
 * الوضع قبل كده:
 *
 * ① الفئة اختيارية في API — لو ماتبعتش بيتخطف أول فئة
 *    (`ExpenseCategory::first()`) والمصروف بيروح لفئة غلط.
 * ② مافيش مسار اعتماد/رفض/صرف — المصروفتعطل على `pending`
 *    للأبد، والحركة المالية بتتسجل `pending` وقت الإنشاء
 *    (لازم توست بعدين — ومفيش زرار بيوصّلها).
 * ③ مافيش مكافآت تعارض: مافيش علاقة حساب، ولا payee، ولا
 *    تدقيق اعتماد — والمتقدم بيقدر يعتمد ملو نفسه (لما
 *    المسار يوجود).
 * ④ مافيش مرفقات ولا إدارة فئات.
 * ⑤ الصرف الفعلي مش بيقلّل الرصيد (الحركة pending من
 *    الإنشاء مش posted).
 */
class ExpenseWorkflowTest extends TestCase
{
    private function creator()
    {
        return $this->makeUserWithRole('clerk', ['finance.expenses.create']);
    }

    private function approver()
    {
        return $this->makeUserWithRole('chief', [
            'finance.expenses.create', 'finance.expenses.approve',
        ]);
    }

    private function payer()
    {
        return $this->makeUserWithRole('treasurer', ['finance.expenses.pay']);
    }

    private function submittedExpense(int $creatorId): Expense
    {
        $category = $this->makeExpenseCategory();

        return Expense::create([
            'organization_id' => $this->org->id,
            'category_id' => $category->id,
            'amount' => 400,
            'currency' => 'EGP',
            'expense_date' => '2026-10-15',
            'description' => 'إيجار المكتب لشهر اكتوبر',
            'payee' => 'بقالة الجيران',
            'created_by' => $creatorId,
            'status' => 'submitted',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 1 — الفئة إجبارية: مفيش خطف أول فئة تلقائياً
    // ═══════════════════════════════════════════════════════════

    public function test_storing_an_expense_requires_an_explicit_category(): void
    {
        $clerk = $this->creator();

        $r = $this->postJsonAs('/api/expenses', [
            'amount' => 400,
            'expense_date' => '2026-10-15',
            'description' => 'كتب',
        ], $clerk);

        $r->assertStatus(422);
        $this->assertSame(0, Expense::count(), 'مفيش مصروف من غير فئة محددة');
    }

    // ═══════════════════════════════════════════════════════════
    // 2 — الدورة كاملة: اعتماد → صرف ⇒ حركة + رصيد + تقرير
    // ═══════════════════════════════════════════════════════════

    public function test_an_approved_and_paid_expense_reduces_the_balance(): void
    {
        $account = $this->makeAccount(['opening_balance' => 1000]);
        $clerk = $this->creator();
        $chief = $this->approver();
        $treasurer = $this->payer();

        $category = $this->makeExpenseCategory();
        $this->postJsonAs('/api/expenses', [
            'amount' => 400,
            'expense_date' => '2026-10-15',
            'description' => 'إيجار المكتب',
            'payee' => 'صاحب الشقة',
            'category_id' => $category->id,
        ], $clerk)->assertStatus(201);

        $expense = Expense::firstOrFail();
        $this->assertSame('submitted', $expense->status);

        // اعتماد من غير المنشئ
        $this->postJsonAs("/api/expenses/{$expense->id}/approve", [], $chief)->assertOk();
        $expense = $expense->fresh();
        $this->assertSame('approved', $expense->status);
        $this->assertSame((string) $chief->id, (string) $expense->approved_by);

        // ⭐ قبل الاعتماد/بعد الاعتماد: رصيد الحساب زي ما هو
        // (الحركة المالية وقت الـ pay — مش وقت الاعتماد ولا وقت التحريك)
        $this->assertEquals(1000.0, $account->fresh()->balance());

        // صرف
        $this->postJsonAs("/api/expenses/{$expense->id}/pay", [
            'financial_account_id' => $account->id,
        ], $treasurer)->assertOk();

        $expense = $expense->fresh();
        $this->assertSame('paid', $expense->status);
        $this->assertSame((string) $treasurer->id, (string) $expense->paid_by);

        // حركة خارج posted
        $m = AccountMovement::where('reference_type', 'expense')
            ->where('reference_id', $expense->id)->firstOrFail();
        $this->assertSame('out', $m->direction);
        $this->assertSame('expense', $m->category);
        $this->assertSame('posted', $m->status);

        // ⭐ الرصيد قام يقل 400
        $this->assertEquals(600.0, $account->fresh()->balance());

        // والتقرير المالي بشوفه (المعتمد/المدفوع)
        $reader = $this->makeUserWithRole('reader', ['reports.view']);
        $r = $this->getJsonAs('/api/reports/financial?from=2026-10-01&to=2026-10-31', $reader);
        $r->assertOk();
        $this->assertEquals(400.0, (float) $r->json('expenses.total'), 'المصروف ظهر في التقرير');
    }

    // ═══════════════════════════════════════════════════════════
    // 3 — المنشئ ما يعتمدش ملو نفسه
    // ═══════════════════════════════════════════════════════════

    public function test_the_creator_cannot_approve_their_own_expense(): void
    {
        // نفس المستخدم مسموح ينشئ + اعتماد — والقاعدة تمنعه
        $self = $this->makeUserWithRole('self', [
            'finance.expenses.create', 'finance.expenses.approve',
        ]);

        $expense = $this->submittedExpense($self->id);
        $expense->save();

        $this->postJsonAs("/api/expenses/{$expense->id}/approve", [], $self)->assertStatus(403);

        $expense = $expense->fresh();
        $this->assertNotSame('approved', $expense->status);
        $this->assertNull($expense->approved_by);
    }

    // ═══════════════════════════════════════════════════════════
    // 4 — حارس مراحل الصرف: مرفوض/معتمد مرتين/غير معتمد
    // ═══════════════════════════════════════════════════════════

    public function test_the_pay_stage_guards(): void
    {
        $account = $this->makeAccount();
        $clerk = $this->creator();
        $chief = $this->approver();
        $treasurer = $this->payer();

        $expense = $this->submittedExpense($clerk->id);

        // صرف قبل الاعتماد → مرفوض
        $this->postJsonAs("/api/expenses/{$expense->id}/pay", [], $treasurer)->assertStatus(422);

        // رفض
        $this->postJsonAs("/api/expenses/{$expense->id}/reject", [
            'reason' => 'ملكه فاتورة مرفقة',
        ], $chief)->assertOk();
        $this->assertSame('rejected', $expense->fresh()->status);

        // المرفوض ما يتعتمدش (دورة المرفوض مغمورة
        // — المعتمد بس الهيصرف بقى)
        $this->postJsonAs("/api/expenses/{$expense->id}/approve", [], $chief)->assertStatus(422);
        $this->postJsonAs("/api/expenses/{$expense->id}/pay", [], $treasurer)->assertStatus(422);

        // المرفوض ما لهش حركة
        $this->assertSame(
            0,
            AccountMovement::where('reference_type', 'expense')->where('reference_id', $expense->id)->count(),
        );

        // سيناريو الدفع المزدوج
        $second = $this->submittedExpense($clerk->id);
        $this->postJsonAs("/api/expenses/{$second->id}/approve", [], $chief)->assertOk();
        $this->postJsonAs("/api/expenses/{$second->id}/pay", [], $treasurer)->assertOk();
        $this->postJsonAs("/api/expenses/{$second->id}/pay", [], $treasurer)->assertStatus(422);
        $this->assertSame(
            1,
            AccountMovement::where('reference_type', 'expense')->where('reference_id', $second->id)->count(),
        );
    }

    // ═══════════════════════════════════════════════════════════
    // 5 — الصلاحيات
    // ═══════════════════════════════════════════════════════════

    public function test_approve_and_pay_need_their_own_permissions(): void
    {
        $clerk = $this->creator();
        $expense = $this->submittedExpense($clerk->id);

        $this->postJsonAs("/api/expenses/{$expense->id}/approve", [], $clerk)->assertStatus(403);
        $this->postJsonAs("/api/expenses/{$expense->id}/pay", [], $clerk)->assertStatus(403);
    }


    // ═══════════════════════════════════════════════════════════
    // 6 — المرفقات: رفع محدود النوع والحجم + تحميل موقّع
    // ═══════════════════════════════════════════════════════════

    public function test_attachments_are_stored_privately_and_reached_via_signed_links(): void
    {
        Storage::fake('local');
        $clerk = $this->creator();
        $expense = $this->submittedExpense($clerk->id);

        // رفع صحيح (بدون GD — ملف بامتداد/نوع png بصياغة fake)
        $r = $this->postJsonAs("/api/expenses/{$expense->id}/attachments", [
            'file' => UploadedFile::fake()->create('receipt.png', 50, 'image/png'),
        ], $clerk);
        $r->assertStatus(201);

        $row = ExpenseAttachment::firstOrFail();
        $this->assertSame('receipt.png', $row->original_name);
        $this->assertSame('expense-attachments', explode('/', $row->path)[0], 'التخزين في مجلد خاص');

        // ⭐ المسار خارج public disk
        Storage::disk('local')->assertExists($row->path);

        // تنزيل موقّع
        $signed = URL::temporarySignedRoute('expense-attachments.download', now()->addMinutes(10), [
            'attachment' => $row->id,
        ]);
        $this->get(str_replace('http://localhost', '', $signed))->assertOk();

        // نوع مرفوض
        $this->postJsonAs("/api/expenses/{$expense->id}/attachments", [
            'file' => UploadedFile::fake()->create('hacked.exe', 100),
        ], $clerk)->assertStatus(422);

        // حجم مرفوض (6MB > 5MB)
        $this->postJsonAs("/api/expenses/{$expense->id}/attachments", [
            'file' => UploadedFile::fake()->create('big.pdf', 6000),
        ], $clerk)->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════
    // 7 — إدارة الفئات
    // ═══════════════════════════════════════════════════════════

    public function test_category_management_and_used_category_guard(): void
    {
        $clerk = $this->creator();

        $r = $this->postJsonAs('/api/expense-categories', [
            'name' => 'نظافة',
        ], $clerk);
        $r->assertStatus(201);

        $category = ExpenseCategory::where('name', 'نظافة')->firstOrFail();
        $used = $this->makeExpenseCategory();

        // مصروف مربوط بالفئة → الحذف مرفوض
        Expense::create([
            'organization_id' => $this->org->id,
            'category_id' => $used->id,
            'amount' => 250,
            'currency' => 'EGP',
            'expense_date' => '2026-10-10',
            'description' => 'مصروف للفئة المستعملة',
            'created_by' => $clerk->id,
            'status' => 'submitted',
        ]);
        $this->deleteJsonAs("/api/expense-categories/{$used->id}", $clerk)->assertStatus(409);

        // الفئة الجديدة (غير المستخدمة) تنحذف عادي
        $this->deleteJsonAs("/api/expense-categories/{$category->id}", $clerk)->assertOk();
        $this->assertNull(ExpenseCategory::find($category->id));
    }
}
