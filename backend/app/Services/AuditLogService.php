<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * تسجيل النشاط في audit_logs.
 *
 * الجدول كان موجود بس فاضي — فيه بيانات من الـ seeder بس ومفيش كود
 * بيكتب فيه. الخدمة دي هي اللي بتتوصل في الـ controllers.
 *
 * ليش مش observer على الـ models؟ لأن عايزين نعرف بالظبط إمتى نكتب:
 * مش كل تغيير في الداتابيز لازم يتسجل، بس الإجراءات اللي المستخدم
 * بيعملها عمداً (أنشأ طالب، عدّل فاتورة، سجّل دخول).
 */
class AuditLogService
{
    /**
     * تسجيل إجراء.
     *
     * @param  string  $action      create | update | delete | login | refund
     * @param  string  $entityType  student | teacher | subscription | invoice | payment | employee | user
     * @param  int     $entityId    معرّف الصف المتأثر
     * @param  mixed   $oldValue    القيم القديمة (للتعديل)
     * @param  mixed   $newValue    القيم الجديدة
     * @param  int|null $userId      من نفّذ الإجراء — لازم نمرره صراحةً
     *                               في تسجيل الدخول لأن المستخدم لسه
     *                               مش متحقّق (audit_logs.user_id NOT NULL)
     */
    public function log(
        string $action,
        string $entityType,
        int $entityId,
        mixed $oldValue = null,
        mixed $newValue = null,
        ?Request $request = null,
        ?int $userId = null,
    ): ?AuditLog {
        // ⭐⭐⭐ **«مش عارف» غير «مش مرسل».**
        //
        // ⭐ الكود الأول كان سطر استنتاج واحد، فأي caller مرّر `null`
        // **صراحةً** (يعني «الفاعل مش معروف») كان الاستنتاج بيكتب
        // فوقه ويسجّل اسم حد تاني هو.
        //
        // ⭐ ده كان غلط صامت: مافيش اختبار في وضعGuest كاشفه، لأن
        // `auth()->id()` كانت `null` فالنتيجة نفس الصفر بالصدفة.
        // أول ما بقى في مستخدم دائم (وضع المستخدم الواحد) ظهر:
        // محاولة دخول لإيميل مجهول اتسجّلت باسم الأدمن.
        //
        // ⭐ فالقاعدة بقت: لو الـcaller بعت 7 args، احترم قراره
        // (حتى لو `null`). أقل من كده → نستنتج من الطلب.
        if (func_num_args() < 7) {
            $userId ??= $request?->user()?->id ?? auth()->id();
        }

        // ⭐⭐⭐ **مافيش skip صامت.** النسخة الأولى كانت:
        //
        //     // لو مفيش مستخدم (أمر Artisan مثلاً) بنتخطّى
        //     if ($userId === null) {
        //         return null;
        //     }
        //
        // ⭐ والسبب المكتوب كان «السجل ما يشترشش على العملية
        // الأصلية».Sound، **لكن الحارس كان بيبلع أحداث أمنية**:
        //
        // ⭐⭐⭐ `LoginRateLimiter::logFailure` كان بيبعت `0` (لأن
        // `NOT NULL`)، فكان بيرمي **FK violation** → 500. وبعد
        // ما صلّحنا العمود لـ`nullable` وبعتّله `null`، الحارس
        // ⭐⭐⭐ الحguards كان بيبلع الأحداث الأمنية بصمت.
        //
        // ⭐⭐ النتيجة: محاولة الدخول لإيميل **مش موجود** — وهي
        // **أحسن حالة اختراب في النظام** — كانت **مش بتتسجّل
        // خالص** من غير ما حد ياخد باله. السطر كان بيرجع
        // `null` والطلب كان بيكمّل عادي، فمافيش أي أثر.
        //
        // ⭐⭐⭐ ليه الحارس ده كان غلط بالأساس
        //
        // ⭐ حالتين مختلفتين كانت بتتقمص في سطر واحد:
        //
        //   ① **مفيش فاعل** (أمر Artisan، scheduler) → السجل
        //      مفيد ومينفعش يتشال.
        //   ② ⭐ **الفاعل مش معروف** (محاولة دخول لحساب مش
        //      موجود) → ده **هو** الحدث اللي السجل مطلوب عشانه.
        //
        // ⭐ `NULL` بقى مقبول في العمود، فمافيش سبب ننسى السطر
        // في الحالتين. سجل بفاعل مجهول **أفضل** من منعدم.
        //
        // ⭐ ملاحظة: `?AuditLog` في الـreturn type بيفضل — مش
        // عشان الحارس، عشان `create()` بيرجّع الموديل.
        //
        // @see \Database\Migrations\2026_10_15_000003_make_audit_logs_user_id_nullable
        // @see \Tests\Feature\LoginHardeningTest::test_a_login_attempt_for_an_unknown_email_is_audited

        return AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_value' => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
            'new_value' => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => $request?->ip(),
            'created_at' => now(),
        ]);
    }

    /** اختصار للإنشاء */
    public function logCreate(string $entityType, int $entityId, mixed $newValue = null, ?Request $request = null): ?AuditLog
    {
        return $this->log('create', $entityType, $entityId, null, $newValue, $request);
    }

    /** اختصار للتعديل — بيبعت القديم والجديد */
    public function logUpdate(string $entityType, int $entityId, mixed $oldValue, mixed $newValue, ?Request $request = null): ?AuditLog
    {
        return $this->log('update', $entityType, $entityId, $oldValue, $newValue, $request);
    }

    /** اختصار للحذف */
    public function logDelete(string $entityType, int $entityId, mixed $oldValue = null, ?Request $request = null): ?AuditLog
    {
        return $this->log('delete', $entityType, $entityId, $oldValue, null, $request);
    }
}
