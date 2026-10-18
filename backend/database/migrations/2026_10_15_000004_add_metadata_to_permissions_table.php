<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ عمودين للكتالوج في `permissions`: `group` و `description`.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه العمودين دول
 *
 * ⭐ الاسم `students.edit` بيقول **إيه**، مش **يعني إيه**.
 * والوصف العربي هو الفرق بين:
 *
 *   • صلاحية حد بيعرفها من اسمها
 *   • وصلاحية حد لازم يفتح الـcontroller عشان يفهمها
 *
 * ⭐ و`group` بيخلّي شاشة «الأدوار» تطلع مقسّمة بالوحدات بدل
 * قائمة مسطّحة من ٥٠ سطر.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ ليه `nullable` مش NOT NULL
 *
 * ⭐ في **Production** في صفوف موجودة من قبل الـmigration. ولو
 * الـعمود `NOT NULL` من غير default:
 *
 *     ALTER TABLE permissions ADD COLUMN group VARCHAR NOT NULL
 *     → في صف تفتقد قيمة → **الmigration كله يفشل**
 *
 * ⭐ و`ALTER TABLE ... ADD COLUMN NOT NULL DEFAULT ''` بيعمل
 * table rewrite على قواعد كبيرة.
 *
 * ⭐ فـ`nullable` + `default(null)` + **backfill في التطبيق**
 * (`permissions:sync`) هو الأأمن. و`permissions:sync` بيكتب
 * الوصف والـgroup من الكتالوج للصفوف الناقصة — فبعد أول تشغيل
 * الداتابيز تبقى كاملة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه مش بنخلي الكتالوج **ينشئ** الأعمدة
 *
 * ⭐ `config/permissions.php` بيانات. لو الـDDL مرتبط بيه، أي
 * تعديل في الوصف يبقى محتاج migration. ده بالظبط اللي الـ
 * seeder كان بيعمله غلط في الـcatalog.
 *
 * @see config/permissions.php
 * @see \App\Console\Commands\SyncPermissions
 * @see \Tests\Feature\PermissionCatalogTest::test_the_permissions_table_can_store_the_catalog_metadata
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Schema::table('permissions', function (Blueprint $table) {
            if (! Schema::hasColumn('permissions', 'group')) {
                // ⭐ `group` كلمة محجوزة في بعض الـSQL dialects
                // (`GROUP BY`) — فالسَمة `group` محتاجةquoting.
                // Laravel بتعملها بـbackticks/قوسين، بس الـname
                // نفسه مرفوض من Spatie Model لو ماطبقش.
                $table->string('group', 100)->nullable()->after('module');
            }

            if (! Schema::hasColumn('permissions', 'description')) {
                $table->text('description')->nullable()->after('group');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Schema::table('permissions', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('permissions', 'description')) {
                $columns[] = 'description';
            }

            if (Schema::hasColumn('permissions', 'group')) {
                $columns[] = 'group';
            }

            foreach ($columns as $column) {
                $table->dropColumn($column);
            }
        });
    }
};