<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حضور وانصراف الموظفين.
 *
 * مبسّط عمداً: مفيش وردات ولا clock-in ولا موافقات. الأدمن بيفتح
 * يوم معيّن ويمسح لكل موظف: حاضر / غايب / متأخر / إجازة.
 *
 * check_in و check_out اختياريين — الغايب مالوش وقت حضور، بس
 * بنسجّل الأوقات لو الأدمن عايز.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');

            $table->enum('status', ['present', 'absent', 'late', 'on_leave', 'half_day'])
                ->default('present');

            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            // الدقائق اللي اتأخر بيها — بتتحسب من check_in بس المفروض
            $table->unsignedSmallInteger('late_minutes')->default(0);
            // الساعات الفعلية — بتتحسب من check_in/check_out
            $table->decimal('worked_hours', 5, 2)->default(0);

            $table->text('notes')->nullable();
            // مين سجّل السطر ده
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // يوم واحد لكل موظف — عشان التسجّل مرتين على نفس اليوم
            // يعمل update مش تكرار
            $table->unique(['employee_id', 'date']);
            $table->index(['date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
