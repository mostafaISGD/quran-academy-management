<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add avatar_url to teachers table
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('avatar_url')->nullable()->after('display_name');
            $table->string('qualifications')->nullable()->after('specialization');
            $table->integer('years_of_experience')->nullable()->after('qualifications');
            $table->string('languages')->nullable()->after('years_of_experience');
            $table->text('bio')->nullable()->after('languages');
        });

        // Create teacher_ratings table
        Schema::create('teacher_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('parents')->onDelete('cascade');
            $table->unsignedTinyInteger('rating'); // 1-5
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_ratings');
        
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn(['avatar_url', 'qualifications', 'years_of_experience', 'languages', 'bio']);
        });
    }
};
