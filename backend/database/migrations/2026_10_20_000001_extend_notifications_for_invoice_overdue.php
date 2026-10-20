<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐ PR 2.16 — شكل `notifications` يلحق بالفواتير المتأخرة.
 *
 * ══════════════════════════════════════════════════════════════
 * الأمر `invoices:notify-overdue` (من PR أقدم) بيعتن
 * `event_type = invoice_overdue` و`student_id` على طبيعته —
 * والجدول ما كانش فيه الاتنين، فأي تشغيل حقيقي كان **يتكسر**
 * (العمود مافيش، والـenum مش فيه الحدث). الأمر كان من غير
 * اختبار فالكسر كان مستخبي — الاختبار ده ظهره (PR 2.16) والـ
 * testing.ERROR قبل الترحيل ده هو الدليل.
 *
 * ① `student_id` — الطالب اللي الفاتورة بتاعته (nullable: تنبيه
 *    عام للإدارة ما يتلزمش طالب).
 * ② `invoice_overdue` في الـenum — الحدث اللي بترابط عليه
 *    تنبيهات الفواتير (والـPR 4.2 هيبني عليه التنبيهات التفصيلية).
 *
 * ⚠️ `down()` بيمسح صفوف `invoice_overdue` الأول — رجوع الترحيل
 * بيرجع الـenum القديم والصفوف بتاعته تبقى غير صالحة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('student_id')
                ->nullable()
                ->constrained('students')
                ->nullOnDelete()
                ->after('user_id');

            $table->enum('event_type', [
                'lesson_reminder', 'subscription_expiring', 'subscription_expired',
                'payment_received', 'makeup_created', 'trial_reminder', 'invoice_overdue',
            ])->change();
        });
    }

    public function down(): void
    {
        // الصفوف بحدث مش معتبر في الـenum الراجع — تتشال قبل الرجوع
        \DB::table('notifications')
            ->where('event_type', 'invoice_overdue')
            ->delete();

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_id');

            $table->enum('event_type', [
                'lesson_reminder', 'subscription_expiring', 'subscription_expired',
                'payment_received', 'makeup_created', 'trial_reminder',
            ])->change();
        });
    }
};
