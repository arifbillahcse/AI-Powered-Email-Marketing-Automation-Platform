<?php

namespace App\Filament\App\Resources\Campaigns\Pages;

use App\Filament\App\Resources\Campaigns\CampaignActions;
use App\Filament\App\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    public function getSubheading(): ?string
    {
        /** @var Campaign $campaign */
        $campaign = $this->record;

        return $campaign->is_template
            ? 'Template. Use it from the Campaigns list to start a new campaign.'
            : 'Status: '.$campaign->status->getLabel();
    }

    protected function getHeaderActions(): array
    {
        return [
            CampaignActions::preview(),
            CampaignActions::launch(),
            CampaignActions::pause(),
            CampaignActions::resume(),
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
