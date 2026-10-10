<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\User;

/**
 * ⭐⭐⭐ **مزامنة الصلاحيات مع المصدر الرسمي.**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ المشكلة اللي existed
 *
 * كان فيه **قائمة صلاحيات ثانية** مكتوبة يدوي في `DatabaseSeeder`،
 * ناقصة `finance.*` و `groups.manage` و `pricing.manage` و
 * `leads.*` — والمصدر الحقيقي `SeedRolesPermissions::PERMISSIONS`
 * كان فيه ٣٤ صلاحية، والقاعدة فيها ٢٥ بس.
 *
 * ⭐ النتيجة اللي ظهرت فعلاً في المتصفح:
 *
 *     GET /api/accounts → 403 "غير مصرح"
 *
 * للمدير نفسه. وصفحات `/accounts` و `/finance` و `/pricing-admin`
 * كانت **ميتة فعلياً**.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ الحل
 *
 * `DatabaseSeeder` بقى بيقرأ من المصدر الواحد، والـ command ده
 * بيطبّق نفس المصدر على قواعد **قائمة** — من غير ما نمسح بيانات.
 *
 * ⭐⚠️ **ليه command مش migration؟**
 *
 * ① الصلاحيات بيانات **مش schema**. الـ migrations بتتم
 *    بـ `down()` بيشيل البيانات، وده خطر على نظام شغّال.
 *
 * ② الأمر **idempotent** — ينفع يتشغّل أي عدد من المرات من غير
 *    تكرار ولا أثر جانبي.
 *
 * ③ بينفع يتشغّل على بيئة الإنتاج من غير `migrate:fresh`.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ⚠️ ليه مافيش `finance.reverse` للمحاسب؟
 *
 * قرار **فصل المهام** من الخطة: «تسجيل دفعة» ≠ «عكس سجل» ≠
 * «فتح فترة مقفولة». لو المحاسب قدر يعكس أي دفعة، الأرقام اللي
 * الإدارة وقّعت عليها تبقى قابلة للتغيير — وده بالظبط اللي
 * الأرقام المالية كلها مبنية عشان تمنعه.
 *
 * @see \Database\Seeders\SeedRolesPermissions::grantAccountantRole
 */
class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync
                            {--dry-run : اعرض اللي هيحصل من غير ما تغيّر حاجة}';

    protected $description = 'يزامن الصلاحيات مع المصدر الرسمي ويعيد توزيع الأدوار';

    public function handle(): int
    {
        $dry = $this->option('dry-run');

        $source = \Database\Seeders\SeedRolesPermissions::PERMISSIONS;

        $this->info('المصدر الرسمي فيه ' . count($source) . ' صلاحية.');

        // ① الصلاحيات الناقصة
        $missing = [];

        foreach ($source as $perm) {
            $exists = Permission::where('name', $perm['name'])
                ->where('guard_name', 'web')
                ->exists();

            if (! $exists) {
                $missing[] = $perm;
            }
        }

        if ($missing) {
            $this->warn('ناقصة: ' . count($missing));
            foreach ($missing as $m) {
                $this->line("  + {$m['name']}");
            }
        } else {
            $this->info('✅ كل الصلاحيات موجودة.');
        }

        // ② صلاحيات زائدة (في القاعدة بس، مش في المصدر)
        $knownNames = array_column($source, 'name');

        $extra = Permission::where('guard_name', 'web')
            ->whereNotIn('name', $knownNames)
            ->pluck('name');

        if ($extra->isNotEmpty()) {
            $this->warn('في القاعدة بس (مش في المصدر): ' . $extra->implode(', '));
        }

        if ($dry) {
            $this->info('--dry-run: مافيش حاجة اتغيّرت.');
            return self::SUCCESS;
        }

        // ③ إنشاء الناقص
        foreach ($missing as $perm) {
            Permission::create([
                'name' => $perm['name'],
                'slug' => str_replace('.', '-', $perm['name']),
                'module' => $perm['module'],
                'guard_name' => 'web',
            ]);
        }

        // ④ إعادة توزيع كل الصلاحيات على admin
        //
        // ⭐ مش `syncPermissions(Permission::all())` — ده كان هيعطي
        // المدير أي صلاحية اتضافت بالغلط. إحنا بنعطيه المصدر بس.
        $adminRole = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            ['slug' => 'admin', 'guard_name' => 'web', 'is_system' => true],
        );

        $adminRole->syncPermissions(
            Permission::where('guard_name', 'web')
                ->whereIn('name', $knownNames)
                ->get()
        );

        $this->info('✅ دور admin اتوزّع على ' . count($knownNames) . ' صلاحية.');

        // ⑤ ⭐⭐⭐ **إعادة ربط أي admin فقد دوره**
        //
        // ⚠️⚠️ المشكلة اللي اتكشفت:
        //
        // `model_has_roles` كان **فاضي** — يعني المدير نفسه كان
        // **بلا أي دور**. النتيجة: `/promo-codes` بيرجع 403،
        // وكل صفحة محمية بصلاحية بتقول «غير مصرح».
        //
        // ⭐ السبب إن `assignRole` بيستعمل `syncPermissions` قبل
        // ما يربط، فلو حصل أي خطأ في المنتصف الربط بيضيع
        // من غير رسالة.
        //
        // ⭐ فبنربط **من الأول**، وبعدين نتأكد إن الربط موجود فعلاً.
        $admins = User::where('email', 'like', 'admin@%')->get();

        foreach ($admins as $admin) {
            $linked = $admin->roles()->where('roles.name', 'admin')->exists();

            if (! $linked) {
                $admin->assignRole($adminRole);
                $this->line("  ↻ دور admin اتربط تاني بـ {$admin->email}");
            }

            // ⭐⭐ **التحقق النهائي** — مافيش إدارة تتصدّق إن
            // الربط شغال من غير ما تشوفه بعينها
            $admin->unsetRelation('roles')->unsetRelation('permissions');

            if (! $admin->fresh()->can($knownNames[0])) {
                $this->error("  ❌ {$admin->email} لسه مش شايف الصلاحيات!");
                return self::FAILURE;
            }
        }

        $this->info('✅ ' . $admins->count() . ' حساب إداري متأكد إنه شايف الصلاحيات.');

        $this->info('✅ خلص.');
        $this->newLine();
        $this->line('جرّب دلوقتي: /accounts و /finance و /pricing-admin');

        return self::SUCCESS;
    }
}
