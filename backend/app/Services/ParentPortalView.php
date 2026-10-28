<?php

namespace App\Services;

use App\Casts\MoneyCast;
use App\Models\Lesson;
use App\Models\ParentModel;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐⭐⭐ **شكل بيانات بوابة ولي الأمر (PR 4.5).**
 *
 * ⭐ الـcontroller بيحوّل الطلب لرد، والخدمة دي بتبني **الشكل**
 * نفسه. السبب: نفس الابن بيظهر في قائمة (`/parent/children`)
 * وفي صفحته (`/parent/children/{id}`)، والقواعد (إخفاء المال،
 * الرصيد المشتق) لازم تكون **واحدة**.
 *
 * ⭐ كل استعلام بيبدأ من أبناء الولي المؤكدين
 * (`ParentChildScope::allowedStudentIds`) — مفيش `student_id` جاي
 * من العميل بيوصل لاستعلام من غير الفلتر ده.
 */
final class ParentPortalView
{
    /** أسماء الأيام لعرض الموعد الأسبوعي */
    private const WEEKDAYS = [
        0 => 'الأحد', 1 => 'الإثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء',
        4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت',
    ];

    public function parentFor(User $user): ?ParentModel
    {
        return ParentModel::where('user_id', $user->id)->first();
    }

    /**
     * قائمة الأبناء + ملخّص كل واحد.
     *
     * @return array{parent: array<string, mixed>, children: array<int, array<string, mixed>>}
     */
    public function children(User $user, ParentModel $parent): array
    {
        // ⭐⭐ الرابط المؤكد بس (PR 1.11) — `whereIn` على نتيجة
        // الـscope مش على الـpivot مباشرة، عشان المصدر واحد.
        $allowed = ParentChildScope::allowedStudentIds($user) ?? [];

        $links = $parent->students()
            ->whereIn('students.id', $allowed)
            ->get()
            ->map(fn (Student $student) => [
                'student' => $student,
                'relationship' => $student->pivot->relationship,
                'is_primary' => (bool) $student->pivot->is_primary,
            ]);

        if ($links->isEmpty()) {
            return ['parent' => $this->parentInfo($parent), 'children' => []];
        }

        $studentIds = $links->pluck('student.id')->all();

        $subscriptions = Subscription::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', ['active', 'paused'])
            ->with(['program', 'teacher'])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('student_id');

        $includedByStudent = Subscription::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', ['active', 'paused'])
            ->orderByDesc('created_at')
            ->get(['student_id', 'lessons_included'])
            ->groupBy('student_id')
            ->map(fn ($rows) => (int) ($rows->first()->lessons_included ?? 0));

        $credits = $this->creditAccountsFor($studentIds, $includedByStudent);

        $upcoming = Lesson::query()
            ->whereIn('student_id', $studentIds)
            ->where('status', 'scheduled')
            ->where('scheduled_start_at', '>=', Carbon::now()->startOfDay())
            ->with('teacher')
            ->orderBy('scheduled_start_at')
            ->limit(60)
            ->get()
            ->groupBy('student_id');

        $outstanding = $this->outstandingFor($studentIds);

        $children = $links->map(function (array $link) use ($user, $subscriptions, $credits, $upcoming, $outstanding) {
            $student = $link['student'];
            $studentId = $student->id;

            return $this->summary(
                user: $user,
                student: $student,
                relationship: $link['relationship'],
                isPrimary: $link['is_primary'],
                subscription: ($subscriptions[$studentId] ?? collect())->first(),
                credit: $credits[$studentId] ?? null,
                outstanding: $outstanding[$studentId] ?? null,
                upcoming: $upcoming[$studentId] ?? collect(),
            );
        })->values()->all();

        return ['parent' => $this->parentInfo($parent), 'children' => $children];
    }

