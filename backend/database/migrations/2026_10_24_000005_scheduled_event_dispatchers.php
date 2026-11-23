<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ **PR 4.2 — مُرسِلات الأحداث المجدولة.**
 *
 * ① `notifications.dedup_key` — **مفتاح منع التكرار الفريد**:
 *    الصيغة `event|user|reference` (حدث + مستلم + مرجع) — إعادة
 *    تشغيل أي أمر مجدول أو إعادة تسجيل حدث لحظي تُربح صفّاً
 *    واحداً. فهرس فريد ⇒ الحسم في القاعدة نفسها لا في الكاش
 *    (نيّة `Cache::flush()` لا تفتح باب إعادة الإرسال).
 *
 * ② توسيع إلنوم `notifications.event_type` بخمسة أحداث لحظية:
 *    `invoice_issued` · `student_absence` · `teacher_absence` ·
 *    `salary_paid` · `lesson_canceled` — بنفس نمط PR 4.1/3.8.
 */
return new class extends Migration
{
    /** مفردة الأحداث الكاملة بعد هذا الترحيل (12 سلفاً + 5 جدداً). */
    private const EVENT_TYPES = [
        'lesson_reminder', 'subscription_expiring', 'subscription_expired',
        'subscription_renewed', 'subscription_paused', 'subscription_resumed',
        'payment_received', 'makeup_created', 'trial_reminder',
        'announcement', 'lesson_completed', 'invoice_overdue',
        'invoice_issued', 'student_absence', 'teacher_absence',
        'salary_paid', 'lesson_canceled',
    ];

    /** مفردة PR 4.1 (المرجعية للرجوع). */
    private const EVENT_TYPES_PREVIOUS = [
        'lesson_reminder', 'subscription_expiring', 'subscription_expired',
        'subscription_renewed', 'subscription_paused', 'subscription_resumed',
        'payment_received', 'makeup_created', 'trial_reminder',
        'announcement', 'lesson_completed', 'invoice_overdue',
    ];

    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // ⭐ المنع-من-التكرار: nullable → صفوف بلا مفتاح
            // (الإشعار اليدوي/الإعلاني) لا تتصادم أبداً.
            $table->string('dedup_key')->nullable()->after('channel')->unique();
        });

        $this->dropEventTypeChecks();
        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('event_type', self::EVENT_TYPES)->change();
        });
    }

    public function down(): void
    {
        // صفوف الأحداث الخمسة الجديدة غير صالحة في enum الراجع.
        DB::table('notifications')
            ->whereIn('event_type', [
                'invoice_issued', 'student_absence', 'teacher_absence',
                'salary_paid', 'lesson_canceled',
            ])
            ->delete();

        $this->dropEventTypeChecks();
        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('event_type', self::EVENT_TYPES_PREVIOUS)->change();
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique('notifications_dedup_key_unique');
            $table->dropColumn('dedup_key');
        });
    }

    /**
     * Postgres: Laravel ينفّذ الـenum كـ`varchar check (...)`
     * وCHECK القديم لا يُشال بـ`change()` ⇒ نسقطه قبل التعديل
     * (نمط PR 3.8/4.1).
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
            DB::statement(
                sprintf('alter table notifications drop constraint "%s"', $constraint->name)
            );
        }
    }
};
