<?php

use App\Filament\App\Resources\Campaigns\Pages\EditCampaign;
use App\Services\Compliance\SpamChecker;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('passes a plain, personal cold email', function () {
    $result = app(SpamChecker::class)->check(
        'Quick question, {{first_name}}',
        '<p>Hi {{first_name|there}},</p><p>Saw that {{company}} is hiring engineers. We help teams like yours ship faster.</p><p>Worth a chat?</p>',
    );

    expect($result['score'])->toBe(100)
        ->and($result['label'])->toBe('Looks good')
        ->and($result['color'])->toBe('success')
        ->and($result['issues'])->toBe([]);
});

it('explains everything that looks like spam', function () {
    $result = app(SpamChecker::class)->check(
        'FREE MONEY!!! Act now',
        '<p>CLICK HERE for a GUARANTEED deal, 100% free!!! Only $99.</p>'
        .'<p><a href="https://bit.ly/x">one</a> <a href="https://a.test">two</a> https://b.test https://c.test</p><img src="https://a.test/i.png">',
    );

    $issues = implode("\n", $result['issues']);

    expect($result['score'])->toBeLessThan(SpamChecker::OK)
        ->and($result['label'])->toBe('Risky')
        ->and($issues)->toContain('"act now"', '"click here"', '"guaranteed"', '"100% free"', '"free money"')
        ->toContain('Exclamation mark in the subject')
        ->toContain('ALL CAPS in the subject')
        ->toContain('4 links')
        ->toContain('Link shorteners (bit.ly)')
        ->toContain('An image')
        ->toContain('money amounts');
});

it('matches spam words as whole words only', function () {
    $result = app(SpamChecker::class)->check('Cashflow tips', '<p>We help with cashflow and freelancing.</p>');

    expect($result['issues'])->toBe([]);
});

it('shows the spam check under every email in the campaign editor', function () {
    ['workspace' => $workspace, 'campaign' => $campaign] = readyCampaign(1, launch: false);
    actingInWorkspace(workspace: $workspace);
    $step = $campaign->steps()->orderBy('position')->first();

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->assertSee('Spam check:')
        ->mountAction(TestAction::make('spamCheck')->schemaComponent('steps')->arguments(['item' => "record-{$step->id}"]))
        ->assertMountedActionModalSee('/100');
});
