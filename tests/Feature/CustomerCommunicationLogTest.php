<?php

use App\Models\CommunicationBlockType;
use App\Models\CommunicationType;
use App\Models\CustomerCommunicationLog;
use App\Models\CustomerContact;
use App\Models\User;
use Database\Seeders\CommunicationLoggingSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Searsandrew\BriarRose\BriarRoseManager;
use Tests\TestCase;

function configureBriarRoseForCustomerLogTests(): void
{
    config()->set('briar-rose.account', '1234567');
    config()->set('briar-rose.consumer_key', 'consumer-key');
    config()->set('briar-rose.consumer_secret', 'consumer-secret');
    config()->set('briar-rose.token_id', 'token-id');
    config()->set('briar-rose.token_secret', 'token-secret');
    config()->set('briar-rose.rest_base_url', 'https://netsuite.test');
    config()->set('briar-rose.rest.retries.enabled', false);
    config()->set('audit.console', true);

    app()->forgetInstance(BriarRoseManager::class);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function customerLogPayload(array $overrides = []): array
{
    return array_merge([
        'id' => '2462',
        'customer_id' => '2462',
        'account_number' => 'A-0999',
        'entityid' => 'CUST-2462',
        'companyname' => 'Andrew Apples',
        'email' => 'buyer@example.test',
        'phone' => '555-0101',
        'category_id' => '11',
        'category_name' => 'APW1',
        'sales_rep_id' => '2214',
        'sales_rep_name' => 'Andrew Sears',
        'cadence_id' => '1',
        'cadence_name' => 'Quarterly',
        'cadence_scriptid' => '_P3M',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeCustomerAccountLookup(array $overrides = [], int $times = 1): void
{
    $customer = customerLogPayload($overrides);

    $sequence = Http::sequence();

    foreach (range(1, $times) as $ignored) {
        $sequence->push([
            'items' => [$customer],
            'hasMore' => false,
        ]);
        $sequence->push([
            'items' => [],
            'hasMore' => false,
        ]);
        $sequence->push([
            'items' => [],
            'hasMore' => false,
        ]);
    }

    Http::preventStrayRequests();
    Http::fake(['*' => $sequence]);
}

function permittedSalesRep(TestCase $testCase, array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    $testCase->seed(CommunicationLoggingSeeder::class);

    return $user->refresh();
}

test('customers are opened by account number and show the communication log table', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup();

    $user = permittedSalesRep($this, [
        'timezone' => 'UTC',
    ]);

    $response = $this->actingAs($user)->get(route('customers.show', ['accountNumber' => 'A-0999']));

    $response
        ->assertOk()
        ->assertSee('Andrew Apples')
        ->assertSee('A-0999')
        ->assertSee('Communication Logs')
        ->assertSee('No communication logs yet.')
        ->assertSee('Add new log')
        ->assertSee('Purchase History')
        ->assertSee('New Product Gaps')
        ->assertSee('No purchase history found yet.')
        ->assertSee('No new product gaps found yet.')
        ->assertSee('data-flux-card', false);

    expect(CustomerCommunicationLog::query()->count())->toBe(0);

    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), "c.custentity3 = 'A-0999'"));
});

