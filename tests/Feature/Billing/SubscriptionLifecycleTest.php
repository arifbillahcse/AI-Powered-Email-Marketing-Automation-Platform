<?php

use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Filament\App\Tenancy\RegisterWorkspace;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\SubscriptionManager;
use Filament\Facades\Filament;
use Livewire\Livewire;

function ownedWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->addMember($owner, WorkspaceRole::Owner);

    return [$workspace, $owner];
}

it('starts a free trial on the default plan for new workspaces', function () {
    $plan = billingOn(['trial_days' => 14]);
    $user = User::factory()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    Livewire::test(RegisterWorkspace::class)
        ->fillForm(['name' => 'Softorio Agency', 'timezone' => 'Asia/Dhaka'])
        ->call('register');

    $subscription = Subscription::sole();

    expect($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($subscription->isUsable())->toBeTrue();
});

it('never starts trials with billing off', function () {
    Plan::factory()->default()->create();
    [$workspace] = ownedWorkspace();

    expect(app(SubscriptionManager::class)->startTrial($workspace))->toBeNull()
        ->and(Subscription::count())->toBe(0);
});

it('activates the plan when its invoice is paid', function () {
    $plan = billingOn(['price_monthly' => 2900]);
    [$workspace, $owner] = ownedWorkspace();
    app(SubscriptionManager::class)->startTrial($workspace);

    $invoice = app(SubscriptionManager::class)->invoiceFor($workspace, $plan, BillingInterval::Monthly);

    expect($invoice->number)->toBe('INV-'.now()->format('Y').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT))
        ->and($invoice->amount)->toBe(2900)
        ->and($invoice->is_renewal)->toBeFalse()
        // Asking again reuses the open invoice.
        ->and(app(SubscriptionManager::class)->invoiceFor($workspace, $plan, BillingInterval::Monthly)->id)->toBe($invoice->id);

    app(SubscriptionManager::class)->markPaid($invoice, 'stripe', 'pi_123');
    app(SubscriptionManager::class)->markPaid($invoice->refresh(), 'stripe', 'pi_123'); // twice: no effect

    $subscription = Subscription::sole();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->meta['transaction_id'])->toBe('pi_123')
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->trial_ends_at)->toBeNull()
        ->and($subscription->current_period_end->isSameDay(now()->addMonth()))->toBeTrue()
        ->and($owner->notifications()->count())->toBe(1);
});

it('renews, then marks overdue, then expires an unpaid subscription', function () {
    $plan = billingOn();
    [$workspace] = ownedWorkspace();
    $subscription = subscribe($workspace, $plan, now()->addDays(2));
    $manager = app(SubscriptionManager::class);

    expect($manager->run()['renewals'])->toBe(1)
        ->and($manager->run()['renewals'])->toBe(0); // only once

    $renewal = Invoice::sole();
    expect($renewal->is_renewal)->toBeTrue()
        ->and($renewal->period_start->equalTo($subscription->current_period_end))->toBeTrue();

    $this->travel(3)->days();
    expect($manager->run()['past_due'])->toBe(1)
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->isUsable())->toBeTrue(); // grace period

    $this->travel(8)->days();
    expect($manager->run()['expired'])->toBe(1)
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired);

    // Paying after expiry starts a fresh period from today.
    $manager->markPaid($renewal);
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_period_end->isSameDay(now()->addMonth()))->toBeTrue()
        ->and($renewal->refresh()->period_start->isSameDay(now()))->toBeTrue();
});

it('ends trials', function () {
    billingOn(['trial_days' => 3]);
    [$workspace, $owner] = ownedWorkspace();
    $subscription = app(SubscriptionManager::class)->startTrial($workspace);

    $this->travel(4)->days();
    app(SubscriptionManager::class)->run();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($owner->notifications()->sole()->data['title'])->toBe('Your free trial has ended');
});

it('stops at the end of the period when canceled', function () {
    $plan = billingOn();
    [$workspace] = ownedWorkspace();
    $subscription = subscribe($workspace, $plan, now()->addDays(2));
    app(SubscriptionManager::class)->cancel($workspace);

    expect(app(SubscriptionManager::class)->run()['renewals'])->toBe(0);

    $this->travel(3)->days();
    app(SubscriptionManager::class)->run();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and(Invoice::count())->toBe(0);
});

it('switches plans when the new plan is paid', function () {
    $starter = billingOn();
    $growth = Plan::factory()->create(['name' => 'Growth', 'price_monthly' => 7900]);
    [$workspace] = ownedWorkspace();
    subscribe($workspace, $starter, now()->addDays(20));

    $invoice = app(SubscriptionManager::class)->invoiceFor($workspace, $growth, BillingInterval::Monthly);
    app(SubscriptionManager::class)->markPaid($invoice);

    $subscription = Subscription::sole();

    expect($invoice->is_renewal)->toBeFalse()
        ->and($subscription->plan_id)->toBe($growth->id)
        ->and($subscription->current_period_start->isSameDay(now()))->toBeTrue();
});

it('runs from the scheduler command', function () {
    billingOn();

    $this->artisan('billing:run')->expectsOutputToContain('0 trials ended')->assertSuccessful();
});

it('installs sample plans once', function () {
    $this->artisan('billing:install-plans')->expectsOutputToContain('Created 3 plans')->assertSuccessful();
    $this->artisan('billing:install-plans')->expectsOutputToContain('Created 0 plans')->assertSuccessful();

    expect(Plan::query()->where('is_default', true)->sole()->slug)->toBe('starter');
});
