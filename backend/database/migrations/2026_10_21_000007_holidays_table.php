<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ **PR 3.6 — العطلات (رسمية + أكاديمية).**
 *
 * ⭐ **قبل PR ده**: مفيش أي مفهوم للعطلة — فالحجز بيحصل في
 * العطلات الرسمية زي أي يوم عادي (والعطلات الأكاديمية كمان).
 *
 * ⭐ **`type`**:
 *
 * - `official` — عطلة رسمية (عيد/رأس سنة): `organization_id` =
 *   **null** ⇒ تخص كل الأكاديميات.
 * - `academy` — إجازة الأكاديمية: `organization_id` = المؤسسة.
 *
 * ⚠️ `down()` بيمسح الجدول — العطلات **بيانات** (مش مخططات)،
 * فمسحها عند الرجوع مقصود وموثّق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('name');
            $table->enum('type', ['official', 'academy'])->default('academy');

            // ⭐ null = عطلة رسمية (لكل الأكاديميات).
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['date', 'type']);
            $table->unique(['date', 'name', 'type', 'organization_id'], 'holidays_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