test('customer page shows purchase history and new product gaps from NetSuite', function () {
    configureBriarRoseForCustomerLogTests();

    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $query = (string) ($request->data()['q'] ?? '');

        if (str_contains($query, "c.custentity3 = 'A-0999'")) {
            return Http::response([
                'items' => [customerLogPayload()],
                'hasMore' => false,
            ]);
        }

        if (str_contains($query, 'SUM(ABS(t.foreigntotal))')) {
            return Http::response([
                'items' => [
                    ['period' => '2026-01', 'amount' => '1250.50'],
                    ['period' => '2026-02', 'amount' => '2400'],
                ],
                'hasMore' => false,
            ]);
        }

        if (
            str_contains($query, 'FROM transactionline tl')
            && str_contains($query, "i.custitem6 = 'T'")
            && str_contains($query, 'i.custitemreleasedate >= ADD_MONTHS(CURRENT_DATE, -6)')
        ) {
            return Http::response([
                'items' => [
                    [
                        'item_id' => '8821',
                        'itemid' => 'HP-500',
                        'displayname' => 'Handpiece Pro 500',
                        'released_at' => '2026-05-01',
                    ],
                ],
                'hasMore' => false,
            ]);
        }

        return Http::response([
            'items' => [],
            'hasMore' => false,
        ]);
    });

    $user = permittedSalesRep($this, [
        'timezone' => 'UTC',
    ]);

    $response = $this->actingAs($user)->get(route('customers.show', ['accountNumber' => 'A-0999']));

    $response
        ->assertOk()
        ->assertSee('Purchase History')
        ->assertSee('$3,651')
        ->assertSee('Jan 2026')
        ->assertSee('Feb 2026')
        ->assertSee('New Product Gaps')
        ->assertSee('Handpiece Pro 500')
        ->assertSee('HP-500')
        ->assertSee('May 1, 2026');

    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), 'SUM(ABS(t.foreigntotal))'));
    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), 'FROM transactionline tl')
        && str_contains((string) ($request->data()['q'] ?? ''), "i.custitem6 = 'T'")
        && str_contains((string) ($request->data()['q'] ?? ''), "TO_CHAR(i.custitemreleasedate, 'YYYY-MM-DD') AS released_at")
        && str_contains((string) ($request->data()['q'] ?? ''), 'ORDER BY i.custitemreleasedate DESC, i.itemid')
        && ! str_contains((string) ($request->data()['q'] ?? ''), 'i.createddate'));
});