    /**
     * صفحة ابن واحدة — نفس ملخّص القائمة + الملف الشخصي + الأعلام.
     *
     * @return array<string, mixed>
     */
    public function child(User $user, ParentModel $parent, Student $student): array
    {
        $link = $parent->students()
            ->where('students.id', $student->id)
            ->first();

        $subscription = Subscription::query()
            ->where('student_id', $student->id)
            ->whereIn('status', ['active', 'paused'])
            ->with(['program', 'teacher'])
            ->orderByDesc('created_at')
            ->first();

        $included = (int) ($subscription->lessons_included ?? 0);

        $credit = null;
        if ($subscription !== null) {
            $credit = $this->creditAccountsFor(
                [$student->id],
                collect([$student->id => $included])
            )[$student->id] ?? null;
        }

        $outstanding = $this->outstandingFor([$student->id])[$student->id] ?? null;

        $upcoming = Lesson::query()
            ->where('student_id', $student->id)
            ->where('status', 'scheduled')
            ->where('scheduled_start_at', '>=', Carbon::now()->startOfDay())
            ->with('teacher')
            ->orderBy('scheduled_start_at')
            ->limit(12)
            ->get();

        $view = $this->summary(
            user: $user,
            student: $student,
            relationship: $link?->pivot->relationship,
            isPrimary: (bool) ($link?->pivot->is_primary ?? false),
            subscription: $subscription,
            credit: $credit,
            outstanding: $outstanding,
            upcoming: $upcoming,
        );

        $view['profile'] = [
            'date_of_birth' => $student->date_of_birth?->toDateString(),
            'gender' => $student->gender,
            'timezone' => $student->timezone,
            'joined_at' => $student->created_at?->toDateString(),
        ];

        $view['flags'] = [
            'can_view_finance' => ParentChildScope::canViewFinance($user, $student->id),
            'can_view_progress' => ParentChildScope::canViewProgress($user, $student->id),
            'can_request_changes' => ParentChildScope::canRequestChanges($user, $student->id),
            'can_manage' => ParentChildScope::canManage($user, $student->id),
            'can_pay' => ParentChildScope::canPay($user, $student->id),
        ];

        return $view;
    }

