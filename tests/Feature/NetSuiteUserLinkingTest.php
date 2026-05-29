<?php

use App\Models\User;
use Database\Seeders\CommunicationLoggingSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Searsandrew\BriarRose\BriarRoseManager;

beforeEach(function () {
    config()->set('briar-rose.account', '1234567');
    config()->set('briar-rose.consumer_key', 'consumer-key');
    config()->set('briar-rose.consumer_secret', 'consumer-secret');
    config()->set('briar-rose.token_id', 'token-id');
    config()->set('briar-rose.token_secret', 'token-secret');
    config()->set('briar-rose.rest_base_url', 'https://netsuite.test');
    config()->set('briar-rose.rest.retries.enabled', false);
    config()->set('panopticon.netsuite_managed_sales_reps_map_table', null);

    app()->forgetInstance(BriarRoseManager::class);

    $this->seed(CommunicationLoggingSeeder::class);

    Http::preventStrayRequests();
});

test('verified email links the user to the matching NetSuite employee', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push([
                'items' => [
                    [
                        'id' => '513',
                        'entityid' => 'Andrew Sears',
                        'altname' => 'Andrew Sears',
                        'email' => 'asears@choicemfg.parts',
                        'isinactive' => 'F',
                        'type' => 'Employee',
                    ],
                    [
                        'id' => '732',
                        'entityid' => 'Andrew Sears',
                        'altname' => 'Andrew Sears',
                        'email' => 'asears@choicemfg.parts',
                        'isinactive' => 'F',
                        'type' => 'CustJob',
                    ],
                ],
                'hasMore' => false,
            ])
            ->push([
                'items' => [
                    [
                        'id' => '513',
                        'managed_sales_rep_ids' => '2214, 513',
                    ],
                ],
                'hasMore' => false,
            ]),
    ]);

    $user = User::factory()->unverified()->create([
        'email' => 'asears@choicemfg.parts',
    ]);

    $this->actingAs($user)->get(verificationUrlFor($user))
        ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    expect($user->fresh()->netsuite_user_id)->toBe(513)
        ->and($user->fresh()->netsuite_managed_sales_rep_ids)->toBe([2214, 513]);

    Http::assertSent(function (Request $request): bool {
        $suiteQl = $request->data()['q'] ?? '';

        return parse_url($request->url(), PHP_URL_PATH) === '/services/rest/query/v1/suiteql'
            && str_contains($suiteQl, "isinactive = 'F'")
            && str_contains($suiteQl, 'FROM entity')
            && str_contains($suiteQl, 'type')
            && str_contains($suiteQl, "lower(email) = lower('asears@choicemfg.parts')");
    });
});

test('verified email is not linked when NetSuite returns multiple matching employees', function () {
    Http::fake([
        '*' => Http::response([
            'items' => [
                [
                    'id' => '2214',
                    'type' => 'Employee',
                ],
                [
                    'id' => '2215',
                    'type' => 'Employee',
                ],
            ],
            'hasMore' => false,
        ]),
    ]);

    $user = User::factory()->unverified()->create([
        'email' => 'duplicate@example.com',
    ]);

    $this->actingAs($user)->get(verificationUrlFor($user))
        ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    expect($user->fresh()->netsuite_user_id)->toBeNull();
});

test('verified users without a NetSuite id are linked on login', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push([
                'items' => [
                    [
                        'id' => '2214',
                        'email' => 'truggles@choicemfg.parts',
                        'isinactive' => 'F',
                        'type' => 'Employee',
                    ],
                ],
                'hasMore' => false,
            ])
            ->push([
                'items' => [
                    [
                        'id' => '2214',
                        'managed_sales_rep_ids' => [
                            ['id' => '513'],
                        ],
                    ],
                ],
                'hasMore' => false,
            ]),
    ]);

    $user = User::factory()->create([
        'email' => 'truggles@choicemfg.parts',
        'netsuite_user_id' => null,
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    expect($user->fresh()->netsuite_user_id)->toBe(2214)
        ->and($user->fresh()->netsuite_managed_sales_rep_ids)->toBe([513]);
});

test('linked users refresh their managed NetSuite sales rep cache on login', function () {
    Http::fake([
        '*' => Http::response([
            'items' => [
                [
                    'id' => '513',
                    'email' => 'asears@choicemfg.parts',
                    'isinactive' => 'F',
                    'managed_sales_rep_ids' => json_encode([
                        ['id' => '2214'],
                        ['value' => '1562'],
                    ]),
                ],
            ],
            'hasMore' => false,
        ]),
    ]);

    $user = User::factory()->create([
        'email' => 'asears@choicemfg.parts',
        'netsuite_user_id' => 513,
        'netsuite_managed_sales_rep_ids' => [9999],
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    expect($user->fresh()->netsuite_managed_sales_rep_ids)->toBe([2214, 1562]);

    Http::assertSent(function (Request $request): bool {
        $suiteQl = $request->data()['q'] ?? '';

        return parse_url($request->url(), PHP_URL_PATH) === '/services/rest/query/v1/suiteql'
            && str_contains($suiteQl, 'FROM entity')
            && str_contains($suiteQl, 'custentity_managed_sales_reps AS managed_sales_rep_ids')
            && str_contains($suiteQl, 'id = 513');
    });
});

test('linked users refresh managed sales reps from REST when SuiteQL does not expose the custom field', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push([
                'type' => 'https://www.rfc-editor.org/rfc/rfc9110.html#section-15.5.1',
                'title' => 'Bad Request',
                'status' => 400,
                'o:errorDetails' => [
                    [
                        'detail' => "Unknown identifier 'custentity_managed_sales_reps'.",
                    ],
                ],
            ], 400)
            ->push([
                'type' => 'https://www.rfc-editor.org/rfc/rfc9110.html#section-15.5.1',
                'title' => 'Bad Request',
                'status' => 400,
                'o:errorDetails' => [
                    [
                        'detail' => "Record 'employee' was not found.",
                    ],
                ],
            ], 400)
            ->push([
                'id' => '513',
                'entityId' => 'Andrew Sears',
                'email' => 'asears@choicemfg.parts',
                'custentity_managed_sales_reps' => [
                    'count' => 3,
                    'hasMore' => false,
                    'items' => [
                        ['id' => '1901', 'refName' => 'Export Account'],
                        ['id' => '1900', 'refName' => 'House Account'],
                        ['id' => '1902', 'refName' => 'OEM Account'],
                    ],
                    'totalResults' => 3,
                ],
            ]),
    ]);

    $user = User::factory()->create([
        'email' => 'asears@choicemfg.parts',
        'netsuite_user_id' => 513,
        'netsuite_managed_sales_rep_ids' => [],
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    expect($user->fresh()->netsuite_managed_sales_rep_ids)->toBe([1901, 1900, 1902]);
});

function verificationUrlFor(User $user): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );
}
