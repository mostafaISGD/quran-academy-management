<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Services\RoleGrantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * الموظفون — قائمة + ملف + سجل نشاط.
 *
 * الموظف هنا هو السجل الإداري. حساب الدخول اختياري ومرتبط بـ user_id.
 */
class EmployeeController extends Controller
{
    // ============================================================
    // القائمة
    // ============================================================

    public function index(Request $request)
    {
        $query = Employee::query()
            ->with(['user', 'manager'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('department'), fn ($q) => $q->where('department', $request->string('department')))
            ->when($request->filled('job_title'), fn ($q) => $q->where('job_title', $request->string('job_title')))
            ->when($request->filled('employment_type'), fn ($q) => $q->where('employment_type', $request->string('employment_type')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search');
                $q->where(function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('job_title', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('sort'), function ($q) use ($request) {
                $sort = $request->string('sort')->toString();
                $dir = $request->string('dir')->lower()->toString() === 'asc' ? 'asc' : 'desc';
                $allowed = [
                    'name' => 'name',
                    'job_title' => 'job_title',
                    'department' => 'department',
                    'joined_at' => 'joined_at',
                    'created_at' => 'created_at',
                ];
                $q->orderBy($allowed[$sort] ?? 'created_at', $dir);
            }, fn ($q) => $q->orderBy('created_at', 'desc'));

        $paginator = $query->paginate($request->integer('per_page') ?: 100);

        // الإحصائيات من الداتابيز — مش من الصفحة الحالية
        $countsQuery = Employee::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('department'), fn ($q) => $q->where('department', $request->string('department')))
            ->when($request->filled('job_title'), fn ($q) => $q->where('job_title', $request->string('job_title')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search');
                $q->where(function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('job_title', 'like', "%{$search}%");
                });
            })
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        // الأقسام والوظائف الموجودة فعلاً — للفلاتر
        $departments = Employee::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department');
        $jobTitles = Employee::query()->whereNotNull('job_title')->distinct()->orderBy('job_title')->pluck('job_title');

        $response = $paginator->toArray();
        $response['counts'] = [
            'active' => (int) ($countsQuery['active'] ?? 0),
            'inactive' => (int) ($countsQuery['inactive'] ?? 0),
            'on_leave' => (int) ($countsQuery['on_leave'] ?? 0),
        ];
        $response['filters'] = [
            'departments' => $departments,
            'job_titles' => $jobTitles,
        ];

        return response()->json($response);
    }

    // ============================================================
    // الإنشاء
    // ============================================================

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'phone' => 'nullable|string',
            'country_code' => 'nullable|string|max:5',
            'email' => 'nullable|email',
            'gender' => 'nullable|in:male,female',
            'date_of_birth' => 'nullable|date',
            'nationality' => 'nullable|string',
            'address' => 'nullable|string',
            'photo_url' => 'nullable|string',
            'job_title' => 'nullable|string',
            'department' => 'nullable|string',
            'employment_type' => 'nullable|in:full_time,part_time,contract,volunteer',
            // سعر الساعة — أساس حساب الأجر. null = بيقفل بشهر ثابت.
            // السقف ١٠٠٠ ج.م/ساعة: أعلى من كده غالباً خطأ إدخال.
            'hourly_rate' => 'nullable|numeric|min:0|max:1000',
            'manager_id' => 'nullable|exists:employees,id',
            'joined_at' => 'nullable|date',
            'status' => 'nullable|in:active,inactive,on_leave',
            'notes' => 'nullable|string',
            // حساب دخول اختياري
            'create_account' => 'nullable|boolean',
            'role_id' => 'nullable|exists:roles,id',
        ]);

