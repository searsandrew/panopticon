<?php

use App\Models\User;
use App\Services\NetSuite\NetSuiteSalesRepRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Searsandrew\BriarRose\BriarRoseManager;

beforeEach(function () {
    config()->set('briar-rose.account', '1234567');
    config()->set('briar-rose.consumer_key', 'consumer-key');
    config()->set('briar-rose.consumer_secret', 'consumer-secret');
    config()->set('briar-rose.token_id', 'token-id');
    config()->set('briar-rose.token_secret', 'token-secret');
    config()->set('briar-rose.rest_base_url', 'https://netsuite.test');
    config()->set('briar-rose.rest.retries.enabled', false);

    app()->forgetInstance(BriarRoseManager::class);

    Http::preventStrayRequests();
});

test('sales rep options are loaded from active NetSuite employee sales reps', function () {
    fakeNetSuiteSalesRepOptions();

    $salesReps = app(NetSuiteSalesRepRepository::class)->active();

    expect($salesReps)->toBe([
        [
            'id' => 513,
            'name' => 'Andrew Sears',
            'email' => 'asears@example.test',
        ],
        [
            'id' => 2214,
            'name' => 'Tom Ruggles',
            'email' => null,
        ],
    ]);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/services/rest/record/v1/employee'
            && ($query['q'] ?? '') === 'issalesrep IS true AND isInactive IS false';
    });

    Http::assertSent(function (Request $request): bool {
        $suiteQl = $request->data()['q'] ?? '';

        return $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/services/rest/query/v1/suiteql'
            && str_contains($suiteQl, 'FROM entity')
            && ! str_contains($suiteQl, "type = 'Employee'")
            && str_contains($suiteQl, 'id IN (2214, 513)');
    });
});

test('masquerade dropdown renders dynamic sales reps and updates the selected NetSuite id', function () {
    fakeNetSuiteSalesRepOptions();

    $user = User::factory()->create([
        'netsuite_user_id' => 513,
        'netsuite_managed_sales_rep_ids' => [1900],
    ]);

    $this->actingAs($user);

    Livewire::test('masquerade')
        ->assertSet('netSuiteId', 513)
        ->assertSee('Andrew Sears')
        ->assertSee('Tom Ruggles')
        ->set('netSuiteId', 2214)
        ->assertRedirect(route('dashboard', absolute: false));

    $user->refresh();

    expect($user->netsuite_user_id)->toBe(2214)
        ->and($user->netsuite_managed_sales_rep_ids)->toBe([]);
});

function fakeNetSuiteSalesRepOptions(): void
{
    Http::fake([
        '*' => Http::sequence()
            ->push([
                'items' => [
                    ['id' => '2214'],
                    ['id' => '513'],
                ],
                'hasMore' => false,
                'links' => [],
            ])
            ->push([
                'items' => [
                    [
                        'id' => '2214',
                        'entityid' => 'Tom Ruggles',
                        'altname' => 'Tom Ruggles',
                        'email' => '',
                    ],
                    [
                        'id' => '513',
                        'entityid' => 'Andrew Sears',
                        'altname' => 'Andrew Sears',
                        'email' => 'asears@example.test',
                    ],
                ],
                'hasMore' => false,
            ]),
    ]);
}
