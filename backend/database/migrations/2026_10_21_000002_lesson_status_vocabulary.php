<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ **PR 3.1 — مفردات حالة الحصة.**
 *
 * ══════════════════════════════════════════════════════════════
 * إيه اللي بيتغيّر؟
 *
 * الـenum القديم (9 قيم):
 *   scheduled, confirmed, in_progress, completed, cancelled,
 *   student_absent, teacher_absent, technical_issue, rescheduled
 *
 * المفردات الجديدة (9 قيم — المواصفة):
 *   scheduled, confirmed, in_progress, completed, student_absent,
 *   teacher_absent, cancelled_by_academy, cancelled_late, rescheduled
 *
 * ⭐ **ليه اتغيّرت؟** «cancelled» كانت بتقول نوعين مختلفين:
 * إلغاء مبكر (قبل الحصة) وإلغاء متأخر (بعد بدايتها) — والفروق
 * بين الاتنين في **الرصيد والمستحقات** فرق حقيقي. و«technical_issue»
 * (عطل تقني) كان بيقع تحت نفس «cancelled» — فعلياً هو إلغاء من
 * الأكاديمية (السبب الأكاديمي مش الطالب).
 *
 * ⚠️ **ليه 3 خطوات مش واحدة؟**
 * Laravel ينفّذ الـenum كـCHECK constraint في Postgres
 * (`varchar check ("status" in (...))`) وفي SQLite كـCHECK داخل
 * DDL الجدول. فالتغيير = تغيير القيد:
 *
 * ① **توسيع** (11 قيمة: التسع الجديدة + القديمتان) — عشان
 *    الصفوف القديمة ما تخالفش القيد الجديد.
 * ② **تحويل الصفوف**: `cancelled`/`technical_issue` →
 *    `cancelled_by_academy` (الإلغاء من الأكاديمية — أقرب معنى
 *    للمفردة القديمة؛ السبب الحقيقي محفوظ في `cancellation_reason`).
 * ③ **تضييق** (9 قيم — مفردات المواصفة بالظبط).
 *
 * ⭐ **Postgres**: الـCHECK القديم له اسم
 * (`lessons_status_check`) وLaravel **مش بيمسحه** لما يعمل
 * `change()` — فبنمسحه بإيدنا قبل كل خطوة (وعموماً: أي CHECK
 * على العمود ده، مش الاسم المتوقع). **SQLite**: إعادة
 * بناء الجدول — اللي Laravel بيعمله بإيه (المفاتيح الأجنبية
 * والأعمدة والفهارس محفوظة — اتأكدنا تجريبياً).
 *
 * ⚠️ `down()` بيرجّع المفردات القديمة ويحوّل الصفوف العكس
 * (وعملي: `cancelled_by_academy`/`cancelled_late` → `cancelled`).
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const LEGACY_VALUES = ['cancelled', 'technical_issue'];

    /** @var array<int, string> */
    private const NEW_VALUES = [
        'scheduled', 'confirmed', 'in_progress', 'completed',
        'student_absent', 'teacher_absent', 'cancelled_by_academy',
        'cancelled_late', 'rescheduled',
    ];

    public function up(): void
    {
        // ① توسيع — الصفوف القديمة لسه موجودة.
        $this->dropStatusChecks();
        Schema::table('lessons', function (Blueprint $table) {
            $table->enum('status', array_merge(self::NEW_VALUES, self::LEGACY_VALUES))->change();
        });

        // ② تحويل الصفوف القديمة.
        DB::table('lessons')
            ->whereIn('status', self::LEGACY_VALUES)
            ->update(['status' => 'cancelled_by_academy']);

        // ③ تضييق على مفردات المواصفة.
        $this->dropStatusChecks();
        Schema::table('lessons', function (Blueprint $table) {
            $table->enum('status', self::NEW_VALUES)->change();
        });
    }

    public function down(): void
    {
        // ⭐ العكس: نوسّع الأول (عشان القيم الجديدة تنفع)، نحوّل
        // الصفوف، وبعدين نضيّق على المفردات القديمة.
        $this->dropStatusChecks();
        Schema::table('lessons', function (Blueprint $table) {
            $table->enum('status', array_merge(self::NEW_VALUES, self::LEGACY_VALUES))->change();
        });

        DB::table('lessons')
            ->whereIn('status', ['cancelled_by_academy', 'cancelled_late'])
            ->update(['status' => 'cancelled']);

        $this->dropStatusChecks();
        Schema::table('lessons', function (Blueprint $table) {
            $table->enum('status', [
                'scheduled', 'confirmed', 'in_progress', 'completed',
                'cancelled', 'student_absent', 'teacher_absent',
                'technical_issue', 'rescheduled',
            ])->change();
        });
    }

    /**
     * ⭐ إسقاط أي CHECK على عمود `lessons.status` — في Postgres
     * الاسم `lessons_status_check` (تلقائي)، لكننا بندوّر على
     * **كل** القيود على العمود عشان نتفادى أي اسم تاني.
     * في SQLite مفيش CHECK مسمّى (مدمج في DDL) — فمفيش حاجة.
     */
    private function dropStatusChecks(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $constraints = DB::select(
            "select con.conname as name
               from pg_constraint con
               join pg_class rel on rel.oid = con.conrelid
               join pg_attribute att on att.attrelid = rel.oid and att.attnum = any (con.conkey)
              where rel.relname = 'lessons'
                and att.attname = 'status'
                and con.contype = 'c'"
        );

        foreach ($constraints as $constraint) {
            DB::statement('alter table lessons drop constraint if exists "'.$constraint->name.'"');
        }
    }
};
