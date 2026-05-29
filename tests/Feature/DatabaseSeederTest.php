<?php

use App\Models\CommunicationBlockType;
use App\Models\CommunicationType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('database seeder creates only production application seed data', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse()
        ->and(CommunicationType::query()->where('slug', CommunicationType::PHONE)->exists())->toBeTrue()
        ->and(CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'sales-rep')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'communication-logs.create')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'communication-logs.view-history')->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'sales-rep')->sole()->hasPermissionTo('communication-logs.view-history'))->toBeFalse();
});
