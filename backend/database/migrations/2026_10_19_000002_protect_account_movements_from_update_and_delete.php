<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ PR 2.4 — حماية `account_movements` على مستوى القاعدة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ ليه الـPHP guard مش كافي؟
 *
 * لأن `AccountMovement::query()->update()` **متخفّي عن الـevents**
 * في Eloquent:
 *
 *     AccountMovement::query()->update(['amount' => 1]);
 *     AccountMovement::query()->delete();          ⭐ مفيش exception
 *
 * والـ`DB::table('account_movements')->delete()` كمان بيعدّي.
 * فالحماية في الموديل بتحمي **الكود بس**، والقاعدة هي اللي
 * بتحمي **البيانات**.
 *
 * ⚠️ ليه PostgreSQL بس؟ لأن SQLite مافيش triggers على
 * `UPDATE/DELETE` من غير hack بـ `authorizer`. والـtrigger
 * بيتبني بـ `plpgsql` — مش portable.
 *
 * ⭐ والـguard في PHP موجود **كمان** — للاختبارات اللي بتشتغل
 * على SQLite، ولعشان الـexception يطلع برسالة مفهومة بدل
 * `SQLSTATE`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable('account_movements')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION account_movements_immutable()
            RETURNS TRIGGER AS $$
            BEGIN
                IF (OLD.amount IS DISTINCT FROM NEW.amount)
                   OR (OLD.direction IS DISTINCT FROM NEW.direction)
                   OR (OLD.category IS DISTINCT FROM NEW.category)
                   OR (OLD.currency IS DISTINCT FROM NEW.currency)
                   OR (OLD.movement_date IS DISTINCT FROM NEW.movement_date)
                   OR (OLD.financial_account_id IS DISTINCT FROM NEW.financial_account_id)
                   OR (OLD.status IS DISTINCT FROM NEW.status) THEN

                    RAISE EXCEPTION
                        'account_movements immutable: % (correction = reversing entry, never UPDATE)',
                        TG_TABLE_NAME
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION account_movements_no_delete()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    'account_movements immutable: DELETE is forbidden (correction = reversing entry, never DELETE)'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_account_movements_immutable
            BEFORE UPDATE ON account_movements
            FOR EACH ROW EXECUTE FUNCTION account_movements_immutable();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_account_movements_no_delete
            BEFORE DELETE ON account_movements
            FOR EACH ROW EXECUTE FUNCTION account_movements_no_delete();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_account_movements_no_delete ON account_movements;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_account_movements_immutable ON account_movements;');
        DB::unprepared('DROP FUNCTION IF EXISTS account_movements_no_delete;');
        DB::unprepared('DROP FUNCTION IF EXISTS account_movements_immutable;');
    }
};