        // ⭐⭐⭐ فحص التصعيد — **قبل** أي كتابة في الداتابيز.
        //
        // ⚠️ الكود كان بيعيّن الدور مباشرة:
        //
        //     $user->assignRole(Role::find($roleId));
        //
        // من غير أي فحص — فموظف استقبال (دوره `students.edit`
        // بس) كان يقدر يبعت `create_account=true&role_id=<admin>`
        // ويعمل **حساب مدير**.
        //
        // ⭐ الفحص هنا **بره** الـ transaction عمداً: لو رُفض
        // الطلب، مافيش حاجة اتكتبت أصلاً. لو كان جوه، هنكتب
        // الـ employee وبعدين نرفض —RoleGrantService بيستخدم abort.
        //
        // ⭐ ونستخدم 403 مش "تجاهل الحقل": لو رجعنا 201 بدون
        // ما نعيّنش، المتصل هيظن إن الدور اتعيّن.
        //
        // @see \App\Services\RoleGrantService
        // @see \Tests\Feature\PrivilegeEscalationTest
        if (! empty($data['role_id'])) {
            $role = Role::find($data['role_id']);

            if (! $role) {
                return response()->json(['message' => 'الدور مش موجود'], 422);
            }

            app(RoleGrantService::class)->assertCanAssignRole(
                $request->user(),
                $role,
                $request,
            );
        }

        // القاعدة: لو حد له دور، لازم يكون عنده حساب — من غيره الـ role
        // هيفضل معلّق على حد مش موجود، وهو أصلاً مالوش فايدة.
        // (دور صفر صلاحيات = صفحة 403 في كل حاجة)
        if (! empty($data['role_id']) && ! ($data['create_account'] ?? false)) {
            $data['create_account'] = true;
        }

        // ⚠️⭐ لازم `null` مش متغيّر معرّف جوه `if`.
        //
        // كان السطر ده جوه الشرط بس، والتعيين بره:
        //
        //     if (...) { $warning = '...'; }
        //     ...
        //     return response()->json($employee + ($warning ? [...] : []), 201);
        //
        // ⭐ فأي موظف بيتعمل **من غير** `create_account=true` كان
        // بيرمي `Undefined variable $warning` → **500**.
        // المسار ده مش نادر — ده إنشاء موظف عادي.
        $warning = null;

        // لو طلب حساب من غير دور — بنسمح، بس بنحذّر عشان الواجهة تقول
        // الموظف ده مش هيقدر يفتح حاجة
        if (($data['create_account'] ?? false) && empty($data['role_id'])) {
            $warning = 'الموظف ده هيكون ليه حساب دخول بدون صلاحيات — مش هيقدر يفتح أي صفحة.';
        }

        $data['organization_id'] = $request->user()->organization_id;
        // بنحطها صراحةً — من غير كده الـ attribute مش بتبقى موجودة
        // على الـ model والرد بيرجع من غير user_id خالص
        $data['user_id'] = null;

        // ⭐⭐ متغيرات **برّه** الـ closure — عشان نعرف بعد
        // الـtransaction هل نعمل دعوة ولا لأ.
        $createAccount = (bool) ($data['create_account'] ?? false);
        $inviteEmail = $data['email'] ?? null;
        $roleId = $data['role_id'] ?? null;

        $employee = DB::transaction(function () use ($data, $request, $createAccount, $roleId) {
            unset($data['create_account'], $data['role_id']);

            $employee = Employee::create($data);

            // إنشاء حساب دخول اختياري
            if ($createAccount && $data['email'] ?? null) {
                $user = User::create([
                    'organization_id' => $data['organization_id'],
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    // ⭐⭐⭐ الحساب بيتعمل **`status = 'invited'`**
                    // ودعوة — مش حساب شغال بكلمة مرور مُسلَّمة.
                    //
                    // ⭐ الكود القديم كان:
                    //     'password' => Hash::make(Str::password(20)),
                    //     'must_change_password' => true,
                    //     'status' => 'active',
                    //
                    // ⭐ يعني حساب **جاهز للدخول** بكلمة مرور
                    // محدش عارفها. لو_words_ دي اتسرّبت من أي مكان
                    // (backup، log، shoulder-surf) كان الدخول جاهز.
                    //
                    // ⭐ الخلل مش في `must_change_password` — الخلل
                    // ⭐ إن الحساب **.active** من يوم إنشائه. `status =
                    // 'invited'` بيقفل الدخول عند `AuthController`
                    // (`$user->status !== 'active'`) فحتى لو كلمة
                    // المرور اتسرّبت، محدش يقدر يدخل.
                    //
                    // ⭐⚠️ كلمة المرور العشوائية **بتفضل موجودة** —
                    // العمود `NOT NULL` و bcrypt مش فاضي. ومفيش
                    // طريق ليها: محدش بيقراها، والقبول بيكتب فوقها.
                    'password' => Hash::make(Str::password(40)),
                    'must_change_password' => true,
                    'password_changed_at' => null,
                    'timezone' => 'Africa/Cairo',
                    'locale' => 'ar',
                    // ⭐ المسار ده كان مكسور فعليا، أي إنشاء حساب لموظف من مسى كان يرمي `500`.
                    'job_title' => $data['job_title'] ?? null,
                    'department' => $data['department'] ?? null,
                    // ⭐⭐ الحالة الحقيقية للحساب المدعو
                    'status' => 'invited',
                ]);

                $employee->update(['user_id' => $user->id]);

                // تعيين الدور
                //
                // ⭐ الدور بيتعيّن **وقت الإنشاء** مش وقت القبول —
                // عشان `InvitationService::provisionUser()` يبقى
                // شغل تفعيل الحساب بس، ومفيش moment بين «عملت
                // الدعوة» و«قبلتها» يكون الدور فيه معلّق.
                if ($roleId) {
                    $role = Role::find($roleId);
                    if ($role) {
                        $user->assignRole($role);
                    }
                }
            }

            return $employee->fresh(['user.roles', 'manager']);
        });

