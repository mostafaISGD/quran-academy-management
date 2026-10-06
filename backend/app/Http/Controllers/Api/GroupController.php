<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupClass;
use App\Models\GroupMember;
use App\Models\Student;
use App\Models\WaitingListEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * المجموعات الأونلاين + قائمة الانتظار.
 *
 * ─────────────────────────────────────────────────────────────
 * ⭐ فصل مهم جدًا: **الطلب عام، والدخول محمي**.
 *
 *  - `GET  /groups`                 → عام (زي الأسعار)
 *  - `POST /groups/{g}/waitlist`    → **عام** — أي حد من غير حساب
 *  - `POST /groups/{g}/admit/...`   → `groups.manage` بس
 *
 * السبب: الموظف الاستقبال بياخد الطلب من العميل، بس **اللي بيقرّر
 * «يدخل» هو الإدارة**. لو الاستقبال يقدر يدخّل، حد هيدخل من غير
 * ما حد يبقى مسؤول عنه — ومفيش مراجعة.
 *
 * ─────────────────────────────────────────────────────────────
 * ⭐ الدخول **بيعلّم السطر بس** — مافيش طالب ولا اشتراك.
 *
 * القرار: الأدمن يدخّل وبيكمّل بنفسه. فلو عملنا طالب واشتراك
 * هنا، احنا غيّرنا القرار من غير ما حد قال.
 */
class GroupController extends Controller
{
    // ============================================================
    // ① العرض العام
    // ============================================================

    /**
     * كل المجموعات النشطة + الأرقام.
     *
     * ⚠️ الأرقام من `occupancy()` — **مكان واحد** في النظام.
     * لو حسبناها هنا تاني، الشاشة والـ API هياخدوا رقمين.
     *
     * ⭐⚠️ **الرد العام مافيش فيه `needs_attention`.**
     *
     * السبب: «فيه ناس مستنية وفيه مقعد فاضي» **إشارة شغل داخلية** —
     * بتقول لموظف الاستقبال «المجموعة دي محتاجة قرار دلوقتي».
     * دي مش معلومة بتتنشر للعموم.
     *
     * اللي **بيتعرض** عام: `waiting_count`. لأنه رقم بلا أسماء،
     * وهو اللي بيخلّي الأهل يستنّوا بدل ما يمشوا.
     *
     * الإشارة الداخلية جاية من `/groups/alerts` — المحمي.
     */
    public function index(Request $request)
    {
        $groups = GroupClass::query()
            ->active()
            ->displayOrder()
            ->withOccupancy()
            ->with(['program:id,name', 'level:id,name', 'teacher:id,display_name'])
            ->get();

        return response()->json([
            'data' => $groups->map(fn (GroupClass $g) => $this->present($g))->all(),
            'meta' => [
                'total' => $groups->count(),
                // ⭐ `null` مش رقم — عشان الواجهة ما تلبسش الصفر
                // على «مفيش تنبيه» لو الرد اتغيّر
                'alerts' => null,
            ],
        ]);
    }

    /** تفاصيل مجموعة واحدة — للعرض العام */
    public function show(GroupClass $group)
    {
        abort_unless($group->status === 'active', 404);

        $group->loadCount([
            'activeMembers as activeMembers_count',
            'waiting as waiting_count',
        ])->load(['program:id,name', 'level:id,name', 'teacher:id,display_name']);

        return response()->json(['data' => $this->present($group)]);
    }

    // ============================================================
    // ② ⭐ الطلب العام — أي حد من غير حساب
    // ============================================================

