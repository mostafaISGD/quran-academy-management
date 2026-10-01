<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quran_surahs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('number')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->unsignedSmallInteger('ayah_count');
            $table->enum('revelation_type', ['meccan', 'medinan']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quran_surahs');
    }
};
