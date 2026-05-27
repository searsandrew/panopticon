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

test('authenticated users can visit the dashboard and see pipeline customers', function () {
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
                        'id' => '2462',
                        'entityid' => 'CUST-2462',
                        'companyname' => 'Acme Dental',
                        'custentity_panopticon_sales_pipeline' => '2214',
                    ],
                ],
                'hasMore' => true,
            ])
            ->push([
                'items' => [
                    [
                        'id' => '2463',
                        'entityid' => 'CUST-2463',
                        'companyname' => 'Bright Smiles',
                        'custentity_panopticon_sales_pipeline' => '2214',
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
        ->assertSee('Acme Dental')
        ->assertSee('Bright Smiles');

    $matchesPipelineCustomerQuery = function (Request $request, int $offset): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $queryParams);

        $suiteQl = $request->data()['q'] ?? '';

        return $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/services/rest/query/v1/suiteql'
            && (int) ($queryParams['limit'] ?? 0) === 1000
            && (int) ($queryParams['offset'] ?? -1) === $offset
            && str_contains($suiteQl, 'custentity_panopticon_sales_pipeline = 2214');
    };

    Http::assertSentInOrder([
        fn (Request $request): bool => $matchesPipelineCustomerQuery($request, 0),
        fn (Request $request): bool => $matchesPipelineCustomerQuery($request, 1000),
    ]);
});