    /**
     * ⭐ حد يحطّ اسمه في قائمة الانتظار.
     *
     * **من غير تسجيل دخول** — وده المقصود. أكتر الناس اللي
     * بتطلب هي اللي لسه ما عندهمش اشتراك. لو ربطناها بحساب،
     * اللي إحنا عايزينه هو اللي مش هيقدر يسجّل.
     *
     * اللي بيتسجّل: `name` + `phone` بس.
     */
    public function joinWaitingList(Request $request, GroupClass $group)
    {
        abort_unless($group->isOpenForWaitlist(), 422, 'المجموعة مش بتستقبل طلبات دلوقتي');

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'min:6', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'name.min' => 'الاسم قصير أوي',
            'phone.min' => 'رقم الموبايل ناقص',
        ]);

        // ⭐ نفس الرقم مسجّل قبل كده في نفس المجموعة؟
        //
        // القيد في القاعدة بيمنع التكرار، بس لو استنيناه
        // هنرجّع 500 للمستخدم. هنا بنقولوله بلطف.
        $existing = WaitingListEntry::where('group_class_id', $group->id)
            ->where('phone', $data['phone'])
            ->first();

        if ($existing) {
            if ($existing->status === 'joined') {
                return response()->json([
                    'message' => 'أنت داخل المجموعة بالفعل',
                    'position' => 0,
                ], 200);
            }

            if ($existing->status === 'declined') {
                // ⭐ لو رجع يطلب — رجّعه للطابور تاني
                $existing->update([
                    'status' => 'waiting',
                    'notes' => $data['notes'] ?? $existing->notes,
                    'entered_at' => now(),
                ]);

                return $this->waitingResponse($existing->fresh());
            }

            return $this->waitingResponse($existing, 'أنت مسجّل في الطابور قبل كده');
        }

        $entry = WaitingListEntry::create([
            'organization_id' => $group->organization_id,
            'group_class_id' => $group->id,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'notes' => $data['notes'] ?? null,
            'status' => 'waiting',
            'entered_at' => now(),
        ]);

        app(\App\Services\AuditLogService::class)->logCreate(
            'waiting_list_entry',
            $entry->id,
            ['group' => $group->name],
            $request,
        );

        return $this->waitingResponse($entry);
    }

    /** الرد الموحّد «أنا في الترتيب كام» */
    private function waitingResponse(WaitingListEntry $entry, ?string $message = null)
    {
        return response()->json([
            'message' => $message ?? 'تم التسجيل — إحنا هنتصل بيك أول ما يفيق مكان',
            'position' => $entry->positionInLine(),
            'waiting_count' => WaitingListEntry::where('group_class_id', $entry->group_class_id)
                ->where('status', 'waiting')
                ->count(),
        ], 201);
    }

    // ============================================================
    // ③ رقم الجرس
    // ============================================================

    /**
     * ⭐ «فيه شغل؟» — رقم واحد للجرس في القائمة الجانبية.
     *
     * محمي بـ `groups.manage`: número «فيه ناس مستنية» مش حاجة
     * تتعرض لكل موظف في النظام.
     */
    public function alerts(Request $request)
    {
        $groups = GroupClass::query()
            ->active()
            ->withOccupancy()
            ->get()
            ->filter(fn (GroupClass $g) => $g->hasWaitingAndSpace());

        return response()->json([
            'count' => $groups->count(),
            'groups' => $groups->map(fn (GroupClass $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'waiting' => $g->occupancy()['waiting'],
                'seats_left' => $g->occupancy()['seats_left'],
            ])->all(),
        ]);
    }

    // ============================================================
    // ④ الإدارة — `groups.manage`
    // ============================================================

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        $group = GroupClass::create($data + [
            'organization_id' => $request->user()->organization_id ?? 1,
        ]);

        app(\App\Services\AuditLogService::class)->logCreate('group_class', $group->id, $data, $request);

        return response()->json([
            'message' => 'اتعملت المجموعة',
            'data' => $this->present($group->fresh()->loadCount(['activeMembers as activeMembers_count', 'waiting as waiting_count'])),
        ], 201);
    }

    public function update(Request $request, GroupClass $group)
    {
        $data = $request->validate($this->rules($group));

        $old = $group->only(array_keys($data));

        $group->update($data);

        app(\App\Services\AuditLogService::class)->logUpdate('group_class', $group->id, $old, $data, $request);

        return response()->json([
            'message' => 'اتحفظت المجموعة',
            'data' => $this->present($group->fresh()->loadCount(['activeMembers as activeMembers_count', 'waiting as waiting_count'])),
        ]);
    }

    /** ⭐ حذف = **أرشفة** — مش مسح. عشان السجل يفضل */
    public function destroy(Request $request, GroupClass $group)
    {
        $waiting = $group->waiting()->count();

        $group->update(['status' => 'archived']);
        $group->delete();

        app(\App\Services\AuditLogService::class)->log(
            'delete', 'group_class', $group->id,
            ['name' => $group->name, 'waiting' => $waiting],
            ['archived' => true],
            $request,
        );

        return response()->json([
            'message' => $waiting > 0
                ? "اتأرشفَت المجموعة —بس في {$waiting} ناس مستنيين، ركّز فيهم الأول"
                : 'اتأرشفَت المجموعة',
            'waiting_left' => $waiting,
        ]);
    }

    private function rules(?GroupClass $group = null): array
    {
        return [
            'program_id' => ['required', 'exists:programs,id'],
            'level_id' => ['nullable', 'exists:levels,id'],
            'teacher_id' => ['nullable', 'exists:teachers,id'],
            'name' => ['required', 'string', 'max:120'],

            /**
             * ⭐ `capacity` اختياري، و`min:1`.
             *
             * `min:1` مش `min:0`: الصفر معناها «مفيش حد يدخل»، وده
             * غلط — لو عايز يقفل، بيوقّفها (`paused`). والصفر لو
             * وصلنا غلط، المجموعة هتتنوّه مرتين ولا مرة.
             */
            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],

            'meeting_url' => ['nullable', 'url', 'max:500'],
            'meeting_provider' => ['nullable', 'string', 'max:50'],
            'weekday' => ['nullable', 'integer', 'min:0', 'max:6'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'status' => ['nullable', Rule::in(['active', 'paused', 'archived'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    // ============================================================
    // ⑤ الأعضاء
    // ============================================================

    public function members(GroupClass $group)
    {
        $members = $group->members()
            ->with('student:id,student_code,first_name,middle_name,last_name,status')
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $members->map(fn (GroupMember $m) => [
                'id' => $m->id,
                'status' => $m->status,
                'source' => $m->source,
                'joined_at' => $m->joined_at?->toIso8601String(),
                'left_at' => $m->left_at?->toIso8601String(),
                'notes' => $m->notes,
                'student' => $m->student ? [
                    'id' => $m->student->id,
                    'code' => $m->student->student_code,
                    'name' => trim($m->student->first_name.' '.$m->student->last_name),
                    'status' => $m->student->status,
                ] : null,
            ])->all(),
        ]);
    }

    /** ⭐ إضافة طالب — بيتحقق إن الطالب موجود فعلاً */
    public function addMember(Request $request, GroupClass $group)
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $student = Student::findOrFail($data['student_id']);

        $member = GroupMember::admit($group, $student, 'manual', $data['notes'] ?? null);

        app(\App\Services\AuditLogService::class)->logCreate(
            'group_member',
            $member->id,
            ['group' => $group->name, 'student' => $student->id],
            $request,
        );

        $group->loadCount([
            'activeMembers as activeMembers_count',
            'waiting as waiting_count',
        ]);

        return response()->json([
            'message' => 'اتضاف الطالب للمجموعة',
            'occupancy' => $group->occupancy(),
        ], 201);
    }

    /**
     * ⭐ شيل عضو — **مش حذف**.
     *
     * السبب: لو حذفنا السطر، ضاع تاريخ «كان في المجموعة من شهر».
     * `markLeft()` بيغيّر الحالة ويسجّل الوقت.
     */
    public function removeMember(Request $request, GroupClass $group, GroupMember $member)
    {
        abort_if($member->group_class_id !== $group->id, 404);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $member->markLeft($data['reason'] ?? null);

        app(\App\Services\AuditLogService::class)->log(
            'update', 'group_member', $member->id,
            ['status' => 'active'],
            ['status' => 'left', 'reason' => $data['reason'] ?? null],
            $request,
        );

        $group->loadCount([
            'activeMembers as activeMembers_count',
            'waiting as waiting_count',
        ]);

        return response()->json([
            'message' => 'الطالب خرج من المجموعة',
            'occupancy' => $group->occupancy(),
        ]);
    }

    // ============================================================
    // ⑥ قائمة الانتظار — العرض
    // ============================================================

    /** ⭐ الطابور بالترتيب، وكل سطر برقمه */
    public function waitingList(GroupClass $group)
    {
        $group->loadCount(['activeMembers as activeMembers_count', 'waiting as waiting_count']);

        $entries = WaitingListEntry::where('group_class_id', $group->id)
            ->waitingOrder()
            ->get();

        // ⭐ الرقم بيتحسب لكل سطر — **نفس الدالة** اللي في الـ public
        return response()->json([
            'data' => $entries->map(fn (WaitingListEntry $e) => $this->presentEntry($e))->all(),
            'occupancy' => $group->occupancy(),
        ]);
    }

    // ============================================================
    // ⑦ قائمة الانتظار — القرار
    // ============================================================

    /**
     * ⭐ ⭐ «ادخل أول واحد» — الزرار الأهم.
     *
     * مفيش خطوة في النص — القرار «يدخل» وخلاص. مافيش حالة
     * «اتعرض عليه»: اللي معروض عليه هو الأدمن نفسه.
     *
     * ⚠️ **مش** بيعمل طالب ولا اشتراك. الأدمن يكمّل بنفسه.
     * وده اللي `$test admitting_someone_only_marks_the_row` بيحميه.
     */
    public function admit(Request $request, GroupClass $group, WaitingListEntry $entry)
    {
        abort_if($entry->group_class_id !== $group->id, 404);

        if (! $entry->isWaiting()) {
            return response()->json([
                'message' => 'السجل ده مش في الطابور أصلاً',
            ], 422);
        }

        // ⚠️ **مش** بنرفض لو المجموعة امتلأت. الأدمن هو اللي شاف.
        $entry->markJoined($request->user());

        // ⭐ لو السطر مرتبط بطالب في النظام، نضيفه عضو كمان؟
        // لا — القرار «الأدمن يكمّل بنفسه». بنقوله بس.
        $studentId = $entry->student_id;

        app(\App\Services\AuditLogService::class)->log(
            'update', 'waiting_list_entry', $entry->id,
            ['status' => 'waiting'],
            ['status' => 'joined', 'group' => $group->name],
            $request,
        );

        $group->loadCount([
            'activeMembers as activeMembers_count',
            'waiting as waiting_count',
        ]);

        return response()->json([
            'message' => 'اتعلّم إنه داخل',
            // ⭐ تذكير: اللي فاضل شغل
            'next_step' => $studentId
                ? 'ضيف الطالب للقائمة الفعلية لو هو مرتبط بحساب'
                : 'السجل ده مربوطش بحساب — اعمل للطالب حساب واشتراك',
            'occupancy' => $group->occupancy(),
            'needs_member' => $studentId === null,
        ]);
    }

    /** ⭐ رفض — السطر بيفضل (عشان محدش يسجّل تاني على طول) */
    public function decline(Request $request, GroupClass $group, WaitingListEntry $entry)
    {
        abort_if($entry->group_class_id !== $group->id, 404);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $entry->markDeclined($data['reason'] ?? null);

        app(\App\Services\AuditLogService::class)->log(
            'update', 'waiting_list_entry', $entry->id,
            ['status' => 'waiting'],
            ['status' => 'declined', 'reason' => $data['reason'] ?? null],
            $request,
        );

        $group->loadCount([
            'activeMembers as activeMembers_count',
            'waiting as waiting_count',
        ]);

        return response()->json([
            'message' => 'اتشال من الطابور',
            'occupancy' => $group->occupancy(),
        ]);
    }

    // ============================================================
    // العرض
    // ============================================================

    /**
     * ⭐ شكل المجموعة في الـ API.
     *
     * الأرقام من `occupancy()` — **الطريقة الوحيدة**. أي حد حسبها
     * تاني كان هيبقى عندنا رقمين.
     */
    private function present(GroupClass $g): array
    {
        $o = $g->occupancy();

        return [
            'id' => $g->id,
            'name' => $g->name,
            'description' => $g->description,
            'status' => $g->status,

            'program' => $g->program ? [
                'id' => $g->program->id,
                'name' => $g->program->name,
            ] : null,

            'level' => $g->level ? [
                'id' => $g->level->id,
                'name' => $g->level->name,
            ] : null,

            'teacher' => $g->teacher ? [
                'id' => $g->teacher->id,
                'name' => $g->teacher->display_name,
            ] : null,

            'meeting_url' => $g->meeting_url,
            'weekday' => $g->weekday,
            'weekday_label' => $g->weekday !== null
                ? (GroupClass::WEEKDAYS[$g->weekday] ?? null)
                : null,
            'start_time' => $g->start_time,
            'end_time' => $g->end_time,
            'schedule_label' => $g->scheduleLabel(),

            // ⭐ الأرقام كلها مجمّوعة في مفتاح واحد
            'occupancy' => $o,
            'capacity' => $o['capacity'],
            'members_count' => $o['members'],
            'waiting_count' => $o['waiting'],
            'seats_left' => $o['seats_left'],
            'is_full' => $o['is_full'],
            'has_space' => $o['has_space'],

            // ⚠️ `needs_attention` **مش** هنا — شوف `index()`.
            // «فيه شغل» إشارة داخلية، مالهاش لازمة في الرد العام.

            'accepts_waitlist' => $g->isOpenForWaitlist(),
        ];
    }

    private function presentEntry(WaitingListEntry $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'phone' => $e->phone,
            'notes' => $e->notes,
            'status' => $e->status,
            // ⭐ الرقم بيتحسب — نفس الدالة في كل مكان
            'position' => $e->positionInLine(),
            'entered_at' => $e->entered_at?->toIso8601String(),
            'joined_at' => $e->joined_at?->toIso8601String(),
            'student_id' => $e->student_id,
            // ⭐ هل هو طالب في النظام؟ («ادخل» بتقوله يعمل إيه بعدها)
            'linked_student' => $e->student_id !== null,
        ];
    }
}
