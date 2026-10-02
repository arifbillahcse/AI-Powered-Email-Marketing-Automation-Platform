<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\MassAssignmentException;

it('shows the login page for both panels', function (string $path) {
    $this->get($path)->assertOk();
})->with(['/app/login', '/admin/login']);

it('shows the sign-up page for the app panel only', function () {
    $this->get('/app/register')->assertOk();
    $this->get('/admin/register')->assertNotFound();
});

it('redirects guests to the panel login', function (string $panel) {
    $this->get("/{$panel}")->assertRedirect("/{$panel}/login");
})->with(['app', 'admin']);

it('sends users without a workspace to create one', function () {
    $this->actingAs(User::factory()->create())
        ->get('/app')
        ->assertRedirect('/app/new');
});

it('sends users with a workspace to its dashboard', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user)->create();

    $this->actingAs($user)->get('/app')->assertRedirect("/app/{$workspace->slug}");
    $this->actingAs($user)->get("/app/{$workspace->slug}")->assertOk();
});

it('asks unverified users to verify their email', function () {
    $user = User::factory()->unverified()->create();
    $workspace = Workspace::factory()->withMember($user)->create();

    $this->actingAs($user)
        ->get("/app/{$workspace->slug}")
        ->assertRedirect('/app/email-verification/prompt');
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

it('loads the profile page with two-factor setup', function () {
    $user = User::factory()->create();
    Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();

    $this->actingAs($user)->get('/app/profile')->assertOk();
});
