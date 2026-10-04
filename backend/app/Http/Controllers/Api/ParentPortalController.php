<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\ParentModel;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * صفحة ولي الأمر.
 *
 * المبدأ: البا��ة ترجع بيانات الأبناء المسجّلين عند ولي الأمر بس.
 * كل استعلام بيبدأ من `parents.user_id = المستخدم الحالي` — فمفيش
 * أي طريقة نعدّل الـ id ونشوف طالب تاني.
 */
class ParentPortalController extends Controller
{
    /** أسماء الأيام لعرض الموعد الأسبوعي */
    private const WEEKDAYS = [
        0 => 'الأحد', 1 => 'الإثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء',
        4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت',
    ];

    public function children(Request $request)
    {
        $parent = ParentModel::where('user_id', $request->user()->id)->first();

        if (!$parent) {
            return response()->json([
                'message' => 'مفيش ملف ولي أمر مرتبط بالحساب ده',
            ], 404);
        }

        // الأبناء المسجّلون فعلاً — مع صلة القرابة
        $links = $parent->students()->get()->map(function ($student) {
            return [
                'student' => $student,
                'relationship' => $student->pivot->relationship,
                'is_primary' => (bool) $student->pivot->is_primary,
            ];
        });

        if ($links->isEmpty()) {
            return response()->json([
                'parent' => $this->parentInfo($parent),
                'children' => [],
            ]);
        }

        $studentIds = $links->pluck('student.id')->all();

        // الاشتراك الحالي لكل ابن — نشط أو متوقف، الأحدث أول
        $subscriptions = Subscription::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', ['active', 'paused'])
            ->with(['program', 'teacher'])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('student_id');

        // رصيد الحصص الحالي لكل اشتراك
        $includedByStudent = Subscription::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', ['active', 'paused'])
            ->orderByDesc('created_at')
            ->get(['student_id', 'lessons_included'])
            ->groupBy('student_id')
            ->map(fn ($rows) => (int) ($rows->first()->lessons_included ?? 0));

        $credits = $this->creditAccountsFor($studentIds, $includedByStudent);

        // الحصص القادمة
        $upcoming = Lesson::query()
            ->whereIn('student_id', $studentIds)
            ->where('status', 'scheduled')
            ->where('scheduled_start_at', '>=', Carbon::now()->startOfDay())
            ->with('teacher')
            ->orderBy('scheduled_start_at')
            ->limit(60)
            ->get()
            ->groupBy('student_id');

        // الفواتير المفتوحة (المستحقة)
        $outstanding = $this->outstandingFor($studentIds);

        $children = $links->map(function (array $link) use ($subscriptions, $credits, $upcoming, $outstanding) {
            $student = $link['student'];
            $studentId = $student->id;

            $subscription = ($subscriptions[$studentId] ?? collect())->first();

            return [
                'student' => [
                    'id' => $studentId,
                    'full_name' => $student->full_name,
                    'student_code' => $student->student_code,
                    'status' => $student->status,
                    'photo_url' => $student->photo_url ?? null,
                ],
                'relationship' => $link['relationship'],
                'subscription' => $subscription ? $this->subscriptionView($subscription) : null,
                'lesson_credit' => ($credits[$studentId] ?? null),
                'upcoming_lessons' => ($upcoming[$studentId] ?? collect())
                    ->take(12)
                    ->map(fn (Lesson $lesson) => [
                        'id' => $lesson->id,
                        'scheduled_start_at' => (string) $lesson->scheduled_start_at,
                        'teacher_name' => $lesson->teacher?->full_name,
                        'status' => $lesson->status,
                    ])
                    ->values()
                    ->all(),
                'outstanding' => $outstanding[$studentId] ?? [
                    'invoices' => 0, 'total' => 0.0, 'currency' => 'EGP',
                ],
            ];
        })->values();

        return response()->json([
            'parent' => $this->parentInfo($parent),
            'children' => $children,
        ]);
    }

    private function parentInfo(ParentModel $parent): array
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

    /** الرصيد المتبقي من حساب الحصص النشط للاشتراك */
    private function creditBalanceFor(int $subscriptionId): ?float
    {
        $row = \Illuminate\Support\Facades\DB::table('lesson_credit_accounts')
            ->where('subscription_id', $subscriptionId)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();

        return $row ? (float) $row->current_balance : null;
    }

    /**
     * أرصدة الحصص الحالية لكل ابن.
     *
     * `included` بييجي من اشتراكه (lessons_included) مش من الحساب —
     * الحساب فيه الرصيد المتبقي بس.
     *
     * @param  int[]  $studentIds
     * @return array<int, array<string, mixed>>
     */
    private function creditAccountsFor(array $studentIds, $includedByStudent): array
    {
        $includedByStudent = $includedByStudent instanceof \Illuminate\Support\Collection
            ? $includedByStudent
            : collect($includedByStudent);

        return \Illuminate\Support\Facades\DB::table('lesson_credit_accounts')
            ->whereIn('student_id', $studentIds)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->first())
            ->map(fn ($row) => [
                'balance' => (float) $row->current_balance,
                'included' => (float) ($includedByStudent[$row->student_id] ?? 0),
                'status' => $row->status,
                'expires_at' => $row->expires_at,
            ])
            ->all();
    }

    /**
     * الفواتير المستحقة (مش paid/void) لكل ابن.
     *
     * @param  int[]  $studentIds
     * @return array<int, array<string, mixed>>
     */
    private function outstandingFor(array $studentIds): array
    {
        return \Illuminate\Support\Facades\DB::table('invoices')
            ->whereIn('student_id', $studentIds)
            ->whereNotIn('status', ['paid', 'void'])
            ->orderByDesc('issue_date')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => [
                'invoices' => $rows->count(),
                'total' => round((float) $rows->sum('balance_due'), 2),
                'currency' => $rows->first()->currency ?? 'EGP',
            ])
            ->all();
    }
}
