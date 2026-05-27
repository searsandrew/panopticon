<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
        ->assertSee('Monthly')
        ->assertSee('P1M')
        ->assertSee('Quarterly')
        ->assertSee('P3M')
        ->assertSee('Annually')
        ->assertSee('P1Y');

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
