<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Mail;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('incomplete local Mailgun configuration falls back to the log mailer', function () {
    config()->set('mail.default', 'mailgun');
    config()->set('services.mailgun.domain', null);
    config()->set('services.mailgun.secret', null);

    app('mail.manager')->forgetMailers();

    (new AppServiceProvider(app()))->boot();

    expect(config('mail.default'))->toBe('log');

    Mail::raw('Testing mail fallback.', function ($message) {
        $message
            ->to('test@example.com')
            ->subject('Testing mail fallback');
    });
});
