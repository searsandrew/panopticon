<?php

use Illuminate\Support\Facades\Schema;

test('customer communication log indexes use MySQL safe names', function () {
    $indexNames = collect(Schema::getIndexes('customer_communication_logs'))
        ->pluck('name')
        ->filter()
        ->values();

    expect($indexNames)
        ->toContain('comm_logs_user_account_status_idx');

    foreach ($indexNames as $indexName) {
        expect(strlen((string) $indexName))->toBeLessThanOrEqual(64);
    }
});

test('customer communication log block foreign keys use MySQL safe names', function () {
    $migration = file_get_contents(database_path('migrations/2026_05_28_143505_create_customer_communication_log_blocks_table.php'));

    expect($migration)
        ->toContain("'comm_log_blocks_log_id_fk'")
        ->toContain("'comm_log_blocks_block_type_id_fk'")
        ->not->toContain('customer_communication_log_blocks_customer_communication_log_id_foreign')
        ->not->toContain('customer_communication_log_blocks_communication_block_type_id_foreign');

    foreach (['comm_log_blocks_log_id_fk', 'comm_log_blocks_block_type_id_fk'] as $foreignKeyName) {
        expect(strlen($foreignKeyName))->toBeLessThanOrEqual(64);
    }
});
