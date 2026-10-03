<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول مواعيد المعلم (commitments).
 *
 * الفكرة: المعلم المستقل بيشتغل في أكاديميّاتmultiple — فبيسجّل كل
 * commitments بتاعه هنا (أكاديمية + شغل خارجي)، والنظام بيحسب الـ
 * free time = كل ما هومسجّلش فيه.
 *
 * ملاحظة: ده مش "ساعات عمل ثابتة" — ده كل الأوقات اللي بتاخده.
 * لو المعلم سجّل ٤-٦م و٧-٩م يوم الأحد، يبقى ٧-٩م متاح لأن مفيش
 * commitment عليه في الفترة دي.
 *
 * حصص الأكاديمية (lessons) مش بتتسجّل هنا — هي في جدول lessons
 * نفسه، والنظام بيتحقق من الاتنين مع بعض.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();

            // 0 = الأحد … 6 = السبت (نفس dayOfWeek في Carbon)
            $table->unsignedTinyInteger('weekday');

            // وقت البداية/النهاية بال HH:MM — من غير تاريخ (بيكرر أسبوعياً)
            $table->time('starts_at');
            $table->time('ends_at');

            // نوع الالتزام: أكاديمية / شغل خارجي / إجازة / شخصي
            $table->enum('kind', ['academy', 'external', 'leave', 'personal'])->default('academy');

            $table->string('title')->nullable();
            $table->text('notes')->nullable();

            // إتعمل بالـ recurring (كل أسبوع) ولا مرة واحدة فقط
            $table->boolean('is_recurring')->default(true);
            // للتأجيلات الواحد بعينها: لو is_recurring = false
            $table->date('specific_date')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['teacher_id', 'weekday']);
            $table->index(['teacher_id', 'specific_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_schedules');
    }
};
