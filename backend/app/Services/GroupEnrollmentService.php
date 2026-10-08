<?php

namespace App\Services;

use App\Models\GroupClass;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentLedgerEntry;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ enrolling طالب في مجموعة — اشتراك **شهري** + فاتورة.
 *
 * ⚠️⚠️ **السبب إن الخدمة دي موجودة:**
 *
 * منطق «اشتراك شهري لبرنامج المجموعة» كان **مكرر في مكانين**:
 * `GroupController@admit` و `WaitlistController@admit` — بنفس
 * السطور بالظبط. أول ما نضيف باقة على المجموعة أو فاتورة، هنبقى
 * لازم نعدّل **الاتنين**، وأول ما ننسا واحد منهم هتبقى
 * المجموعتان بيتعاملوا بشكل مختلف.-means حد يدخل من كذا مكان
 * بيبقى نص الشغل متعمل ونصه لأ.
 *
 * ⭐ ف\Service **مصدر واحد** — أي حد بيدخل مجموعة (من الطابور،
 * من صفحة الانتظار، أو يدوي) بياخد نفس المعاملة بالظبط.
 *
 * ⭐ **الفاتورة بتتعمل مع الاشتراك في نفس اللحظة** (قرار ثابت).
 * السبب: لو عملنا الاشتراك بس، الاشتراك هيتقدم كل شهر من غير
 * فاتورة، ومش هينفع نعرف قد إيه اتحصّل ومن فاتورة.
 */
class GroupEnrollmentService
{
    /**
     * ⭐ ادخل الطالب في المجموعة — اشتراك + فاتورة لو محتاجين.
     *
     * ⚠️ بتتوقع تكون جوه transaction — أي حاجة فشلت ترجع
     * الطالب زي ما كان. (المتحصل على `admit` بيغلّفها.)
     *
     * @return array{
     *     subscription: Subscription|null,
     *     subscription_created: bool,
     *     invoice: Invoice|null,
     *     invoice_created: bool,
     *     plan: SubscriptionPlan|null,
     * }
     */
    public function enroll(GroupClass $group, Student $student, ?User $by = null): array
    {
        $blank = [
            'subscription' => null,
            'subscription_created' => false,
            'invoice' => null,
            'invoice_created' => false,
            'plan' => null,
        ];

        // ⭐ مفيش برنامج = مفيش اشتراك. المجموعة لازم يكون ليها برنامج.
        if (! $group->program_id) {
            return $blank;
        }

        /**
         * ⚠️ لو الطالب عنده اشتراك **لسه** على نفس البرنامج —
         * بنرجّعه زي ما هو ومش بنعمل واحد تاني.
         *
         * السبب: هو داخل مجموعة على نفس البرنامج ده من قبل،
         * فبياخد حصصه أصلاً. عمل اشتراك تاني = عميل بيدفع
         * مرتين على نفس الشيء.
         *
         * ⚠️ وبيبقى الفاتورة كمان **مش** جديدة — الفاتورة بتاعة
         * الاشتراك القائم موجودة بالفعل.
         */
        $existing = Subscription::where('student_id', $student->id)
            ->where('program_id', $group->program_id)
            ->whereIn('status', ['active', 'paused'])
            ->first();

        if ($existing) {
            /**
             * ⚠️⚠️ `array_merge` مش `+`.
             *
             * `$blank + ['subscription' => $existing]` مكانتش هتشتغل —
             * عامل `+` على المصفوفات بيسيب مفاتيح الطرف **الأيسر**،
             * و`$blank['subscription']` أصله `null`. فكان الرد
             * بيرجع `subscription_id: null` والاشتراك موجود فعلاً
             * (يعني الواجهة بتقول «معملناش» والاشتراك اتعمل).
             */
            return array_merge($blank, [
                'subscription' => $existing,
                'plan' => $existing->plan,
            ]);
        }

        $plan = $this->planFor($group);

        $subscription = Subscription::create([
            'organization_id' => $group->organization_id,
            'student_id' => $student->id,
            'plan_id' => $plan?->id,
            'program_id' => $group->program_id,
            'teacher_id' => $group->teacher_id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            // ⭐ شهري بس — ده قرار ثابت للمجموعات
            'billing_type' => 'monthly',
            // ⚠️ `price` NOT NULL في الجدول — فلو مفيش باقة على
            // المجموعة ولا على البرنامج، من غير ده الحفظ هيفشل.
            'price' => $plan?->price ?? 0,
            'currency' => $plan?->currency ?? $group->organization?->default_currency ?? 'EGP',
            'lesson_duration_minutes' => $plan?->lesson_duration_minutes,
            'lessons_included' => $plan?->lessons_count,
            'status' => 'active',
        ]);

        // ⭐ فاتورة في **نفس** اللحظة (قرار ثابت)
        $invoice = $this->invoiceFor($subscription, $plan, $by);

        return [
            'subscription' => $subscription,
            'subscription_created' => true,
            'invoice' => $invoice,
            'invoice_created' => $invoice !== null,
            'plan' => $plan,
        ];
    }

