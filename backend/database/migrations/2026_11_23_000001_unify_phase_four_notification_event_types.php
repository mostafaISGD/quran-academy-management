<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREVIOUS_EVENTS = [
        'lesson_reminder', 'subscription_expiring', 'subscription_expired',
        'payment_received', 'makeup_created', 'trial_reminder',
        'invoice_overdue', 'announcement', 'lesson_completed',
        'subscription_paused', 'subscription_resumed',
        'student_request_created', 'student_request_approved', 'student_request_rejected',
    ];

    private const RESTORED_EVENTS = [
        'subscription_renewed', 'invoice_issued', 'student_absence',
        'teacher_absence', 'salary_paid', 'lesson_canceled',
    ];

    public function up(): void
    {
        $this->replaceConstraint([...self::PREVIOUS_EVENTS, ...self::RESTORED_EVENTS]);
    }

    public function down(): void
    {
        if (DB::table('notifications')->whereIn('event_type', self::RESTORED_EVENTS)->exists()) {
            throw new RuntimeException('لا يمكن الرجوع مع وجود إشعارات تعتمد على أنواع أحداث المرحلة الرابعة.');
        }

        $this->replaceConstraint(self::PREVIOUS_EVENTS);
    }

    private function replaceConstraint(array $events): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $checks = DB::select(<<<'SQL'
                select con.conname
                from pg_constraint con
                join pg_attribute att on att.attrelid = con.conrelid
                    and con.conkey = array[att.attnum]
                where con.conrelid = 'notifications'::regclass
                    and con.contype = 'c'
                    and att.attname = 'event_type'
                SQL);

            foreach ($checks as $check) {
                $name = str_replace('"', '""', $check->conname);
                DB::statement('alter table notifications drop constraint "'.$name.'"');
            }

            $pdo = DB::connection()->getPdo();
            $allowed = implode(', ', array_map($pdo->quote(...), $events));
            DB::statement(
                'alter table notifications add constraint notifications_event_type_check check (event_type in ('.$allowed.'))',
            );

            return;
        }

        Schema::table('notifications', function (Blueprint $table) use ($events) {
            $table->enum('event_type', $events)->change();
        });
    }
};
