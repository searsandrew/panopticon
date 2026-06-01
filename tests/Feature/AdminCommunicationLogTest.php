<?php

use App\Models\CommunicationBlockType;
use App\Models\CommunicationType;
use App\Models\CustomerCommunicationLog;
use App\Models\User;
use Database\Seeders\CommunicationLoggingSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function configureAdminLogTestAdmin(string $email = 'admin@example.test'): User
{
    config()->set('panopticon.admin', $email);

    return User::factory()->create([
        'email' => $email,
        'email_verified_at' => now(),
        'previous_login_at' => now()->subDay(),
        'last_login_at' => now(),
        'timezone' => 'UTC',
    ]);
}

function createAdminSubmittedLog(
    User $user,
    CommunicationType $type,
    CommunicationBlockType $summaryType,
    string $summary,
    array $attributes = [],
): CustomerCommunicationLog {
    $log = CustomerCommunicationLog::factory()
        ->submitted()
        ->for($user)
        ->for($type, 'communicationType')
        ->create(array_merge([
            'customer_name' => 'Acme Dental',
            'customer_account_number' => 'A-1001',
            'submitted_at' => now()->subHour(),
        ], $attributes));

    $log->blocks()->create([
        'communication_block_type_id' => $summaryType->id,
        'position' => 0,
        'body' => $summary,
    ]);

    return $log;
}

test('non admins cannot open the admin communication log queue', function () {
    config()->set('panopticon.admin', 'admin@example.test');

    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('admin.index'))
        ->assertForbidden();
});

test('admin queue lists logs submitted since the previous login and tracks read state', function () {
    $this->seed(CommunicationLoggingSeeder::class);

    $admin = configureAdminLogTestAdmin();
    $salesRep = User::factory()->create(['name' => 'Sam Seller']);
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();

    $newLog = createAdminSubmittedLog($salesRep, $type, $summaryType, 'New admin queue summary.', [
        'customer_name' => 'New Customer',
        'submitted_at' => now()->subHour(),
    ]);

    createAdminSubmittedLog($salesRep, $type, $summaryType, 'Old admin queue summary.', [
        'customer_name' => 'Old Customer',
        'submitted_at' => now()->subDays(2),
    ]);

    $this->actingAs($admin);

    Livewire::test('pages::admin.index')
        ->assertSee('New admin queue summary.')
        ->assertSee('New Customer')
        ->assertSee('Sam Seller')
        ->assertSee('Mark as Read')
        ->assertDontSee('Old admin queue summary.')
        ->call('markAsRead', $newLog->id)
        ->assertSee('Mark as Unread');

    expect(DB::table('customer_communication_log_reads')
        ->where('customer_communication_log_id', $newLog->id)
        ->where('user_id', $admin->id)
        ->exists())->toBeTrue();

    Livewire::test('pages::admin.index')
        ->call('markAsUnread', $newLog->id)
        ->assertSee('Mark as Read');

    expect(DB::table('customer_communication_log_reads')
        ->where('customer_communication_log_id', $newLog->id)
        ->where('user_id', $admin->id)
        ->exists())->toBeFalse();
});

test('opening an admin log marks it read and opens the shared details modal', function () {
    $this->seed(CommunicationLoggingSeeder::class);

    $admin = configureAdminLogTestAdmin();
    $salesRep = User::factory()->create();
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();

    $log = createAdminSubmittedLog($salesRep, $type, $summaryType, 'Open this log from admin.');

    $this->actingAs($admin);

    Livewire::test('pages::admin.index')
        ->call('openLog', $log->id)
        ->assertDispatched('open-communication-log-detail');

    expect(DB::table('customer_communication_log_reads')
        ->where('customer_communication_log_id', $log->id)
        ->where('user_id', $admin->id)
        ->exists())->toBeTrue();
});

test('admin context actions flag request update and archive logs', function () {
    $this->seed(CommunicationLoggingSeeder::class);

    $admin = configureAdminLogTestAdmin();
    $salesRep = User::factory()->create();
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();

    $log = createAdminSubmittedLog($salesRep, $type, $summaryType, 'Admin action summary.');

    $this->actingAs($admin);

    Livewire::test('pages::admin.index')
        ->call('flagForFollowUp', $log->id)
        ->assertDispatched('communication-log-saved')
        ->call('requestUpdate', $log->id)
        ->assertDispatched('communication-log-saved')
        ->assertSee('Update requested')
        ->call('archiveLog', $log->id)
        ->assertDispatched('communication-log-saved')
        ->assertDontSee('Admin action summary.');

    $archivedLog = CustomerCommunicationLog::withTrashed()->findOrFail($log->id);

    expect($archivedLog)
        ->requires_follow_up->toBeTrue()
        ->status->toBe(CustomerCommunicationLog::STATUS_UPDATE_REQUESTED);

    $this->assertSoftDeleted('customer_communication_logs', [
        'id' => $log->id,
    ]);
});
