<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Searsandrew\BriarRose\BriarRoseManager;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('unverified users are redirected to the email verification notice', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('verification.notice'));
});

test('authenticated users can visit the dashboard and see pipeline prospects above active customers with cadence', function () {
    config()->set('briar-rose.account', '1234567');
    config()->set('briar-rose.consumer_key', 'consumer-key');
    config()->set('briar-rose.consumer_secret', 'consumer-secret');
    config()->set('briar-rose.token_id', 'token-id');
    config()->set('briar-rose.token_secret', 'token-secret');
    config()->set('briar-rose.rest_base_url', 'https://netsuite.test');
    config()->set('briar-rose.rest.retries.enabled', false);

    app()->forgetInstance(BriarRoseManager::class);

    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::sequence()
            ->push([
                'items' => [
                    [
                        'id' => '3001',
                        'customer_id' => '3001',
                        'entityid' => 'PROSPECT-3001',
                        'companyname' => 'Pipeline Parts',
                        'email' => 'lead@pipeline.test',
                        'phone' => '555-0199',
                        'pipeline_owner_id' => '2214',
                        'cadence_name' => 'Monthly',
                        'cadence_scriptid' => '_P1M',
                    ],
                ],
                'hasMore' => false,
            ])
            ->push([
                'items' => [
                    [
                        'id' => '2462',
                        'customer_id' => '2462',
                        'entityid' => 'CUST-2462',
                        'companyname' => 'Acme Dental',
                        'email' => 'buyer@acme.test',
                        'phone' => '555-0101',
                        'category_id' => '11',
                        'category_name' => 'APW1',
                        'sales_rep_id' => '2214',
                        'cadence_id' => '1',
                        'cadence_name' => 'Quarterly',
                        'cadence_scriptid' => '_P3M',
                    ],
                ],
                'hasMore' => true,
            ])
            ->push([
                'items' => [
                    [
                        'id' => '2463',
                        'customer_id' => '2463',
                        'entityid' => 'CUST-2463',
                        'companyname' => 'Bright Smiles',
                        'category_id' => '99',
                        'category_name' => 'Dealer',
                        'sales_rep_id' => '2214',
                        'cadence_id' => '2',
                        'cadence_name' => 'Annually',
                        'cadence_scriptid' => '_P1Y',
                    ],
                ],
                'hasMore' => false,
            ]),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response
        ->assertOk()
        ->assertSee('Pipeline')
        ->assertSee('Pipeline Parts')
        ->assertSee('Acme Dental')
        ->assertSee('Bright Smiles')
        ->assertSee('APW1')
        ->assertSee('Dealer')
        ->assertSee('bg-blue-400/20', false)
        ->assertSee('bg-zinc-400/15', false)
        ->assertSee('Monthly')
        ->assertSee('Quarterly')
        ->assertSee('Annually')
        ->assertSee('Contact Due')
        ->assertSee('Due now')
        ->assertSee('bg-yellow-400/25', false)
        ->assertDontSee('Duration')
        ->assertSee('data-flux-card', false)
        ->assertSee('data-flux-table', false)
        ->assertSee('sticky top-0 z-20', false)
        ->assertSee('Log')
        ->assertSee('Add log entry')
        ->assertSee('href="'.route('customers.show', ['customer' => 2462]).'"', false)
        ->assertSee('wire:navigate', false);

    $matchesPipelineCustomerQuery = function (Request $request, int $offset): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $queryParams);

        $suiteQl = $request->data()['q'] ?? '';

        return $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/services/rest/query/v1/suiteql'
            && (int) ($queryParams['limit'] ?? 0) === 1000
            && (int) ($queryParams['offset'] ?? -1) === $offset
            && str_contains($suiteQl, 'CUSTOMLIST_PANOPTICON_CADENCE_OPTIONS')
            && str_contains($suiteQl, 'c.custentity_panopticon_sales_pipeline AS pipeline_owner_id')
            && str_contains($suiteQl, 'c.custentity_panopticon_sales_pipeline = 2214');
    };

    $matchesSalesRepCustomerQuery = function (Request $request, int $offset): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $queryParams);

        $suiteQl = $request->data()['q'] ?? '';

        return $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/services/rest/query/v1/suiteql'
            && (int) ($queryParams['limit'] ?? 0) === 1000
            && (int) ($queryParams['offset'] ?? -1) === $offset
            && str_contains($suiteQl, 'CUSTOMLIST_PANOPTICON_CADENCE_OPTIONS')
            && str_contains($suiteQl, 'c.category AS category_id')
            && str_contains($suiteQl, 'BUILTIN.DF(c.category) AS category_name')
            && str_contains($suiteQl, 'c.custentity_panopticon_comm_cadence AS cadence_id')
            && str_contains($suiteQl, 'cadence.scriptid AS cadence_scriptid')
            && str_contains($suiteQl, 'c.salesrep = 2214');
    };

    Http::assertSentInOrder([
        fn (Request $request): bool => $matchesPipelineCustomerQuery($request, 0),
        fn (Request $request): bool => $matchesSalesRepCustomerQuery($request, 0),
        fn (Request $request): bool => $matchesSalesRepCustomerQuery($request, 1000),
    ]);
});

