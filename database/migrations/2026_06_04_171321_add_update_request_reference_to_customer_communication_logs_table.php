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
            $table
                ->foreignUlid('update_requested_log_id')
                ->nullable()
                ->after('last_autosaved_at')
                ->constrained('customer_communication_logs')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_communication_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('update_requested_log_id');
        });
    }
};
