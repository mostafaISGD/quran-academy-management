<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مرونة طريقة عمل الموظفين: فريلانس / بالساعات، بدون جدول ورديات.
 *
 * القرار: كل الموظفين غير المعلمين بيشتغلوا **بالساعات المرنة** —
 * مش دوام ثابت. ده بيشيل الحاجة لحاجتين:
 *
 *  ١. جدول `employee_shifts` (مين متوقع أنهي يوم وإمتى) — مش هينفع
 *     نحط أوقات ثابتة والمستخدمين بيشتغلوا بطرق مختلفة. ولما مفيش
 *     جدول، **غياب سطر في اليوم = الموظف ما اشتغلش**، فمفيش داعي
 *     لحالة «مش مدعو للشغل».
 *
 *  ٢. `late_minutes` — مفيش وقت مرجعي نقيس بيه التأخير، فكان بيتحسب
 *     +١٥ دقيقة تلقائياً وده كذب.
 *
 * اللي فاضل: `worked_hours` بقى **اللي الأدمن يكتبه** وهو مصدر
 * الأجر — بدل ما يتشتق من وقت دخول وخروج.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn('late_minutes');
        });

        Schema::table('employees', function (Blueprint $table) {
            // سعر الساعة — nullable عشان المتطوع مالوش أجر أصلاً.
            // نتخزنه في Payroll line كـ لقطة، فبتغيير السعر بعدين ما
            // بيغيّرش مرتب فتره اتحسبت قبل كده.
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('employment_type');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('hourly_rate');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unsignedSmallInteger('late_minutes')->default(0);
        });
    }
};