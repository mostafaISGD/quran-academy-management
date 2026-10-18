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
                            {--dry-run : اعرض اللي هيحصل من غير ما تغيّر حاجة}
                            {--prune : ⭐ احذف الصلاحيات اللي مش في الكتالوج — خطر، اقرأ الأول}';

    protected $description = 'يزامن الصلاحيات مع المصدر الرسمي ويعيد توزيع الأدوار';

    /**
     * ⭐⭐⭐ **اللي الأمر بيعمله بالظبط**
     *
     *   ① ينشئ الناقص
     *   ② **يحدّث** الوصف والـgroup على الموجود
     *   ③ يوزّع الأدوار من الكتالوج
     *   ④ بيحذف **لأ** — إلا بـ`--prune` وتأكيد
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐⭐ ليه الحذف **مقفول** افتراضيًا
     *
     * ⭐ حذف صلاحية = **إبطالها عند كل من عنده فورًا**. والصلاحية
     * اللي مش في الكتالوج مش معناها إنها ما بقاش مستخدمة —
     * ممكن تكون في seeder تاني، أو في اختبار، أو تكون **قديمة**
     * لسه راو بيطلبها (`settings.manage` مثلًا، والـ`PR 1.7` هو
     * اللي يشيلها).
     *
     * ⭐ فالحذف لازم يكون **قرار صريح**. `--prune` بياخده، بس
     * بيطبع تحذير بالأسماء قبل ما يعمل حاجة.
     *
     * @see \Database\Seeders\SeedRolesPermissions::permissions
     * @see config/permissions.php
     */
    public function handle(): int
    {
        $dry = $this->option('dry-run');

        $source = \Database\Seeders\SeedRolesPermissions::permissions();

        // ⭐⭐ **الأسماء القديمة لسه شغّالة** — لازم بتتعمل مع
        // الكتالوج الجديد، وإلا الراوتات بتموت بـ403.
        //
        // ⭐ الاتنين قائمت sales **بيتداخلوا** — فبنحسب الـunion.
        $legacy = array_unique(array_merge(
            \Database\Seeders\SeedRolesPermissions::legacyPermissionsInUse(),
            \Database\Seeders\SeedRolesPermissions::legacyPermissionsInRoles(),
        ));

        $this->info('المصدر الرسمي فيه ' . count($source) . ' صلاحية.');

        if ($legacy) {
            $this->line(
                '  + ' . count($legacy) . ' اسم قديم لسه مستخدم '
                .'(راوتات أو أدوار — لحد PR 1.7): ' . implode(', ', $legacy)
            );
        }

        // ① الصلاحيات الناقصة
        $missing = [];
        $stale = [];

        foreach ($source as $perm) {
            $row = Permission::where('name', $perm['name'])
                ->where('guard_name', 'web')
                ->first();

            if (! $row) {
                $missing[] = $perm;

                continue;
            }

            // ⭐⭐ **الوصف والـgroup الناقصين** — ده الـbackfill
            // بتاع `2026_10_15_000004`.
            //
            // ⭐ أول تشغيل للـأمر بعد الـmigration بيرجّع `null`
            // في الصفين، والصفحة بتطلع «صلاحية من غير وصف».
            if ($row->description !== $perm['description'] || $row->group !== $perm['group']) {
                $stale[] = $perm['name'];
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

        if ($stale) {
            $this->warn('وصف/مجموعة ناقصة: ' . count($stale).' — '.implode(', ', $stale));
        }

        // ② صلاحيات في القاعدة بس
        $knownNames = array_merge(
            array_column($source, 'name'),
            $legacy,
        );

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

        // ③ إنشاء الناقص — **مع** الوصف والـgroup
        foreach (array_merge($missing, $stale) as $perm) {
            Permission::updateOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                [
                    'slug' => str_replace('.', '-', $perm['name']),
                    'module' => $perm['module'],
                    'group' => $perm['group'],
                    'description' => $perm['description'],
                    'guard_name' => 'web',
                ],
            );
        }

        // ③.١ الأسماء القديمة — بنفس المنطق
        foreach ($legacy as $name) {
            // ⭐ الخريطة قيمها **قوائم** — والوحدة من أول وجهة
            $targets = \Database\Seeders\SeedRolesPermissions::migrationMap()[$name] ?? [$name];
            $target = $targets[0];

            Permission::updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                [
                    'slug' => str_replace('.', '-', $name),
                    'module' => explode('.', $target)[0],
                    'group' => 'أسماء قديمة',
                    'description' => 'اسم قديم — بيشتغل لحد ما PR 1.7 يطبّق خريطة التحويل',
                    'guard_name' => 'web',
                ],
            );
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

        // ④.١ ⭐⭐⭐ `--prune` — **العملية الوحيدة اللي بتحذف**
        //
        // ⭐ بتطلب تأكيد. والسبب إن كل صلاحية بتشيلها بتتصل من
        // `role_has_permissions` — يعني **كل** الأدوار اللي عندها
        // الصلاحية دي بتفقدها في نفس اللحظة.
        //
        // ⭐ والتأكيد الافتراضي **لا** حتى مع `--prune`: `--prune`
        // بيقول «عايز»، والتأكيد بيقول «متأكد إنك عارف بتعمل إيه».
        if ($this->option('prune')) {
            $orphans = Permission::where('guard_name', 'web')
                ->whereNotIn('name', $knownNames)
                ->get();

            if ($orphans->isEmpty()) {
                $this->info('لا حاجة للحذف.');
            } elseif ($this->confirm(
                '⚠️  حذف ' . $orphans->count() . ' صلاحية من ' . count($orphans) . '؟',
                false,
            )) {
                $names = $orphans->pluck('name')->all();

                // ⭐ `syncPermissions` قبل الحذف: Spatie بيربط
                // `model_has_roles` بـ`personal_access_tokens` مش
                // هنا، بس بي Cleopatra pivot cleanup لوحده.
                foreach ($orphans as $permission) {
                    $permission->roles()->detach();
                }

                Permission::whereIn('name', $names)->delete();

                $this->info('🗑  اتشالت: ' . implode(', ', $names));
            } else {
                $this->warn('اتلغى — مافيش حاجة اتغيّرت.');
            }
        } elseif ($extra->isNotEmpty()) {
            $this->line(
                'ℹ️  ' . $extra->count()
                . ' صلاحية في القاعدة بس. للحذف: permissions:sync --prune'
            );
        }

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
