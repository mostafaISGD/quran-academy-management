<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use Tests\TestCase;

/**
 * PR 2.14 — الخصومات ما كانتش بتتحسب صح.
 *
 * الوضع قبل كده:
 *
 * ① الكود الترويجي كان بيتطبق بصمت لو صالح، وبيُتجاهل بصمت لو
 *    ملغي/منتهي/مستنفد — مفيش رفض واضح، يعني ممكن تدخل كود
 *    غلط وتصدق إن الخصم اتطبق.
 * ② سقف الاستخدام لكل طالب (`max_uses_per_student`) كان مكتوب في
 *    الموديل وما محدش بيقراه — الطالب يقدر يستخدم نفس الكود مليون
 *    مرة.
 * ③ الفاتورة ما بتسجلش مين الكود اللي اتطبق (`promo_code_id`) ولا
 *    الأسباب (`discount_reason`) ولا سعر القائمة الأصلي
 *    (`list_amount`) — المجموع هو الوحيد اللي بيبان.
 * ④ فحص السقف الإجمالي كان من غير transaction ولا lock — نداءان
 *    متزامنان بيدخلان الكود أكتر من حده.
 * ⑤ تقرير «كام ضاع في الخصومات» مكانش موجود أصلاً.
 */
class PromoCodeIssuanceTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════
    // 1) WELCOME10 بيخصم 10% — والفاتورة بتوثّق السعر الأصلي
    // ═══════════════════════════════════════════════════════════
    //
    // المواصفة: «كود WELCOME10 يخصم 10% ويظهر بنفس الأصل على الفاتورة»
    //   subtotal = 500 (300+200)
    //   خصم يدوي = 20 → الأساس الترويجي = 480
    //   ترويجي 10% = 48 → discount = 68 → total = 432

    public function test_welcome10_discounts_10_percent_and_documents_the_original(): void
    {
        $student = $this->makeStudent();
        $clerk = $this->makeUserWithRole('clerk14', ['finance.payments.create']);

        $promo = PromoCode::create([
            'organization_id' => $this->org->id,
            'code' => 'WELCOME10',
            'name' => 'ترحيب 10%',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ]);

        $r = $this->postJsonAs('/api/invoices', [
            'student_id' => $student->id,
            'issue_date' => now()->toDateString(),
            'discount' => 20,
            'discount_reason' => 'خصم أخ الأخت',
            'promo_code' => 'WELCOME10',
            'items' => [
                ['description' => 'التحفيظ', 'quantity' => 1, 'unit_price' => 300],
                ['description' => 'الانشطة', 'quantity' => 1, 'unit_price' => 200],
            ],
        ], $clerk);

        $r->assertCreated();

        // الفاتورة بتجيب السعر الأصلي + تفكيك الخصم + هوية الكود
        // ⚠️ MoneyCast بيرجّع نص بالوحدة الكبرى («500.00») — النص نص، فنحضّر للـfloat عند التحقق
        $this->assertSame(500.0, (float) $r->json('list_amount'));
        $this->assertSame(68.0, (float) $r->json('discount'));
        $this->assertSame(432.0, (float) $r->json('total'));
        $this->assertSame('خصم أخ الأخت', $r->json('discount_reason'));
        $this->assertNotNull($r->json('promo_code_id'), 'الفاتورة بتربط الكود الترويجي');

        $invoice = Invoice::where('student_id', $student->id)->firstOrFail();
        $this->assertSame($promo->id, (int) Invoice::find($invoice->id)->promo_code_id);

        // استخدام مسجل بقيم صحيحة + العداد اتزاد
        $usage = PromoCodeUsage::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertEquals(48.0, (float) $usage->discount_amount);
        $this->assertEquals(1, $promo->fresh()->used_count);
    }

    // ═══════════════════════════════════════════════════════════
    // 2) كود منتهي = رفض صريح 422 (كان بيتجاهل بصمت)
    // ═══════════════════════════════════════════════════════════

    public function test_an_expired_code_is_rejected(): void
    {
        $student = $this->makeStudent();
        $clerk = $this->makeUserWithRole('clerk14', ['finance.payments.create']);

        PromoCode::create([
            'organization_id' => $this->org->id,
            'code' => 'OLD20',
            'name' => 'عرض قديم',
            'type' => 'percent',
            'value' => 20,
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);

        $r = $this->postJsonAs('/api/invoices', $this->invoicePayload($student, 'OLD20'), $clerk);

        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['promo_code']);
        $this->assertSame(0, Invoice::count(), 'مافيش فاتورة بكود منتهي');
    }

    // ═══════════════════════════════════════════════════════════
    // 3) السقف الإجمالي — الاستخدام فوق حد `max_uses` مرفوض
    // ═══════════════════════════════════════════════════════════

    public function test_a_code_past_its_total_limit_is_rejected(): void
    {
        $clerk = $this->makeUserWithRole('clerk14', ['finance.payments.create']);

        PromoCode::create([
            'organization_id' => $this->org->id,
            'code' => 'LIMIT50',
            'name' => 'عرض محدود',
            'type' => 'fixed',
            'value' => 50,
            'max_uses' => 1,
            'is_active' => true,
            'max_uses_per_student' => 5,
        ]);

        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();

        $r1 = $this->postJsonAs('/api/invoices', $this->invoicePayload($studentA, 'LIMIT50'), $clerk);
        $r1->assertCreated();
        $this->assertEquals(50.0, (float) $r1->json('discount'));

        $r2 = $this->postJsonAs('/api/invoices', $this->invoicePayload($studentB, 'LIMIT50'), $clerk);

        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['promo_code']);
        $this->assertSame(1, PromoCodeUsage::count(), 'مافيش استخدام تاني فوق السقف');
    }

    // ═══════════════════════════════════════════════════════════
    // 4) السقف لكل طالب — الطالب اللي استخدمه قبل كده مرفوض (كان متنسي)
    // ═══════════════════════════════════════════════════════════

    public function test_the_per_student_limit_is_enforced(): void
    {
        $clerk = $this->makeUserWithRole('clerk14', ['finance.payments.create']);

        PromoCode::create([
            'organization_id' => $this->org->id,
            'code' => 'ONEPER',
            'name' => 'مرة واحدة للطالب',
            'type' => 'percent',
            'value' => 10,
            'max_uses' => 100,
            'max_uses_per_student' => 1,
            'is_active' => true,
        ]);

        $student = $this->makeStudent();

        $r1 = $this->postJsonAs('/api/invoices', $this->invoicePayload($student, 'ONEPER'), $clerk);
        $r1->assertCreated();

        $r2 = $this->postJsonAs('/api/invoices', $this->invoicePayload($student, 'ONEPER'), $clerk);
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['promo_code']);
        $this->assertSame(1, PromoCodeUsage::where('student_id', $student->id)->count());
    }

    // ═══════════════════════════════════════════════════════════
    // 5) الطالب المستنفد للكود بيتسكَّت في الـbulk بسبب واضح
    // ═══════════════════════════════════════════════════════════

    public function test_a_student_who_cannot_use_the_code_is_skipped_in_bulk(): void
    {
        $clerk = $this->makeUserWithRole('clerk14', ['finance.payments.create']);

        PromoCode::create([
            'organization_id' => $this->org->id,
            'code' => 'BULK50',
            'name' => 'عرض دفععة',
            'type' => 'fixed',
            'value' => 50,
            'max_uses_per_student' => 1,
            'is_active' => true,
        ]);

        // الطالب A استخدم الكود قبل كده — B لسه
        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();
        $program = $this->makeProgram();
        $this->makeSubscription($program, $studentA);
        $this->makeSubscription($program, $studentB);

        PromoCodeUsage::create([
            'organization_id' => $this->org->id,
            'promo_code_id' => PromoCode::where('code', 'BULK50')->firstOrFail()->id,
            'invoice_id' => Invoice::create([
                'organization_id' => $this->org->id,
                'student_id' => $studentA->id,
                'invoice_number' => 'INV-SEEDED',
                'issue_date' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'subtotal' => 100,
                'discount' => 10,
                'tax' => 0,
                'total' => 90,
                'paid_amount' => 0,
                'balance_due' => 90,
                'currency' => 'EGP',
                'status' => 'issued',
            ])->id,
            'student_id' => $studentA->id,
            'discount_amount' => 10,
            'original_amount' => 100,
            'final_amount' => 90,
        ]);

        $r = $this->postJsonAs('/api/students/bulk/create-invoices', [
            'student_ids' => [$studentA->id, $studentB->id],
            'promo_code' => 'BULK50',
        ], $clerk);

        $r->assertOk();
        $skippedStudents = collect($r->json('skipped'))->pluck('student_id');
        $this->assertContains($studentA->id, $skippedStudents, 'الطالب المستنفد اتسكيت بيسبب واضح');
        $this->assertNotContains($studentB->id, $skippedStudents);
        $this->assertSame(1, $r->json('created_count'));
    }

    // ═══════════════════════════════════════════════════════════
    // 6) تقرير «كام ضاع في الخصومات»
    // ═══════════════════════════════════════════════════════════

    public function test_the_discounts_report_sums_the_cost(): void
    {
        $analyst = $this->makeUserWithRole('analyst14', ['reports.view']);
        $clerk = $this->makeUserWithRole('clerk14', ['finance.payments.create']);

        PromoCode::create([
            'organization_id' => $this->org->id,
            'code' => 'WELCOME10',
            'name' => 'ترحيب 10%',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ]);

        $student = $this->makeStudent();

        // فاتورة بخصم ترويجي (48) + يدوي (20) — أساس الترويجي = 500−20 = 480
        $this->postJsonAs('/api/invoices', [
            'student_id' => $student->id,
            'issue_date' => now()->toDateString(),
            'discount' => 20,
            'promo_code' => 'WELCOME10',
            'items' => [
                ['description' => 'التحفيظ', 'quantity' => 1, 'unit_price' => 300],
                ['description' => 'الانشطة', 'quantity' => 1, 'unit_price' => 200],
            ],
        ], $clerk)->assertCreated();

        $r = $this->getJsonAs('/api/reports/discounts', $analyst);

        $r->assertOk();
        $this->assertNotNull($r->json('totals'));
        $reportPromo = $r->json('totals.promo_discounts');
        $this->assertEquals(48.0, (float) $reportPromo);
        $this->assertEquals(20.0, (float) $r->json('totals.manual_discounts'));
        $byCode = $r->json('by_code');
        $this->assertSame('WELCOME10', $byCode[0]['code'] ?? '', 'التفصيل لكل كود موجود');
    }

    /** مولّد payload فاتورة بسيطة — items إجمالها 500. */
    private function invoicePayload($student, string $promoCode): array
    {
        return [
            'student_id' => $student->id,
            'issue_date' => now()->toDateString(),
            'promo_code' => $promoCode,
            'items' => [
                ['description' => 'التحفيظ', 'quantity' => 1, 'unit_price' => 300],
                ['description' => 'الانشطة', 'quantity' => 1, 'unit_price' => 200],
            ],
        ];
    }
}
