<?php

namespace App\Filament\App\Resources\Campaigns\Pages;

use App\Enums\CampaignLeadStatus;
use App\Enums\CampaignStatus;
use App\Filament\App\Resources\Campaigns\CampaignActions;
use App\Filament\App\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use App\Models\EmailMessage;
use App\Services\Sending\SendingDiagnostics;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Number;

class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    public function getSubheading(): ?string
    {
        /** @var Campaign $campaign */
        $campaign = $this->record;

        if ($campaign->is_template) {
            return 'Template. Use it from the Campaigns list to start a new campaign.';
        }

        $stats = EmailMessage::query()
            ->where('campaign_id', $campaign->getKey())
            ->whereNotNull('sent_at')
            ->selectRaw('count(*) as sent, count(opened_at) as opened, count(clicked_at) as clicked, count(bounced_at) as bounced')
            ->first();

        $unsubscribed = $campaign->campaignLeads()->where('status', CampaignLeadStatus::Unsubscribed->value)->count();
        $replied = $campaign->campaignLeads()->whereNotNull('replied_at')->count();

        // An active campaign that can't send says why, right at the top.
        $blocked = $campaign->status === CampaignStatus::Active
            ? app(SendingDiagnostics::class)->headline($campaign)
            : null;

        return collect([
            $blocked ? "⚠ Not sending right now: {$blocked}" : null,
            'Status: '.$campaign->status->getLabel(),
            Number::format((int) $stats?->sent).' sent',
            $campaign->track_opens ? Number::format((int) $stats?->opened).' opened' : null,
            $campaign->track_clicks ? Number::format((int) $stats?->clicked).' clicked' : null,
            Number::format($replied).' replied',
            Number::format((int) $stats?->bounced).' bounced',
            Number::format($unsubscribed).' unsubscribed',
        ])->filter()->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            CampaignActions::preview(),
            CampaignActions::sendingStatus(),
            CampaignActions::launch(),
            CampaignActions::pause(),
            CampaignActions::resume(),
            CampaignActions::aiPersonalize(),
            ActionGroup::make([
                CampaignActions::addNewLeads(),
                CampaignActions::duplicate(),
                CampaignActions::saveAsTemplate(),
                CampaignActions::complete(),
                DeleteAction::make(),
            ]),
        ];
    }

    protected function getFormActions(): array
    {
        /** @var Campaign $campaign */
        $campaign = $this->record;

        return $campaign->isEditable() ? parent::getFormActions() : [];
    }
}
