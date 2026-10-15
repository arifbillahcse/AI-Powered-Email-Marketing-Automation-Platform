<?php

use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Filament\Admin\Pages\SystemHealth;
use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\Plans\Pages\ManagePlans;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Workspaces\Pages\ListWorkspaces;
use App\Filament\Admin\Widgets\AbuseWatch;
use App\Filament\Admin\Widgets\PlatformStats;
use App\Jobs\SendCampaignEmail;
use App\Models\CampaignLead;
use App\Models\EmailMessage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Admin\Impersonator;
use App\Services\Billing\SubscriptionManager;
use App\Services\Sending\EngagementRecorder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->superAdmin()->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();
});

it('creates plans with prices in cents and one trial plan', function () {
    $old = Plan::factory()->default()->create();

    Livewire::test(ManagePlans::class)
        ->callAction('create', data: [
            'name' => 'Growth',
            'slug' => 'growth',
            'currency' => 'USD',
            'price_monthly' => '79.00',
            'price_yearly' => '790',
            'trial_days' => 7,
            'limits' => ['mailboxes' => 15, 'leads' => null, 'emails_per_month' => 50000, 'team_members' => 5],
            'modules' => ['ai'],
            'is_active' => true,
            'is_default' => true,
            'sort' => 2,
        ])
        ->assertHasNoActionErrors();

    $plan = Plan::query()->where('slug', 'growth')->sole();

    expect($plan->price_monthly)->toBe(7900)
        ->and($plan->price_yearly)->toBe(79000)
        ->and($plan->limit('mailboxes'))->toBe(15)
        ->and($plan->limit('leads'))->toBeNull()
        ->and($plan->is_default)->toBeTrue()
        ->and($old->refresh()->is_default)->toBeFalse();
});

it('lists workspaces with plan and owner, and suspends them', function () {
    $owner = User::factory()->create(['email' => 'owner@acme.test']);
    $workspace = Workspace::factory()->create(['name' => 'Acme']);
    $workspace->addMember($owner, WorkspaceRole::Owner);

    Livewire::test(ListWorkspaces::class)
        ->assertCanSeeTableRecords([$workspace])
        ->assertSee('owner@acme.test')
        ->callAction(TestAction::make('suspend')->table($workspace), data: ['reason' => 'Spam complaints.'])
        ->assertNotified('Acme suspended');

    expect($workspace->refresh()->isSuspended())->toBeTrue()
        ->and($workspace->suspension_reason)->toBe('Spam complaints.');

    Livewire::test(ListWorkspaces::class)
        ->callAction(TestAction::make('unsuspend')->table($workspace));

    expect($workspace->refresh()->isSuspended())->toBeFalse();
});

it('sets a workspace plan by hand', function () {
    $plan = Plan::factory()->create();
    $workspace = Workspace::factory()->create();

    Livewire::test(ListWorkspaces::class)
        ->callAction(TestAction::make('setPlan')->table($workspace), data: [
            'plan_id' => $plan->id,
            'interval' => 'yearly',
            'status' => 'active',
            'period_end' => now()->addYear()->toDateTimeString(),
        ])
        ->assertHasNoActionErrors();

    $subscription = Subscription::sole();

    expect($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->interval)->toBe(BillingInterval::Yearly)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active);
});

it('confirms bank transfers', function () {
    config(['billing.enabled' => true]);
    $plan = Plan::factory()->create();
    $workspace = Workspace::factory()->create();
    $invoice = app(SubscriptionManager::class)->invoiceFor($workspace, $plan, BillingInterval::Monthly);
    $invoice->forceFill(['gateway' => 'manual'])->save();

    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$invoice])
        ->callAction(TestAction::make('markPaid')->table($invoice), data: ['reference' => 'CITY-889'])
        ->assertNotified("{$invoice->number} marked as paid");

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->meta['transaction_id'])->toBe('CITY-889')
        ->and(Subscription::sole()->status)->toBe(SubscriptionStatus::Active);
});

it('impersonates a customer and switches back', function () {
    $customer = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('impersonate')->table($customer))
        ->assertRedirect(url('/app'));

    expect(auth()->id())->toBe($customer->id)
        ->and(session(Impersonator::SESSION_KEY))->toBe($this->admin->id);

    $this->post(route('impersonation.stop'))->assertRedirect(url('/admin/users'));

    expect(auth()->id())->toBe($this->admin->id)
        ->and(session()->has(Impersonator::SESSION_KEY))->toBeFalse();
});

it('never impersonates another super admin', function () {
    $other = User::factory()->superAdmin()->create();

    Livewire::test(ListUsers::class)->assertActionHidden(TestAction::make('impersonate')->table($other));

    expect(fn () => app(Impersonator::class)->start($this->admin, $other))->toThrow(InvalidArgumentException::class);
});

it('suspends users so they can no longer sign in', function () {
    $customer = User::factory()->create();
    Workspace::factory()->withMember($customer, WorkspaceRole::Owner)->create();

    Livewire::test(ListUsers::class)->callAction(TestAction::make('suspend')->table($customer));

    expect($customer->refresh()->suspended_at)->not->toBeNull();
    $this->actingAs($customer)->get('/app')->assertForbidden();
});

it('shows system health', function () {
    Livewire::test(SystemHealth::class)
        ->assertSee('Database')
        ->assertSee('Cron (scheduler)')
        ->assertSee('No heartbeat yet');

    // What the every-minute scheduled heartbeat writes.
    Cache::put('system:scheduler_heartbeat', now()->toIso8601String());

    Livewire::test(SystemHealth::class)->assertDontSee('No heartbeat yet');
});

it('shows whether the last update was fully applied', function () {
    $health = app(App\Services\Admin\SystemHealth::class);

    expect($health->pendingMigrations())->toBe(0)
        ->and($health->configCacheIsStale())->toBeFalse();

    Livewire::test(SystemHealth::class)
        ->assertSee('Deployment')
        ->assertSee('Database and cached settings are up to date.');
});

it('flags workspaces with abusive sending and shows platform stats', function () {
    fakeTransport();
    ['workspace' => $workspace, 'mailbox' => $mailbox] = readyCampaign(20, mailbox: ['daily_limit' => 200]);
    config(['outreach.safety.enabled' => false]); // keep sending to measure
    CampaignLead::query()->each(fn (CampaignLead $lead) => SendCampaignEmail::dispatchSync($lead->id, $mailbox->id, 1));
    EmailMessage::query()->take(3)->get()->each(fn (EmailMessage $message) => app(EngagementRecorder::class)->complaint($message));

    Livewire::test(AbuseWatch::class)->assertSee($workspace->name)->assertSee('15%');
    Livewire::test(ListWorkspaces::class)->filterTable('at_risk')->assertCanSeeTableRecords([$workspace]);
    Livewire::test(PlatformStats::class)->assertSee('Workspaces')->assertSee('MRR');
});

it('keeps customers out of admin resources', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin/workspaces')->assertForbidden();
});
