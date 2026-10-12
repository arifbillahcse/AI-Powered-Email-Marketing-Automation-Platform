<?php

use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\WorkspaceRole;
use App\Filament\App\Pages\Billing;
use App\Filament\App\Pages\Dashboard;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\SubscriptionManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('is hidden while billing is off', function () {
    $workspace = actingInWorkspace();

    $this->get(Billing::getUrl(tenant: $workspace))->assertForbidden();
});

it('shows the plan, usage and plans to choose from', function () {
    $plan = billingOn(['name' => 'Starter', 'price_monthly' => 2900, 'limits' => ['mailboxes' => 3, 'leads' => 5000]]);
    Plan::factory()->create(['name' => 'Growth', 'price_monthly' => 7900]);
    $workspace = actingInWorkspace();
    app(SubscriptionManager::class)->startTrial($workspace);

    Livewire::test(Billing::class)
        ->assertSee('Starter')
        ->assertSee('Trial')
        ->assertSee('Mailboxes: 0 / 3')
        ->assertSee('Growth')
        ->assertSee('$79.00 / month');
});

it('creates an invoice and gives bank transfer instructions', function () {
    $plan = billingOn(['price_monthly' => 2900]);
    config(['billing.gateways.manual.instructions' => 'Pay to Softorio, City Bank 123.']);
    actingInWorkspace();

    Livewire::test(Billing::class)
        ->callAction('choosePlan', data: ['plan_id' => $plan->id, 'interval' => 'monthly', 'gateway' => 'manual'])
        ->assertHasNoFormErrors()
        ->assertNotified();

    $invoice = Invoice::sole();

    expect($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->gateway)->toBe('manual')
        ->and($invoice->amount)->toBe(2900);
});

it('sends the customer to the card checkout', function () {
    $plan = billingOn(['price_monthly' => 2900]);
    config(['billing.gateways.stripe' => ['enabled' => true, 'secret' => 'sk_test_x', 'webhook_secret' => 'whsec']]);
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'])]);
    actingInWorkspace();

    Livewire::test(Billing::class)
        ->callAction('choosePlan', data: ['plan_id' => $plan->id, 'interval' => 'monthly', 'gateway' => 'stripe'])
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');
});

it('pays an open invoice from the table', function () {
    $plan = billingOn(['price_monthly' => 2900]);
    $workspace = actingInWorkspace();
    $invoice = app(SubscriptionManager::class)->invoiceFor($workspace, $plan, BillingInterval::Monthly);

    Livewire::test(Billing::class)
        ->assertCanSeeTableRecords([$invoice])
        ->callAction(TestAction::make('pay')->table($invoice), data: ['gateway' => 'manual'])
        ->assertNotified();
});

it('says how the payment went when the customer comes back', function () {
    billingOn();
    actingInWorkspace();

    Livewire::withQueryParams(['payment' => 'paid', 'invoice' => 'INV-2026-000001'])
        ->test(Billing::class)
        ->assertNotified('Payment received');
});

it('shows trial days left on every page', function () {
    billingOn(['trial_days' => 14]);
    $workspace = actingInWorkspace();
    app(SubscriptionManager::class)->startTrial($workspace);

    Livewire::test(Dashboard::class)->assertSee('Free trial: 14 days left.');
});

it('prints invoices for members only', function () {
    $plan = billingOn();
    $workspace = actingInWorkspace(role: WorkspaceRole::Member);
    $invoice = app(SubscriptionManager::class)->invoiceFor($workspace, $plan, BillingInterval::Monthly);

    $this->get(route('billing.invoice', $invoice))->assertOk()->assertSee($invoice->number)->assertSee('$29.00');

    $this->actingAs(User::factory()->create())->get(route('billing.invoice', $invoice))->assertNotFound();
    auth()->logout();
    $this->get(route('billing.invoice', $invoice))->assertRedirect(route('filament.app.auth.login'));
});
