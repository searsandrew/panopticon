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
        Schema::create('customer_communication_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('netsuite_customer_id');
            $table->string('customer_account_number', 32);
            $table->string('customer_name')->nullable();
            $table->unsignedBigInteger('netsuite_sales_rep_id')->nullable();
            $table->foreignUlid('communication_type_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_contact_id')->nullable()->constrained('customer_contacts')->nullOnDelete();
            $table->string('contact_person_name')->nullable();
            $table->dateTime('contact_at');
            $table->string('status', 24)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_autosaved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'customer_account_number', 'status']);
            $table->index(['netsuite_customer_id', 'status']);
            $table->index('netsuite_sales_rep_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_communication_logs');
    }
};
