<?php

namespace App\Filament\App\Resources\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Lead;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignCloner;
use App\Services\Campaigns\CampaignLauncher;
use App\Services\Campaigns\CampaignLaunchException;
use App\Services\Campaigns\CampaignMessageBuilder;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

class CampaignActions
{
    public static function preview(): Action
    {
        return Action::make('preview')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->authorize('view')
            ->modalHeading('Preview')
            ->modalDescription('Exactly what a lead receives, from the saved campaign. Save first to preview your latest edits.')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->fillForm(fn (Campaign $record): array => [
                'lead_id' => app(CampaignAudience::class)->query($record)->orderBy('leads.id')->value('leads.id'),
                'step_id' => $record->steps()->value('id'),
            ])
            ->schema([
                Select::make('lead_id')
                    ->label('Lead')
                    ->placeholder('Sample lead (Jane Doe)')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search, Campaign $record): array => app(CampaignAudience::class)->query($record)
                        ->where(fn ($query) => $query->whereLike('leads.email', "%{$search}%")->orWhereLike('leads.first_name', "%{$search}%"))
                        ->limit(20)
                        ->pluck('leads.email', 'leads.id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Lead::query()->whereKey($value)->value('email'))
                    ->live(),
                Select::make('step_id')
                    ->label('Email')
                    ->options(fn (Campaign $record): array => $record->steps()->get()
                        ->mapWithKeys(fn ($step): array => [$step->id => "Email {$step->position}".($step->subject ? ": {$step->subject}" : ' (same thread)')])
                        ->all())
                    ->live(),
                Html::make(fn (Get $get, Campaign $record): HtmlString => static::renderPreview($record, $get('lead_id'), $get('step_id'))),
            ]);
    }

    public static function renderPreview(Campaign $campaign, mixed $leadId, mixed $stepId): HtmlString
    {
        $step = $campaign->steps()->whereKey($stepId)->first() ?? $campaign->steps()->first();

        if (! $step) {
            return new HtmlString('<p>Add an email to the sequence and save to preview it.</p>');
        }

        $lead = filled($leadId)
            ? app(CampaignAudience::class)->query($campaign)->whereKey($leadId)->first()
            : null;

        $lead ??= new Lead([
            'email' => 'jane@example.com',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'company' => 'Example Inc',
            'title' => 'Head of Growth',
        ]);

        $mailbox = $campaign->emailAccounts()->orderBy('email_accounts.id')->first();
        $message = app(CampaignMessageBuilder::class)->build($campaign, $step, $lead, $mailbox, '#unsubscribe');

        return new HtmlString(view('filament.app.campaigns.preview', [
            'message' => $message,
            'lead' => $lead,
            'mailbox' => $mailbox,
        ])->render());
    }

    public static function launch(): Action
    {
        return Action::make('launch')
            ->label('Launch')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('success')
            ->authorize('update')
            ->visible(fn (Campaign $record): bool => $record->status === CampaignStatus::Draft && ! $record->is_template)
            ->requiresConfirmation()
            ->modalHeading('Launch campaign?')
            ->modalDescription(fn (Campaign $record): string => Number::format(app(CampaignAudience::class)->count($record)).' leads will be enrolled. Sending starts in the next send window.')
            ->modalSubmitActionLabel('Launch')
            ->action(function (Campaign $record): void {
                try {
                    $enrolled = app(CampaignLauncher::class)->launch($record, Auth::user());
                } catch (CampaignLaunchException $exception) {
                    Notification::make()
                        ->title('This campaign can\'t launch yet')
                        ->body(implode("\n", array_map(fn (string $problem): string => "• {$problem}", $exception->problems)))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->title('Campaign launched')->body(Number::format($enrolled).' leads enrolled.')->success()->send();
            });
    }

    public static function pause(): Action
    {
        return Action::make('pause')
            ->icon(Heroicon::OutlinedPause)
            ->color('warning')
            ->authorize('update')
            ->visible(fn (Campaign $record): bool => $record->status === CampaignStatus::Active)
            ->action(function (Campaign $record): void {
                app(CampaignLauncher::class)->pause($record);
                Notification::make()->title('Campaign paused')->success()->send();
            });
    }

    public static function resume(): Action
    {
        return Action::make('resume')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->authorize('update')
            ->visible(fn (Campaign $record): bool => $record->status === CampaignStatus::Paused)
            ->action(function (Campaign $record): void {
                app(CampaignLauncher::class)->resume($record);
                Notification::make()->title('Campaign resumed')->success()->send();
            });
    }

    public static function addNewLeads(): Action
    {
        return Action::make('addNewLeads')
            ->label('Add new leads')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->authorize('update')
            ->visible(fn (Campaign $record): bool => in_array($record->status, [CampaignStatus::Active, CampaignStatus::Paused], true))
            ->requiresConfirmation()
            ->modalDescription('Enrolls leads that joined the campaign\'s lists or segments since launch. Leads already in the campaign are not touched.')
            ->action(function (Campaign $record): void {
                $added = app(CampaignLauncher::class)->enroll($record, Auth::user());
                Notification::make()->title(Number::format($added).' new leads enrolled')->success()->send();
            });
    }

    public static function complete(): Action
    {
        return Action::make('complete')
            ->label('Stop campaign')
            ->icon(Heroicon::OutlinedStop)
            ->color('danger')
            ->authorize('update')
            ->visible(fn (Campaign $record): bool => in_array($record->status, [CampaignStatus::Active, CampaignStatus::Paused], true))
            ->requiresConfirmation()
            ->modalDescription('No more emails will be sent. A stopped campaign can\'t be restarted, but you can duplicate it.')
            ->action(function (Campaign $record): void {
                app(CampaignLauncher::class)->complete($record);
                Notification::make()->title('Campaign stopped')->success()->send();
            });
    }

    public static function duplicate(): Action
    {
        return Action::make('duplicate')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->visible(fn (): bool => Auth::user()->can('create', Campaign::class))
            ->fillForm(fn (Campaign $record): array => ['name' => "{$record->name} (copy)"])
            ->schema([TextInput::make('name')->required()->maxLength(150)])
            ->action(function (Campaign $record, array $data, $livewire): void {
                $copy = app(CampaignCloner::class)->clone($record, $data['name'], by: Auth::user());

                Notification::make()->title('Campaign duplicated')->success()->send();
                $livewire->redirect(CampaignResource::getUrl('edit', ['record' => $copy]));
            });
    }

    public static function saveAsTemplate(): Action
    {
        return Action::make('saveAsTemplate')
            ->label('Save as template')
            ->icon(Heroicon::OutlinedBookmark)
            ->color('gray')
            ->visible(fn (Campaign $record): bool => ! $record->is_template && Auth::user()->can('create', Campaign::class))
            ->fillForm(fn (Campaign $record): array => ['name' => "{$record->name} template"])
            ->schema([TextInput::make('name')->label('Template name')->required()->maxLength(150)])
            ->action(function (Campaign $record, array $data): void {
                app(CampaignCloner::class)->clone($record, $data['name'], asTemplate: true, by: Auth::user());
                Notification::make()->title('Saved as template')->body('Find it under the Templates tab.')->success()->send();
            });
    }
}
