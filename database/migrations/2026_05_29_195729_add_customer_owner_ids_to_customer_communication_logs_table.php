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
            $table->unsignedBigInteger('netsuite_customer_sales_rep_id')->nullable()->after('netsuite_sales_rep_id');
            $table->unsignedBigInteger('netsuite_customer_pipeline_owner_id')->nullable()->after('netsuite_customer_sales_rep_id');

            $table->index('netsuite_customer_sales_rep_id', 'comm_logs_customer_sales_rep_idx');
            $table->index('netsuite_customer_pipeline_owner_id', 'comm_logs_customer_pipeline_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_communication_logs', function (Blueprint $table) {
            $table->dropIndex('comm_logs_customer_sales_rep_idx');
            $table->dropIndex('comm_logs_customer_pipeline_idx');
            $table->dropColumn(['netsuite_customer_sales_rep_id', 'netsuite_customer_pipeline_owner_id']);
        });
    }
};
