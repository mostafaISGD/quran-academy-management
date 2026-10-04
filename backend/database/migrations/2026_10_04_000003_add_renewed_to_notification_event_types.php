<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * نوع الإشعار «subscription_renewed».
 *
 * كان ناقص: المعالجة اليومية بتجدّد الاشتراكات وبتبعت إشعار لكل
 * تجديد، والـ enum مش كان فيه القيمة دي — فالإدراج كانيفشل.
 */
return new class extends Migration
{
    /** كل الأنواع المتاحة */
    private const TYPES = [
        'lesson_reminder',
        'subscription_expiring',
        'subscription_expired',
        'subscription_renewed',
        'payment_received',
        'makeup_created',
        'trial_reminder',
    ];

    public function up(): void
    {
        // SQLite مفيهوش ALTER enum — لازم نعمل جدول من جديد ونقل البيانات
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');

            DB::statement('CREATE TABLE notifications_new (
                id integer PRIMARY KEY AUTOINCREMENT,
                user_id integer NOT NULL,
                event_type varchar NOT NULL CHECK (event_type IN (\''
                    . implode('\',\'', self::TYPES) . '\')),
                channel varchar NOT NULL CHECK (channel IN (\'in_app\', \'email\', \'whatsapp\', \'sms\')),
                payload text NULL,
                sent_at datetime NULL,
                read_at datetime NULL,
                created_at datetime NULL,
                updated_at datetime NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )');

            DB::statement('INSERT INTO notifications_new
                (id, user_id, event_type, channel, payload, sent_at, read_at, created_at, updated_at)
                SELECT id, user_id, event_type, channel, payload, sent_at, read_at, created_at, updated_at
                FROM notifications');

            DB::statement('DROP TABLE notifications');
            DB::statement('ALTER TABLE notifications_new RENAME TO notifications');

            DB::statement('CREATE INDEX notifications_user_id_read_at_index ON notifications (user_id, read_at)');

            DB::statement('PRAGMA foreign_keys = ON');

            return;
        }

        // MySQL / Postgres — نعمل عمود تاني ونرجّعه
        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('event_type', self::TYPES)->change();
        });
    }

    public function down(): void
    {
        $this->up();
    }
};
