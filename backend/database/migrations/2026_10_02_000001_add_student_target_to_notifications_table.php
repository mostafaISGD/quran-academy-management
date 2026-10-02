<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إضافة الإشعارات الجماعية للطلاب:
 *  - `student_id` nullable → الإشعار يوصل لطالب (مش لازم يكون له حساب)
 *  - `user_id` يبقى nullable → الطالب ممكن يكون مش ليه حساب
 *  - event_type جديد: `announcement`
 *
 * SQLite ما بيقبلش تعديل enum في المكان — فبنعمل rebuild للجدول.
 */
return new class extends Migration
{
    private const NEW_EVENT_TYPES = [
        'lesson_reminder', 'subscription_expiring', 'subscription_expired',
        'payment_received', 'makeup_created', 'trial_reminder', 'announcement',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildForSqlite();
        } else {
            Schema::table('notifications', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
                $table->foreignId('student_id')->nullable()->after('user_id')->constrained('students')->cascadeOnDelete();
                $table->enum('event_type', self::NEW_EVENT_TYPES)->change();
            });
        }
    }

    public function down(): void
    {
        // نرجّع بالصفوف اللي event_type = announcement بس
        DB::table('notifications')->where('event_type', 'announcement')->delete();

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropConstrainedForeignId('student_id');
                $table->dropColumn('student_id');
            });
        }
    }

    private function rebuildForSqlite(): void
    {
        $types = implode(', ', array_map(fn ($t) => "'{$t}'", self::NEW_EVENT_TYPES));
        $channels = "'in_app', 'email', 'whatsapp', 'sms'";

        DB::statement("
            CREATE TABLE notifications_new (
                id integer primary key autoincrement,
                user_id integer NULL,
                student_id integer NULL,
                event_type varchar NOT NULL CHECK (event_type IN ({$types})),
                channel varchar NOT NULL CHECK (channel IN ({$channels})),
                payload text NULL,
                sent_at datetime NULL,
                read_at datetime NULL,
                created_at datetime NULL,
                updated_at datetime NULL,
                CONSTRAINT notifications_user_id_foreign
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT notifications_student_id_foreign
                    FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
            )
        ");

        DB::statement('
            INSERT INTO notifications_new
                (id, user_id, event_type, channel, payload, sent_at, read_at, created_at, updated_at)
            SELECT
                id, user_id, event_type, channel, payload, sent_at, read_at, created_at, updated_at
            FROM notifications
        ');

        DB::statement('DROP TABLE notifications');
        DB::statement('ALTER TABLE notifications_new RENAME TO notifications');
        DB::statement('CREATE INDEX notifications_user_id_read_at_index ON notifications (user_id, read_at)');
        DB::statement('CREATE INDEX notifications_student_id_index ON notifications (student_id)');
    }
};
