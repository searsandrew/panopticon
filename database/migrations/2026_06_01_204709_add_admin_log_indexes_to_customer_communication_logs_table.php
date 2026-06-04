<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_communication_logs', function (Blueprint $table) {
            $table->index(['status', 'submitted_at'], 'comm_logs_status_submitted_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_communication_logs', function (Blueprint $table) {
            $table->dropIndex('comm_logs_status_submitted_at_idx');
        });
    }
};
