<?php

use App\Filament\App\Auth\Register;
use App\Models\User;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel('app'));

it('registers a new user and sends a verification email', function () {
    Notification::fake();

    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Arif Billah',
            'email' => 'arif@example.com',
            'password' => 'a-strong-password',
            'passwordConfirmation' => 'a-strong-password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'arif@example.com')->sole();

    expect($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->is_super_admin)->toBeFalse();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('pre-fills the email when arriving from an invitation', function () {
    session([Register::INVITATION_EMAIL_SESSION_KEY => 'invitee@example.com']);

    Livewire::test(Register::class)
        ->assertSchemaStateSet(['email' => 'invitee@example.com']);
});