        // ⭐⭐⭐ الدعوة — **برّه** الـ transaction.
        //
        // ⚠️ ليه برّه؟ لأن `InvitationService::sendMail()` بيرسل
        // إيميل حقيقي. لو الإرسال جوه الـtransaction:
        //   • SMTP بطيء (ثانية-ثانيتين) والـDB transaction هيفضل
        //     **مفتوح**، والقفل على الصف هيفضل ماسك
        //   • لو الإرسال فشل وارمي، الـ rollback هيمسح الموظف
        //     والحساب كمان — والمستخدم قال «اتقبل» وهو مش
        //     متحصلش على حاجة.
        // `InvitationService::sendMail()` بيبلع الخطأ جوّه نفسه
        // أصلاً، فمافيش rollback من ناحية الإيميل.
        $invitationToken = null;

        if ($createAccount && $inviteEmail) {
            $invitationToken = app(\App\Services\InvitationService::class)->create([
                'email' => $inviteEmail,
                'name' => $data['name'],
                'role_id' => $roleId,
                // ⭐⭐ الربط بالملف — من غيره الحساب بيتفعّل بس الـ
                // employee يفضل `user_id = null` ومفيش حد يوصله.
                'profile_type' => 'employee',
                'profile_id' => $employee->id,
            ], $request->user());
        }

        // تسجيل النشاط
        app(\App\Services\AuditLogService::class)->logCreate(
            'employee',
            $employee->id,
            ['name' => $employee->name, 'job_title' => $employee->job_title, 'department' => $employee->department],
            $request,
        );

        // ⭐⭐⭐ `Model + array` هو TypeError في PHP 8 — ما اشتغلش ولا مرة.
        //
        // `EmployeeController::store` كان بيرمي 500 على **كل** طلب
        // إنشاء، فمحدش كان بيقدر يسجّل موظف أصلاً. الـendpoint
        // ده كان ميت من زمان وما كانش فيه اختبار بيكشفه.
        //
        // ⭐ نستخدم `toArray()` ونضيف `warning` جواهها — أنضف من
        // `json_merge` على الموديل.
        $payload = $employee->toArray();

        if ($warning) {
            $payload['warning'] = $warning;
        }

