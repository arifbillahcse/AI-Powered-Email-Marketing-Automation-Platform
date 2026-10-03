<?php

use App\Enums\AiContentType;
use App\Enums\AiGenerationStatus;
use App\Enums\AiProvider;
use App\Enums\WorkspaceRole;
use App\Jobs\DispatchAiGenerations;
use App\Jobs\GenerateAiContent;
use App\Jobs\SendCampaignEmail;
use App\Models\AiGeneration;
use App\Models\AiSetting;
use App\Models\AiUsage;
use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Models\EmailMessage;
use App\Models\Lead;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\AiGenerationService;
use App\Services\Ai\PromptBuilder;
use App\Services\Ai\TextGeneratorFactory;
use App\Services\Campaigns\CampaignLauncher;
use App\Services\Campaigns\CampaignMessageBuilder;
use App\Services\Leads\SuppressionList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * An AI generation for a lead in a campaign step, in the given state.
 */
function makeGeneration(Campaign $campaign, Lead $lead, AiContentType $type, string $output, AiGenerationStatus $status = AiGenerationStatus::Approved, int $position = 1): AiGeneration
{
    $generation = new AiGeneration;
    $generation->forceFill([
        'workspace_id' => $campaign->workspace_id,
        'campaign_id' => $campaign->id,
        'campaign_step_id' => $campaign->steps()->where('position', $position)->value('id'),
        'lead_id' => $lead->id,
        'type' => $type,
        'status' => $status,
        'output' => $output,
    ])->save();

    return $generation;
}

function aiService(): AiGenerationService
{
    return app(AiGenerationService::class);
}

it('writes, reviews and sends personalized emails to 500 leads', function () {
    $transport = fakeTransport();
    $generator = fakeAi(fn (string $system, string $user): string => 'Personal line '.substr(md5($user), 0, 10).'.');
    ['workspace' => $workspace, 'campaign' => $campaign, 'mailbox' => $mailbox] = readyCampaign(500);
    $owner = User::factory()->create();
    $workspace->addMember($owner, WorkspaceRole::Owner);

    $step = $campaign->steps()->where('position', 1)->first();
    $step->update(['body' => '<p>Hi {{first_name|there}},</p><p>{{ai_first_line}}</p>']);

    // 1. Generate (the sync queue runs the whole batch right here).
    expect(aiService()->queue($campaign, $step, AiContentType::FirstLine, null, $owner))->toBe(500)
        ->and(AiGeneration::query()->where('status', AiGenerationStatus::Ready->value)->count())->toBe(500)
        ->and($generator->calls)->toHaveCount(500)
        ->and(AiUsage::query()->where('provider', 'platform')->sum('input_tokens'))->toEqual(500 * 100)
        ->and($owner->notifications()->count())->toBe(1);

    // 2. Unapproved content is never sent: the leads wait.
    SendCampaignEmail::dispatchSync(CampaignLead::query()->first()->id, $mailbox->id, 1);
    expect($transport->sent)->toBe([]);

    // 3. Approve everything, then send.
    expect(aiService()->approveMany(AiGeneration::query(), $workspace->id, $owner))->toBe(500);

    CampaignLead::query()->each(fn (CampaignLead $campaignLead) => SendCampaignEmail::dispatchSync($campaignLead->id, $mailbox->id, 1));

    $generation = AiGeneration::query()->with('lead')->first();
    $email = collect($transport->sent)->first(fn ($email) => $email->getTo()[0]->getAddress() === $generation->lead->email);

    expect($transport->sent)->toHaveCount(500)
        ->and(EmailMessage::query()->whereNotNull('sent_at')->count())->toBe(500)
        ->and($email->getHtmlBody())->toContain('<p>'.e($generation->output).'</p>');
});

it('queues one generation per audience lead and skips suppressed leads', function () {
    Queue::fake();
    ['workspace' => $workspace, 'campaign' => $campaign, 'leads' => $leads] = readyCampaign(3, launch: false);
    app(SuppressionList::class)->add($workspace->id, $leads[0]->email);
    $step = $campaign->steps()->first();

    expect(aiService()->queue($campaign, $step, AiContentType::FirstLine, null))->toBe(2)
        ->and(aiService()->queue($campaign, $step, AiContentType::FirstLine, null))->toBe(0)
        ->and(aiService()->queue($campaign, $step, AiContentType::SubjectLine, null))->toBe(2)
        ->and(AiGeneration::query()->where('lead_id', $leads[0]->id)->exists())->toBeFalse();

    Queue::assertPushed(DispatchAiGenerations::class, fn (DispatchAiGenerations $job) => $job->workspaceId === $workspace->id);
});

