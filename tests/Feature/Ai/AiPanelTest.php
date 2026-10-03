<?php

use App\Enums\AiContentType;
use App\Enums\AiGenerationStatus;
use App\Enums\AiProvider;
use App\Enums\WorkspaceRole;
use App\Filament\App\Pages\AiSettings;
use App\Filament\App\Resources\AiGenerations\Pages\ListAiGenerations;
use App\Filament\App\Resources\AiPromptTemplates\Pages\ManageAiPromptTemplates;
use App\Filament\App\Resources\Campaigns\Pages\EditCampaign;
use App\Models\AiGeneration;
use App\Models\AiPromptTemplate;
use App\Models\AiSetting;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * A campaign in the workspace with $leads leads in its audience, and one
 * "needs review" generation per lead.
 *
 * @return array{campaign: Campaign, generations: Collection<int, AiGeneration>}
 */
function campaignAwaitingReview(Workspace $workspace, int $leads = 2): array
{
    $list = LeadList::factory()->for($workspace)->create();
    $campaign = Campaign::factory()->for($workspace)->withSteps()->create();
    $campaign->leadLists()->attach($list);
    $stepId = $campaign->steps()->where('position', 1)->value('id');

    $generations = Lead::factory()->for($workspace)->count($leads)->create()->map(function (Lead $lead) use ($list, $campaign, $stepId): AiGeneration {
        $lead->lists()->attach($list);

        $generation = new AiGeneration;
        $generation->forceFill([
            'workspace_id' => $campaign->workspace_id,
            'campaign_id' => $campaign->id,
            'campaign_step_id' => $stepId,
            'lead_id' => $lead->id,
            'type' => AiContentType::FirstLine,
            'status' => AiGenerationStatus::Ready,
            'output' => "Loved {$lead->email}'s site.",
        ])->save();

        return $generation;
    });

    return ['campaign' => $campaign, 'generations' => $generations];
}

it('shows only this workspace\'s content waiting for review', function () {
    // Before signing in: Filament attaches records created afterwards to the current workspace.
    ['generations' => $theirs] = campaignAwaitingReview(Workspace::factory()->create());
    $workspace = actingInWorkspace();
    ['generations' => $mine] = campaignAwaitingReview($workspace);

    Livewire::test(ListAiGenerations::class)
        ->assertCanSeeTableRecords($mine)
        ->assertCanNotSeeTableRecords($theirs)
        ->assertSee('2 needs review');
});

it('approves, edits, rejects and regenerates from the review queue', function () {
    Queue::fake();
    $workspace = actingInWorkspace();
    ['generations' => $generations] = campaignAwaitingReview($workspace, 4);
    [$approve, $edit, $reject, $regenerate] = $generations->all();

    Livewire::test(ListAiGenerations::class)
        ->callAction(TestAction::make('approve')->table($approve))
        ->callAction(TestAction::make('edit')->table($edit), data: ['output' => 'Hand-written line.'])
        ->assertHasNoFormErrors()
        ->callAction(TestAction::make('reject')->table($reject))
        ->callAction(TestAction::make('regenerate')->table($regenerate));

    expect($approve->refresh()->status)->toBe(AiGenerationStatus::Approved)
        ->and($edit->refresh())->status->toBe(AiGenerationStatus::Approved)->output->toBe('Hand-written line.')->edited->toBeTrue()
        ->and($reject->refresh()->status)->toBe(AiGenerationStatus::Rejected)
        ->and($regenerate->refresh()->status)->toBe(AiGenerationStatus::Pending);
});

it('approves a selection in bulk', function () {
    $workspace = actingInWorkspace();
    ['generations' => $generations] = campaignAwaitingReview($workspace, 3);

    Livewire::test(ListAiGenerations::class)
        ->selectTableRecords($generations->take(2))
        ->callAction(TestAction::make('approveSelected')->table()->bulk())
        ->assertNotified('2 items approved');

    expect(AiGeneration::query()->where('status', AiGenerationStatus::Approved->value)->count())->toBe(2);
});

