<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PromoCode;
use Illuminate\Validation\ValidationException;

/**
 * PR 2.14 — التحقق عن الكود الترويجي في لحظة الإصدار.
 *
 * ═══════════════════════════════════════════════════════════
 * الوضع قبل كده (ليه الخدمة دي أصلاً؟):
 *
 * ① الفحص كان «قراءة ثم كتابة» من غير transaction ولا
 *    `lockForUpdate`: نداءان متزامنان بيدخلان الكود أكثر من
 *    `max_uses` — العداد بيقول الحقيقة، لكن الباب مفتوح للمنافسة.
 * ② `max_uses_per_student` كان مكتوب في الموديل ومحدش بيقراه
 *    — الطالب يقدر يستخدم نفس الكود مليون مرة.
 * ③ الكود غير الصالح (ملغي/منتهي/مستنفد) كان بيتجاهل بصمت:
 *    الفاتورة تنجح 201 من غير خصم — المستخدم يصدق إن الخصم
 *    اتطبق. **التحقق بيتم عند الإصدار برفض واضح**.
 * ④ الخصم القديم كان بيتحسب على أساس فيه الضرايب — الأساس دلوقتي
 *    السعر الأصلي ناقص الخصم اليدوي بس.
 * ═══════════════════════════════════════════════════════════
 *
 * ⚠️ الفحص المقفول — `lockForUpdate` على السطر — بناديه **من
 * جوه** transaction الكاتب: `Invoice::create` + `incrementUsage`
 * + سطر الاستخدام في صفقة واحدة. عداد `used_count` **هو مصدر
 * الحقيقة** — والقراءة من غير القفل سباق مكشوف.
 */
class PromoCodeService
{
    /**
     * @param  string|null  $programId  برنامج الاشتراك — للنطاق `program`.
     * @param  string|null  $planCategory  فئة الخطة — للنطاق `category`.
     * @param  int|null  $excludedInvoiceId  عند التعديل: نحتاج نستبعد فاتورة معينة.
     *
     * @throws ValidationException (422) بأي سبب رفض.
     */
    public function assertUsableLocked(
        string $code,
        int $organizationId,
        ?int $studentId = null,
        ?int $programId = null,
        ?string $planCategory = null,
        ?int $excludedInvoiceId = null,
    ): PromoCode {
        /** @var PromoCode|null $promo */
        $promo = PromoCode::query()
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->lockForUpdate()
            ->first();

        if ($promo === null) {
            $this->reject("الكود الترويجي «{$code}» مش موجود");
        }

        if (! $promo->is_active) {
            $this->reject('الكود الترويجي مش مفعّل');
        }

        if ($promo->starts_at && $promo->starts_at->isFuture()) {
            $this->reject('الكود الترويجي لسه مشفتح — بتفتح بتاريخ '.$promo->starts_at->toDateString());
        }

        if ($promo->expires_at && $promo->expires_at->isPast()) {
            $this->reject('انتهت صلاحية الكود الترويجي بتاريخ '.$promo->expires_at->toDateString());
        }

        if ($promo->max_uses !== null && $promo->used_count >= $promo->max_uses) {
            $this->reject("الكود اتسطفّ منه (".$promo->used_count.' من '.$promo->max_uses.')');
        }

        // ⭐ سقف الاستخدام لكل طالب — كان متنسي خارج السير
        if ($studentId !== null && ! $promo->canStudentUse($studentId)) {
            $this->reject('الطالب استنفد الحد المسموح من الكود ('.$promo->max_uses_per_student.' مرة)');
        }

        // ⭐ نطاق التطبيق — بالنطاق وشركة الخطة
        if ($promo->applies_to === 'program' && $programId !== null && (int) $programId !== (int) $promo->program_id) {
            $this->reject('الكود مخصوص لبرنامج تاني');
        }

        if ($promo->applies_to === 'category' && $planCategory !== null && $planCategory !== $promo->category) {
            $this->reject('الكود مخصوص لفئة الاشتراكات ('.(string) $promo->category.')');
        }

        // ⭐ طلاب جدد فقط — الطالب عنده فاتورة قبلها = مش جديد
        if ($promo->new_students_only && $studentId !== null) {
            $hasPrevious = Invoice::query()
                ->where('student_id', $studentId)
                ->when($excludedInvoiceId !== null, fn ($q) => $q->where('id', '!=', $excludedInvoiceId))
                ->exists();

            if ($hasPrevious) {
                $this->reject('الكود مخصوص للطلاب الجدد بس');
            }
        }

        return $promo;
    }

    /**
     * ⭐ زيادة العداد مع سطر الاستخدام — **جوه نفس الصفقة**
     * عشان `used_count` = عدد الاستخدامات المحسوبة فعلاً.
     */
    public function recordUsage(
        PromoCode $promo,
        Invoice $invoice,
        float $discountAmount,
        float $originalAmount,
        ?int $userId = null,
    ): void {
        $promo->incrementUsage();

        \App\Models\PromoCodeUsage::create([
            'organization_id' => $invoice->organization_id,
            'promo_code_id' => $promo->id,
            'invoice_id' => $invoice->id,
            'student_id' => $invoice->student_id,
            'discount_amount' => $discountAmount,
            'original_amount' => $originalAmount,
            'final_amount' => $invoice->total,
            'created_by' => $userId,
            'applied_at' => now(),
        ]);
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['promo_code' => $message]);
    }
}
