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

test('admin queue lists logs uncleared by the current admin and tracks read state', function () {
    $this->seed(CommunicationLoggingSeeder::class);

    $admin = configureAdminLogTestAdmin();
    $salesRep = User::factory()->create(['name' => 'Sam Seller']);
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();

    $unreadLog = createAdminSubmittedLog($salesRep, $type, $summaryType, 'Older unread admin queue summary.', [
        'customer_name' => 'Older Customer',
        'submitted_at' => now()->subDays(2),
    ]);

    $readLog = createAdminSubmittedLog($salesRep, $type, $summaryType, 'Newer read admin queue summary.', [
        'customer_name' => 'Read Customer',
        'submitted_at' => now()->subHour(),
    ]);

    $readLog->readByUsers()->attach($admin->id, [
        'read_at' => now(),
    ]);

    $this->actingAs($admin);

    Livewire::test('pages::admin.index')
        ->assertSee('Older Customer')
        ->assertSee('Read Customer')
        ->assertSee('A-1001')
        ->assertSee('Sam Seller')
        ->assertSee('Uncleared for you')
        ->assertSee('Phone')
        ->assertSee('Summary')
        ->assertSee('Submitted')
        ->assertSee('Status')
        ->assertDontSee('Options')
        ->assertSee('bg-blue-500', false)
        ->call('markAsRead', $unreadLog->id)
        ->assertSee('Older Customer')
        ->assertDontSee('bg-blue-500', false);

    expect(DB::table('customer_communication_log_reads')
        ->where('customer_communication_log_id', $unreadLog->id)
        ->where('user_id', $admin->id)
        ->exists())->toBeTrue();

    Livewire::test('pages::admin.index')
        ->call('markAsUnread', $readLog->id)
        ->assertSee('Read Customer')
        ->assertSee('bg-blue-500', false)
        ->call('clearLog', $readLog->id)
        ->assertDontSee('Read Customer');

    expect(DB::table('customer_communication_log_reads')
        ->where('customer_communication_log_id', $readLog->id)
        ->where('user_id', $admin->id)
        ->whereNotNull('cleared_at')
        ->exists())->toBeTrue();
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

test('admin context actions flag request update clear and archive logs', function () {
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
        ->call('clearLog', $log->id)
        ->assertDontSee('Acme Dental');

    expect(DB::table('customer_communication_log_reads')
        ->where('customer_communication_log_id', $log->id)
        ->where('user_id', $admin->id)
        ->whereNotNull('cleared_at')
        ->exists())->toBeTrue();

    Livewire::test('pages::admin.index')
        ->call('archiveLog', $log->id)
        ->assertDispatched('communication-log-saved')
        ->assertDontSee('Acme Dental');

    $archivedLog = CustomerCommunicationLog::withTrashed()->findOrFail($log->id);

    expect($archivedLog)
        ->requires_follow_up->toBeTrue()
        ->status->toBe(CustomerCommunicationLog::STATUS_UPDATE_REQUESTED);

    $this->assertSoftDeleted('customer_communication_logs', [
        'id' => $log->id,
    ]);
});

test('admin editor flyout opens submitted logs from the shared detail modal edit event', function () {
    $this->seed(CommunicationLoggingSeeder::class);

    $admin = configureAdminLogTestAdmin();
    $salesRep = User::factory()->create();
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();

    $log = createAdminSubmittedLog($salesRep, $type, $summaryType, 'Admin editable summary.', [
        'customer_name' => 'Editable Customer',
        'customer_account_number' => 'E-4444',
        'netsuite_customer_id' => 4444,
    ]);

    $this->actingAs($admin);

    Livewire::test('customer-communication-log-flyout', [
        'customer' => [],
        'accountNumber' => '',
        'showTrigger' => false,
        'openAnyCustomerLog' => true,
    ])
        ->call('openExisting', $log->id)
        ->assertSet('showLogFlyout', true)
        ->assertSet('editingSubmittedLog', true)
        ->assertSet('accountNumber', 'E-4444')
        ->assertSee('Edit Communication')
        ->assertSee('Editable Customer');
});

test('admin archive action soft deletes logs', function () {
    $this->seed(CommunicationLoggingSeeder::class);

    $admin = configureAdminLogTestAdmin();
    $salesRep = User::factory()->create();
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();

    $log = createAdminSubmittedLog($salesRep, $type, $summaryType, 'Admin archive summary.');

    $this->actingAs($admin);

    Livewire::test('pages::admin.index')
        ->call('archiveLog', $log->id)
        ->assertDispatched('communication-log-saved')
        ->assertDontSee('Acme Dental');

    $archivedLog = CustomerCommunicationLog::withTrashed()->findOrFail($log->id);

    expect($archivedLog->trashed())->toBeTrue();

    $this->assertSoftDeleted('customer_communication_logs', [
        'id' => $log->id,
    ]);
});

test('admin queue filters by contact user block category and read state', function () {
    $this->seed(CommunicationLoggingSeeder::class);

    $admin = configureAdminLogTestAdmin();
    $firstSalesRep = User::factory()->create(['name' => 'Sam Seller']);
    $secondSalesRep = User::factory()->create(['name' => 'Tina Tech']);
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();
    $warrantyType = CommunicationBlockType::query()->where('slug', 'warranty')->sole();

    $warrantyLog = createAdminSubmittedLog($firstSalesRep, $type, $summaryType, 'Warranty admin queue summary.', [
        'customer_name' => 'Warranty Customer',
        'submitted_at' => now()->subMinutes(30),
    ]);
    $warrantyLog->blocks()->create([
        'communication_block_type_id' => $warrantyType->id,
        'position' => 1,
        'body' => 'Warranty block details.',
    ]);

    $readLog = createAdminSubmittedLog($secondSalesRep, $type, $summaryType, 'Read admin queue summary.', [
        'customer_name' => 'Read Filter Customer',
        'submitted_at' => now()->subMinutes(20),
    ]);
    $readLog->readByUsers()->attach($admin->id, [
        'read_at' => now(),
    ]);

    $archivedLog = createAdminSubmittedLog($firstSalesRep, $type, $summaryType, 'Archived admin queue summary.', [
        'customer_name' => 'Archived Customer',
        'submitted_at' => now()->subMinutes(10),
    ]);
    $archivedLog->delete();

    $this->actingAs($admin);

    Livewire::test('pages::admin.index')
        ->assertSee('Warranty Customer')
        ->assertSee('Read Filter Customer')
        ->assertDontSee('Archived Customer')
        ->set('userFilter', $secondSalesRep->id)
        ->assertSee('Read Filter Customer')
        ->assertDontSee('Warranty Customer')
        ->set('userFilter', 'all')
        ->set('blockTypeFilter', $warrantyType->id)
        ->assertSee('Warranty Customer')
        ->assertDontSee('Read Filter Customer')
        ->set('blockTypeFilter', 'all')
        ->set('readStateFilter', 'read')
        ->assertSee('Read Filter Customer')
        ->assertDontSee('Warranty Customer')
        ->set('readStateFilter', 'unread')
        ->assertSee('Warranty Customer')
        ->assertDontSee('Read Filter Customer')
        ->set('readStateFilter', 'archived')
        ->assertSee('Archived Customer')
        ->assertDontSee('Warranty Customer');
});
