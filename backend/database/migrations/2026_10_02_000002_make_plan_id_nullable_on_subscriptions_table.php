<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `subscriptions.plan_id` بقى اختياري.
 *
 * السبب: نموذج إضافة الطالب كان فيه حقل «الخطة» المكرّر مع «نوع الفوترة»،
 * وأرقام الخطة في الـ UI كانت وهمية (1/2/3) ومش بتطابق جدول
 * subscription_plans — فكانت بتخزّن اشتراكات على باقات غلط بصمت.
 * الاشتراك دلوقتي بيتسجّل بالسعر + billing_type مباشرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite ما بيقبلش ALTER COLUMN — بنعمل rebuild للجدول
            $this->rebuild(true);
        } else {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->unsignedBigInteger('plan_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // نملأ الصفوف الفاضية بأول باقة موجودة قبل رجوع القيد
        DB::table('subscriptions')->whereNull('plan_id')->update([
            'plan_id' => DB::table('subscription_plans')->orderBy('id')->value('id'),
        ]);

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuild(false);
        } else {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->unsignedBigInteger('plan_id')->nullable(false)->change();
            });
        }
    }

    /**
     * إعادة بناء جدول subscriptions على SQLite مع تعديل قابلية plan_id.
     * بننسخ كل الأعمدة والفهارس كما هي من الجدول الحالي.
     */
    private function rebuild(bool $nullable): void
    {
        // 1) نجمع تعريف الأعمدة الحالي
        $columns = collect(DB::select('PRAGMA table_info("subscriptions")'))
            ->map(function ($c) use ($nullable) {
                $name = '"' . $c->name . '"';
                $type = $c->type;
                $notNull = $c->notnull ? ' NOT NULL' : '';
                $default = $c->dflt_value !== null ? " DEFAULT {$c->dflt_value}" : '';
                $pk = $c->pk ? ' PRIMARY KEY AUTOINCREMENT' : '';

                if ($c->name === 'plan_id') {
                    $notNull = $nullable ? '' : ' NOT NULL';
                }

                return "{$name} {$type}{$notNull}{$default}{$pk}";
            })
            ->implode(', ');

        // 2) الـ foreign keys للجدول
        $fks = collect(DB::select('PRAGMA foreign_key_list("subscriptions")'))
            ->map(function ($fk) {
                $onDelete = strtoupper($fk->on_delete) === 'CASCADE' ? 'ON DELETE CASCADE' : '';
                $onUpdate = strtoupper($fk->on_update) === 'CASCADE' ? 'ON UPDATE CASCADE' : '';
                return "FOREIGN KEY (\"{$fk->from}\") REFERENCES \"{$fk->table}\"(\"{$fk->to}\") {$onDelete} {$onUpdate}";
            })
            ->implode(', ');

        $tableDef = "CREATE TABLE \"subscriptions_tmp\" ({$columns}" . ($fks ? ", {$fks}" : '') . ')';

        // 3) الفهارس — بننسخها بعد الـ rename عشان يشيروا للاسم الجديد
        $indexSql = collect(DB::select(
            "SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'subscriptions' AND sql IS NOT NULL"
        ))->pluck('sql')->all();

        DB::statement('PRAGMA foreign_keys=OFF');

        DB::statement($tableDef);
        DB::statement('INSERT INTO "subscriptions_tmp" SELECT * FROM "subscriptions"');
        DB::statement('DROP TABLE "subscriptions"');
        DB::statement('ALTER TABLE "subscriptions_tmp" RENAME TO "subscriptions"');

        foreach ($indexSql as $sql) {
            DB::statement($sql);
        }

        DB::statement('PRAGMA foreign_keys=ON');
    }
};
