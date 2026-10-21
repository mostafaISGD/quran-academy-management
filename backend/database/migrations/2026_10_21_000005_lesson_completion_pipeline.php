<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ **PR 3.4 — خط أنابيب إتمام الحصة.**
 *
 * ① **`activity_logs`** (جديد) — سجل النشاط: مين عمل إيه على أي
 *    موضوع. قبل PR ده كان السجل الوحيد `audit_logs` (وحدوده
 *    إدارية) — وسجل الحصة كان `lesson_status_history` بس.
 *
 * ② **`notifications.lesson_id`** + حدث `lesson_completed` — عشان
 *    الإشعار يبقى مربوط بالحصة (مش رقم في payload).
 *
 * ③ **`teacher_earnings.lesson_id` فريد** — **التفرّد على مستوى
 *    قاعدة البيانات**: مستحق واحد لكل حصة مهما اتنادى الإتمام
 *    كام مرة. (`ensureForLesson` كان بيمنع التكرار بالتطبيق
 *    فقط — فكان فيه سباق ممكن).
 *
 * ⚠️ **إصلاح CHECK على Postgres**: Laravel بينفّذ الـenum كـ
 * `varchar check (...)` — والـCHECK القديم على
 * `notifications.event_type` **مش بيتشال** بـ`change()` (من
 * migration PR 2.16) ⇒ أي قيمة جديدة كانت هتترفض على Postgres.
 * فهنا: نسقط كل CHECK على العمود ونضيفه من جديد بقيم كاملة.
 * (في SQLite مفيش CHECK مسمّى — إعادة البناء بتعملها لوحدها.)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── ① سجل النشاط ────────────────────────────────────────
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // ⭐ الموضوع (lesson / student / invoice …) + الإجراء.
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('action', 60);
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['organization_id', 'created_at']);
        });

        // ── ② إشعار إتمام الحصة ─────────────────────────────────
        $this->dropEventTypeChecks();

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();

            $table->enum('event_type', [
                'lesson_reminder', 'subscription_expiring', 'subscription_expired',
                'payment_received', 'makeup_created', 'trial_reminder',
                'invoice_overdue', 'announcement', 'lesson_completed',
            ])->change();
        });

        // ── ③ مستحق واحد لكل حصة (تفرّد على مستوى القاعدة) ─────
        Schema::table('teacher_earnings', function (Blueprint $table) {
            $table->unique('lesson_id', 'teacher_earnings_lesson_unique');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_earnings', function (Blueprint $table) {
            $table->dropUnique('teacher_earnings_lesson_unique');
        });

        // ⭐ إشعارات «إتمام الحصة» ما بتقبلش قيمة قديمة ⇒
        // بتتشال قبل الرجوع.
        DB::table('notifications')->where('event_type', 'lesson_completed')->delete();

        $this->dropEventTypeChecks();

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lesson_id');

            $table->enum('event_type', [
                'lesson_reminder', 'subscription_expiring', 'subscription_expired',
                'payment_received', 'makeup_created', 'trial_reminder',
                'invoice_overdue', 'announcement',
            ])->change();
        });

        Schema::dropIfExists('activity_logs');
    }

    /**
     * ⭐ إسقاط كل CHECK على `notifications.event_type` (Postgres).
     */
    private function dropEventTypeChecks(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $constraints = DB::select(
            "select con.conname as name
               from pg_constraint con
               join pg_class rel on rel.oid = con.conrelid
               join pg_attribute att on att.attrelid = rel.oid and att.attnum = any (con.conkey)
              where rel.relname = 'notifications'
                and att.attname = 'event_type'
                and con.contype = 'c'"
        );

        foreach ($constraints as $constraint) {
            DB::statement('alter table notifications drop constraint if exists "'.$constraint->name.'"');
        }
    }
};
