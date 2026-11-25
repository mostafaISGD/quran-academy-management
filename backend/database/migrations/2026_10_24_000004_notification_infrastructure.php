<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ **PR 4.1 — البنية التحتية للإشعارات.**
 *
 * ثلاث جداول:
 *
 * - `notification_templates` — قالب لكل (مؤسسة، حدث، قناة، لغة)
 *   بعنوان ونص فيهما عناصر إحلال (`:student_name` مثلاً).
 * - `notification_deliveries` — صف تسليم لكل قناة خارجية.
 *   ⭐ **القاعدة الذهبية**: `sent_at` لا يُكتب إلا بعد إرسال
 *   فعلي — وضع «مُرسَل» بلا إرسال هو علة bulkNotify القديمة.
 * - `notification_preferences` — تفضيلات المستخدم لكل قناة
 *   (تعطيل الإيميل مثلاً)، والحالة الافتراضية = مفعّلة.
 *
 * ④ تطبيع `notifications.event_type` — إرجاع
 *    `subscription_renewed` التي سقطت من آخر كشف enum
 *    (PR 3.8) رغم أن `DailySubscriptionService` يستخدمها
 *    فعلياً ⇒ كل إشعار تجديد اشتراك كان سيفشل.
 *
 * ⭐ `notifications` نفسه يبقى **الصندوق الوارد** فقط —
 * ساعته «وصلت للصندوق» وليست «قبول المزود».
 */
return new class extends Migration
{
    /** مفردة الأحداث الكاملة بعد هذا الترحيل. */
    private const EVENT_TYPES = [
        'lesson_reminder', 'subscription_expiring', 'subscription_expired',
        'subscription_renewed', 'subscription_paused', 'subscription_resumed',
        'payment_received', 'makeup_created', 'trial_reminder',
        'announcement', 'lesson_completed', 'invoice_overdue',
    ];

    /** مفردة PR 3.8 (المرجعية للرجوع). */
    private const EVENT_TYPES_PREVIOUS = [
        'lesson_reminder', 'subscription_expiring', 'subscription_expired',
        'payment_received', 'makeup_created', 'trial_reminder',
        'invoice_overdue', 'announcement', 'lesson_completed',
        'subscription_paused', 'subscription_resumed',
    ];

    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('event', 50);
            $table->string('channel', 20);
            $table->string('language', 5)->default('ar');
            $table->string('title_template')->nullable();
            $table->text('body_template');
            $table->timestamps();

            $table->unique(['organization_id', 'event', 'channel', 'language'], 'notification_template_unique');
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('notification_id')->nullable()->constrained('notifications')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('event', 50);
            $table->enum('channel', ['database', 'email', 'push', 'whatsapp', 'sms']);
            $table->json('payload')->nullable();
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('provider', 80)->nullable();
            $table->string('external_id', 120)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['channel', 'status']);
            $table->index('event');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('channel', ['database', 'email', 'push', 'whatsapp', 'sms']);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'channel'], 'notification_preference_unique');
        });

        // ④ تطبيع enum الأحداث على `notifications` (نمط PR 3.8).
        $this->dropEventTypeChecks();
        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('event_type', self::EVENT_TYPES)->change();
        });
    }

    public function down(): void
    {
        // صفوف subscription_renewed غير صالحة في enum الراجع.
        DB::table('notifications')
            ->where('event_type', 'subscription_renewed')
            ->delete();

        $this->dropEventTypeChecks();
        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('event_type', self::EVENT_TYPES_PREVIOUS)->change();
        });

        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_templates');
    }

    /**
     * Postgres: Laravel ينفّذ الـenum كـ`varchar check (...)`
     * وCHECK القديم لا يُشال بـ`change()` ⇒ نسقطه قبل التعديل
     * (نفس نمط PR 3.8).
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
