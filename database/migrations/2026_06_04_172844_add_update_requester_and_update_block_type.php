<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_communication_logs', function (Blueprint $table) {
            $table
                ->foreignUlid('update_requested_by_user_id')
                ->nullable()
                ->after('update_requested_log_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        if (DB::table('communication_block_types')->where('slug', 'update')->doesntExist()) {
            DB::table('communication_block_types')->insert([
                'id' => (string) Str::ulid(),
                'slug' => 'update',
                'name' => 'Update',
                'sort_order' => 15,
                'is_active' => true,
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('communication_block_types')
            ->where('slug', 'update')
            ->update([
                'name' => 'Update',
                'sort_order' => 15,
                'is_active' => true,
                'is_system' => true,
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_communication_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('update_requested_by_user_id');
        });

        $updateBlockTypeIds = DB::table('communication_block_types')
            ->where('slug', 'update')
            ->where('is_system', true)
            ->pluck('id');

        if (
            $updateBlockTypeIds->isNotEmpty()
            && DB::table('customer_communication_log_blocks')
                ->whereIn('communication_block_type_id', $updateBlockTypeIds)
                ->doesntExist()
        ) {
            DB::table('communication_block_types')
                ->whereIn('id', $updateBlockTypeIds)
                ->delete();
        }
    }
};
