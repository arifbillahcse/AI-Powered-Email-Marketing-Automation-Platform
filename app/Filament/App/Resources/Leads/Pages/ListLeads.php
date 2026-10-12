<?php

namespace App\Filament\App\Resources\Leads\Pages;

use App\Filament\App\Actions\SpreadsheetImportAction;
use App\Filament\App\Resources\Leads\LeadResource;
use App\Filament\Exports\LeadExporter;
use App\Filament\Imports\LeadImporter;
use App\Models\Lead;
use App\Services\Billing\Entitlements;
use App\Services\Leads\EmailVerification;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SpreadsheetImportAction::make()
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
            Action::make('verifyUnchecked')
                ->label('Verify emails')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('gray')
                ->visible(fn (): bool => Auth::user()->can('create', Lead::class))
                ->disabled(fn (): bool => ! app(Entitlements::class)->allowsModule(Filament::getTenant(), 'email_verification'))
                ->tooltip(fn (): ?string => app(Entitlements::class)->allowsModule(Filament::getTenant(), 'email_verification') ? null : 'Email verification isn\'t included in your plan.')
                ->requiresConfirmation()
                ->modalHeading('Verify emails')
                ->modalDescription(fn (): string => 'Checks the '.Number::format($this->unchecked()->count()).' leads that haven\'t been checked yet. To re-check others, select them and use "Verify emails" in the bulk actions.')
                ->action(function (): void {
                    $count = app(EmailVerification::class)->queue(Filament::getTenant()->getKey(), $this->unchecked(), Auth::user());

                    Notification::make()->title('Verifying '.Number::format($count).' '.str('lead')->plural($count))->body('You\'ll get a notification with the results.')->success()->send();
                }),
            CreateAction::make()->label('Add lead'),
        ];
    }

    /**
     * @return Builder<Lead>
     */
    protected function unchecked(): Builder
    {
        return Lead::query()->where('workspace_id', Filament::getTenant()->getKey())->whereNull('verification_status');
    }
}
