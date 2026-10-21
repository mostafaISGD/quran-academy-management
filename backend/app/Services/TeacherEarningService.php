<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\Setting;
use App\Exceptions\EarningAlreadyPaid;
use App\Models\TeacherEarning;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PR 2.10 — مصدر واحد لمستحقات المعلمين.
 *
 * قبل كده الخط كان شبه مفتوح:
 *
 *   - إنشاء المستحق موجود في `LessonController::complete` لكن
 *     **بلا حارس**: نداء الإكمال مرتين = مستحقين. وبدون
 *     `rate_snapshot` — المستحق القديم ما كانش بيرجع لسعر
 *     لحظته.
 *   - مافيش اعتماد نهائياً — فكشف المرتبات (`harvest()`
 *     بيتقرأ على `approved`/`paid`) ما فيهوش شي.
 *
 * المكان الواحد دلوقتي:
 *
 *   `ensureForLesson()` — إنشاء مستحق الحصة (لحظة أول إكمال).
 *   `approve()` / `bulkApprove()` — مسار الاعتماد الحقيقي،
 *     وكل اعتماد بيكتب سطر في دفتر المعلم — عشان مقارنة (د)
 *     في `finance:reconcile` تفضل صامتة في السليم وتصرخ في
 *     الغلط (اعتماد من غير دفتر = انحراف مسجل).
 *   `autoApprove()` — إعداد بكل مؤسسة
 *     (`payroll.auto_approve_earnings`, نافذ العطاء الصفر).
 */
class TeacherEarningService
{
    private const AUTO_APPROVE_KEY = 'payroll.auto_approve_earnings';

    /**
     * إنشاء/إرجاع مستحق درس — يعمل مرة واحدة لكل حصة.
     *
     * الشروط زي ما كانت: المعلم **نشط** بعقد `per_lesson`
     * وسعر جاري. مفيش شرط → لا مستحق (الرواتب الشهرية جاية من
     * مسار شهري — PR 2.11).
     *
     * عناصر الأمان: فحص السطر موجود أولاً، والإنشاء داخل
     * الصفقة — والفهرس الفريد الجزئي على `lesson_id` هو الحاجز
     * الأخير للنداءين المتزامنين على نفس الحصة (احدهم بيرجع
     * استثناء بتكرار وإحساس الطلب يفشل والذي كان أول الحالة).
     */
    /**
     * ⭐⭐⭐ **PR 3.5 — وضع أجر المعلم في الحصة الجماعية (قرار 7).**
     *
     * ⭐ `per_lesson` (الافتراضي): **أجر واحد ثابت للحصة** مهما
     * كان عدد الطلاب — وده القرار 7. `per_student`: مستحق لكل
     * طالب.
     *
     * ⭐ **ليه الإعداد؟** عشان الأكاديمية تتحكم: حصة 10 طلاب =
     * أجر واحد ولا 10؟ (قرار الإدارة، مش افتراض الكود).
     */
    public const GROUP_PAY_MODE_SETTING = 'lessons.group_teacher_pay_mode';

    /**
     * ⭐ مستحق واحد (أو مستحق لكل طالب) — idempotent.
     */
    public function ensureForLesson(Lesson $lesson, ?User $by = null): ?TeacherEarning
    {
        return DB::transaction(function () use ($lesson, $by) {
            $existing = TeacherEarning::where('lesson_id', $lesson->id)->first();
            if ($existing) {
                return $existing;
            }

            $teacher = $lesson->teacher;
            if (! $teacher) {
                return null;
            }

            $contract = $teacher->activeContract;
            if (! $contract || $contract->contract_type !== 'per_lesson') {
                return null;
            }

            $rate = $teacher->currentRate;
            if (! $rate) {
                return null;
            }

            /**
             * ⭐ لقطة السعر والعقد لحظة الحصة — السليوم سيتم
             * مصدر الحقيقة: حتى لو السعر اتغيّر بعدين بقى، المستحق
             * ما بيتقرش تاني ومع نفسه.
             */
            $snapshot = [
                'rate_id' => $rate->id,
                'rate_type' => $rate->rate_type,
                'amount' => (float) $rate->amount,
                'currency' => $rate->currency,
                'contract_id' => $contract->id,
                'contract_type' => $contract->contract_type,
                'captured_at' => now()->toIso8601String(),
            ];

            $orgId = (int) ($teacher->organization_id ?? 0);

            /**
             * ⭐⭐⭐ **PR 3.5 — وضع أجر الحصة الجماعية (قرار 7).**
             *
             * `per_lesson` (الافتراضي): مستحق **واحد** بسعر الحصة
             * — مهما كان عدد الطلاب.
             *
             * `per_student`: الأجر = **السعر × عدد الطلاب**، في
             * **سطر واحد** (لأن `teacher_earnings.lesson_id` فريد —
             * ضمان «مستحق واحد لكل حصة» من PR 3.4) — والعدد
             * والتوزيع موثّقين في `notes` و`rate_snapshot`.
             */
            $mode = (string) \App\Models\Setting::get(
                self::GROUP_PAY_MODE_SETTING,
                'per_lesson',
                $lesson->organization_id,
            );

            $students = $lesson->students()->pluck('students.id')->all();
            $amount = $rate->amount;

            if ($students !== [] && $mode === 'per_student') {
                $amount = $rate->amount * count($students);
                $snapshot['pay_mode'] = 'per_student';
                $snapshot['students_count'] = count($students);
            } else {
                $snapshot['pay_mode'] = 'per_lesson';
            }

            $earning = TeacherEarning::create([
                'teacher_id' => $teacher->id,
                'lesson_id' => $lesson->id,
                'contract_id' => $contract->id,
                'rate_id' => $rate->id,
                'amount' => $amount,
                'currency' => $rate->currency,
                'earning_date' => now()->toDateString(),
                'status' => 'pending',
                'rate_snapshot' => $snapshot,
            ]);

            if (self::autoApprove($orgId)) {
                $this->applyApproval($earning, $by);
            }

            return $earning;
        });
    }

