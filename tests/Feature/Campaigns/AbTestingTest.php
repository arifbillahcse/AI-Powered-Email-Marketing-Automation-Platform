<?php

use App\Enums\WorkspaceRole;
use App\Filament\App\Resources\Campaigns\Pages\EditCampaign;
use App\Jobs\SendCampaignEmail;
use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Campaigns\AbTesting;
use App\Services\Campaigns\CampaignCloner;
use Livewire\Livewire;

/**
 * A ready campaign whose first email has a version B.
 *
 * @return array<string, mixed>
 */
function abCampaign(int $leads, array $campaign = []): array
{
    $setup = readyCampaign($leads, $campaign, mailbox: ['daily_limit' => 200]);
    $step = $setup['campaign']->steps()->where('position', 1)->sole();
    $step->update(['subject' => 'Version A subject']);
    $step->variants()->create(['subject' => 'Version B subject', 'body' => '<p>Hi {{first_name|there}}, version B.</p>']);

    return [...$setup, 'step' => $step->refresh()];
}

function sendFirstEmails(array $setup): void
{
    CampaignLead::query()->each(fn (CampaignLead $lead) => SendCampaignEmail::dispatchSync($lead->id, $setup['mailbox']->id, 1));
}

it('labels versions and splits leads evenly and stably', function () {
    ['step' => $step] = abCampaign(1);
    $testing = app(AbTesting::class);

    expect(array_keys($testing->versions($step)))->toBe(['A', 'B'])
        ->and($step->variants()->sole()->label)->toBe('B');

    $picks = collect(range(1, 400))->map(fn (int $id): string => $testing->pick($step, $id)['label'])->countBy();

    expect($picks['A'])->toBeGreaterThan(150)->and($picks['B'])->toBeGreaterThan(150)
        ->and($testing->pick($step, 7)['label'])->toBe($testing->pick($step, 7)['label']);
});

it('sends each lead its version and records which one', function () {
    $transport = fakeTransport();
    $setup = abCampaign(20);
    sendFirstEmails($setup);

    $messages = EmailMessage::query()->get();

    expect($messages->pluck('variant')->unique()->sort()->values()->all())->toBe(['A', 'B']);

    foreach ($messages as $message) {
        expect($message->subject)->toBe($message->variant === 'B' ? 'Version B subject' : 'Version A subject');
    }

    expect(collect($transport->sent)->map(fn ($email) => $email->getSubject())->unique()->sort()->values()->all())
        ->toBe(['Version A subject', 'Version B subject']);
});

it('keeps the version-B subject on same-thread follow-ups', function () {
    fakeTransport();
    $setup = abCampaign(20);
    sendFirstEmails($setup);
    $leadWithB = EmailMessage::query()->where('variant', 'B')->firstOrFail()->campaign_lead_id;

    $this->travel(4)->days();
    SendCampaignEmail::dispatchSync($leadWithB, $setup['mailbox']->id, 2);

    expect(EmailMessage::query()->where('campaign_lead_id', $leadWithB)->where('step_position', 2)->value('subject'))->toBe('Re: Version B subject');
});

it('picks the winner once every version has enough sends', function () {
    fakeTransport();
    $owner = User::factory()->create();
    $setup = abCampaign(40, ['ab_min_sends' => 10, 'ab_metric' => 'reply']);
    $setup['workspace']->addMember($owner, WorkspaceRole::Owner);
    sendFirstEmails($setup);
    $testing = app(AbTesting::class);

    // B gets replies, A doesn't.
    EmailMessage::query()->where('variant', 'B')->take(3)->get()
        ->each(fn (EmailMessage $message) => $message->forceFill(['replied_at' => now()])->save());

    $setup['campaign']->update(['ab_min_sends' => 1000]);
    expect($testing->decide($setup['campaign']->refresh()))->toBe(0); // not enough sends yet

    $setup['campaign']->update(['ab_min_sends' => 10]);
    $this->artisan('campaigns:ab-test')->expectsOutputToContain('Picked 1 A/B test winners.')->assertSuccessful();

    $step = $setup['step']->refresh();

    expect($step->ab_winner)->toBe('B')
        ->and($testing->pick($step, 1)['label'])->toBe('B')
        ->and($testing->pick($step, 2)['label'])->toBe('B')
        ->and($owner->notifications()->sole()->data['title'])->toBe('A/B test decided: version B wins');
});

it('shows A/B results on the campaign', function () {
    fakeTransport();
    $setup = abCampaign(10);
    sendFirstEmails($setup);
    actingInWorkspace(workspace: $setup['workspace']);

    Livewire::test(EditCampaign::class, ['record' => $setup['campaign']->getRouteKey()])
        ->mountAction('abResults')
        ->assertMountedActionModalSee('Version A')
        ->assertMountedActionModalSee('Version B')
        ->assertMountedActionModalSee('Reply rate');
});

it('copies versions when a campaign is duplicated', function () {
    ['campaign' => $campaign] = abCampaign(1);
    $campaign->steps()->first()->update(['ab_winner' => 'B']);

    $copy = app(CampaignCloner::class)->clone($campaign, 'Copy');
    $step = $copy->steps()->first();

    expect($step->variants()->pluck('label')->all())->toBe(['B'])
        ->and($step->ab_winner)->toBeNull();
});
