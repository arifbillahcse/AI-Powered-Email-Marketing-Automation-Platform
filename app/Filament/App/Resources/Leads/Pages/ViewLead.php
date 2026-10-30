<?php

namespace App\Filament\App\Resources\Leads\Pages;

use App\Filament\App\Actions\AddLeadNoteAction;
use App\Filament\App\Actions\LeadFollowUpDoneAction;
use App\Filament\App\Actions\LeadPrivacyActions;
use App\Filament\App\Actions\SendLeadEmailAction;
use App\Filament\App\Actions\SetLeadFollowUpAction;
use App\Filament\App\Actions\TagLeadAction;
use App\Filament\App\Actions\ToggleLeadStarAction;
use App\Filament\App\Resources\Leads\LeadResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SendLeadEmailAction::make(),
            AddLeadNoteAction::make(),
            ActionGroup::make([
                TagLeadAction::make(),
                ToggleLeadStarAction::make(),
                SetLeadFollowUpAction::make(),
                LeadFollowUpDoneAction::make(),
            ])
                ->label('Organize')
                ->icon(Heroicon::OutlinedTag)
                ->button()
                ->color('gray'),
            EditAction::make(),
            LeadPrivacyActions::group(),
        ];
    }
}
