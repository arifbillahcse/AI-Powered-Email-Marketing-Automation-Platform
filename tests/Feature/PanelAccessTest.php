<?php

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;

it('shows the login page for both panels', function (string $path) {
    $this->get($path)->assertOk();
})->with(['/app/login', '/admin/login']);

it('redirects guests to the panel login', function (string $panel) {
    $this->get("/{$panel}")->assertRedirect("/{$panel}/login");
})->with(['app', 'admin']);

it('lets a customer into the app panel', function () {
    $this->actingAs(User::factory()->create())
        ->get('/app')
        ->assertOk();
});

it('keeps customers out of the admin panel', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertForbidden();
});

it('lets super admins into the admin panel', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->get('/admin')
        ->assertOk();
});

it('refuses to mass assign the super admin flag', function () {
    // Strict models (outside production) throw instead of silently dropping it.
    User::create([
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'secret-password',
        'is_super_admin' => true,
    ]);
})->throws(MassAssignmentException::class);
