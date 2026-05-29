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
