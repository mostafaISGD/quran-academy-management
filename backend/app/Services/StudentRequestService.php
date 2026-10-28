<?php

namespace App\Services;

use App\Enums\StudentRequestStatus;
use App\Enums\StudentRequestType;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\ParentModel;
use App\Models\Student;
use App\Models\StudentRequest;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * ⭐⭐⭐ **دورة حياة طلب ولي الأمر (PR 4.5).**
 *
 * ⭐ الإنشاء → إشعار الإدارة. القرار → إشعار الولي + `audit_logs`.
 * السحب → من الولي، وللطلب المعلّق بس.
 *
 * ⭐⚠️ **الموافقة بتسجّل القرار — مش بتطبّقه.** الإلغاء/التأجيل/
 * التعليق ليهم مسارات إدارية مراجَعة (`LessonService`,
 * `SubscriptionPauseService`) وبيقفلوا على الحالة. لو الموافقة
 * طبّقت بنفسها، يبقى في **طريقين** لتغيير نفس الحصة — وواحد منهم
 * (الطلب) مابيعدّيش على سياسات الحصص ولا على سجل الحالة.
 */
final class StudentRequestService
{
    public function __construct(private readonly AuditLogService $audit) {}

    /** @return array<int, array<string, mixed>> */
    public function forParent(ParentModel $parent): array
    {
        return StudentRequest::query()
            ->where('parent_id', $parent->id)
            ->with(['student', 'lesson', 'subscription'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (StudentRequest $r) => $this->present($r))
            ->all();
    }

    /**
     * ⭐ إنشاء طلب — مع التحقق إن الحصة/الاشتراك **بتاع الابن ده**.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, ParentModel $parent, Student $student, array $data, Request $http): StudentRequest
    {
        $type = StudentRequestType::from($data['type']);

        $lesson = null;
        if ($type->needsLesson()) {
            $lesson = Lesson::query()
                ->whereKey($data['lesson_id'] ?? 0)
                ->where('student_id', $student->id)
                ->first();

            if ($lesson === null) {
                throw ValidationException::withMessages([
                    'lesson_id' => 'الحصة دي مش من حصص الابن ده',
                ]);
            }
        }

        $subscription = null;
        if ($type->needsSubscription()) {
            $subscription = Subscription::query()
                ->whereKey($data['subscription_id'] ?? 0)
                ->where('student_id', $student->id)
                ->first();

            if ($subscription === null) {
                throw ValidationException::withMessages([
                    'subscription_id' => 'الاشتراك ده مش من اشتراكات الابن ده',
                ]);
            }
        }

        if ($type->needsDate() && empty($data['requested_date'])) {
            throw ValidationException::withMessages([
                'requested_date' => 'حدد التاريخ الجديد المطلوب',
            ]);
        }

        $request = StudentRequest::create([
            'organization_id' => $student->organization_id,
            'student_id' => $student->id,
            'parent_id' => $parent->id,
            'lesson_id' => $lesson?->id,
            'subscription_id' => $subscription?->id,
            'type' => $type,
            'status' => StudentRequestStatus::Pending,
            'requested_date' => $type->needsDate() ? ($data['requested_date'] ?? null) : null,
            'reason' => $data['reason'],
        ]);

        $this->notifyManagers($request, $parent);
        $this->audit->log('create', 'student_request', $request->id, null, $this->snapshot($request), $http);

        return $request->load(['student', 'lesson', 'subscription']);
    }

    /** ⭐ سحب الطلب — الولي صاحبه، والحالة معلّقة بس. */
    public function withdraw(ParentModel $parent, StudentRequest $request, Request $http): StudentRequest
    {
        if ($request->parent_id !== $parent->id) {
            abort(403, 'الطلب ده مش بتاعك');
        }

        $this->assertPending($request);

        $before = $this->snapshot($request);
        $request->update(['status' => StudentRequestStatus::Withdrawn]);

        $this->audit->log('update', 'student_request', $request->id, $before, $this->snapshot($request), $http);

        return $request->fresh(['student', 'lesson', 'subscription']);
    }

    /**
     * ⭐ قرار الإدارة — نهائي، وبيتبعت للولي.
     */
    public function decide(
        User $admin,
        StudentRequest $request,
        StudentRequestStatus $status,
        ?string $notes,
        Request $http,
    ): StudentRequest {
        if ($status !== StudentRequestStatus::Approved && $status !== StudentRequestStatus::Rejected) {
            throw new \InvalidArgumentException('قرار غير صالح');
        }

        if ($status === StudentRequestStatus::Rejected && trim((string) $notes) === '') {
            throw ValidationException::withMessages([
                'admin_notes' => 'اكتب سبب الرفض — الولي لازم يعرف ليه',
            ]);
        }

        $this->assertPending($request);

        $before = $this->snapshot($request);

        $request->update([
            'status' => $status,
            'admin_notes' => $notes,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $this->notifyParent($request);
        $this->audit->log('update', 'student_request', $request->id, $before, $this->snapshot($request), $http, $admin->id);

        return $request->fresh(['student', 'lesson', 'subscription']);
    }

    /** @return array<string, mixed> */
    public function present(StudentRequest $request): array
    {
        return [
            'id' => $request->id,
            'type' => $request->type->value,
            'type_label' => $request->type->label(),
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'reason' => $request->reason,
            'admin_notes' => $request->admin_notes,
            'requested_date' => $request->requested_date?->toDateString(),
            'created_at' => $request->created_at?->toIso8601String(),
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'student' => $request->student ? [
                'id' => $request->student->id,
                'full_name' => $request->student->full_name,
                'student_code' => $request->student->student_code,
            ] : null,
            'parent' => $request->parent ? [
                'id' => $request->parent->id,
                'name' => $request->parent->name,
                'phone' => $request->parent->phone,
            ] : null,
            'lesson' => $request->lesson ? [
                'id' => $request->lesson->id,
                'scheduled_start_at' => $request->lesson->scheduled_start_at?->toIso8601String(),
                'status' => $request->lesson->status,
            ] : null,
            'subscription' => $request->subscription ? [
                'id' => $request->subscription->id,
                'status' => $request->subscription->status,
            ] : null,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    private function assertPending(StudentRequest $request): void
    {
        if ($request->status !== StudentRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'الطلب ده اتقرر فيه بالفعل',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(StudentRequest $request): array
    {
        return [
            'type' => $request->type->value,
            'status' => $request->status->value,
            'student_id' => $request->student_id,
            'parent_id' => $request->parent_id,
            'lesson_id' => $request->lesson_id,
            'subscription_id' => $request->subscription_id,
            'requested_date' => $request->requested_date?->toDateString(),
            'reviewed_by' => $request->reviewed_by,
        ];
    }

    /**
     * ⭐ إشعار كل من يملك `student_requests.manage` في نفس المؤسسة.
     *
     * ⭐ لو مفيش حد (مؤسسة جديدة)، الطلب لسه بيظهر في الصندوق —
     * الإشعار راحة مش شرط وصول.
     */
    private function notifyManagers(StudentRequest $request, ParentModel $parent): void
    {
        // ⭐ `permission()` scope بيرمي `PermissionDoesNotExist` لو
        // الصلاحية لسه مش في الجدول (مؤسسة ما اتعملش لها seed).
        // الإشعار راحة — مش شرط وصول الطلب للصندوق.
        if (! Permission::query()
            ->where('name', 'student_requests.manage')
            ->where('guard_name', 'web')
            ->exists()) {
            return;
        }

        $managers = User::query()
            ->where('organization_id', $request->organization_id)
            ->when($parent->user_id, fn ($q) => $q->where('id', '!=', $parent->user_id))
            ->permission('student_requests.manage')
            ->get();

        foreach ($managers as $manager) {
            Notification::create([
                'user_id' => $manager->id,
                'student_id' => $request->student_id,
                'event_type' => 'student_request_created',
                'channel' => 'in_app',
                'payload' => [
                    'request_id' => $request->id,
                    'type' => $request->type->value,
                    'type_label' => $request->type->label(),
                    'student_id' => $request->student_id,
                    'student_name' => $request->student?->full_name,
                    'parent_name' => $parent->name,
                ],
                'sent_at' => now(),
            ]);
        }
    }

    /** ⭐ إشعار ولي الأمر بالقرار. */
    private function notifyParent(StudentRequest $request): void
    {
        $user = $request->parent?->user;

        if ($user === null) {
            return;
        }

        Notification::create([
            'user_id' => $user->id,
            'student_id' => $request->student_id,
            'event_type' => 'student_request_'.$request->status->value,
            'channel' => 'in_app',
            'payload' => [
                'request_id' => $request->id,
                'type' => $request->type->value,
                'type_label' => $request->type->label(),
                'status' => $request->status->value,
                'status_label' => $request->status->label(),
                'admin_notes' => $request->admin_notes,
            ],
            'sent_at' => now(),
        ]);
    }
}
