<?php

namespace App\Filament\App\Resources\Integrations\Pages;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Models\Integration;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageIntegrations extends ManageRecords
{
    protected static string $resource = IntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add integration')
                ->using(function (array $data): Integration {
                    $integration = new Integration;
                    $integration->workspace_id = Filament::getTenant()->getKey();

                    return IntegrationResource::save($integration, $data);
                }),
        ];
    }
}