    /**
     * ⭐ الباقة اللي هيتبعت عليها اشتراك المجموعة.
     *
     * الترتيب: باقة المجموعة **أول حاجة** — دي اللي الأدمن
     * اختارها. لو مفيش، باقة البرنامج الشهرية. لو مفيش كمان،
     * باقة شهرية مش مربوطة ببرنامج (باقة عامة).
     *
     * ⚠️ بنجيب **الشهرية بس** من غير ما نفلتر على البرنامج، لأن
     * المجموعات **شهرية بس** قرار — لو لقينا باقة سنوية هيبقى
     * غلط منطقياً.
     */
    private function planFor(GroupClass $group): ?SubscriptionPlan
    {
        if ($group->package_id) {
            $pinned = SubscriptionPlan::find($group->package_id);

            // ⭐ الباقة المثبّتة على المجموعة **ملزم** حتى لو اتمسحت
            // أو اتوقفت. غير كده هنعمل اشتراك سعره 0 من غير ما حد
            // ياخد باله.
            if ($pinned) {
                return $pinned;
            }
        }

        return SubscriptionPlan::where('program_id', $group->program_id)
            ->where('billing_type', 'monthly')
            ->where('status', 'active')
            ->first()
            ?? SubscriptionPlan::whereNull('program_id')
                ->where('billing_type', 'monthly')
                ->where('status', 'active')
                ->first();
    }

    /**
     * ⭐ فاتورة الاشتراك + سطر في كشف حساب الطالب.
     *
     * ⚠️ لو الاشتراك سعره **صفر** (مفيش باقة) مش بنعمل فاتورة —
     * فاتورة بـ `0 ج.م` بتبوّظ الجداول وتلخبط التقارير. وبيبقى
     * واضح إن مفيش تحصيل على الطالب ده.
     */
    private function invoiceFor(Subscription $subscription, ?SubscriptionPlan $plan, ?User $by): ?Invoice
    {
        $amount = (float) $subscription->price;

        if ($amount <= 0) {
            return null;
        }

        $issueDate = now();
        $description = 'اشتراك شهري'
            . ($plan?->name ? ' — ' . $plan->name : '');

        $invoice = Invoice::create([
            'organization_id' => $subscription->organization_id,
            'student_id' => $subscription->student_id,
            'subscription_id' => $subscription->id,
            'invoice_number' => 'INV-' . strtoupper(uniqid()),
            'currency' => $subscription->currency ?? 'EGP',
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $issueDate->copy()->addDays(7)->toDateString(),
            'subtotal' => $amount,
            'discount' => 0,
            'tax' => 0,
            'total' => $amount,
            'paid_amount' => 0,
            'balance_due' => $amount,
            'status' => 'issued',
            'notes' => $description,
        ]);

        $invoice->items()->create([
            'description' => $description,
            'item_type' => 'subscription',
            'quantity' => 1,
            'unit_price' => $amount,
            'total' => $amount,
        ]);

        // ⭐ كشف الحساب — من غيره رصيد الطالب مش هيتحدث وأرقام
        // صفحة الطالب هتفضل غلط.
        //
        // ⚠️ لازم **الموديل** مش `DB::table()->insert()`: الجدول
        // `created_at` NOT NULL، و`insert()` الـ خام مش بتلمّ
        // الـ timestamps — فكان بيرمي exception والعملية كلها
        // بترجع 500 (لما الاشتراك والفاتورة يبانوا شغالين).
        StudentLedgerEntry::create([
            'student_id' => $subscription->student_id,
            'invoice_id' => $invoice->id,
            'type' => 'invoice',
            'debit' => $amount,
            'balance_after' => $this->currentBalance($subscription->student_id) + $amount,
            'currency' => $invoice->currency,
            'description' => 'فاتورة ' . $invoice->invoice_number,
            'reference_type' => 'invoice',
            'reference_id' => $invoice->id,
            'created_by' => $by?->id,
        ]);

        return $invoice;
    }

    /**
     * ⭐ رصيد الطالب الحالي من كشف الحساب.
     *
     * ⚠️ مش محتاجين نجمع كل السطور — الرصيد **في آخر سطر**
     * уже (`balance_after` بيتحسب وبيتخزن مع كل سطر).
     */
    private function currentBalance(int $studentId): float
    {
        $last = StudentLedgerEntry::where('student_id', $studentId)
            ->orderByDesc('id')
            ->first();

        return (float) ($last?->balance_after ?? 0);
    }

    // ============================================================
    // الأقفال
    // ============================================================