    /**
     * ⭐⭐⭐ **PR 3.4 — عكس مستحق الحصة** (تصحيح بعد الإتمام).
     *
     * ⭐ **مش حذف ولا تعديل**: المستحق بيتحوّل `cancelled` والسبب
     * بيتسجّل — فالتاريخ باقي (القاعدة 7).
     *
     * ⭐ **المدفوع ممنوع**: لو المستحق اتصرف خلاص، العكس محتاج
     * **قيد عكسي في دفتر المعلم** (`teacher_ledger_entries`) —
     * وده شغل الدفتر مش هنا ⇒ 422 (تصحيح يدوي بقيد عكسي).
     */
    public function reverseForLesson(Lesson $lesson, ?User $by = null, ?string $reason = null): ?TeacherEarning
    {
        $earning = TeacherEarning::where('lesson_id', $lesson->id)->first();

        if ($earning === null) {
            return null;
        }

        if ($earning->status === 'cancelled') {
            return $earning;
        }

        if ($earning->status === 'paid') {
            throw new EarningAlreadyPaid($earning);
        }

        $earning->forceFill([
            'status' => 'cancelled',
            'notes' => trim(($earning->notes ?? '').' | عكس: '.($reason ?? 'تصحيح حالة الحصة')),
        ])->save();

        return $earning->fresh();
    }

    /**
     * اعتماد مستحق واحد — من قفل السطر وتحقق الحالة.
     *
     * المعلَق فقط: المعتمد بيرجع معلق تاني بلا ضرر.
     */
    public function approve(int $id, ?User $by = null): TeacherEarning
    {
        return DB::transaction(function () use ($id, $by) {
            $earning = TeacherEarning::where('id', $id)->lockForUpdate()->first();

            if (! $earning) {
                throw new \LogicException('المستحق مش موجود');
            }

            if ($earning->status !== 'pending') {
                throw new \LogicException('الاعتماد للمستحق المعلَق فقط (الحالة: '.$earning->status.')');
            }

            return $this->applyApproval($earning, $by);
        });
    }

    /**
     * اعتماد جماعي — المعلَق فقط، والباقي بيتعمل شطفه بالمعرف.
     *
     * @param  array<int, int>  $ids
     * @return array{approved: int[], skipped: int[]}
     */
    public function bulkApprove(array $ids, ?User $by = null): array
    {
        return DB::transaction(function () use ($ids, $by) {
            $approved = [];
            $skipped = [];

            foreach (TeacherEarning::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $earning) {
                if ($earning->status !== 'pending') {
                    $skipped[] = $earning->id;

                    continue;
                }

                $this->applyApproval($earning, $by);
                $approved[] = $earning->id;
            }

            return ['approved' => $approved, 'skipped' => $skipped];
        });
    }

    /**
     * إعداد الاعتماد الفوري لكل مؤسسة (افتراضي 0 = مقفول).
     */
    public static function autoApprove(?int $organizationId): bool
    {
        return ((int) Setting::get(self::AUTO_APPROVE_KEY, 0, $organizationId)) === 1;
    }

    /**
     * إعتماد فعلي: الحالة + سطر دفتر المعلم + التدقيق.
     *
     * ⭐ الدائن (`credit`) — فلوس استحققت للمعلم.
     */
    private function applyApproval(TeacherEarning $earning, ?User $by): TeacherEarning
    {
        if ($earning->status !== 'pending') {
            return $earning;
        }

        $earning->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $by?->id,
        ]);

        app(TeacherLedgerService::class)->write([
            'teacher_id' => $earning->teacher_id,
            'earning_id' => $earning->id,
            'type' => 'lesson_earning',
            'credit' => (float) $earning->amount,
            'currency' => $earning->currency,
            'description' => 'اعتماد مستحق درس #'.($earning->lesson_id ?? $earning->id),
            'reference_type' => 'teacher_earning',
            'reference_id' => (string) $earning->id,
            'created_by' => $by?->id,
        ]);

        // ⭐ `audit_logs.user_id` NOT NULL — الاعتماد دايماً بيجي
        // من مستخدم؛ مسار آلي مستقبلي (بدون مستخدم) بيتسجل
        // بسطر الدفتر (المصدر المحاسبي) من غير تدقيق.
        if ($by) {
            app(\App\Services\AuditLogService::class)->log(
                'earning_approved',
                'teacher_earning',
                $earning->id,
                null,
                ['status' => 'approved', 'amount' => (float) $earning->amount],
                null,
                $by->id,
            );
        }

        return $earning;
    }
}
