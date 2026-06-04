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
        Schema::table('customer_communication_log_reads', function (Blueprint $table) {
            $table->timestamp('cleared_at')->nullable()->after('read_at');

            $table->index(['user_id', 'cleared_at'], 'comm_log_reads_user_cleared_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_communication_log_reads', function (Blueprint $table) {
            $table->dropIndex('comm_log_reads_user_cleared_at_idx');
            $table->dropColumn('cleared_at');
        });
    }
};
