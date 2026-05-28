<?php

use App\Models\CommunicationBlockType;
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
function fakeCustomerAccountLookup(array $overrides = [], int $times = 1): void
{
    $customer = array_merge([
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

    $sequence = Http::sequence();

    foreach (range(1, $times) as $ignored) {
        $sequence->push([
            'items' => [$customer],
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

test('customers are opened by account number and start a private draft', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup();

    $user = permittedSalesRep($this);

    $response = $this->actingAs($user)->get(route('customers.show', ['accountNumber' => 'A-0999']));

    $response
        ->assertOk()
        ->assertSee('Andrew Apples')
        ->assertSee('A-0999')
        ->assertSee('Contact date and time')
        ->assertSee('Communication type')
        ->assertSee('Summary')
        ->assertSee('Signals')
        ->assertSee('data-flux-card', false);

    $draft = CustomerCommunicationLog::query()->sole();

    expect($draft->status)->toBe(CustomerCommunicationLog::STATUS_DRAFT)
        ->and($draft->user_id)->toBe($user->id)
        ->and($draft->customer_account_number)->toBe('A-0999')
        ->and($draft->netsuite_customer_id)->toBe(2462)
        ->and($draft->blocks)->toHaveCount(1);

    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), "c.custentity3 = 'A-0999'"));
});

test('summary is required before a communication log can be submitted', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup();

    $user = permittedSalesRep($this);

    $this->actingAs($user);

    Livewire::test('pages::customers.show', ['accountNumber' => 'A-0999'])
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

    Livewire::test('pages::customers.show', ['accountNumber' => 'A-0999'])
        ->assertSee('Jordan Buyer')
        ->assertDontSee('Hidden Contact')
        ->set('contactPersonName', 'Alex Buyer')
        ->set('blocks.0.body', 'Reviewed the current purchasing cadence.')
        ->call('addBlock')
        ->set('blocks.1.communication_block_type_id', $suggestionType->id)
        ->set('blocks.1.body', 'Send the new product sample.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('Communication logged.');

    $submitted = CustomerCommunicationLog::query()
        ->where('status', CustomerCommunicationLog::STATUS_SUBMITTED)
        ->sole();

    expect($submitted->customer_account_number)->toBe('A-0999')
        ->and($submitted->netsuite_customer_id)->toBe(2462)
        ->and($submitted->netsuite_sales_rep_id)->toBe(2214)
        ->and($submitted->user_id)->toBe($user->id)
        ->and($submitted->blocks()->count())->toBe(2)
        ->and(CustomerContact::query()->where('netsuite_customer_id', 2462)->where('name', 'Alex Buyer')->exists())->toBeTrue()
        ->and(CustomerCommunicationLog::query()->where('status', CustomerCommunicationLog::STATUS_DRAFT)->count())->toBe(1)
        ->and(DB::table('audits')->where('auditable_type', CustomerCommunicationLog::class)->exists())->toBeTrue();
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

test('drafts are private to the app user even when users share a NetSuite id', function () {
    configureBriarRoseForCustomerLogTests();
    fakeCustomerAccountLookup(times: 2);

    $firstUser = permittedSalesRep($this);
    $secondUser = User::factory()->create();
    $secondUser->assignRole('sales-rep');

    $this->actingAs($firstUser);

    Livewire::test('pages::customers.show', ['accountNumber' => 'A-0999'])
        ->set('blocks.0.body', 'First private draft.');

    $this->actingAs($secondUser);

    Livewire::test('pages::customers.show', ['accountNumber' => 'A-0999'])
        ->assertSet('blocks.0.body', '');

    expect(CustomerCommunicationLog::query()->where('status', CustomerCommunicationLog::STATUS_DRAFT)->count())->toBe(2)
        ->and(CustomerCommunicationLog::query()->where('user_id', $firstUser->id)->where('status', CustomerCommunicationLog::STATUS_DRAFT)->sole()->blocks()->first()->body)->toBe('First private draft.')
        ->and(CustomerCommunicationLog::query()->where('user_id', $secondUser->id)->where('status', CustomerCommunicationLog::STATUS_DRAFT)->sole()->blocks()->first()->body)->toBe('');
});
