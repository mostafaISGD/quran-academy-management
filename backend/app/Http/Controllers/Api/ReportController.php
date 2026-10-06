<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Lead;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\MemorizationRecord;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\Teacher;
use App\Models\TeacherEarning;
use App\Models\TeacherPayment;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function dashboardSummary(Request $request)
    {
        $today = Carbon::today();
        $todayLessons = Lesson::query()->whereDate('scheduled_start_at', $today);
        $monthStart = $today->copy()->startOfMonth();
        $monthlyRevenue = Payment::query()->where('status', 'completed')->whereRange('paid_at', $monthStart, $today)->sum('amount');
        $expiringSoon = Subscription::query()->where('status', 'active')->whereRange('end_date', $today, $today->copy()->addDays(3))->count();
        $pendingTeacherPayments = TeacherPayment::query()->whereIn('status', ['pending'])->sum('amount');
        $unscheduledLeads = Lead::query()->whereIn('status', ['new', 'contacted'])->count();

        return response()->json([
            'today' => [
                'lessons_total' => (clone $todayLessons)->count(),
                'lessons_completed' => (clone $todayLessons)->where('status', 'completed')->count(),
                'lessons_upcoming' => (clone $todayLessons)->whereIn('status', ['scheduled', 'confirmed'])->count(),
            ],
            'totals' => [
                'active_students' => Student::where('status', 'active')->count(),
                'active_teachers' => Teacher::where('status', 'active')->count(),
                // ⭐ رقم خام — مش `number_format`
                'monthly_revenue' => $monthlyRevenue,
            ],
            'alerts' => [
                'subscriptions_expiring_soon' => $expiringSoon,
                'pending_teacher_payments' => $pendingTeacherPayments,
                'unscheduled_leads' => $unscheduledLeads,
            ],
        ]);
    }

    public function financial(Request $request)
    {
        $start = $request->filled('from') ? Carbon::parse($request->string('from')) : Carbon::now()->startOfMonth();
        $end = $request->filled('to') ? Carbon::parse($request->string('to')) : Carbon::now()->endOfMonth();

        $payments = Payment::query()->where('status', 'completed')->whereRange('paid_at', $start, $end)->get();
        // ⭐ أرقام خام — `number_format` هنا كان بيرجّع نص
        // `"1234.50"`، فالواجهة كانت بتشيل الفاصلة وترجع رقم
        // (اقتباس مرتين: نص منسّق → رقم → نص منسّق).
        $byCurrency = $payments->groupBy('currency')
            ->map(fn ($group) => ['total' => $group->sum('amount'), 'count' => $group->count()]);
        $byMethod = $payments->groupBy('payment_method')
            ->map(fn ($group) => ['total' => $group->sum('amount'), 'count' => $group->count()]);
        $refunds = Refund::query()->whereRange('processed_at', $start, $end)->get();
        $teacherEarnings = TeacherEarning::query()->whereRange('earning_date', $start, $end)->get();
        $teacherPaymentsMade = TeacherPayment::query()->whereRange('paid_at', $start, $end)->get();

        return response()->json([
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'revenue' => [
                'by_currency' => $byCurrency,
                'by_method' => $byMethod,
                'total_refunded' => $refunds->sum('amount'),
            ],
            'teacher_costs' => [
                'gross_earnings' => $teacherEarnings->sum('amount'),
                'payments_made' => $teacherPaymentsMade->sum('amount'),
            ],
        ]);
    }

    public function academic(Request $request)
    {
        $start = $request->filled('from') ? Carbon::parse($request->string('from')) : Carbon::now()->startOfMonth();
        $end = $request->filled('to') ? Carbon::parse($request->string('to')) : Carbon::now()->endOfMonth();

        $lessons = Lesson::query()->whereRange('scheduled_start_at', $start, $end);
        $attendance = LessonAttendance::query()->whereRange('marked_at', $start, $end);
        $memorization = MemorizationRecord::query()->whereRange('recorded_at', $start, $end);

        return response()->json([
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'lessons' => [
                'total' => (clone $lessons)->count(),
                'completed' => (clone $lessons)->where('status', 'completed')->count(),
                'cancelled' => (clone $lessons)->where('status', 'cancelled')->count(),
                'student_absent' => (clone $lessons)->where('status', 'student_absent')->count(),
                'teacher_absent' => (clone $lessons)->where('status', 'teacher_absent')->count(),
            ],
            'attendance' => [
                'present' => (clone $attendance)->where('status', 'present')->count(),
                'absent' => (clone $attendance)->where('status', 'absent')->count(),
                'late' => (clone $attendance)->where('status', 'late')->count(),
            ],
            'memorization' => ['total' => (clone $memorization)->count(), 'avg_quality' => round((clone $memorization)->avg('quality') ?? 0, 1)],
            'subscriptions' => ['active' => Subscription::where('status', 'active')->count(), 'expired' => Subscription::where('status', 'expired')->count()],
        ]);
    }

    public function sales(Request $request)
    {
        $start = $request->filled('from') ? Carbon::parse($request->string('from')) : Carbon::now()->startOfMonth();
        $end = $request->filled('to') ? Carbon::parse($request->string('to')) : Carbon::now()->endOfMonth();

        $leads = Lead::query()->whereRange('created_at', $start, $end);
        $assessments = Assessment::query()->whereRange('scheduled_at', $start, $end);
        $totalLeads = (clone $leads)->count();
        $converted = (clone $leads)->where('status', 'converted')->count();
        $conversionRate = $totalLeads > 0 ? round(($converted / $totalLeads) * 100, 1) : 0;

        return response()->json([
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            // ⭐ رقم خام — الـ `%` بتتحط في العرض (مش في الداتابيز)
            'leads' => ['total' => $totalLeads, 'converted' => $converted, 'conversion_rate' => $conversionRate],
            'trials' => [
                'total' => (clone $assessments)->count(),
                'ready_to_subscribe' => (clone $assessments)->where('result', 'ready_to_subscribe')->count(),
                'needs_follow_up' => (clone $assessments)->where('result', 'needs_follow_up')->count(),
                'not_suitable' => (clone $assessments)->where('result', 'not_suitable')->count(),
            ],
        ]);
    }

    // ===== NEW REPORTS =====

    // 1. Attendance Report - تقرير الحضور والغياب
    public function attendance(Request $request)
    {
        $start = $request->filled('from') ? Carbon::parse($request->string('from')) : Carbon::now()->startOfMonth();
        $end = $request->filled('to') ? Carbon::parse($request->string('to')) : Carbon::now()->endOfMonth();
        $studentId = $request->filled('student_id') ? $request->integer('student_id') : null;
        $teacherId = $request->filled('teacher_id') ? $request->integer('teacher_id') : null;
        $programId = $request->filled('program_id') ? $request->integer('program_id') : null;

        $attendanceQuery = LessonAttendance::query()->whereRange('marked_at', $start, $end);
        $lessonQuery = Lesson::query()->whereRange('scheduled_start_at', $start, $end);

        if ($studentId) {
            $attendanceQuery->whereHas('lesson', fn($q) => $q->where('student_id', $studentId));
            $lessonQuery->where('student_id', $studentId);
        }
        if ($teacherId) {
            $attendanceQuery->whereHas('lesson', fn($q) => $q->where('teacher_id', $teacherId));
            $lessonQuery->where('teacher_id', $teacherId);
        }
        if ($programId) {
            $attendanceQuery->whereHas('lesson', fn($q) => $q->where('program_id', $programId));
            $lessonQuery->where('program_id', $programId);
        }

        // Overall stats
        $totalLessons = (clone $lessonQuery)->count();
        $completedLessons = (clone $lessonQuery)->where('status', 'completed')->count();
        $cancelledLessons = (clone $lessonQuery)->where('status', 'cancelled')->count();
        $studentAbsentLessons = (clone $lessonQuery)->where('status', 'student_absent')->count();
        $teacherAbsentLessons = (clone $lessonQuery)->where('status', 'teacher_absent')->count();

        $totalAttendance = (clone $attendanceQuery)->count();
        $presentCount = (clone $attendanceQuery)->where('status', 'present')->count();
        $absentCount = (clone $attendanceQuery)->where('status', 'absent')->count();
        $lateCount = (clone $attendanceQuery)->where('status', 'late')->count();

        $attendanceRate = $totalAttendance > 0 ? round(($presentCount / $totalAttendance) * 100, 1) : 0;

        // Per student breakdown
        $studentStats = LessonAttendance::query()->whereRange('marked_at', $start, $end)
            ->with('lesson.student:id,first_name,last_name,student_code')
            ->get()
            ->groupBy('lesson.student_id')
            ->map(function ($records, $studentId) {
                $student = $records->first()->lesson->student ?? null;
                $total = $records->count();
                $present = $records->where('status', 'present')->count();
                $absent = $records->where('status', 'absent')->count();
                $late = $records->where('status', 'late')->count();
                return [
                    'student_id' => $studentId,
                    'student_name' => $student ? $student->full_name : 'غير معروف',
                    'student_code' => $student?->student_code ?? '—',
                    'total_sessions' => $total,
                    'present' => $present,
                    'absent' => $absent,
                    'late' => $late,
                    'attendance_rate' => $total > 0 ? round(($present / $total) * 100, 1) : 0,
                ];
            })
            ->values();

        // Daily breakdown
        $dailyStats = LessonAttendance::query()->whereRange('marked_at', $start, $end)
            ->selectRaw('DATE(marked_at) as date, status, COUNT(*) as count')
            ->groupBy('date', 'status')
            ->get()
            ->groupBy('date')
            ->map(function ($records) {
                $total = $records->sum('count');
                $present = $records->where('status', 'present')->sum('count');
                $absent = $records->where('status', 'absent')->sum('count');
                $late = $records->where('status', 'late')->sum('count');
                return [
                    'date' => $records->first()->date,
                    'total' => $total,
                    'present' => $present,
                    'absent' => $absent,
                    'late' => $late,
                    'rate' => $total > 0 ? round(($present / $total) * 100, 1) : 0,
                ];
            })
            ->values();

        return response()->json([
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'summary' => [
                'total_lessons_scheduled' => $totalLessons,
                'completed_lessons' => $completedLessons,
                'cancelled_lessons' => $cancelledLessons,
                'student_absent_lessons' => $studentAbsentLessons,
                'teacher_absent_lessons' => $teacherAbsentLessons,
                'total_attendance_records' => $totalAttendance,
                'present' => $presentCount,
                'absent' => $absentCount,
                'late' => $lateCount,
                'attendance_rate' => $attendanceRate . '%',
            ],
            'by_student' => $studentStats,
            'by_date' => $dailyStats,
        ]);
    }

    // 2. Subscription Status Report - تقرير حالة الاشتراكات
    public function subscriptions(Request $request)
    {
        $start = $request->filled('from') ? Carbon::parse($request->string('from')) : Carbon::now()->startOfMonth();
        $end = $request->filled('to') ? Carbon::parse($request->string('to')) : Carbon::now()->endOfMonth();
        $status = $request->filled('status') ? $request->string('status') : null;
        $programId = $request->filled('program_id') ? $request->integer('program_id') : null;
        $teacherId = $request->filled('teacher_id') ? $request->integer('teacher_id') : null;

        $query = Subscription::query()->with(['student:id,first_name,last_name,student_code,status,country_code', 'program:id,name', 'teacher:id,full_name']);

        if ($status) $query->where('status', $status);
        if ($programId) $query->where('program_id', $programId);
        if ($teacherId) $query->where('teacher_id', $teacherId);

        $subscriptions = $query->get();

        // Summary counts
        $total = $subscriptions->count();
        $active = $subscriptions->where('status', 'active')->count();
        $expired = $subscriptions->where('status', 'expired')->count();
        $paused = $subscriptions->where('status', 'paused')->count();
        $cancelled = $subscriptions->where('status', 'cancelled')->count();

        // Expiring soon (within 7 days)
        $expiringSoon = $subscriptions->where('status', 'active')
            ->whereNotNull('end_date')
            ->filter(fn($s) => Carbon::parse($s->end_date)->between(Carbon::now(), Carbon::now()->addDays(7)))
            ->count();

        // Revenue by currency
        $revenueByCurrency = $subscriptions->where('status', 'active')
            ->groupBy('currency')
            ->map(fn($group) => ['total' => $group->sum('price'), 'count' => $group->count()]);

        // By program
        $byProgram = $subscriptions->groupBy('program_id')
            ->map(function ($group, $programId) {
                $program = $group->first()->program;
                return [
                    'program_id' => (int)$programId,
                    'program_name' => $program?->name ?? 'غير معروف',
                    'total' => $group->count(),
                    'active' => $group->where('status', 'active')->count(),
                    'expired' => $group->where('status', 'expired')->count(),
                    'revenue' => $group->where('status', 'active')->sum('price'),
                ];
            })
            ->values();

        // By teacher
        $byTeacher = $subscriptions->groupBy('teacher_id')
            ->map(function ($group, $teacherId) {
                $teacher = $group->first()->teacher;
                return [
                    'teacher_id' => (int)$teacherId,
                    'teacher_name' => $teacher?->full_name ?? 'بدون معلم',
                    'total_students' => $group->count(),
                    'active' => $group->where('status', 'active')->count(),
                    'expired' => $group->where('status', 'expired')->count(),
                ];
            })
            ->values();

        // Detailed list
        $details = $subscriptions->map(function ($sub) {
            $daysLeft = $sub->end_date ? Carbon::parse($sub->end_date)->diffInDays(Carbon::now(), false) : null;
            return [
                'id' => $sub->id,
                'student' => [
                    'id' => $sub->student->id,
                    'name' => $sub->student->full_name,
                    'code' => $sub->student->student_code,
                    'status' => $sub->student->status,
                ],
                'program' => $sub->program?->name ?? '—',
                'teacher' => $sub->teacher?->full_name ?? '—',
                'start_date' => $sub->start_date,
                'end_date' => $sub->end_date,
                'days_left' => $daysLeft,
                'is_expiring_soon' => $daysLeft !== null && $daysLeft >= 0 && $daysLeft <= 7,
                'status' => $sub->status,
                'billing_type' => $sub->billing_type,
                'price' => $sub->price,
                'currency' => $sub->currency,
                'lessons_included' => $sub->lessons_included,
                'lesson_duration_minutes' => $sub->lesson_duration_minutes,
            ];
        });

        return response()->json([
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'summary' => [
                'total' => $total,
                'active' => $active,
                'expired' => $expired,
                'paused' => $paused,
                'cancelled' => $cancelled,
                'expiring_soon' => $expiringSoon,
            ],
            'revenue_by_currency' => $revenueByCurrency,
            'by_program' => $byProgram,
            'by_teacher' => $byTeacher,
            'details' => $details,
        ]);
    }
}