it('lets clients read the review queue but not change it', function () {
    $workspace = actingInWorkspace(role: WorkspaceRole::Client);
    ['generations' => $generations] = campaignAwaitingReview($workspace, 1);

    Livewire::test(ListAiGenerations::class)
        ->assertCanSeeTableRecords($generations)
        ->assertActionHidden(TestAction::make('approve')->table($generations[0]))
        ->assertActionHidden(TestAction::make('edit')->table($generations[0]));
});

it('starts AI personalization from a campaign', function () {
    Queue::fake();
    $workspace = actingInWorkspace();
    ['campaign' => $campaign] = campaignAwaitingReview($workspace, 2);
    AiGeneration::query()->delete();
    $template = AiPromptTemplate::query()->forceCreate([
        'workspace_id' => $workspace->id,
        'name' => 'Agency intro',
        'type' => AiContentType::FirstLine,
        'instructions' => 'We build WordPress sites.',
    ]);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callAction('aiPersonalize', data: [
            'step_id' => $campaign->steps()->where('position', 1)->value('id'),
            'type' => AiContentType::FirstLine->value,
            'template_id' => $template->id,
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Writing for 2 leads');

    expect(AiGeneration::query()->where('ai_prompt_template_id', $template->id)->count())->toBe(2);
});

it('manages AI prompts', function () {
    $workspace = actingInWorkspace();

    Livewire::test(ManageAiPromptTemplates::class)
        ->callAction('create', data: [
            'name' => 'Subject lines',
            'type' => AiContentType::SubjectLine->value,
            'instructions' => 'Short and curious.',
            'tone' => 'direct',
            'length' => 'short',
            'language' => 'English',
        ])
        ->assertHasNoFormErrors();

    expect(AiPromptTemplate::sole())
        ->workspace_id->toBe($workspace->id)
        ->type->toBe(AiContentType::SubjectLine)
        ->tone->toBe('direct');
});

it('saves an own API key encrypted and never shows it again', function () {
    $workspace = actingInWorkspace();

    Livewire::test(AiSettings::class)
        ->fillForm([
            'provider' => AiProvider::Anthropic->value,
            'api_key' => 'sk-ant-own-key',
            'model' => 'claude-sonnet-5-5',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('data.api_key', null)
        ->assertDontSee('sk-ant-own-key');

    $setting = AiSetting::for($workspace);

    expect($setting->provider)->toBe(AiProvider::Anthropic)
        ->and($setting->model)->toBe('claude-sonnet-5-5')
        ->and($setting->api_key)->toBe('sk-ant-own-key')
        ->and(DB::table('ai_settings')->value('api_key'))->not->toContain('sk-ant-own-key');

    // Saving again without a key keeps the saved one.
    Livewire::test(AiSettings::class)
        ->assertSet('data.api_key', null)
        ->fillForm(['model' => 'claude-haiku-4-5'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(AiSetting::for($workspace))->api_key->toBe('sk-ant-own-key')->model->toBe('claude-haiku-4-5');
});

it('requires a key when switching to an own-key provider', function () {
    actingInWorkspace();

    Livewire::test(AiSettings::class)
        ->fillForm(['provider' => AiProvider::OpenAi->value, 'model' => 'gpt-test'])
        ->call('save')
        ->assertHasFormErrors(['api_key' => 'required']);
});

it('tests the AI connection', function () {
    actingInWorkspace();
    fakeAi('OK');

    Livewire::test(AiSettings::class)
        ->call('test')
        ->assertNotified('AI is connected');
});

it('keeps AI settings to owners and admins', function () {
    $user = User::factory()->create();
    $workspace = actingInWorkspace($user, WorkspaceRole::Member);

    $this->get("/app/{$workspace->slug}/ai-settings")->assertForbidden();
});
