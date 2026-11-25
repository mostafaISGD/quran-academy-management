<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐ **أنواع إشعارات طلبات ولي الأمر (PR 4.5).**
 *
 * `notifications.event_type` قيده مُغلَق من أول migration — أي نوع
 * جديد لازم migration (وإلا `CHECK constraint failed`).
 *
 * ⭐ الأنواع:
 *  • `student_request_created`  → للإدارة لما الولي يرفع طلب
 *  • `student_request_approved` → للولي لما القرار يطلع موافقة
 *  • `student_request_rejected` → للولي لما القرار يطلع رفض
 *
 * ⚠️ Postgres: القيد القديم مش بيتشال بـ`change()` — نسقطه الأول
 * (نفس نمط PR 3.8).
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const TYPES = [
        'lesson_reminder', 'subscription_expiring', 'subscription_expired',
        'payment_received', 'makeup_created', 'trial_reminder',
        'invoice_overdue', 'announcement', 'lesson_completed',
        'subscription_paused', 'subscription_resumed',
        'student_request_created', 'student_request_approved', 'student_request_rejected',
    ];

    public function up(): void
    {
        $this->dropEventTypeChecks();

        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('event_type', self::TYPES)->change();
        });
    }

    public function down(): void
    {
        DB::table('notifications')
            ->whereIn('event_type', [
                'student_request_created',
                'student_request_approved',
                'student_request_rejected',
            ])
            ->delete();

        $this->dropEventTypeChecks();

        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('event_type', [
                'lesson_reminder', 'subscription_expiring', 'subscription_expired',
                'payment_received', 'makeup_created', 'trial_reminder',
                'invoice_overdue', 'announcement', 'lesson_completed',
                'subscription_paused', 'subscription_resumed',
            ])->change();
        });
    }

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
