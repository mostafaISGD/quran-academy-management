<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupClass;
use App\Models\GroupMember;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\WaitingListEntry;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ إدارة قائمة الانتظار — صفحة مستقلة للأدمن.
 *
 *  - `GET  /waitlist`                 → عرض كل طلبات الانتظار (فلترة/بحث)
 *  - `POST /waitlist`                 → إضافة انتظار جديد (بكل الحقول)
 *  - `PUT  /waitlist/{entry}`         → تعديل بيانات طالب انتظار
 *  - `DELETE /waitlist/{entry}`       → حذف طلب انتظار (حذف فعلي — مش علامات)
 *  - `POST /waitlist/{entry}/admit`   → قبول/إدخال (من إدارة الانتظار)
 *
 * ⚠️ **الصلاحية مش هنا** — كل الراوتات في `routes/api.php` عليها
 * `permission:groups.manage`. هنا متنسجلوش تاني:
 * الـ `Controller` الأساس في المشروع **مش** فيه trait
 * `AuthorizesRequests`، فـ `$this->authorize()` مش موجودة أصلاً
 * لو ناديناها كانت هترمي 500.
 */
class WaitlistController extends Controller
{
    /**
     * ⭐ عرض كل طلبات الانتظار مع فلترة وبحث.
     *
     * Query params:
     *  - status: waiting|joined|declined
     *  - group_id: فلتر بمجموعة معينة
     *  - package_id: فلتر بباقة معينة
     *  - search: بحث في الاسم/الموبايل/هاتف الولي/المستوى
     *  - per_page: تعداد (افتراضي 50، حد 200)
     */
    public function index(Request $request)
    {
        /**
         * ⚠️⚠️ **`when()` بيبعت الشرط نفسه كـ argument تاني — مش القيمة!**
         *
         * يعني `->when($request->filled('search'), fn ($q, $search) => ...)`
         * كانت `$search` فيها `true` مش النص اللي اليوزر كتبه،
         * فكل بحث كان بيتحوّل لـ `%1%` — وأي سطر فيه `1` في
         * الموبايل كان بيطلع. مفيش فلترة خالص عمليًا.
         *
         * الحل: بنقرا النص **بره** وبنclosure، مش من `when`.
         */
        $search = trim((string) $request->input('search', ''));

        $query = WaitingListEntry::with([
            'student:id,student_code,first_name,last_name,status',
            'package:id,name,price,lessons_count,billing_type,lesson_duration_minutes',
            'proposedGroup:id,name',
            'groupClass:id,name',
        ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('group_id'), fn ($q) => $q->where('group_class_id', $request->integer('group_id')))
            ->when($request->filled('package_id'), fn ($q) => $q->where('package_id', $request->integer('package_id')))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('parent_phone', 'like', "%{$search}%")
                        ->orWhere('current_level', 'like', "%{$search}%");
                });
            })
            ->waitingOrder();

        // ⚠️ `integer()` بترجع 0 لو الـ param مش موجود — فـ `??` مش هتشتغل
        $perPage = min($request->integer('per_page') ?: 50, 200);

        $entries = $query->paginate($perPage);

        return response()->json([
            // ⚠️ في Laravel 11 `items()` بترجع **array** مش Collection،
            // فـ `->map()` عليها كان بيرمي 500. `getCollection()` هي
            // اللي بترجع Collection زي أول.
            'data' => $entries->getCollection()->map(fn ($e) => $this->presentEntry($e))->all(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    /**
     * ⭐ إضافة طلب انتظار جديد — بكل الحقول.
     *
     * ⚠️ الطلب **محميّ** بـ `groups.manage` من الراوت — مش عام
     * زي `/groups/{g}/waitlist`.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            // ⚠️ نفس قاعدة `/groups/{g}/waitlist` بالظبط — رقم ناقص
            // (أقل من ٦) مش موبايل، ومينفعش نخزّنه ونلاقي نفسنا
            // بنتصل برقم غلط بعد شهر.
            'phone' => 'required|string|min:6|max:30',
            'parent_phone' => 'nullable|string|min:6|max:30',
            'current_level' => 'nullable|string|max:255',
            'package_id' => 'nullable|exists:subscription_plans,id',
            'proposed_group_id' => 'nullable|exists:group_classes,id',
            'group_class_id' => 'nullable|exists:group_classes,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $data['organization_id'] = $request->user()->organization_id;
        $data['status'] = 'waiting';

        // ⚠️ `entered_at` هو اللي بيحدد **ترتيب الطابور** — من غيره
        // `positionInLine()` بترمي 500 (المقارنة `<` مع null).
        // لازم يتحط هنا زي ما `/groups/{g}/waitlist` بيعمل.
        $data['entered_at'] = now();

        // ⭐ نفس الموبايل ما يسجّلش مرتين لنفس المجموعة (لو مجموعة محددة)
        if (! empty($data['group_class_id'])) {
            $exists = WaitingListEntry::where('group_class_id', $data['group_class_id'])
                ->where('phone', $data['phone'])
                ->where('status', 'waiting')
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'نفس الموبايل مسجّل في انتظار هذه المجموعة أصلاً',
                ], 422);
            }
        }

        $entry = WaitingListEntry::create($data);

        app(AuditLogService::class)->logCreate(
            'waiting_list_entry',
            $entry->id,
            ['name' => $entry->name, 'group' => $entry->group_class_id],
            $request,
        );

        return response()->json([
            'message' => 'تم إضافة طلب الانتظار',
            'data' => $this->presentEntry($entry->load(['package', 'proposedGroup', 'groupClass'])),
        ], 201);
    }

    /**
     * ⭐ تعديل بيانات طلب انتظار.
     */
    public function update(Request $request, WaitingListEntry $entry)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|min:6|max:30',
            'parent_phone' => 'nullable|string|min:6|max:30',
            'current_level' => 'nullable|string|max:255',
            'package_id' => 'nullable|exists:subscription_plans,id',
            'proposed_group_id' => 'nullable|exists:group_classes,id',
            'group_class_id' => 'nullable|exists:group_classes,id',
            'notes' => 'nullable|string|max:1000',
            'status' => 'sometimes|in:waiting,joined,declined',
        ]);

        $entry->update($data);

        return response()->json([
            'message' => 'تم تعديل طلب الانتظار',
            'data' => $this->presentEntry($entry->fresh()->load(['package', 'proposedGroup', 'groupClass'])),
        ]);
    }

    /**
     * ⭐ حذف طلب انتظار — حذف فعلي من قاعدة البيانات.
     *
     * ⚠️ مش زي `markDeclined` اللي بتبقى السطر للمراجعة.
     * ده حذف كامل — مش محتاجه في السجلات.
     */
    public function destroy(Request $request, WaitingListEntry $entry)
    {
        app(AuditLogService::class)->logDelete(
            'waiting_list_entry',
            $entry->id,
            ['name' => $entry->name, 'phone' => $entry->phone],
            $request,
        );

        $entry->delete();

        return response()->json([
            'message' => 'تم حذف طلب الانتظار',
        ]);
    }

    /**
     * ⭐ «ادخل» من صفحة الانتظار — بيكمّل الشغل كله.
     *
     * بيشتغل زي `GroupController@admit` بالظبط:
     *  1. يعلم السطر `joined`
     *  2. يبني/يلاقي الطالب
     *  3. يدخله عضو في المجموعة
     *  4. يعمل اشتراك شهري بالباقة
     */
    public function admit(Request $request, WaitingListEntry $entry)
    {
        if (! $entry->isWaiting()) {
            return response()->json([
                'message' => 'السجل ده مش في الطابور أصلاً',
            ], 422);
        }

        // ⚠️ بنتحقق **بره** الـ transaction — لو رمينا جوّاها الـ rollback
        // هيلغي كل حاجة عملناها قبل ما الـ exception يطلع.
        $groupId = $entry->group_class_id ?? $entry->proposed_group_id;

        if (! $groupId) {
            return response()->json([
                'message' => 'مفيش مجموعة محددة للسطر — اختار مجموعة قبل ما تدخله',
            ], 422);
        }

        $orgId = $entry->organization_id;

        return DB::transaction(function () use ($request, $entry, $orgId, $groupId) {
            $entry->markJoined($request->user());

            /**
             * ⭐ **نثبّت المجموعة اللي دخلها فعلاً.**
             *
             * فوقه جبنا `$groupId` من `group_class_id ?? proposed_group_id`.
             * فلو جابها من **المقترحة**، الـ `group_class_id` بيفضل
             * فاضي — والسطر في الصفحة بيقول «مش مربوط بأي مجموعة»
             * رغم إنه داخل واحدة! يعني بعد أسبوعين من admissions
             * مش هعرف راح فين.
             *
             * فنثبّت هنا: المجموعة اللي دخلها **هي** المجموعة المربوطة.
             */
            if (! $entry->group_class_id) {
                $entry->update(['group_class_id' => $groupId]);
            }

            // ===== 1) الطالب =====
            $student = null;
            if ($entry->student_id) {
                $student = Student::find($entry->student_id);
            }

            if (! $student) {
                $student = Student::where('organization_id', $orgId)
                    ->where('phone', $entry->phone)
                    ->first();
            }

            if (! $student) {
                $parts = preg_split('/\s+/u', trim($entry->name), 2);
                $student = Student::create([
                    'organization_id' => $orgId,
                    'student_code' => 'STU-' . strtoupper(uniqid()),
                    'first_name' => $parts[0] ?? $entry->name,
                    'last_name' => $parts[1] ?? '',
                    'phone' => $entry->phone,
                    'status' => 'active',
                ]);
            }

            $entry->update(['student_id' => $student->id]);

            // ===== 2) العضوية =====
            $group = GroupClass::findOrFail($groupId);

            $member = GroupMember::admit($group, $student, 'waitlist', $entry->notes);

            // ===== 3) الاشتراك الشهري =====
            $subscription = null;
            if ($group->program_id) {
                $subscription = Subscription::where('student_id', $student->id)
                    ->where('program_id', $group->program_id)
                    ->whereIn('status', ['active', 'paused'])
                    ->first();

                if (! $subscription) {
                    $plan = SubscriptionPlan::where('program_id', $group->program_id)
                        ->where('billing_type', 'monthly')
                        ->where('status', 'active')
                        ->first()
                        ?? SubscriptionPlan::whereNull('program_id')
                            ->where('billing_type', 'monthly')
                            ->where('status', 'active')
                            ->first();

                    $subscription = Subscription::create([
                        'organization_id' => $orgId,
                        'student_id' => $student->id,
                        'plan_id' => $plan?->id,
                        'program_id' => $group->program_id,
                        'teacher_id' => $group->teacher_id,
                        'start_date' => now()->toDateString(),
                        'end_date' => now()->addMonth()->toDateString(),
                        'billing_type' => 'monthly',
                        'price' => $plan?->price ?? 0,
                        'currency' => $plan?->currency ?? 'EGP',
                        'lesson_duration_minutes' => $plan?->lesson_duration_minutes,
                        'lessons_included' => $plan?->lessons_count,
                        'status' => 'active',
                    ]);
                }
            }

            app(AuditLogService::class)->log(
                'update', 'waiting_list_entry', $entry->id,
                ['status' => 'waiting'],
                ['status' => 'joined', 'group' => $group->name, 'student_id' => $student->id],
                $request,
            );

            return response()->json([
                'message' => 'اتضاف الطالب للمجموعة واتعامل اشتراكه',
                'student_id' => $student->id,
                'member_id' => $member->id,
                'subscription_id' => $subscription?->id,
                'occupancy' => $group->occupancy(),
            ], 201);
        });
    }

    /**
     * ⭐ عرض مدخل واحد بتنسيق موحد.
     */
    private function presentEntry(WaitingListEntry $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'phone' => $e->phone,
            'parent_phone' => $e->parent_phone,
            'current_level' => $e->current_level,
            'notes' => $e->notes,
            'status' => $e->status,
            'position' => $e->isWaiting() ? $e->positionInLine() : 0,
            'entered_at' => $e->entered_at?->toIso8601String(),
            'joined_at' => $e->joined_at?->toIso8601String(),
            'group' => $e->groupClass ? [
                'id' => $e->groupClass->id,
                'name' => $e->groupClass->name,
            ] : null,
            'proposed_group' => $e->proposedGroup ? [
                'id' => $e->proposedGroup->id,
                'name' => $e->proposedGroup->name,
            ] : null,
            'package' => $e->package ? [
                'id' => $e->package->id,
                'name' => $e->package->name,
                'price' => $e->package->price,
                'lessons_count' => $e->package->lessons_count,
                'lesson_duration_minutes' => $e->package->lesson_duration_minutes,
            ] : null,
        ];
    }
}