test('active customer table paginates and sorts without refetching NetSuite', function () {
    config()->set('briar-rose.account', '1234567');
    config()->set('briar-rose.consumer_key', 'consumer-key');
    config()->set('briar-rose.consumer_secret', 'consumer-secret');
    config()->set('briar-rose.token_id', 'token-id');
    config()->set('briar-rose.token_secret', 'token-secret');
    config()->set('briar-rose.rest_base_url', 'https://netsuite.test');
    config()->set('briar-rose.rest.retries.enabled', false);

    app()->forgetInstance(BriarRoseManager::class);

    $activeCustomers = collect(range(1, 12))
        ->map(fn (int $number): array => [
            'id' => (string) (3000 + $number),
            'customer_id' => (string) (3000 + $number),
            'entityid' => sprintf('CUST-%02d', $number),
            'companyname' => sprintf('Customer %02d', $number),
            'email' => sprintf('customer%02d@example.test', $number),
            'phone' => sprintf('555-01%02d', $number),
            'sales_rep_id' => '2214',
            'cadence_name' => 'Monthly',
            'cadence_scriptid' => '_P1M',
        ])
        ->all();

    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::sequence()
            ->push([
                'items' => [],
                'hasMore' => false,
            ])
            ->push([
                'items' => $activeCustomers,
                'hasMore' => false,
            ]),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->assertSeeText('Customer 01')
        ->assertDontSeeText('Customer 11')
        ->call('gotoPage', 2, 'active-customers-page')
        ->assertSeeText('Customer 11')
        ->assertDontSeeText('Customer 01')
        ->call('sortActiveCustomers', 'customer')
        ->assertSet('activeCustomerSortDirection', 'desc')
        ->assertSeeText('Customer 12')
        ->assertDontSeeText('Customer 01')
        ->call('sortActiveCustomers', 'contact_due')
        ->assertSet('activeCustomerSortColumn', 'contact_due');

    Http::assertSentCount(2);
});

test('active customer category badges map known categories and fallback categories to colors', function () {
    config()->set('briar-rose.account', '1234567');
    config()->set('briar-rose.consumer_key', 'consumer-key');
    config()->set('briar-rose.consumer_secret', 'consumer-secret');
    config()->set('briar-rose.token_id', 'token-id');
    config()->set('briar-rose.token_secret', 'token-secret');
    config()->set('briar-rose.rest_base_url', 'https://netsuite.test');
    config()->set('briar-rose.rest.retries.enabled', false);

    app()->forgetInstance(BriarRoseManager::class);

    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::sequence()
            ->push([
                'items' => [],
                'hasMore' => false,
            ])
            ->push([
                'items' => [],
                'hasMore' => false,
            ]),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $dashboard = Livewire::test('pages::dashboard')->instance();
    $knownCategoryColors = [
        'APW1' => 'blue',
        'APW2' => 'sky',
        'APW3' => 'indigo',
        'Commercial' => 'emerald',
        'Commercial Service' => 'teal',
        'Extended Warranty' => 'amber',
        'OEM' => 'purple',
    ];

    foreach ($knownCategoryColors as $category => $color) {
        expect($dashboard->activeCustomerCategoryBadgeColor(['category_name' => $category]))->toBe($color);
    }

    expect(array_unique(array_values($knownCategoryColors)))->toHaveCount(count($knownCategoryColors));
    expect($dashboard->activeCustomerCategoryBadgeColor(['category_name' => 'Dealer']))->toBe('zinc');
    expect($dashboard->activeCustomerCategoryBadgeColor([]))->toBe('zinc');
    expect($dashboard->activeCustomerCategoryLabel([]))->toBe('Uncategorized');
});

