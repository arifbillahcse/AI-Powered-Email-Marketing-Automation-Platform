<?php

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Tag;
use App\Models\Workspace;

it('normalizes email, domain and custom field keys', function () {
    $lead = Lead::factory()->create([
        'email' => '  Jane.Doe@ACME.com ',
        'custom_fields' => ['Company Size' => '11-50', 'First Name' => 'ignored', 'Lead Score!' => 9],
    ]);

    expect($lead->email)->toBe('jane.doe@acme.com')
        ->and($lead->email_domain)->toBe('acme.com')
        ->and($lead->custom_fields)->toBe(['company_size' => '11-50', 'lead_score' => 9]);
});

it('exposes standard and custom fields as variables', function () {
    $lead = Lead::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'company' => null,
        'custom_fields' => ['company_size' => '11-50'],
    ]);

    expect($lead->variables())
        ->toMatchArray(['first_name' => 'Jane', 'full_name' => 'Jane Doe', 'company' => '', 'company_size' => '11-50']);
});

it('syncs tags by name and logs it on the timeline', function () {
    $lead = Lead::factory()->create();

    $lead->syncTagNames(['Hot', ' priority ', 'hot']);

    expect($lead->tags()->pluck('name')->sort()->values()->all())->toBe(['hot', 'priority'])
        ->and(Tag::count())->toBe(2)
        ->and($lead->activities()->where('type', LeadActivityType::Tagged)->exists())->toBeTrue();
});

it('keeps tags separate per workspace', function () {
    $a = Lead::factory()->create();
    $b = Lead::factory()->for(Workspace::factory())->create();

    $a->syncTagNames(['hot']);
    $b->syncTagNames(['hot']);

    expect(Tag::count())->toBe(2);
});

it('logs creation and status changes', function () {
    $lead = Lead::factory()->create();
    $lead->update(['status' => LeadStatus::Interested]);

    expect($lead->activities()->pluck('type')->map->value->all())->toBe(['status_changed', 'created']);
});
