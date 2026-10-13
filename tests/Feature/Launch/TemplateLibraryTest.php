<?php

use App\Enums\CampaignStatus;
use App\Enums\StepType;
use App\Filament\App\Resources\Campaigns\Pages\ListCampaigns;
use App\Models\Campaign;
use App\Models\Workspace;
use App\Services\Campaigns\TemplateLibrary;
use App\Services\Campaigns\TemplateRenderer;
use Livewire\Livewire;

it('creates a draft campaign from every template', function (string $key) {
    $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

    $campaign = app(TemplateLibrary::class)->create($workspace, $key, 'From the library');
    $steps = $campaign->steps()->get();

    expect($campaign)
        ->status->toBe(CampaignStatus::Draft)
        ->timezone->toBe('Asia/Dhaka')
        ->and($steps)->toHaveCount(count(TemplateLibrary::TEMPLATES[$key]['steps']))
        ->and($steps->pluck('position')->all())->toBe(range(1, $steps->count()));

    // The first email has a subject, every email a body, and no variable renders empty.
    $emails = $steps->filter(fn ($step): bool => $step->type === StepType::Email)->values();
    expect($emails->first()->subject)->not->toBeEmpty();

    foreach ($steps as $step) {
        $text = $step->subject.' '.$step->body;
        expect(app(TemplateRenderer::class)->variablesWithoutFallback($text))->toBe([]);

        if ($step->type === StepType::Email) {
            expect(strip_tags((string) $step->body))->not->toBeEmpty();
        }
    }
})->with(array_keys(TemplateLibrary::TEMPLATES));

it('offers multichannel templates only when the plan includes them', function () {
    $plan = billingOn(['modules' => ['ai']]);
    $workspace = Workspace::factory()->create();
    subscribe($workspace, $plan);

    expect(app(TemplateLibrary::class)->available($workspace))->not->toHaveKey('multichannel')
        ->and(fn () => app(TemplateLibrary::class)->create($workspace, 'multichannel', 'Nope'))->toThrow(InvalidArgumentException::class);
});

it('starts a campaign from the library on the campaigns page', function () {
    $workspace = actingInWorkspace();

    Livewire::test(ListCampaigns::class)
        ->callAction('templateLibrary', data: ['template' => 'agency-services', 'name' => 'Agency pitch'])
        ->assertRedirect();

    expect(Campaign::query()->where('workspace_id', $workspace->id)->where('name', 'Agency pitch')->sole()->steps()->count())->toBe(3);
});
