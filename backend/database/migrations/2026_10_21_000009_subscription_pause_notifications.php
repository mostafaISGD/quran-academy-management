<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ **PR 3.8 — إشعار إيقاف/استئناف الاشتراك.**
 *
 * ① `notifications.subscription_id` — عشان الإشعار يبقى مربوط
 *    بالاشتراك (زي `lesson_id` في PR 3.4).
 * ② حدثان جديدان: `subscription_paused` · `subscription_resumed`.
 *
 * ⚠️ **Postgres**: Laravel بينفّذ الـenum كـ`varchar check (...)`
 * والـCHECK القديم مش بيتشال بـ`change()` ⇒ نسقطه قبل التعديل
 * (نفس نمط PR 3.2 و3.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropEventTypeChecks();

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            $table->enum('event_type', [
                'lesson_reminder', 'subscription_expiring', 'subscription_expired',
                'payment_received', 'makeup_created', 'trial_reminder',
                'invoice_overdue', 'announcement', 'lesson_completed',
                'subscription_paused', 'subscription_resumed',
            ])->change();
        });
    }

    public function down(): void
    {
        DB::table('notifications')
            ->whereIn('event_type', ['subscription_paused', 'subscription_resumed'])
            ->delete();

        $this->dropEventTypeChecks();

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');

            $table->enum('event_type', [
                'lesson_reminder', 'subscription_expiring', 'subscription_expired',
                'payment_received', 'makeup_created', 'trial_reminder',
                'invoice_overdue', 'announcement', 'lesson_completed',
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
