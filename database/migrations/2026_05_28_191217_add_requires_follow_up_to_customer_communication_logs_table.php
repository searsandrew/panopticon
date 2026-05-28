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
            $table->boolean('requires_follow_up')->default(false)->after('status');
            $table->index(['netsuite_customer_id', 'requires_follow_up'], 'communication_logs_customer_follow_up_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_communication_logs', function (Blueprint $table) {
            $table->dropIndex('communication_logs_customer_follow_up_index');
            $table->dropColumn('requires_follow_up');
        });
    }
};