it('rewrites unapproved content on request but never approved content', function () {
    Queue::fake();
    ['campaign' => $campaign, 'leads' => $leads] = readyCampaign(2, launch: false);
    $approved = makeGeneration($campaign, $leads[0], AiContentType::FirstLine, 'Keep me');
    $ready = makeGeneration($campaign, $leads[1], AiContentType::FirstLine, 'Redo me', AiGenerationStatus::Ready);

    expect(aiService()->queue($campaign, $campaign->steps()->first(), AiContentType::FirstLine, null, redo: true))->toBe(1)
        ->and($approved->refresh()->status)->toBe(AiGenerationStatus::Approved)
        ->and($ready->refresh()->status)->toBe(AiGenerationStatus::Pending);
});

it('approves, edits, rejects and regenerates', function () {
    Queue::fake();
    ['campaign' => $campaign, 'leads' => $leads] = readyCampaign(1, launch: false);
    $user = User::factory()->create();
    $generation = makeGeneration($campaign, $leads[0], AiContentType::FirstLine, 'Draft', AiGenerationStatus::Ready);

    aiService()->approve($generation, $user);
    expect($generation->refresh()->status)->toBe(AiGenerationStatus::Approved)
        ->and($generation->approved_by)->toBe($user->id);

    aiService()->reject($generation);
    expect($generation->refresh()->status)->toBe(AiGenerationStatus::Rejected);

    aiService()->edit($generation, "  My own line.\r\n", $user);
    expect($generation->refresh())
        ->status->toBe(AiGenerationStatus::Approved)
        ->output->toBe('My own line.')
        ->edited->toBeTrue();

    aiService()->regenerate($generation, $user);
    expect($generation->refresh()->status)->toBe(AiGenerationStatus::Pending);
    Queue::assertPushed(DispatchAiGenerations::class);
});

it('fills emails with approved content only', function () {
    ['campaign' => $campaign, 'leads' => $leads, 'mailbox' => $mailbox] = readyCampaign(1, launch: false);
    $lead = $leads[0];
    $campaign->steps()->where('position', 1)->update([
        'subject' => '{{ai_subject|Hello}}',
        'body' => '<p>{{ai_email}}</p>',
    ]);
    $campaign->load('workspace');
    $builder = app(CampaignMessageBuilder::class);
    $first = $campaign->steps()->where('position', 1)->first();
    $second = $campaign->steps()->where('position', 2)->first();

    $subject = makeGeneration($campaign, $lead, AiContentType::SubjectLine, 'quick idea', AiGenerationStatus::Ready);
    makeGeneration($campaign, $lead, AiContentType::EmailBody, "Hi <Rahim>,\n\nSecond paragraph.");

    $draft = $builder->build($campaign, $first, $lead, $mailbox, 'https://example.test/u/x');
    expect($draft['subject'])->toBe('Hello')
        ->and($draft['html'])->toContain("<p>Hi &lt;Rahim&gt;,</p>\n<p>Second paragraph.</p>")
        ->and($draft['html'])->not->toContain('<p><p>')
        ->and($draft['text'])->toContain("Hi <Rahim>,\n\nSecond paragraph.")
        ->and($draft['missing'])->toBe([]);

    aiService()->approve($subject, User::factory()->create());

    expect($builder->build($campaign, $first, $lead, $mailbox, 'https://example.test/u/x')['subject'])->toBe('quick idea')
        ->and($builder->build($campaign, $second, $lead, $mailbox, 'https://example.test/u/x')['subject'])->toBe('Re: quick idea');
});

it('holds a lead until its AI content is approved', function () {
    $transport = fakeTransport();
    ['campaign' => $campaign, 'leads' => $leads, 'mailbox' => $mailbox] = readyCampaign(1);
    $campaign->steps()->where('position', 1)->update(['body' => '<p>{{ai_first_line}}</p>']);
    $generation = makeGeneration($campaign, $leads[0], AiContentType::FirstLine, 'Loved the new site.', AiGenerationStatus::Ready);
    $campaignLead = CampaignLead::sole();

    SendCampaignEmail::dispatchSync($campaignLead->id, $mailbox->id, 1);

    expect($transport->sent)->toBe([])
        ->and(EmailMessage::query()->count())->toBe(0)
        ->and($campaignLead->refresh()->next_send_at->isAfter(now()->addMinutes(59)))->toBeTrue();

    aiService()->approve($generation, User::factory()->create());
    SendCampaignEmail::dispatchSync($campaignLead->id, $mailbox->id, 1);

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]->getHtmlBody())->toContain('Loved the new site.');
});

