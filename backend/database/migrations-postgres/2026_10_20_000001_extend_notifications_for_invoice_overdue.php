<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EVENTS = [
        'lesson_reminder', 'subscription_expiring', 'subscription_expired', 'subscription_renewed',
        'payment_received', 'makeup_created', 'trial_reminder',
    ];

    public function up(): void
    {
        // The earlier PostgreSQL migration already created the student foreign key.
        Schema::table('notifications', function (Blueprint $table): void {
            $table->enum('event_type', [...self::EVENTS, 'invoice_overdue'])->change();
        });
    }

    public function down(): void
    {
        DB::table('notifications')->where('event_type', 'invoice_overdue')->delete();
        Schema::table('notifications', function (Blueprint $table): void {
            $table->enum('event_type', self::EVENTS)->change();
        });
    }
};
