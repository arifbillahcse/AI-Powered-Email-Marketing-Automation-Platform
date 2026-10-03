<?php

namespace App\Filament\App\Resources\Campaigns\RelationManagers;

use App\Enums\CampaignLeadStatus;
use App\Models\CampaignLead;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of where each enrolled lead is in the sequence.
 */
class CampaignLeadsRelationManager extends RelationManager
{
    protected static string $relationship = 'campaignLeads';

    protected static ?string $title = 'Leads';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        // Access to the campaign itself was already authorized by the page.
        return ! $ownerRecord->getAttribute('is_template');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['lead', 'emailAccount']))
            ->columns([
                TextColumn::make('lead.email')
                    ->label('Lead')
                    ->description(fn (CampaignLead $record): ?string => $record->lead->fullName() ?: null)
                    ->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('steps_sent')->label('Emails sent'),
                TextColumn::make('emailAccount.email')->label('Mailbox')->placeholder('Not assigned yet'),
                TextColumn::make('next_send_at')
                    ->label('Next email')
                    ->since()
                    ->placeholder('—')
                    ->state(fn (CampaignLead $record) => $record->status === CampaignLeadStatus::Active ? $record->next_send_at : null),
                TextColumn::make('last_sent_at')->label('Last sent')->since()->placeholder('—'),
            ])
            ->defaultSort('id')
            ->filters([
                SelectFilter::make('status')->options(CampaignLeadStatus::class),
            ])
            ->emptyStateHeading('No leads enrolled')
            ->emptyStateDescription('Leads are enrolled when the campaign launches.');
    }
}
