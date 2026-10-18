<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\RoleGrantService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * الأدوار والصلاحيات.
 *
 * ⭐⭐⭐ كل الدوال الكتابة هنا بتعدّي من `RoleGrantService`.
 *
 * ⚠️ الكود قبل PR 0.3 كان:
 *
 *     public function updatePermissions(Request $request, Role $role)
 *     {
 *         $role->syncPermissions($data['permissions']);
 *     }
 *
 * مفيش حاجة. أي حد عنده `settings.manage` كان يقدر:
 *   • ياخد صلاحيات من دور المدير
 *   • يدّي دور المعلم كل صلاحيات النظام
 *   • يعدّل دور admin وهو نفسه مش إداري
 *
 * ومفيش تسجيل في `audit_logs` لشيء.
 *
 * @see \App\Services\RoleGrantService — القاعدة نفسها في مكان واحد
 * @see \Tests\Feature\PrivilegeEscalationTest
 */
class RoleController extends Controller
{
    public function __construct(
        private RoleGrantService $grants,
        private AuditLogService $audit,
    ) {}

    public function index()
    {
        return response()->json(Role::with('permissions')->get());
    }

    /**
     * إنشاء دور جديد.
     *
     * ⭐⭐⭐ التحقق من التطابق مع أدوار النظام مقصود ومهم.
     *
     * `roles.name` و `roles.slug` مالهمش unique constraint في
     * الداتابيز. فلو حد عمل دور باسم `admin` كان يقدر يخدع أي
     * مقارنة بالاسم — والنظام فيها كذا مقارنة (`hasRole('admin')`)
     * بتتغذّى على **الاسم** مش على الـ id.
     *
     * @see \Tests\Feature\PrivilegeEscalationTest::test_a_system_role_name_cannot_be_reused_by_a_new_role
     */
    public function store(Request $request)
    {
        $systemNames = Role::where('is_system', true)->pluck('name')->all();
        $systemSlugs = Role::where('is_system', true)->pluck('slug')->all();

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                // ⭐ فريد — مفيش unique constraint في الداتابيز
                Rule::unique('roles', 'name'),
                // ⭐ ممنوع إعادة استخدام اسم دور نظام
                Rule::notIn($systemNames),
            ],
            'slug' => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'slug'),
                Rule::notIn($systemSlugs),
            ],
            'description' => 'nullable|string',
        ]);

        $data['organization_id'] = $request->user()->organization_id;

        // ⭐⭐ `is_system` بيتقرّر هنا مش من الـ request.
        //
        // `is_system` في الـ fillable، فلو حد بعت `is_system: true`
        // كان هيقدر يعمل دور محمي من التعديل زي أدوار النظام
        // بالظبط — وبكده يتخطى فحص `is_system` في
        // `assertCanGrantPermissions` كله.
        $data['is_system'] = false;

        $role = Role::create($data);

        $this->audit->logCreate('role', (int) $role->id, [
            'name' => $role->name,
            'slug' => $role->slug,
        ], $request);

        return response()->json($role, 201);
    }

    /**
     * ⭐⭐⭐ تعديل صلاحيات دور.
     *
     * الفحوصات بالترتيب، وكلها في `RoleGrantService`:
     *   ① عنده `users.roles.assign`
     *   ② الدور مش نظام
     *   ③ كل صلاحية في الطلب موجودة عند المانح
     *
     * ⭐ المرفض بيحصل **قبل** أي كتابة — فمفيش تعديل جزئي.
     */
    public function updatePermissions(Request $request, Role $role)
    {
        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $this->grants->assertCanGrantPermissions(
            $request->user(),
            $role,
            $data['permissions'],
            $request,
        );

        // ⭐ قبل/بعد مع اسم الدور.
        //
        // ⭐ سطر الاسم مش رفاهية: من غيره السجل بيقول «اتغيرت
        // صلاحيات [] إلى [students.view]» ومش بيقول **مين** اتغيّر.
        // وبما إن نفس الدور بيتعدّل كذا مرة، السجل من غير اسم
        // بيبقى غير قابل للاستخدام عملياً.
        $before = [
            'role' => $role->name,
            'slug' => $role->slug,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ];

        $role->syncPermissions($data['permissions']);

        $after = [
            'role' => $role->name,
            'slug' => $role->slug,
            'permissions' => $role->fresh()->permissions->pluck('name')->sort()->values()->all(),
        ];

        $this->audit->logUpdate(
            'role',
            (int) $role->id,
            $before,
            $after,
            $request,
        );

        return response()->json($role->load('permissions'));
    }

    /**
     * ⭐ حذف دور — أدوار النظام ممنوعة.
     *
     * ⭐ الراوت ده كان مش موجود. اتضاف هنا عشان PR 1.7 (إدارة
     * الأدوار في الواجهة) يلاقي القاعدة جاهزة — ما نبنيش
     * endpoint من غير حماية.
     */
    public function destroy(Request $request, Role $role)
    {
        $this->grants->assertNotSystemRole($role, $request);

        abort_if(
            $role->users()->exists(),
            422,
            'فيه ناس معيّنين على الدور ده — شيلهم الأول'
        );

        $name = $role->name;

        $role->delete();

        $this->audit->log('delete', 'role', (int) $role->id, ['name' => $name], null, $request);

        return response()->json(['message' => 'تم حذف الدور']);
    }

    public function allPermissions()
    {
        return response()->json(Permission::all());
    }
}