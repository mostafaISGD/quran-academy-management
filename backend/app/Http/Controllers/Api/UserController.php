<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\RoleGrantService;
use App\Services\UserStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * ⭐⭐⭐ إدارة الحسابات من مكان واحد.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ ليه كل العمليات الحساسة **برّه** `Route::group` العادي
 *
 * ⭐ الحارس الحقيقي للعمليات دي مش الـ`permission:` — هو
 * `UserStatusService::assertNotSelf()` و `assertNotLastAdmin()`.
 *
 * ⭐ لأن الإخطاء.jacksonjerra بتاعت الـpermission بتيجي قبل
 * الدخول، فلو حد أفلت من الـpermission (ساعة، صلاحية مش بتتحدث،
 * typo في الاسم) القيود بتتنفّذ برضه. القيود في الخدمة عشان
 * **الطريق الوحيد** يعدّي من غيرها.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ ليه **مافيش** endpoint «احذف»
 *
 * ⭐ عشان **مفيشوش**. الحساب بيتأرشف (`status = 'archived'`)
 * وبس. الحذف الفعلي بيبقى:
 *
 *   ① يمسح كل سجل الحضور والكشوف والدرجات Wartifacts
 *   ② بيكسر `audit_logs` — ومين عمل إيه على مين بيضيع
 *   ③ بيكسر رأس الشجرة المالي (الفواتير والدفعات بتشير للمستخدم)
 *
 * ⭐ كل ده بيحصل بـ`TRUNCATE` في SQL اجتهاداً مش في زرار. لو
 *   ③ بيكسر رأس الشجرة المالي (الفواتير والدفعات بتشير للمستخدم)
 *
 * @see \App\Services\UserStatusService
 * @see \Tests\Feature\UserManagementTest
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserStatusService $status,
        private readonly AuditLogService $audit,
        private readonly RoleGrantService $grants,
    ) {}

    // ═══════════════════════════════════════════════════════════════
    // ① العرض
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ `GET /api/users` — قائمة مع بحث وفلترة.
     *
     * ⭐⭐ **`password` مش في الرد أبداً.** الـmodel بيستخدم
     * `$hidden` بس ده **مش ضمانة** — لو حد شال السطر ده من
     * الـmodel، كل الحسابات هتطلع في الـJSON.
     *
     * ⭐ فبنبني الرد **بإيدنا** من الحقول المسموحة. أصعب في
     * المراجعة، بس أصعب في الكسر.
     *
     * @queryParam search  اسم أو إيميل (جزئي)
     * @queryParam role    اسم دور
     * @queryParam status  حالة الحساب
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:120',
            'role' => 'nullable|string|max:60',
            'status' => 'nullable|string|in:active,invited,suspended,archived,inactive',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = User::query()->with('roles:id,name,slug');

        if ($data['search'] ?? null) {
            $needle = '%'.mb_strtolower(trim($data['search'])).'%';

            // ⭐ `mb_strtolower` على النمط كمان — `MB_CASE_INSENSITIVE`
            // للاسم، و`LOWER()` للعمود.
            //
            // ⭐ ليش الاسمين مع بعض؟ الأسماء العربية مفيهاش
            // `uppercase` أصلاً، واللاتينية فيها. البحث بدون
            // تطبيع بيدي «محمد» فوق و«MUHAMMAD» تحت — نفس
            // الشخص.
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$needle]);
            });
        }

        if ($data['role'] ?? null) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $data['role']));
        }

        if ($data['status'] ?? null) {
            $query->where('status', $data['status']);
        }

        $perPage = $data['per_page'] ?? 25;

        $page = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (User $u) => $this->row($u))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * ⭐ `GET /api/users/{user}` — تفاصيل حساب واحد.
     */
    public function show(User $user)
    {
        $user->load(['roles:id,name,slug']);

        return response()->json([
            'user' => $this->row($user, detail: true),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // ② الإيقاف وإعادة التفعيل
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ `POST /api/users/{user}/suspend`
     *
     * ⭐⭐ `reason` **إجباري** — والسبب بيتسجّل في `audit_logs`.
     *
     * ⭐ ليه إجباري؟ لأن «إيقاف بلا سبب» بيبقى عشوائي: بعد ٦ شهور
     * ⭐ ليه إجباري؟ لأن «إيقاف بلا سبب» بيبقى عشوائي: بعد شهور
     * محدش يفتحم remembers ليه وقف فلان، فالإيقاف بيبقى قرار مش
     */
    public function suspend(Request $request, User $user)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->status->suspend($user, $request->user(), $data['reason']);

        return response()->json([
            'message' => 'الحساب اتوقف. اتقفلت كل جلساته وتوكيناته.',
            'user' => $this->row($user->fresh()),
        ]);
    }

    public function reactivate(Request $request, User $user)
    {
        $this->status->reactivate($user, $request->user());

        return response()->json([
            'message' => 'الحساب رجع نشط. يسجّل دخول من جديد.',
            'user' => $this->row($user->fresh()),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ الأرشفة
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ `POST /api/users/{user}/archive` — **نهائية**.
     *
     * ⭐ `reason` تاني إجباري — الأرشفة قرار أصعب من الوقف.
     */
    public function archive(Request $request, User $user)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->status->archive($user, $request->user(), $data['reason']);

        return response()->json([
            'message' => 'الحساب اتأرشف. البيانات كلها اتسابت.',
            'user' => $this->row($user->fresh()),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ إعادة تعيين كلمة المرور
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ `POST /api/users/{user}/force-password-reset`
     *
     * ⭐ مش بيغيّر كلمة المرور — مش ممكن أصلاً من غيرIntervention
     * المستخدم. بيعمل كذا: يبطل كل الجلسات والتوكينات، ويفرض
     * `must_change_password`.
     *
     * ⭐ **مسموح على النفس** — نسيان كلمة المرور حالة مشروعة.
     * الفرق عن الوقف: مفيش قفل دائم، والمستخدم راجع بعد دقائق.
     *
     * @see \App\Services\UserStatusService::forcePasswordReset
     */
    public function forcePasswordReset(Request $request, User $user)
    {
        $this->status->forcePasswordReset($user, $request->user());

        return response()->json([
            'message' => 'اتطلب تغيير كلمة المرور. الجلسات القديمة اتقفلت.',
            'user' => $this->row($user->fresh()),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑤ الدور
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ `PUT /api/users/{user}/role`
     *
     * ⭐⭐ **`syncRoles` مش `assignRole`** — عايزين **استبدال**
     * ⭐ مش بيغيّر كلمة المرور — مش ممكن من غير تدخل المستخدم.
     *
     * ⭐ وال fiscal guard هو `RoleGrantService::assertCanAssignRole`:
     * ممنوع تسليم دور فيه صلاحية انت مش ماسكها. واللي بيمنع
     * إنك تغيّر دور **نفسك** هو `assertNotSelf`.
     */
    public function updateRole(Request $request, User $user)
    {
        $data = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        $role = Role::findOrFail($data['role_id']);

        $this->status->assertNotSelf($user, $request->user(), 'تغيير الدور');

        // ⭐⭐ قبل أي كتابة — التصعيد مستحيل
        $this->grants->assertCanAssignRole($request->user(), $role, $request);

        $old = $user->roles->pluck('name')->all();

        $user->syncRoles([$role->name]);

        $this->audit->log(
            'user_role_changed',
            'user',
            (int) $user->id,
            ['roles' => $old],
            ['roles' => [$role->name]],
            $request,
            (int) $request->user()->id,
        );

        return response()->json([
            'message' => 'الدور اتغيّر.',
            'user' => $this->row($user->fresh(['roles:id,name,slug'])),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ صف واحد — **بإيدنا** مش `$model->toArray()`.
     *
     * ⭐ الحقول المسموحة **مكتوبة صراحةً**. السردي عن
     * `password`: لو `$hidden` اتشال من الـmodel بالغلط، `toArray()`
     * هيرجّع الهاش لكل حساب في القائمة. القائمة دي فيها كل
     * حسابات الأكاديمية.
     */
    private function row(User $user, bool $detail = false): array
    {
        $row = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'roles' => $user->roles->pluck('name')->values(),
            'last_login_at' => optional($user->last_login_at)->toIso8601String(),
            'created_at' => optional($user->created_at)->toIso8601String(),
            // ⭐ `must_change_password` مهم للواجهة: الحساب مش
            // هيفتح غير صفحة التغيير.
            'must_change_password' => (bool) $user->must_change_password,
        ];

        if ($detail) {
            $row['employee'] = $user->employee?->only(['id', 'name', 'job_title', 'department']);
            $row['teacher'] = $user->teacher?->only(['id', 'teacher_code', 'display_name']);
            $row['parent'] = $user->parentProfile?->only(['id', 'name', 'phone']);
            $row['open_sessions'] = DB::table('sessions')->where('user_id', $user->id)->count();
        }

        return $row;
    }
}
