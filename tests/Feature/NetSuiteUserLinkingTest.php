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

    app()->forgetInstance(BriarRoseManager::class);

    $this->seed(CommunicationLoggingSeeder::class);

    Http::preventStrayRequests();
});

test('verified email links the user to the matching NetSuite employee', function () {
    Http::fake([
        '*' => Http::response([
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
        ]),
    ]);

    $user = User::factory()->unverified()->create([
        'email' => 'asears@choicemfg.parts',
    ]);

    $this->actingAs($user)->get(verificationUrlFor($user))
        ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    expect($user->fresh()->netsuite_user_id)->toBe(513);

    Http::assertSent(function (Request $request): bool {
        $suiteQl = $request->data()['q'] ?? '';

        return parse_url($request->url(), PHP_URL_PATH) === '/services/rest/query/v1/suiteql'
            && str_contains($suiteQl, "isinactive = 'F'")
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
        '*' => Http::response([
            'items' => [
                [
                    'id' => '2214',
                    'email' => 'truggles@choicemfg.parts',
                    'isinactive' => 'F',
                    'type' => 'Employee',
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

    expect($user->fresh()->netsuite_user_id)->toBe(2214);
});

function verificationUrlFor(User $user): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );
}