it('blocks launch until AI content without a fallback is approved', function () {
    ['campaign' => $campaign, 'leads' => $leads] = readyCampaign(2, launch: false);
    $step = $campaign->steps()->where('position', 1)->first();
    $step->update(['body' => '<p>{{ai_first_line}}</p>']);
    $launcher = app(CampaignLauncher::class);

    expect($launcher->problems($campaign))->toContain(
        'Email 1 uses {{ai_first_line}}, but 2 leads have no approved AI content for it yet. Generate and approve it with "AI personalize", or add a fallback like {{ai_first_line|...}}.'
    );

    makeGeneration($campaign, $leads[0], AiContentType::FirstLine, 'One');
    expect(implode(' ', $launcher->problems($campaign)))->toContain('but 1 lead has no approved');

    $step->update(['body' => '<p>{{ai_first_line|Hope you are well.}}</p>']);
    expect($launcher->problems($campaign))->toBe([]);
});

it('marks refusals and configuration errors as failed', function () {
    ['campaign' => $campaign, 'leads' => $leads] = readyCampaign(2, launch: false);
    $refused = makeGeneration($campaign, $leads[0], AiContentType::FirstLine, '', AiGenerationStatus::Pending);
    $broken = makeGeneration($campaign, $leads[1], AiContentType::FirstLine, '', AiGenerationStatus::Pending);

    fakeAi(refuse: true);
    GenerateAiContent::dispatchSync($refused->id);

    fakeAi(new AiException('The Anthropic API key was rejected. Check it in AI settings.'));
    GenerateAiContent::dispatchSync($broken->id);

    expect($refused->refresh())->status->toBe(AiGenerationStatus::Failed)
        ->error->toContain('declined')
        ->and($broken->refresh())->status->toBe(AiGenerationStatus::Failed)
        ->error->toBe('The Anthropic API key was rejected. Check it in AI settings.');
});

it('leaves rate-limited generations pending for the queue to retry', function () {
    ['campaign' => $campaign, 'leads' => $leads] = readyCampaign(1, launch: false);
    $generation = makeGeneration($campaign, $leads[0], AiContentType::FirstLine, '', AiGenerationStatus::Pending);
    fakeAi(new AiException('Anthropic rate limit reached. Retrying shortly.', retryable: true));

    expect(fn () => (new GenerateAiContent($generation->id))->handle(app(TextGeneratorFactory::class), app(PromptBuilder::class)))
        ->toThrow(AiException::class);

    expect($generation->refresh()->status)->toBe(AiGenerationStatus::Pending);
});

it('stops at the monthly allowance of included credits', function () {
    config(['outreach.ai.platform_api_key' => 'sk-ant-platform', 'outreach.ai.platform_monthly_tokens' => 1000]);
    ['workspace' => $workspace, 'campaign' => $campaign, 'leads' => $leads] = readyCampaign(1, launch: false);
    $generation = makeGeneration($campaign, $leads[0], AiContentType::FirstLine, '', AiGenerationStatus::Pending);

    // Own-key usage doesn't count against the allowance; platform usage does.
    foreach (['anthropic' => 5000, 'platform' => 1000] as $provider => $tokens) {
        (new AiUsage)->forceFill(['workspace_id' => $workspace->id, 'provider' => $provider, 'model' => 'claude-opus-5-5', 'input_tokens' => $tokens, 'created_at' => now()])->save();
    }

    GenerateAiContent::dispatchSync($generation->id);

    expect($generation->refresh())->status->toBe(AiGenerationStatus::Failed)
        ->error->toContain('included AI credits are used up');
});

it('keeps API keys encrypted and out of arrays', function () {
    ['workspace' => $workspace] = readyCampaign(1, launch: false);
    $setting = AiSetting::for($workspace);
    $setting->provider = AiProvider::Anthropic;
    $setting->api_key = 'sk-ant-secret';
    $setting->save();

    expect(DB::table('ai_settings')->value('api_key'))->not->toContain('sk-ant-secret')
        ->and(AiSetting::for($workspace)->api_key)->toBe('sk-ant-secret')
        ->and(AiSetting::for($workspace)->toArray())->not->toHaveKey('api_key');
});
