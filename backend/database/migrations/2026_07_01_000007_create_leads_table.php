<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('full_name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('country_code', 5)->nullable();
            $table->unsignedSmallInteger('student_age')->nullable();
            $table->foreignId('interested_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->enum('source', ['facebook', 'instagram', 'website', 'referral', 'other'])->nullable();
            $table->foreignId('assigned_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', [
                'new', 'contacted', 'qualified', 'trial_booked',
                'trial_completed', 'offer_sent', 'converted', 'lost',
            ])->default('new');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('assigned_staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
