<?php

use App\Enums\SuppressionReason;
use App\Enums\WorkspaceRole;
use App\Filament\App\Resources\Leads\Pages\ViewLead;
use App\Jobs\SendCampaignEmail;
use App\Models\CampaignLead;
use App\Models\EmailMessage;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Suppression;
use App\Services\Compliance\LeadDataExporter;
use App\Services\Leads\SuppressionList;
use Livewire\Livewire;

/**
 * A lead who got a campaign email.
 */
function contactedLead(): array
{
    fakeTransport();
    $setup = readyCampaign(1);
    $lead = $setup['leads'][0];
    $lead->update(['custom_fields' => ['team_size' => '12']]);
    SendCampaignEmail::dispatchSync(CampaignLead::sole()->id, $setup['mailbox']->id, 1);

    return [...$setup, 'lead' => $lead->refresh()];
}

it('exports everything stored about a lead', function () {
    ['lead' => $lead, 'campaign' => $campaign] = contactedLead();

    $data = app(LeadDataExporter::class)->export($lead);

    expect($data['lead']['email'])->toBe($lead->email)
        ->and($data['lead']['custom_fields'])->toBe(['team_size' => '12'])
        ->and($data['campaigns'][0]['campaign'])->toBe($campaign->name)
        ->and($data['emails_sent'])->toHaveCount(1)
        ->and($data['emails_sent'][0]['subject'])->toBe(EmailMessage::sole()->subject)
        ->and(collect($data['activity'])->pluck('type'))->toContain('email_sent')
        ->and($data['suppression'])->toBe([]);
});

it('downloads the export from the lead page', function () {
    ['workspace' => $workspace, 'lead' => $lead] = contactedLead();
    actingInWorkspace(workspace: $workspace);

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->callAction('exportPersonalData')
        ->assertFileDownloaded();
});

it('erases a lead but keeps the address suppressed', function () {
    ['workspace' => $workspace, 'lead' => $lead] = contactedLead();
    actingInWorkspace(workspace: $workspace, role: WorkspaceRole::Admin);

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->callAction('erasePersonalData')
        ->assertNotified("{$lead->email} erased")
        ->assertRedirect();

    expect(Lead::query()->whereKey($lead->id)->exists())->toBeFalse()
        ->and(EmailMessage::count())->toBe(0)
        ->and(CampaignLead::count())->toBe(0)
        ->and(LeadActivity::query()->where('lead_id', $lead->id)->count())->toBe(0)
        ->and(Suppression::query()->where('value', $lead->email)->sole()->reason)->toBe(SuppressionReason::Erased);

    // The address stays on the do-not-contact list.
    expect(app(SuppressionList::class)->isSuppressed($workspace->id, $lead->email))->toBeTrue();
});

it('lets only owners and admins erase', function () {
    ['workspace' => $workspace, 'lead' => $lead] = contactedLead();
    actingInWorkspace(workspace: $workspace, role: WorkspaceRole::Member);

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->assertActionHidden('erasePersonalData')
        ->assertActionVisible('exportPersonalData');
});
