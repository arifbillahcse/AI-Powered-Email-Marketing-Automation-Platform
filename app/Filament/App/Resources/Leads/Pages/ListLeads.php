<?php

namespace App\Filament\App\Resources\Leads\Pages;

use App\Filament\App\Resources\Leads\LeadResource;
use App\Filament\Exports\LeadExporter;
use App\Filament\Imports\LeadImporter;
use App\Models\Lead;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ImportAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()
                ->label('Import CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->importer(LeadImporter::class)
                // Set server-side; the form can't change it.
                ->options(fn (): array => ['workspace_id' => Filament::getTenant()->getKey()])
                ->chunkSize(100)
                ->maxRows(200_000)
                ->visible(fn (): bool => Auth::user()->can('create', Lead::class)),
            ExportAction::make()
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->exporter(LeadExporter::class),
            CreateAction::make()->label('Add lead'),
        ];
    }
}