test('the reusable flyout starts a private draft when opened', function () {
    configureBriarRoseForCustomerLogTests();

    $user = permittedSalesRep($this);

    $this->actingAs($user);

    Livewire::test('customer-communication-log-flyout', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('open')
        ->assertSet('showLogFlyout', true)
        ->assertSee('Contact date and time')
        ->assertSee('Communication type')
        ->assertSee('Summary');

    $draft = CustomerCommunicationLog::query()->sole();

    expect($draft->status)->toBe(CustomerCommunicationLog::STATUS_DRAFT)
        ->and($draft->user_id)->toBe($user->id)
        ->and($draft->customer_account_number)->toBe('A-0999')
        ->and($draft->netsuite_customer_id)->toBe(2462)
        ->and($draft->netsuite_sales_rep_id)->toBe(2214)
        ->and($draft->netsuite_customer_sales_rep_id)->toBe(2214)
        ->and($draft->netsuite_customer_pipeline_owner_id)->toBeNull()
        ->and($draft->requires_follow_up)->toBeFalse()
        ->and($draft->blocks)->toHaveCount(1);
});

test('the add log action always starts a fresh draft', function () {
    configureBriarRoseForCustomerLogTests();

    $user = permittedSalesRep($this);

    $this->actingAs($user);

    Livewire::test('customer-communication-log-flyout', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('open')
        ->set('blocks.0.body', 'First draft stays in the table.');

    Livewire::test('customer-communication-log-flyout', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('open')
        ->assertSet('blocks.0.body', '');

    expect(CustomerCommunicationLog::query()->where('status', CustomerCommunicationLog::STATUS_DRAFT)->count())->toBe(2);
});

test('summary is required before a communication log can be submitted', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup();

    $user = permittedSalesRep($this, [
        'timezone' => 'UTC',
    ]);

    $this->actingAs($user);

    Livewire::test('customer-communication-log-flyout', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('open')
        ->set('blocks.0.body', '')
        ->call('submit')
        ->assertHasErrors(['blocks.0.body']);

    expect(CustomerCommunicationLog::query()->where('status', CustomerCommunicationLog::STATUS_SUBMITTED)->count())->toBe(0);
});

test('submitted logs save blocks contacts and audit history', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup();

    $user = permittedSalesRep($this);
    $suggestionType = CommunicationBlockType::query()->where('slug', 'suggestion')->sole();

    CustomerContact::factory()->create([
        'netsuite_customer_id' => 2462,
        'customer_account_number' => 'A-0999',
        'name' => 'Jordan Buyer',
        'normalized_name' => CustomerContact::normalizeName('Jordan Buyer'),
    ]);

    CustomerContact::factory()->create([
        'netsuite_customer_id' => 9999,
        'customer_account_number' => 'Z-9999',
        'name' => 'Hidden Contact',
        'normalized_name' => CustomerContact::normalizeName('Hidden Contact'),
    ]);

    $this->actingAs($user);

    Livewire::test('customer-communication-log-flyout', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('open')
        ->assertSee('Jordan Buyer')
        ->assertDontSee('Hidden Contact')
        ->set('contactPersonName', 'Alex Buyer')
        ->set('blocks.0.body', 'Reviewed the current purchasing cadence.')
        ->call('addBlock')
        ->set('blocks.1.communication_block_type_id', $suggestionType->id)
        ->set('blocks.1.body', 'Send the new product sample.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('communication-log-saved');

    $submitted = CustomerCommunicationLog::query()
        ->where('status', CustomerCommunicationLog::STATUS_SUBMITTED)
        ->sole();

    expect($submitted->customer_account_number)->toBe('A-0999')
        ->and($submitted->netsuite_customer_id)->toBe(2462)
        ->and($submitted->netsuite_sales_rep_id)->toBe(2214)
        ->and($submitted->netsuite_customer_sales_rep_id)->toBe(2214)
        ->and($submitted->netsuite_customer_pipeline_owner_id)->toBeNull()
        ->and($submitted->user_id)->toBe($user->id)
        ->and($submitted->blocks()->count())->toBe(2)
        ->and(CustomerContact::query()->where('netsuite_customer_id', 2462)->where('name', 'Alex Buyer')->exists())->toBeTrue()
        ->and(CustomerCommunicationLog::query()->where('status', CustomerCommunicationLog::STATUS_DRAFT)->count())->toBe(0)
        ->and(DB::table('audits')->where('auditable_type', CustomerCommunicationLog::class)->exists())->toBeTrue();
});

test('the customer page lists submitted logs with customer date status and truncated summary', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup();

    $user = permittedSalesRep($this, [
        'timezone' => 'UTC',
    ]);
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();

    $log = CustomerCommunicationLog::factory()
        ->submitted()
        ->for($user)
        ->for($type, 'communicationType')
        ->create([
            'netsuite_customer_id' => 2462,
            'customer_account_number' => 'A-0999',
            'contact_person_name' => 'Alex Buyer',
            'contact_at' => '2026-05-28 10:30:00',
        ]);

    $log->blocks()->create([
        'communication_block_type_id' => $summaryType->id,
        'position' => 0,
        'body' => 'Reviewed open opportunities and confirmed follow up timing.',
    ]);

    $response = $this->actingAs($user)->get(route('customers.show', ['accountNumber' => 'A-0999']));

    $response
        ->assertOk()
        ->assertSee('Communication Logs')
        ->assertSee('Andrew Apples')
        ->assertSee('Reviewed open opportunities and confirmed follow up timing.')
        ->assertSee('May 28, 10:30 AM')
        ->assertSee('Submitted')
        ->assertSee('Alex Buyer')
        ->assertSee('truncate whitespace-nowrap overflow-hidden', false)
        ->assertSee('data-flux-table', false);
});

test('closing the flyout keeps the draft visible on the customer log table', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup();

    $user = permittedSalesRep($this);

    $this->actingAs($user);

    $page = Livewire::test('pages::customers.show', ['accountNumber' => 'A-0999'])
        ->assertSee('No communication logs yet.');

    Livewire::test('customer-communication-log-flyout', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('open')
        ->set('blocks.0.body', 'Started a draft before the call was ready.')
        ->call('close')
        ->assertSet('showLogFlyout', false)
        ->assertSet('logId', null)
        ->assertSet('blocks', [])
        ->assertDispatched('communication-log-saved');

    $draft = CustomerCommunicationLog::query()
        ->where('status', CustomerCommunicationLog::STATUS_DRAFT)
        ->sole();

    expect($draft->blocks()->sole()->body)->toBe('Started a draft before the call was ready.');

    $page
        ->dispatch('communication-log-saved')
        ->assertSee('Started a draft before the call was ready.')
        ->assertSee('Draft')
        ->assertDontSee('No communication logs yet.');
});

test('click away closing the flyout keeps the draft visible on the customer log table', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup();

    $user = permittedSalesRep($this);

    $this->actingAs($user);

    $page = Livewire::test('pages::customers.show', ['accountNumber' => 'A-0999'])
        ->assertSee('No communication logs yet.');

    Livewire::test('customer-communication-log-flyout', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('open')
        ->set('blocks.0.body', 'Click-away should still save this draft.')
        ->set('showLogFlyout', false)
        ->assertDispatched('communication-log-saved');

    $page
        ->dispatch('communication-log-saved')
        ->assertSee('Click-away should still save this draft.')
        ->assertSee('Draft');
});

test('drafts can be deleted from the editable flyout', function () {
    configureBriarRoseForCustomerLogTests();

    $user = permittedSalesRep($this);

    $this->actingAs($user);

    $component = Livewire::test('customer-communication-log-flyout', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('open')
        ->assertSee('Delete draft')
        ->set('blocks.0.body', 'This draft can go away.');

    $draft = CustomerCommunicationLog::query()
        ->where('status', CustomerCommunicationLog::STATUS_DRAFT)
        ->sole();

    $component
        ->call('deleteDraft')
        ->assertSet('showLogFlyout', false)
        ->assertSet('logId', null)
        ->assertDispatched('communication-log-saved');

    $this->assertSoftDeleted('customer_communication_logs', [
        'id' => $draft->id,
    ]);

    expect(CustomerCommunicationLog::query()->count())->toBe(0);
});

test('drafts can be deleted from the customer log table', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup();

    $user = permittedSalesRep($this);
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();

    $draft = CustomerCommunicationLog::factory()
        ->for($user)
        ->for($type, 'communicationType')
        ->create([
            'netsuite_customer_id' => 2462,
            'customer_account_number' => 'A-0999',
            'status' => CustomerCommunicationLog::STATUS_DRAFT,
        ]);

    $draft->blocks()->create([
        'communication_block_type_id' => $summaryType->id,
        'position' => 0,
        'body' => 'Delete this draft from the table.',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::customers.show', ['accountNumber' => 'A-0999'])
        ->assertSee('Delete this draft from the table.')
        ->assertSee('Delete')
        ->call('deleteDraft', $draft->id)
        ->assertDispatched('communication-log-saved')
        ->assertDontSee('Delete this draft from the table.');

    $this->assertSoftDeleted('customer_communication_logs', [
        'id' => $draft->id,
    ]);
});

test('logs can be flagged for follow up from the flyout and customer viewer', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup();

    $user = permittedSalesRep($this);

    $this->actingAs($user);

    Livewire::test('customer-communication-log-flyout', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('open')
        ->call('toggleFollowUp')
        ->assertSet('requiresFollowUp', true)
        ->assertSee('Follow-up flagged')
        ->set('blocks.0.body', 'This needs a follow-up call.')
        ->call('submit')
        ->assertHasNoErrors();

    $log = CustomerCommunicationLog::query()
        ->where('status', CustomerCommunicationLog::STATUS_SUBMITTED)
        ->sole();

    expect($log->requires_follow_up)->toBeTrue();

    Livewire::test('pages::customers.show', ['accountNumber' => 'A-0999'])
        ->assertSee('Follow-up')
        ->assertSee('bg-amber-50/70', false)
        ->call('viewLog', $log->id)
        ->assertSet('showLogDetails', true)
        ->assertSee('Follow-up flagged')
        ->call('toggleFollowUp', $log->id)
        ->assertDispatched('communication-log-saved');

    expect($log->refresh()->requires_follow_up)->toBeFalse();
});

test('the reusable log list opens submitted logs and drafts explicitly', function () {
    configureBriarRoseForCustomerLogTests();

    $user = permittedSalesRep($this);
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();

    $submitted = CustomerCommunicationLog::factory()
        ->submitted()
        ->for($user)
        ->for($type, 'communicationType')
        ->create([
            'netsuite_customer_id' => 2462,
            'customer_account_number' => 'A-0999',
            'requires_follow_up' => true,
        ]);

    $submitted->blocks()->create([
        'communication_block_type_id' => $summaryType->id,
        'position' => 0,
        'body' => 'Submitted summary for the list modal.',
    ]);

    $draft = CustomerCommunicationLog::factory()
        ->for($user)
        ->for($type, 'communicationType')
        ->create([
            'netsuite_customer_id' => 2462,
            'customer_account_number' => 'A-0999',
            'status' => CustomerCommunicationLog::STATUS_DRAFT,
        ]);

    $draft->blocks()->create([
        'communication_block_type_id' => $summaryType->id,
        'position' => 0,
        'body' => 'Draft summary for the list modal.',
    ]);

    $this->actingAs($user);

    Livewire::test('customer-communication-log-list', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('openList')
        ->assertSet('showLogList', true)
        ->assertSee('Submitted summary for the list modal.')
        ->assertSee('Draft summary for the list modal.')
        ->assertSee('Follow-up')
        ->call('viewLog', $submitted->id)
        ->assertSet('showLogDetails', true)
        ->call('viewLog', $draft->id)
        ->assertDispatched('open-communication-log-editor')
        ->assertSet('showLogList', false);
});

test('drafts can be deleted from the reusable log list', function () {
    configureBriarRoseForCustomerLogTests();

    $user = permittedSalesRep($this);
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();

    $draft = CustomerCommunicationLog::factory()
        ->for($user)
        ->for($type, 'communicationType')
        ->create([
            'netsuite_customer_id' => 2462,
            'customer_account_number' => 'A-0999',
            'status' => CustomerCommunicationLog::STATUS_DRAFT,
        ]);

    $draft->blocks()->create([
        'communication_block_type_id' => $summaryType->id,
        'position' => 0,
        'body' => 'Delete this draft from the list modal.',
    ]);

    $this->actingAs($user);

    Livewire::test('customer-communication-log-list', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('openList')
        ->assertSee('Delete this draft from the list modal.')
        ->assertSee('Delete')
        ->call('deleteDraft', $draft->id)
        ->assertDispatched('communication-log-saved')
        ->assertDontSee('Delete this draft from the list modal.');

    $this->assertSoftDeleted('customer_communication_logs', [
        'id' => $draft->id,
    ]);
});

test('clicking a draft row opens the editable flyout instead of the read only details', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup();

    $user = permittedSalesRep($this);
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();

    $draft = CustomerCommunicationLog::factory()
        ->for($user)
        ->for($type, 'communicationType')
        ->create([
            'netsuite_customer_id' => 2462,
            'customer_account_number' => 'A-0999',
            'status' => CustomerCommunicationLog::STATUS_DRAFT,
        ]);

    $draft->blocks()->create([
        'communication_block_type_id' => $summaryType->id,
        'position' => 0,
        'body' => 'This draft should reopen in the flyout.',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::customers.show', ['accountNumber' => 'A-0999'])
        ->call('viewLog', $draft->id)
        ->assertSet('showLogDetails', false)
        ->assertDispatched('open-communication-log-editor');
});

test('submitted logs open a read only details modal with every block', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup();

    $user = permittedSalesRep($this);
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();
    $suggestionType = CommunicationBlockType::query()->where('slug', 'suggestion')->sole();

    $log = CustomerCommunicationLog::factory()
        ->submitted()
        ->for($user)
        ->for($type, 'communicationType')
        ->create([
            'netsuite_customer_id' => 2462,
            'customer_account_number' => 'A-0999',
            'contact_person_name' => 'Alex Buyer',
            'contact_at' => '2026-05-28 10:30:00',
        ]);

    $log->blocks()->createMany([
        [
            'communication_block_type_id' => $summaryType->id,
            'position' => 0,
            'body' => 'Reviewed open opportunities and confirmed follow up timing.',
        ],
        [
            'communication_block_type_id' => $suggestionType->id,
            'position' => 1,
            'body' => 'Send the updated sample kit on the next visit.',
        ],
    ]);

    $this->actingAs($user);

    Livewire::test('pages::customers.show', ['accountNumber' => 'A-0999'])
        ->call('viewLog', $log->id)
        ->assertSet('showLogDetails', true)
        ->assertSee('Communication Log')
        ->assertSee('Reviewed open opportunities and confirmed follow up timing.')
        ->assertSee('Send the updated sample kit on the next visit.')
        ->assertSee('Alex Buyer')
        ->assertDontSee('viewLogHistory', false)
        ->assertSee($user->name);
});

test('submitted logs can be softly edited from the shared flyout', function () {
    configureBriarRoseForCustomerLogTests();

    $user = permittedSalesRep($this);
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();
    $submittedAt = now()->subHour();

    $log = CustomerCommunicationLog::factory()
        ->submitted()
        ->for($user)
        ->for($type, 'communicationType')
        ->create([
            'netsuite_customer_id' => 2462,
            'customer_account_number' => 'A-0999',
            'submitted_at' => $submittedAt,
        ]);

    $log->blocks()->create([
        'communication_block_type_id' => $summaryType->id,
        'position' => 0,
        'body' => 'Original summary.',
    ]);

    $this->actingAs($user);

    Livewire::test('customer-communication-log-flyout', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('openExisting', $log->id)
        ->assertSet('editingSubmittedLog', true)
        ->assertSee('Edit Communication')
        ->set('blocks.0.body', 'Updated summary after reviewing the conversation.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('communication-log-saved');

    $log->refresh();

    expect($log->status)->toBe(CustomerCommunicationLog::STATUS_SUBMITTED)
        ->and($log->submitted_at?->toDateTimeString())->toBe($submittedAt->toDateTimeString())
        ->and($log->blocks()->sole()->body)->toBe('Updated summary after reviewing the conversation.')
        ->and(DB::table('audits')->where('auditable_type', CustomerCommunicationLog::class)->where('auditable_id', $log->id)->exists())->toBeTrue();
});

test('log history shows meaningful edits and hides autosave audits', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup();

    $user = permittedSalesRep($this);
    $type = CommunicationType::query()->where('slug', CommunicationType::PHONE)->sole();
    $summaryType = CommunicationBlockType::query()->where('slug', CommunicationBlockType::SUMMARY)->sole();

    $log = CustomerCommunicationLog::factory()
        ->submitted()
        ->for($user)
        ->for($type, 'communicationType')
        ->create([
            'netsuite_customer_id' => 2462,
            'customer_account_number' => 'A-0999',
            'contact_person_name' => 'Alex Buyer',
        ]);

    $block = $log->blocks()->create([
        'communication_block_type_id' => $summaryType->id,
        'position' => 0,
        'body' => 'Original summary.',
    ]);

    $this->actingAs($user);
    $user->givePermissionTo('communication-logs.view-history');

    $log->update(['last_autosaved_at' => now()->addMinute()]);
    $log->update(['contact_person_name' => 'Taylor Buyer']);
    $block->update(['body' => 'Updated summary after reviewing the conversation.']);

    Livewire::test('pages::customers.show', ['accountNumber' => 'A-0999'])
        ->call('viewLogHistory', $log->id)
        ->assertSet('showLogHistory', true)
        ->assertSee('History &amp; Edits', false)
        ->assertSee('Contact person')
        ->assertSee('Taylor Buyer')
        ->assertSee('Updated summary after reviewing the conversation.')
        ->assertDontSee('last_autosaved_at')
        ->assertDontSee('Last autosaved');
});

test('customer access is limited to the users linked NetSuite sales rep id', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup();

    $user = permittedSalesRep($this, [
        'netsuite_user_id' => 9999,
    ]);

    $response = $this->actingAs($user)->get(route('customers.show', ['accountNumber' => 'A-0999']));

    $response->assertForbidden();

    expect(CustomerCommunicationLog::query()->count())->toBe(0);
});

test('sales rep managers can access managed customers while submitted logs stay customer scoped', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup(times: 2);

    $manager = permittedSalesRep($this, [
        'netsuite_user_id' => 513,
        'netsuite_managed_sales_rep_ids' => [2214],
    ]);
    $salesRep = User::factory()->create([
        'netsuite_user_id' => 2214,
        'netsuite_managed_sales_rep_ids' => [],
    ]);
    $salesRep->assignRole('sales-rep');

    $this->actingAs($manager)
        ->get(route('customers.show', ['accountNumber' => 'A-0999']))
        ->assertOk()
        ->assertSee('Andrew Apples');

    $this->actingAs($manager);

    Livewire::test('customer-communication-log-flyout', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('open')
        ->set('blocks.0.body', 'Manager spoke with the customer on the territory rep account.')
        ->call('submit')
        ->assertHasNoErrors();

    $submitted = CustomerCommunicationLog::query()
        ->where('status', CustomerCommunicationLog::STATUS_SUBMITTED)
        ->sole();

    expect($submitted->netsuite_sales_rep_id)->toBe(513)
        ->and($submitted->netsuite_customer_sales_rep_id)->toBe(2214)
        ->and($submitted->user_id)->toBe($manager->id);

    $this->actingAs($salesRep);

    Livewire::test('pages::customers.show', ['accountNumber' => 'A-0999'])
        ->assertSee('Manager spoke with the customer on the territory rep account.')
        ->call('viewLog', $submitted->id)
        ->assertSee($manager->name);
});

test('customer access can be granted through a managed pipeline owner id', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup([
        'sales_rep_id' => '9999',
        'pipeline_owner_id' => '2214',
        'pipeline_owner_name' => 'Tom Ruggles',
    ]);

    $manager = permittedSalesRep($this, [
        'netsuite_user_id' => 513,
        'netsuite_managed_sales_rep_ids' => [2214],
    ]);

    $this->actingAs($manager)
        ->get(route('customers.show', ['accountNumber' => 'A-0999']))
        ->assertOk()
        ->assertSee('Andrew Apples');
});

test('drafts are private to the app user even when users share a NetSuite id', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup(times: 2);

    $firstUser = permittedSalesRep($this);
    $secondUser = User::factory()->create();
    $secondUser->assignRole('sales-rep');

    $this->actingAs($firstUser);

    Livewire::test('customer-communication-log-flyout', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('open')
        ->set('blocks.0.body', 'First private draft.');

    $this->actingAs($secondUser);

    Livewire::test('customer-communication-log-flyout', [
        'customer' => customerLogPayload(),
        'accountNumber' => 'A-0999',
    ])
        ->call('open')
        ->assertSet('blocks.0.body', '');

    expect(CustomerCommunicationLog::query()->where('status', CustomerCommunicationLog::STATUS_DRAFT)->count())->toBe(2)
        ->and(CustomerCommunicationLog::query()->where('user_id', $firstUser->id)->where('status', CustomerCommunicationLog::STATUS_DRAFT)->sole()->blocks()->first()->body)->toBe('First private draft.')
        ->and(CustomerCommunicationLog::query()->where('user_id', $secondUser->id)->where('status', CustomerCommunicationLog::STATUS_DRAFT)->sole()->blocks()->first()->body)->toBe('');
});
