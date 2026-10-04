<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تصنيفات البرامج.
 *
 * ليه جدول مش enum؟ لأن البرنامج بياخد أكتر من تصنيف:
 * النوع (تحفيظ) + المجال (قرآن كريم) + الفئة (أطفال) + المستوى (مبتدئ).
 * والقيم دي بتزيد مع الوقت — «التفسير» و«الفقه» ممكن يتضافوا بكره.
 * enum كان هيحتاج تعديل كود + deployment في كل مرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('icon', 20)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        // ربط البرنامج بالتصنيفات — البرنامج ياخد كذا تصنيف
        Schema::create('program_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('program_category_id')->constrained('program_categories')->cascadeOnDelete();
            // تسمية مستقلة: «أطفال» في برنامج و«مراهقين» في آخر
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['program_id', 'program_category_id'], 'program_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_category');
        Schema::dropIfExists('program_categories');
    }
};
