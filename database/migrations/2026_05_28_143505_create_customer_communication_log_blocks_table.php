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
        Schema::create('customer_communication_log_blocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('customer_communication_log_id');
            $table->ulid('communication_block_type_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->longText('body')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('customer_communication_log_id', 'comm_log_blocks_log_id_fk')
                ->references('id')
                ->on('customer_communication_logs')
                ->cascadeOnDelete();
            $table->foreign('communication_block_type_id', 'comm_log_blocks_block_type_id_fk')
                ->references('id')
                ->on('communication_block_types')
                ->restrictOnDelete();
            $table->index(['customer_communication_log_id', 'position'], 'communication_log_blocks_log_position_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_communication_log_blocks');
    }
};
