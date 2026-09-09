<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['action', 'created_at'], 'activity_logs_action_created_idx');
            $table->index(['user_id', 'action', 'created_at'], 'activity_logs_user_action_idx');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_action_created_idx');
            $table->dropIndex('activity_logs_user_action_idx');
        });
    }
};
