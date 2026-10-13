<?php

use App\Console\Commands\CreateDemo;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\EmailMessage;
use App\Models\Lead;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsReport;

it('creates a demo account with sample data and results', function () {
    $this->artisan('app:demo', ['--email' => 'demo@softorio.test', '--password' => 'demo-password-123'])->assertSuccessful();

    $user = User::query()->where('email', 'demo@softorio.test')->sole();
    $workspace = Workspace::query()->where('name', CreateDemo::WORKSPACE)->sole();
    $totals = app(AnalyticsReport::class)->totals(AnalyticsFilters::fromState($workspace, ['range' => '30d']));

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->roleIn($workspace))->not->toBeNull()
        ->and($workspace->hasMailingAddress())->toBeTrue()
        ->and(Lead::query()->where('workspace_id', $workspace->id)->count())->toBe(60)
        ->and(Campaign::query()->where('workspace_id', $workspace->id)->pluck('status')->all())->toContain(CampaignStatus::Draft, CampaignStatus::Completed)
        ->and($totals['sent'])->toBeGreaterThan(40)
        ->and($totals['replied'])->toBeGreaterThan(0)
        ->and(EmailMessage::query()->whereNotNull('email_account_id')->exists())->toBeFalse();
});

it('keeps an existing demo unless asked to rebuild it', function () {
    $this->artisan('app:demo', ['--email' => 'demo@softorio.test', '--password' => 'demo-password-123'])->assertSuccessful();
    $this->artisan('app:demo', ['--email' => 'demo@softorio.test'])->assertSuccessful();

    expect(Workspace::query()->where('name', CreateDemo::WORKSPACE)->count())->toBe(1);

    $old = Workspace::query()->where('name', CreateDemo::WORKSPACE)->value('id');
    $this->artisan('app:demo', ['--email' => 'demo@softorio.test', '--fresh' => true])->assertSuccessful();

    expect(Workspace::query()->where('name', CreateDemo::WORKSPACE)->sole()->id)->not->toBe($old)
        ->and(Lead::query()->count())->toBe(60);
});
