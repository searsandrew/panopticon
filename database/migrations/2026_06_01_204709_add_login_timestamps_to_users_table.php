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
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('previous_login_at')->nullable()->after('email_verified_at');
            $table->timestamp('last_login_at')->nullable()->after('previous_login_at');

            $table->index('previous_login_at', 'users_previous_login_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_previous_login_at_idx');
            $table->dropColumn(['previous_login_at', 'last_login_at']);
        });
    }
};