test('active customer contact due uses last log and cadence and defaults untouched customers to now', function () {
    config()->set('panopticon.contact_due_warning_days', 2);
    config()->set('briar-rose.account', '1234567');
    config()->set('briar-rose.consumer_key', 'consumer-key');
    config()->set('briar-rose.consumer_secret', 'consumer-secret');
    config()->set('briar-rose.token_id', 'token-id');
    config()->set('briar-rose.token_secret', 'token-secret');
    config()->set('briar-rose.rest_base_url', 'https://netsuite.test');
    config()->set('briar-rose.rest.retries.enabled', false);

    app()->forgetInstance(BriarRoseManager::class);

    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::sequence()
            ->push([
                'items' => [],
                'hasMore' => false,
            ])
            ->push([
                'items' => [],
                'hasMore' => false,
            ]),
    ]);

    $this->travelTo(Carbon::parse('2026-05-28 12:00:00'));

    $user = User::factory()->create();
    $this->actingAs($user);

    $dashboard = Livewire::test('pages::dashboard')->instance();

    expect($dashboard->activeCustomerContactDueLabel([
        'cadence_iso8601' => 'P3M',
    ]))->toBe('Due now');
    expect($dashboard->activeCustomerContactDueBadgeColor([
        'cadence_iso8601' => 'P3M',
    ]))->toBe('yellow');

    expect($dashboard->activeCustomerContactDueLabel([
        'last_log_at' => '2026-02-28 12:00:00',
        'cadence_iso8601' => 'P3M',
    ]))->toBe('Due now');
    expect($dashboard->activeCustomerContactDueBadgeColor([
        'last_log_at' => '2026-02-28 12:00:00',
        'cadence_iso8601' => 'P3M',
    ]))->toBe('yellow');

    expect($dashboard->activeCustomerContactDueLabel([
        'last_log_at' => '2026-01-28 12:00:00',
        'cadence_iso8601' => 'P3M',
    ]))->toBe('Overdue by 1 month');
    expect($dashboard->activeCustomerContactDueBadgeColor([
        'last_log_at' => '2026-01-28 12:00:00',
        'cadence_iso8601' => 'P3M',
    ]))->toBe('red');

    expect($dashboard->activeCustomerContactDueLabel([
        'last_log_at' => '2026-05-28 12:00:00',
        'cadence_iso8601' => 'P1M',
    ]))->toBe('Due in 1 month');
    expect($dashboard->activeCustomerContactDueBadgeColor([
        'last_log_at' => '2026-05-28 12:00:00',
        'cadence_iso8601' => 'P1M',
    ]))->toBe('green');
});

test('active customer contact due warning window is configurable', function () {
    config()->set('panopticon.contact_due_warning_days', 5);
    config()->set('briar-rose.account', '1234567');
    config()->set('briar-rose.consumer_key', 'consumer-key');
    config()->set('briar-rose.consumer_secret', 'consumer-secret');
    config()->set('briar-rose.token_id', 'token-id');
    config()->set('briar-rose.token_secret', 'token-secret');
    config()->set('briar-rose.rest_base_url', 'https://netsuite.test');
    config()->set('briar-rose.rest.retries.enabled', false);

    app()->forgetInstance(BriarRoseManager::class);

    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::sequence()
            ->push([
                'items' => [],
                'hasMore' => false,
            ])
            ->push([
                'items' => [],
                'hasMore' => false,
            ]),
    ]);

    $this->travelTo(Carbon::parse('2026-05-28 12:00:00'));

    $user = User::factory()->create();
    $this->actingAs($user);

    $dashboard = Livewire::test('pages::dashboard')->instance();

    expect($dashboard->activeCustomerContactDueBadgeColor([
        'last_log_at' => '2026-05-28 12:00:00',
        'cadence_iso8601' => 'P4D',
    ]))->toBe('yellow');
});

test('authenticated users can visit the customer communication page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('customers.show', ['customer' => 2462]));

    $response
        ->assertOk()
        ->assertSee('Customer 2462')
        ->assertSee('Communication');
});

test('linked users without a NetSuite sales rep id see an unlinked dashboard state', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create([
        'netsuite_user_id' => null,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertSee('not linked to a NetSuite employee');

    Http::assertNothingSent();
});