    /**
     * ⭐⭐ الشكل الموحّد لأي ابن — بلا استعلامات (بياخد البيانات جاهزة).
     *
     * @param  array<string, mixed>|null  $credit
     * @param  array<string, mixed>|null  $outstanding
     * @param  Collection<int, Lesson>  $upcoming
     * @return array<string, mixed>
     */
    private function summary(
        User $user,
        Student $student,
        ?string $relationship,
        bool $isPrimary,
        ?Subscription $subscription,
        ?array $credit,
        ?array $outstanding,
        Collection $upcoming,
    ): array {
        $canViewFinance = ParentChildScope::canViewFinance($user, $student->id);
        $canViewProgress = ParentChildScope::canViewProgress($user, $student->id);

        return [
            'student' => [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'student_code' => $student->student_code,
                'status' => $student->status,
                'photo_url' => $student->photo_url ?? null,
            ],
            'relationship' => $relationship,
            'is_primary' => $isPrimary,
            'subscription' => $subscription ? $this->subscriptionView($subscription) : null,
            'lesson_credit' => $canViewFinance ? $credit : null,
            'upcoming_lessons' => $canViewProgress
                ? $upcoming->take(12)->map(fn (Lesson $lesson) => [
                    'id' => $lesson->id,
                    'scheduled_start_at' => (string) $lesson->scheduled_start_at,
                    'teacher_name' => $lesson->teacher?->full_name,
                    'status' => $lesson->status,
                ])->values()->all()
                : [],
            // ⭐ العملة الافتراضية من الإعدادات — مش محبوسة في الكود
            // (قاعدة 6 + SettingsGrepTest). لو الابن ملوش فواتير،
            // بنرجّع صفر بعملة المؤسسة.
            'outstanding' => $canViewFinance
                ? ($outstanding ?? [
                    'invoices' => 0,
                    'total' => 0.0,
                    'currency' => config('currencies.default'),
                ])
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function parentInfo(ParentModel $parent): array
    {
        return [
            'id' => $parent->id,
            'name' => $parent->name,
            'phone' => $parent->phone,
            'email' => $parent->email,
        ];
    }

    /** @return array<string, mixed> */
    private function subscriptionView(Subscription $subscription): array
    {
        $included = (int) ($subscription->lessons_included ?? 0);
        $used = $included > 0
            ? $included - (int) ($this->creditBalanceFor($subscription->id) ?? $included)
            : 0;

        $days = $subscription->schedule_weekdays ?? [];
        $dayNames = is_array($days)
            ? collect($days)->map(fn ($d) => self::WEEKDAYS[(int) $d] ?? (string) $d)->implode(' · ')
            : '';

        $daysLeft = $subscription->end_date
            ? (int) Carbon::today()->diffInDays($subscription->end_date->copy()->startOfDay(), false)
            : null;

        return [
            'id' => $subscription->id,
            'program_name' => $subscription->program?->name ?? '—',
            'teacher_name' => $subscription->teacher?->full_name,
            'status' => $subscription->status,
            'billing_type' => $subscription->billing_type,
            'start_date' => $subscription->start_date?->toDateString(),
            'end_date' => $subscription->end_date?->toDateString(),
            'days_left' => $daysLeft,
            'progress' => $included > 0 ? round(max(0, $used) / $included, 2) : 0,
            'weekday_names' => $dayNames,
            'start_time' => $subscription->schedule_start_time,
        ];
    }

    /**
     * ⭐⭐ **PR 3.3 — الرصيد المتبقي من الخدمة** (مش من الكاش).
     *
     * `LessonCreditService::balanceForSubscription` بيحسب الرصيد
     * من **حركات** `lesson_credit_transactions` (المصدر للحقيقة).
     */
    private function creditBalanceFor(int $subscriptionId): ?float
    {
        return app(LessonCreditService::class)->balanceForSubscription($subscriptionId);
    }

    /**
     * أرصدة الحصص الحالية لكل ابن.
     *
     * @param  int[]  $studentIds
     * @return array<int, array<string, mixed>>
     */
    private function creditAccountsFor(array $studentIds, Collection $includedByStudent): array
    {
        $service = app(LessonCreditService::class);

        return DB::table('lesson_credit_accounts')
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', ['active', 'depleted'])
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->first())
            ->map(fn ($row) => [
                // ⭐ PR 3.3 — الرصيد **المشتق** من الحركات.
                'balance' => $service->balance((int) $row->id),
                'included' => (float) ($includedByStudent[$row->student_id] ?? 0),
                'status' => $row->status,
                'expires_at' => $row->expires_at,
            ])
            ->all();
    }

    /**
     * الفواتير المستحقة (مش paid/void) لكل ابن.
     *
     * ⚠️ `sum()` بيرجّع **قرش خام** — بيعدّي على الـcast. لازم
     * `MoneyCast::fromAggregate` وإلا الرقم بيطلع ١٠٠× (قاعدة 6).
     *
     * @param  int[]  $studentIds
     * @return array<int, array<string, mixed>>
     */
    private function outstandingFor(array $studentIds): array
    {
        return DB::table('invoices')
            ->whereIn('student_id', $studentIds)
            ->whereNotIn('status', ['paid', 'void'])
            ->orderByDesc('issue_date')
            ->get()
            ->groupBy('student_id')
            ->map(function ($rows) {
                // ⭐ الفاتورة بتحمل عملتها؛ والافتراضي من الإعدادات.
                $currency = $rows->first()->currency ?? config('currencies.default');

                return [
                    'invoices' => $rows->count(),
                    'total' => (float) MoneyCast::fromAggregate($rows->sum('balance_due'), $currency),
                    'currency' => $currency,
                ];
            })
            ->all();
    }
}
