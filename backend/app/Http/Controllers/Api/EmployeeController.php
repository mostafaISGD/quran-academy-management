<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
            'manager_id' => 'nullable|exists:employees,id',
            'joined_at' => 'nullable|date',
            'status' => 'nullable|in:active,inactive,on_leave',
            'notes' => 'nullable|string',
            // حساب دخول اختياري
            'create_account' => 'nullable|boolean',
            'role_id' => 'nullable|exists:roles,id',
        ]);

        // القاعدة: لو حد له دور، لازم يكون عنده حساب — من غيره الـ role
        // هيفضل معلّق على حد مش موجود، وهو أصلاً مالوش فايدة.
        // (دور صفر صلاحيات = صفحة 403 في كل حاجة)
        if (!empty($data['role_id']) && !($data['create_account'] ?? false)) {
            $data['create_account'] = true;
        }

        // لو طلب حساب من غير دور — بنسمح، بس بنحذّر عشان الواجهة تقول
        // الموظف ده مش هيقدر يفتح حاجة
        if (($data['create_account'] ?? false) && empty($data['role_id'])) {
            $warning = 'الموظف ده هيكون ليه حساب دخول بدون صلاحيات — مش هيقدر يفتح أي صفحة.';
        }

        $data['organization_id'] = $request->user()->organization_id;
        // بنحطها صراحةً — من غير كده الـ attribute مش بتبقى موجودة
        // على الـ model والرد بيرجع من غير user_id خالص
        $data['user_id'] = null;

        $employee = DB::transaction(function () use ($data, $request) {
            $createAccount = $data['create_account'] ?? false;
            $roleId = $data['role_id'] ?? null;
            unset($data['create_account'], $data['role_id']);

            $employee = Employee::create($data);

            // إنشاء حساب دخول اختياري
            if ($createAccount && $data['email'] ?? null) {
                $user = User::create([
                    'organization_id' => $data['organization_id'],
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password' => Hash::make('password'),
                    'timezone' => 'Africa/Cairo',
                    'locale' => 'ar',
                    'job_title' => $data['job_title'],
                    'department' => $data['department'],
                    'status' => 'active',
                ]);

                $employee->update(['user_id' => $user->id]);

                // تعيين الدور
                if ($roleId) {
                    $role = Role::find($roleId);
                    if ($role) {
                        $user->assignRole($role);
                    }
                }
            }

            return $employee->fresh(['user.roles', 'manager']);
        });

        // تسجيل النشاط
        app(\App\Services\AuditLogService::class)->logCreate(
            'employee',
            $employee->id,
            ['name' => $employee->name, 'job_title' => $employee->job_title, 'department' => $employee->department],
            $request,
        );

        return response()->json($employee + ($warning ? ['warning' => $warning] : []), 201);
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
            'manager_id' => 'nullable|exists:employees,id',
            'joined_at' => 'nullable|date',
            'status' => 'sometimes|in:active,inactive,on_leave',
            'notes' => 'nullable|string',
            // ربط حساب دخول موجود
            'user_id' => 'nullable|exists:users,id',
            'role_id' => 'nullable|exists:roles,id',
        ]);

        $old = $employee->only(array_keys($data));

        $employee = DB::transaction(function () use ($employee, $data, $request) {
            $roleId = $data['role_id'] ?? null;
            unset($data['role_id']);

            // عينت دور بس الموظف مالوش حساب → نعمله واحد.
            // دور بلا حساب = صلاحية معلّقة على حد مش موجود.
            if ($roleId && !$employee->user_id) {
                if (empty($employee->email)) {
                    return ['error' => 'الموظف مالوش حساب دخول ومفيش إيميل — املا الإيميل الأول'];
                }

                $user = User::create([
                    'organization_id' => $employee->organization_id,
                    'name' => $employee->name,
                    'email' => $employee->email,
                    'phone' => $employee->phone,
                    'password' => Hash::make('password'),
                    'timezone' => 'Africa/Cairo',
                    'locale' => 'ar',
                    'job_title' => $employee->job_title,
                    'department' => $employee->department,
                    'status' => 'active',
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
