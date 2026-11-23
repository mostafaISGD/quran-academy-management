<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesParentChild;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\MemorizationRecord;
use App\Models\Payment;
use App\Models\ProgressRecord;
use App\Services\ParentPortalView;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * ⭐⭐⭐ **صفحة الابن الواحدة (PR 4.5).**
 *
 * ⭐ البوابة القديمة كانت ملخّص. هنا ولي الأمر بيفتح ابنه وبيشوف:
 * الملف والحضور والتقدم والحصص السابقة والفواتير والمدفوعات.
 *
 * ⭐ الحراسة طبقتين:
 *   ① `parent.only` — الحساب ولي أمر فعلاً.
 *   ② `ResolvesParentChild` — الطالب **ابن مؤكَّد** للحساب ده
 *      (`ParentChildScope`)، مش أي طالب في المؤسسة.
 *
 * ⭐ والأعلام بتحكم الأقسام: `can_view_progress` للأكاديمي،
 * و`can_view_finance` للمال (قبول الـroadmap).
 */
class ParentChildController extends Controller
{
    use ResolvesParentChild;

    public function __construct(private readonly ParentPortalView $view) {}

    /** ⭐ ملف الابن + ملخّص + الأعلام. */
    public function show(Request $request, int $student)
    {
        $parent = $this->parentOrFail($request);
        $child = $this->childOrFail($request, $student);

        return response()->json($this->view->child($request->user(), $parent, $child));
    }

    /** ⭐ سجل الحضور + ملخّص عددي. */
    public function attendance(Request $request, int $student)
    {
        $child = $this->childOrFail($request, $student);
        $this->requireProgress($request, $child);

        $rows = LessonAttendance::query()
            ->where('student_id', $child->id)
            ->with(['lesson.teacher', 'lesson.program'])
            ->orderByDesc('marked_at')
            ->limit(100)
            ->get();

        $summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];

        foreach ($rows as $row) {
            $summary[$row->status] = ($summary[$row->status] ?? 0) + 1;
        }

        return response()->json([
            'student_id' => $child->id,
            'summary' => $summary + ['total' => $rows->count()],
            'attendance' => $rows->map(fn (LessonAttendance $a) => [
                'lesson_id' => $a->lesson_id,
                'date' => $a->lesson?->scheduled_start_at?->toIso8601String(),
                'status' => $a->status,
                'late_minutes' => $a->late_minutes,
                'notes' => $a->notes,
                'teacher_name' => $a->lesson?->teacher?->full_name,
                'program_name' => $a->lesson?->program?->name,
            ])->values()->all(),
        ]);
    }

    /** ⭐ تقييمات التقدم + سجل الحفظ. */
    public function progress(Request $request, int $student)
    {
        $child = $this->childOrFail($request, $student);
        $this->requireProgress($request, $child);

        $records = ProgressRecord::query()
            ->where('student_id', $child->id)
            ->with(['program', 'level', 'teacher'])
            ->orderByDesc('recorded_at')
            ->limit(100)
            ->get();

        $memorization = MemorizationRecord::query()
            ->where('student_id', $child->id)
            ->with(['surah', 'teacher'])
            ->orderByDesc('recorded_at')
            ->limit(100)
            ->get();

        return response()->json([
            'student_id' => $child->id,
            'progress' => $records->map(fn (ProgressRecord $r) => [
                'id' => $r->id,
                'category' => $r->category,
                'score' => $r->score !== null ? (float) $r->score : null,
                'notes' => $r->notes,
                'recorded_at' => $r->recorded_at?->toIso8601String(),
                'program_name' => $r->program?->name,
                'level_name' => $r->level?->name,
                'teacher_name' => $r->teacher?->full_name,
            ])->values()->all(),
            'memorization' => $memorization->map(fn (MemorizationRecord $m) => [
                'id' => $m->id,
                'surah_name' => $m->surah?->name_ar,
                'from_ayah' => $m->from_ayah,
                'to_ayah' => $m->to_ayah,
                'quality' => $m->quality,
                'notes' => $m->notes,
                'recorded_at' => $m->recorded_at?->toIso8601String(),
                'teacher_name' => $m->teacher?->full_name,
            ])->values()->all(),
        ]);
    }

    /** ⭐ الحصص السابقة (اللي فات موعدها) — الأحدث الأول. */
    public function lessons(Request $request, int $student)
    {
        $child = $this->childOrFail($request, $student);
        $this->requireProgress($request, $child);

        $rows = Lesson::query()
            ->where('student_id', $child->id)
            ->where('scheduled_start_at', '<', Carbon::now())
            ->with(['teacher', 'program', 'attendance'])
            ->orderByDesc('scheduled_start_at')
            ->limit(50)
            ->get();

        return response()->json([
            'student_id' => $child->id,
            'lessons' => $rows->map(fn (Lesson $l) => [
                'id' => $l->id,
                'scheduled_start_at' => $l->scheduled_start_at?->toIso8601String(),
                'scheduled_end_at' => $l->scheduled_end_at?->toIso8601String(),
                'status' => $l->status,
                'lesson_type' => $l->lesson_type,
                'teacher_name' => $l->teacher?->full_name,
                'program_name' => $l->program?->name,
                'attendance_status' => $l->attendance?->status,
            ])->values()->all(),
        ]);
    }

    /** ⭐ الفواتير — `can_view_finance` إجباري. */
    public function invoices(Request $request, int $student)
    {
        $child = $this->childOrFail($request, $student);
        $this->requireFinance($request, $child);

        $rows = Invoice::query()
            ->where('student_id', $child->id)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $today = Carbon::today();

        return response()->json([
            'student_id' => $child->id,
            'invoices' => $rows->map(fn (Invoice $i) => [
                'id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'issue_date' => $i->issue_date?->toDateString(),
                'due_date' => $i->due_date?->toDateString(),
                'total' => $i->total,
                'paid_amount' => $i->paid_amount,
                'balance_due' => $i->balance_due,
                'currency' => $i->currency,
                'status' => $i->status,
                // ⭐ «متأخرة» بتتحسب من `due_date` — مش حالة مخزّنة (قاعدة 10).
                'is_overdue' => $i->due_date !== null
                    && $i->due_date->lt($today)
                    && ! in_array($i->status, ['paid', 'void'], true),
            ])->values()->all(),
        ]);
    }

    /** ⭐ المدفوعات — `can_view_finance` إجباري. */
    public function payments(Request $request, int $student)
    {
        $child = $this->childOrFail($request, $student);
        $this->requireFinance($request, $child);

        $rows = Payment::query()
            ->where('student_id', $child->id)
            ->with('invoice:id,invoice_number')
            ->orderByDesc('paid_at')
            ->limit(100)
            ->get();

        return response()->json([
            'student_id' => $child->id,
            'payments' => $rows->map(fn (Payment $p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'payment_method' => $p->payment_method,
                'status' => $p->status,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'transaction_reference' => $p->transaction_reference,
                'invoice_number' => $p->invoice?->invoice_number,
            ])->values()->all(),
        ]);
    }
}
