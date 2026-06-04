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
        Schema::create('customer_communication_log_reads', function (Blueprint $table) {
            $table->id();
            $table->ulid('customer_communication_log_id');
            $table->foreignUlid('user_id');
            $table->timestamp('read_at');
            $table->timestamps();

            $table->foreign('customer_communication_log_id', 'comm_log_reads_log_id_fk')
                ->references('id')
                ->on('customer_communication_logs')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'comm_log_reads_user_id_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->unique(['customer_communication_log_id', 'user_id'], 'comm_log_reads_log_user_unique');
            $table->index(['user_id', 'read_at'], 'comm_log_reads_user_read_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_communication_log_reads');
    }
};
