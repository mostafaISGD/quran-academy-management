<?php

namespace App\Services;

use App\Models\ParentalConsent;
use App\Models\Student;
use App\Models\User;

/**
 * ⭐ PR 1.12 — مين يقدر يكون له حساب، ومين يقدر يتفعّل.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ القاعدة
 *
 *   • **تحت 13**  → مفيش حساب خالص، ولا «حساب موافق عليه»)
 *   • **13+**      → الحساب موجود بس **مفيش تفعيل** من غير موافقة
 *                    مسجّلة من ولي أمر.
 *
 * ⭐ السبب عملي: طفل بينشئ حساب بإيميل أهله من غير ما حد يعرف
 * = هو ياخد الحساب كله + وعلى الأرجح الفاتورة. والـconsent المسجّل
 * بيخلي **الأهلي** بيقرروا، ومين سحب موافقته الـsuspend فوراً
 * (مش إلغاء حساب بس).
 *
 * ⭐ الخدمة دي هي **المصدر الوحيد** للحكم — الـcontroller بيستدعيها
 * بس، وأي كود تاني كمان لازم يستدعيها. لو الـRules اتكرر في
 * الـcontroller والـservice، النتيجة هتختلف مع الوقت — والقاعدة
 * في مكان واحد.
 */
class StudentAccountEligibility
{
    public function __construct(private UserStatusService $users) {}

    /**
     * ⭐ الطالب ده يقدر يكون ليه حساب أصلاً؟
     *
     * @throws \DomainException تحت 13
     */
    public function assertCanHaveAccount(Student $student): void
    {
        $years = StudentAge::years($student);

        if ($years === null) {
            throw new \DomainException(
                'الطالب مافيش له تاريخ ميلاد — كمّل التاريخ الأول، وبعدين هنقرر.'
            );
        }

        if (StudentAge::isMinor($student)) {
            throw new \DomainException(
                "الطالب {$years} سنة — الحساب المستقل من 13 سنة بس. ".
                'تحت 13 بيدخل الحصة برابط من ولي الأمر.'
            );
        }
    }

    /** ⭐ فوق 13 + فيه موافقة سارية = يتفعّل */
    public function canActivate(Student $student, string $scope = ParentalConsent::SCOPE_ACCOUNT): bool
    {
        if (! $student->user_id) {
            return false;
        }

        return $this->hasActiveConsent($student, $scope);
    }

    /** ⭐ فيه موافقة سارية للطالب في النطاق ده؟ */
    public function hasActiveConsent(Student $student, string $scope = ParentalConsent::SCOPE_ACCOUNT): bool
    {
        return ParentalConsent::where('student_id', $student->id)
            ->where('scope', $scope)
            ->where('granted', true)
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * ⭐ اسحب موافقة — والحساب يتقفل لو مربوط بحساب.
     *
     * ⭐ **السحب بيقف الحساب فوراً**: الحساب موجود عشان الموافقة،
     * فلما الموافقة راحت، الحساب بقى بلا أساس.
     * Jon حسب `UserStatusService` — فبيبطل التوكنات والجلسات
     * زي أي إيقاف (PR 1.5).
     */
    public function revoke(ParentalConsent $consent, User $actor): void
    {
        if ($consent->revoked_at !== null) {
            return;
        }

        $consent->update([
            'granted' => false,
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
        ]);

        $student = Student::find($consent->student_id);

        if ($student?->user_id) {
            $user = User::find($student->user_id);

            if ($user && $user->status === 'active') {
                $this->users->suspend($user, $actor, 'سحب ولي الأمر موافقته على الحساب');
            }
        }

        app(AuditLogService::class)->log(
            'parental_consent_revoked',
            'student',
            (int) $consent->student_id,
            ['scope' => $consent->scope, 'granted' => true],
            ['scope' => $consent->scope, 'granted' => false],
            null,
            $actor->id,
        );
    }

    /**
     * ⭐ سجّل موافقة.
     *
     * ⭐ granted_by هو الموظف اللي سجّلها — لأن **الأهلي** مش
     * بيعتمدوا على تسجيل دخول الأكاديمية، فمصدر تسجيل الموافقة
     * هو الموظف اللي كتبها.
     */
    public function grant(
        Student $student,
        ?int $parentId,
        User $actor,
        string $scope = ParentalConsent::SCOPE_ACCOUNT,
        ?string $notes = null,
    ): ParentalConsent {
        $consent = ParentalConsent::create([
            'student_id' => $student->id,
            'parent_id' => $parentId,
            'scope' => $scope,
            'granted' => true,
            'granted_at' => now(),
            'granted_by' => $actor->id,
            'notes' => $notes,
        ]);

        app(AuditLogService::class)->log(
            'parental_consent_granted',
            'student',
            (int) $student->id,
            null,
            ['scope' => $scope, 'parent_id' => $parentId],
            null,
            $actor->id,
        );

        return $consent;
    }
}