        // ⭐⭐⭐ الرد فيه **رابط الدعوة** — مش بس «تم».
        //
        // ⭐ ليه؟ لأن «أهلاً بك، حسابك جاهز» معناه إيه من غير رابط؟
        // الموظف الجديد مالوش حساب **يدخل بيه**، فالمدير لازم
        // يقدر يسلّمه الرابط.
        //
        // ⭐⚠️ التوكن الخام بيرجع **مرة واحدة** — من هنا. مافيش
        // endpoint تاني بيرجّعه، ومش بيتخزّن في الداتابيز (hash).
        // فلو الرد ده ضاع، الرجوع الوحيد هو **إعادة إرسال** من
        // `POST /api/invitations/{id}/resend`.
        if ($invitationToken !== null) {
            $payload['invitation'] = [
                'sent' => true,
                'accept_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/')
                    .'/accept-invitation?token='.urlencode($invitationToken),
                'expires_at' => now()->addHours(72)->toIso8601String(),
            ];
        }

        return response()->json($payload, 201);
    }

    // ============================================================
    // العرض
    // ============================================================

    public function show(Employee $employee)
    {
        $employee->load(['user', 'manager', 'subordinates']);

        return response()->json([
            'employee' => $employee,
            // الدور والصلاحيات — الواجهة بتعرضها كـ«الطلاب ✓ · المالية ✗»
            'role' => $employee->role,
            'role_label' => $employee->role_label,
            'granted' => $this->grantedByUnit($employee),
            'all_units' => $this->permissionMatrix(),
        ]);
    }

    /**
     * صلاحيات الموظف مجمّعة حسب الوحدة، مع الـ actions المتاحة
     * في كل وحدة — عشان الواجهة تقدر تعرض صح ("✓" أو "✗")
     * حتى لما الموظف مالوش الدور أصلاً.
     *
     * @return array<string, array<int, array{name:string, granted:bool}>>
     */
    private function grantedByUnit(Employee $employee): array
    {
        $mine = $employee->user
            ? $employee->user->getAllPermissions()->pluck('name')->all()
            : [];

        $out = [];

        foreach ($this->permissionMatrix() as $unit => $permissions) {
            $out[$unit] = array_map(
                fn (string $name) => ['name' => $name, 'granted' => in_array($name, $mine, true)],
                $permissions
            );
        }

        return $out;
    }

    /**
     * كل الصلاحيات مجمّعة حسب الوحدة.
     *
     * @return array<string, string[]>
     */
    private function permissionMatrix(): array
    {
        $units = [
            'students' => 'الطلاب',
            'teachers' => 'المعلمون',
            'lessons' => 'الحصص',
            'payments' => 'المالية',
            'leads' => 'العملاء المحتملون',
            'reports' => 'التقارير',
            'settings' => 'الإعدادات',
        ];

        $all = \Spatie\Permission\Models\Permission::pluck('name')->all();

        $out = [];
        foreach ($units as $prefix => $label) {
            $items = array_values(array_filter(
                $all,
                fn ($p) => str_starts_with($p, $prefix . '.')
            ));
            sort($items);
            $out[$label] = $items;
        }

        return $out;
    }

    // ============================================================
    // التعديل
    // ============================================================

    public function update(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'name' => 'sometimes|string',
            'phone' => 'sometimes|string',
            'country_code' => 'nullable|string|max:5',
            'email' => 'nullable|email',
            'gender' => 'nullable|in:male,female',
            'date_of_birth' => 'nullable|date',
            'nationality' => 'nullable|string',
            'address' => 'nullable|string',
            'photo_url' => 'nullable|string',
            'job_title' => 'nullable|string',
            'department' => 'nullable|string',
            'employment_type' => 'nullable|in:full_time,part_time,contract,volunteer',
            // سعر الساعة — أساس حساب الأجر. null = بيقفل بشهر ثابت.
            // السقف ١٠٠٠ ج.م/ساعة: أعلى من كده غالباً خطأ إدخال.
            'hourly_rate' => 'nullable|numeric|min:0|max:1000',
            'manager_id' => 'nullable|exists:employees,id',
            'joined_at' => 'nullable|date',
            'status' => 'sometimes|in:active,inactive,on_leave',
            'notes' => 'nullable|string',
            // ربط حساب دخول موجود
            'user_id' => 'nullable|exists:users,id',
            'role_id' => 'nullable|exists:roles,id',
        ]);

        $old = $employee->only(array_keys($data));

        // ⭐⭐⭐ نفس فحص التصعيد بتاع `store`.
        //
        // ⭐ الباج كان مكرر في المكانين: `update` كان بياخد
        // `role_id` وبيعمل `syncRoles([$role->name])` من غير أي
        // فحص — فالتصعيد ممكن كان بيحصل حتى لموظف موجود أصلاً،
        // وده أخطر من إنشاء حساب جديد (التصعيد بيستهدف حد
        // معروُ وسهل استهدافه).
        //
        // ⭐ والفحص **بره** الـ transaction تاني، عشان الرفض
        // ما يعملش تعديل جزئي.
        //
        // @see \App\Services\RoleGrantService
        if (! empty($data['role_id'])) {
            $role = Role::find($data['role_id']);

            if (! $role) {
                return response()->json(['message' => 'الدور مش موجود'], 422);
            }

            app(RoleGrantService::class)->assertCanAssignRole(
                $request->user(),
                $role,
                $request,
            );
        }

        // ⭐ متغير برّه الـclosure — عشان نعرف بعد الـtransaction
        // هل الحساب الجديد محتاج دعوة.
        $willCreateAccount = false;

        $employee = DB::transaction(function () use ($employee, $data, $request, &$willCreateAccount) {
            $roleId = $data['role_id'] ?? null;
            unset($data['role_id']);

            // عينت دور بس الموظف مالوش حساب → نعمله واحد.
            // دور بلا حساب = صلاحية معلّقة على حد مش موجود.
            if ($roleId && !$employee->user_id) {
                if (empty($employee->email)) {
                    return ['error' => 'الموظف مالوش حساب دخول ومفيش إيميل — املا الإيميل الأول'];
                }

                $willCreateAccount = true;

                $user = User::create([
                    'organization_id' => $employee->organization_id,
                    'name' => $employee->name,
                    'email' => $employee->email,
                    'phone' => $employee->phone,
                    // ⭐⭐ نفس قرار `store`: حساب **مدعو** مش
                    // حساب شغال. `status='active'` مع كلمة مرور
                    // عشوائية محدش بيعرفها = باب مفتوح لو الكلمة
                    // دي اتسرّبت من أي مكان.
                    'password' => Hash::make(Str::password(40)),
                    'must_change_password' => true,
                    'password_changed_at' => null,
                    'timezone' => 'Africa/Cairo',
                    'locale' => 'ar',
                    'job_title' => $employee->job_title,
                    'department' => $employee->department,
                    // ⭐⭐ الحالة الحقيقية للحساب المدعو
                    'status' => 'invited',
                ]);

                $employee->update(['user_id' => $user->id]);
            }

            $employee->update($data);

            // تعيين الدور على حساب الدخول
            if ($roleId && $employee->user_id) {
                $user = User::find($employee->user_id);
                $role = Role::find($roleId);
                if ($user && $role) {
                    $user->syncRoles([$role->name]);
                }
            }

            return $employee->fresh(['user.roles', 'manager']);
        });

        if (isset($employee['error'])) {
            return response()->json(['message' => $employee['error']], 422);
        }

        // تسجيل النشاط
        app(\App\Services\AuditLogService::class)->logUpdate(
            'employee',
            $employee->id,
            $old,
            $employee->only(array_keys($data)),
            $request,
        );

        // ⭐⭐⭐ الدعوة — **برّه** الـtransaction. نفس سبب `store`:
        // الإيميل جوّه الـtransaction بيقفل الـDB ويخلي فشل
        // الإيميل يعمل rollback للموظف كله.
        if ($willCreateAccount && ! empty($employee->email)) {
            $token = app(\App\Services\InvitationService::class)->create([
                'email' => $employee->email,
                'name' => $employee->name,
                'role_id' => $data['role_id'] ?? null,
                'profile_type' => 'employee',
                'profile_id' => $employee->id,
            ], $request->user());

            $response = $employee->fresh()->load(['user', 'manager'])->toArray();

            // ⭐ نفس `store` — المدير لازم يقدر يسلّم الرابط
            $response['invitation'] = [
                'sent' => true,
                'accept_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/')
                    .'/accept-invitation?token='.urlencode($token),
                'expires_at' => now()->addHours(72)->toIso8601String(),
            ];

            return response()->json($response);
        }

        return response()->json($employee->fresh()->load(['user', 'manager']));
    }

    // ============================================================
    // الحذف
    // ============================================================

    public function destroy(Request $request, Employee $employee)
    {
        $old = $employee->only(['name', 'job_title', 'department', 'status']);

        $employee->delete();

        app(\App\Services\AuditLogService::class)->logDelete('employee', $employee->id, $old, $request);

        return response()->json(['message' => 'تم حذف الموظف']);
    }

    // ============================================================
    // سجل النشاط
    // ============================================================

    public function activity(Request $request, Employee $employee)
    {
        // الموظف ممكن يبقى من غير حساب دخول — فمفيش نشاط
        if (!$employee->user_id) {
            return response()->json(['data' => []]);
        }

        $query = \App\Models\AuditLog::query()
            ->where('user_id', $employee->user_id)
            ->with('user')
            ->orderByDesc('created_at');

        return response()->json($query->paginate($request->integer('per_page') ?: 50));
    }
}
