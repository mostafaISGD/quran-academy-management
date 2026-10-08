<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ إضافة حقول **مستقلة** لقائمة الانتظار.
 *
 * القديمة كانت مربوطة دايماً بمجموعة (`group_class_id` إلزامي).
 * ده كان بيقسّم الشغل: مستنّي مجموعة، تعبّي اسم + رقم بس.
 *
 * الجديدة بتخلي الادارة **مستقلة** — الأدمن بيشوف طلبات
 * مختلفة، بيختار لأي مجموعة أنسب، وكمانات بيختار الباقة
 * اللي بيحسسها أنسب للطالب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waiting_list_entries', function (Blueprint $table) {
            // المجموعة المقترحة اختياري — مش لازم تكون محدّدة
            $table->unsignedBigInteger('group_class_id')->nullable()->change();

            // ⭐ بيانات الاتصال الإضافية
            $table->string('parent_phone', 30)->nullable()->after('phone');

            // ⭐ المستوى الحالي — نص حر (مش قائمة) عشان الأدمن
            //   يقدر يكتب «حفظ البقرة» / «متقن الجزء الخامس»
            $table->string('current_level', 255)->nullable()->after('parent_phone');

            // الباقة اللي مستهدفة
            $table->foreignId('package_id')->nullable()->after('current_level')
                ->constrained('subscription_plans')->nullOnDelete();

            // مجموعة مقترحة — رقم، مش إلزامي
            $table->foreignId('proposed_group_id')->nullable()->after('package_id')
                ->constrained('group_classes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('waiting_list_entries', function (Blueprint $table) {
            $table->dropForeign(['proposed_group_id']);
            $table->dropForeign(['package_id']);
            $table->dropColumn(['proposed_group_id', 'package_id', 'current_level', 'parent_phone']);
        });
    }
};
