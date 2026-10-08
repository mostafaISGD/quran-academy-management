<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ربط الاشتراك بالمجموعة مباشرةً.
 *
 * ⭐ **السبب:** قبل كده، الربط بين الاشتراك والمجموعة كان
 * بيتم من خلال:
 *   Student → GroupMember → GroupClass
 * وك.ن ده بيخلي استعلام "كل اشتراكات مجموعة X" يتطلب join على
 * ثلاث جداول — بطيء ومعقد.
 *
 * الربط المباشر بساطة:
 *   Subscription → GroupClass
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('group_class_id')
                ->nullable()
                ->after('teacher_id')
                ->constrained('group_classes')
                ->nullOnDelete();

            $table->index(['group_class_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['group_class_id']);
            $table->dropIndex(['group_class_id', 'status']);
            $table->dropColumn('group_class_id');
        });
    }
};
