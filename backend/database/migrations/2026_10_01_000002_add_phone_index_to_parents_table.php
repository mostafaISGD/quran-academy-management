<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // فهرس على رقم الولي لت البحث والإكمال التلقائي يكون سريع
        Schema::table('parents', function (Blueprint $table) {
            $table->index('phone', 'parents_phone_index');
        });
    }

    public function down(): void
    {
        Schema::table('parents', function (Blueprint $table) {
            $table->dropIndex('parents_phone_index');
        });
    }
};