<?php

use App\Models\User;

it('creates a super admin', function () {
    $this->artisan('app:create-super-admin', [
        '--name' => 'Arif',
        '--email' => 'arif@example.com',
        '--password' => 'a-strong-password',
    ])->assertSuccessful();

    $user = User::where('email', 'arif@example.com')->sole();

    expect($user->is_super_admin)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

it('rejects weak passwords', function () {
    $this->artisan('app:create-super-admin', [
        '--name' => 'Arif',
        '--email' => 'arif@example.com',
        '--password' => 'short',
    ])->assertFailed();

    expect(User::count())->toBe(0);
});
