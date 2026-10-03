<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المواعيد الأسبوعية للاشتراك.
 *
 * قبل كده كان الـ weekdays والوقت بيتحسبوا في المتصفح بس وبيروحوا
 * من غير ما يتحفظوا — يعني تعديل أي حاجة فيهم كان بيحصل في Owls
 * والاشتراك نفسه ماكانش عارف الموعد.
 *
 * weekdays = JSON array من ٠ (الأحد) لحد ٦ (السبت)
 * start_time = وقت بداية الحصة HH:MM
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->json('schedule_weekdays')->nullable()->after('lesson_duration_minutes');
            $table->char('schedule_start_time', 5)->nullable()->after('schedule_weekdays');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['schedule_weekdays', 'schedule_start_time']);
        });
    }
};