    /**
     * ⭐ القفل: **ليه** الطالب ده مش قابل للنقل دلوقتي.
     *
     * ⚠️ القاعدة اللي اتفقنا عليها: **ما ينفعش ينقل قبل ما
     * اشتراكه يخلص وفواتيره تتسدّد.**
     *
     * السبب: لو نقلناه وهو داخل في اشتراك على برنامج المجموعة
     * الأولى، يبقى عنده اشتراك على برنامج مش بياخد فيه حصص،
     * وفي نفس الوقت الجديدة ممكن تحسب له فاتورة تانية. يعني
     * الحساب بينقفل عليه مرتين على نفس الشهر.
     *
     * ⭐⭐ **النتيجة المتعمّدة — اقراها قبل ما «تصلح»:**
     *
     * الاشتراك **شهري** وبيعمل لحظة الدخول. فالقاعدة دي معناها
     * عملياً إن الطالب **ينقل بعد ما شهره يخلص** — في الغالب آخر
     * يوم من الشهر، مش في نصه.
     *
     * ده **مش باج** — القرار ده متفق عليه صريح. البدائل اتناقشت
     * (قفل على الفواتير بس / زر «نقل رغم كل شيء») ورُفضت. فلو حد
     * لقى «النقل مش بيشتغل»، القرار **مش عنده** — لازم كلام مع
     * الإدارة قبل ما يغيّر الشرط.
     *
     * @return array<int, array{code: string, message: string}>
     */
    public function moveBlockers(Student $student): array
    {
        $blockers = [];

        $active = Subscription::where('student_id', $student->id)
            ->whereIn('status', ['active', 'paused'])
            ->get();

        foreach ($active as $sub) {
            $blockers[] = [
                'code' => 'active_subscription',
                'message' => 'الطالب عنده اشتراك لسه شغال لحد '
                    . $sub->end_date?->toDateString()
                    . ' — لازم يخلص قبل ما ينقل.',
            ];
        }

        foreach ($this->unsettledInvoices($student) as $invoice) {
            $blockers[] = [
                'code' => 'unsettled_invoice',
                'message' => 'الطالب عليه فاتورة '
                    . $invoice->invoice_number
                    . ' فيها ' . $invoice->balance_due
                    . ' لسه ما اتسدتش.',
            ];
        }

        return $blockers;
    }

    /**
     * ⭐ القفل: **ليه** مينفعش نغيّر باقة المجموعة دلوقتي.
     *
     * نفس المنطق: لو المجموعة فيها اشتراكات لسه شغالة أو
     * فواتير مفتوحة، تغيير الباقة معناه إن نص الطلاب على سعر
     * قديم ونص على جديد — والحساب آخر الشهر هيطلع مش متفق.
     *
     * @return array<int, array{code: string, message: string}>
     */
    public function packageBlockers(GroupClass $group): array
    {
        $blockers = [];

        /**
         * ⭐ عدد الأعضاء الفعليين.
         *
         * ده هو القفل الأساسي: تغيير الباقة = تغيير **سعر**
         * اشتراك كل عضو. فلو فيه أعضاء، غيّرنا السعر من غير
         * ما حد يوافق.
         */
        $members = $group->activeMembers()->with('student')->get();

        if ($members->isNotEmpty()) {
            $blockers[] = [
                'code' => 'active_members',
                'message' => 'المجموعة فيها '
                    . $members->count()
                    . ' طالب — تغيير الباقة معناه تغيير سعر اشتراكاتهم.',
            ];
        }

        /**
         * ⭐ الفواتير المفتوحة.
         *
         * ⭐ لو فاتورة عليها **رصيد فاضل** — يعني مدفوعة جزئياً أو
         * مش مدفوعة خالص — دي اللي بتتُنسي، فلازم نقفل. لو
         * مدفوعة بالكامل (`balance_due = 0`) خلاص اتقفلت.
         */
        foreach ($members as $member) {
            if (! $member->student) {
                continue;
            }

            $open = $this->unsettledInvoices($member->student);

            if ($open->isNotEmpty()) {
                $blockers[] = [
                    'code' => 'unsettled_invoice',
                    'message' => 'في ' . $open->count()
                        . ' فاتورة لسه مفتوحة لطلاب المجموعة.',
                ];

                // ⭐ رسالة واحدة كفاية — مينفعش نطلع ١٩ رسالة
                break;
            }
        }

        return $blockers;
    }

    /**
     * ⭐ فواتير الطالب اللي لسه ما اتسدّدت.
     *
     * ⚠️ شرطين مع بعض، مش واحد:
     *   • `balance_due > 0` — فيه فلوس فاضلة
     *   • الحالة مش `paid`/`cancelled`
     *
     * فاتورة مدفوعة جزئياً (`partially_paid`) لسه **مفتوحة** —
     * دي اللي بينسى الأدمن سدادها، فممنوعش نعمل عليها خلط.
     */
    private function unsettledInvoices(Student $student): \Illuminate\Support\Collection
    {
        return Invoice::where('student_id', $student->id)
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->where('balance_due', '>', 0)
            ->get();
    }
}