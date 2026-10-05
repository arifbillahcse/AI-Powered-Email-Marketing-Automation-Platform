<?php

namespace App\Filament\App\Resources\InboxThreads\Pages;

use App\Enums\LeadStatus;
use App\Filament\App\Resources\InboxThreads\InboxThreadResource;
use App\Filament\App\Resources\Leads\LeadResource;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Lead;
use App\Services\Inbox\InboxReplier;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * One conversation: the campaign emails (or the one-off email) we sent, the
 * lead's replies and our answers, oldest first. Bodies are plain text, escaped by Blade.
 *
 * @property InboxThread $record
 */
class ViewInboxThread extends ViewRecord
{
    protected static string $resource = InboxThreadResource::class;

    protected string $view = 'filament.app.resources.inbox-threads.view-thread';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->unread && $this->canWrite()) {
            $this->record->forceFill(['unread' => false])->save();
        }
    }

    public function getTitle(): string
    {
        return $this->record->subject ?? 'Conversation';
    }

    public function getSubheading(): ?string
    {
        $lead = $this->lead();

        return collect([
            trim($lead->fullName().' <'.$lead->email.'>'),
            $lead->company,
            'Label: '.$lead->status->getLabel(),
        ])->filter()->implode(' · ');
    }

    /**
     * @return Collection<int, array{direction: string, from: string, at: ?CarbonInterface, subject: ?string, body: ?string, badge: ?string, badge_color: string}>
     */
    public function timeline(): Collection
    {
        $mailboxes = EmailAccount::query()->where('workspace_id', $this->record->workspace_id)->pluck('email', 'id');

        $sent = EmailMessage::query()
            ->where('campaign_lead_id', $this->record->campaign_lead_id ?? 0) // none for a one-off email
            ->whereNotNull('sent_at')
            ->get(['id', 'email_account_id', 'step_position', 'subject', 'sent_at', 'bounced_at'])
            ->map(fn (EmailMessage $message): array => [
                'direction' => 'campaign',
                'from' => $mailboxes[$message->email_account_id] ?? 'Campaign',
                'at' => $message->sent_at,
                'subject' => $message->subject,
                'body' => null,
                'badge' => $message->bounced_at ? 'Bounced' : "Campaign email {$message->step_position}",
                'badge_color' => $message->bounced_at ? 'danger' : 'gray',
            ]);

        $messages = InboxMessage::query()
            ->where('inbox_thread_id', $this->record->getKey())
            ->get()
            ->map(fn (InboxMessage $message): array => [
                'direction' => $message->direction,
                'from' => $message->isInbound()
                    ? trim(($message->from_name ? "{$message->from_name} " : '').'<'.$message->from_email.'>')
                    : ($message->from_email ?: ($mailboxes[$message->email_account_id] ?? 'You')),
                'at' => $message->sent_at ?? $message->created_at,
                'subject' => $message->subject,
                'body' => $message->body,
                'badge' => match (true) {
                    $message->auto_reply => 'Out of office',
                    $message->isInbound() => 'Reply',
                    default => 'You: '.$message->status->getLabel(),
                },
                'badge_color' => match (true) {
                    $message->auto_reply => 'warning',
                    $message->isInbound() => 'success',
                    default => $message->status->getColor(),
                },
                'error' => $message->error,
            ]);

        return $sent->concat($messages)->sortBy(fn (array $item) => $item['at']?->getTimestamp() ?? 0)->values();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->authorize('update')
                ->disabled(fn (): bool => $this->record->email_account_id === null)
                ->modalHeading(fn (): string => 'Reply to '.$this->lead()->email)
                ->modalDescription(fn (): string => 'Sent from '.($this->mailboxEmail() ?? 'the original mailbox').' in the same thread, with your signature.')
                ->modalSubmitActionLabel('Send reply')
                ->schema([
                    Textarea::make('body')
                        ->label('Your reply')
                        ->rows(10)
                        ->required()
                        ->maxLength(InboxReplier::MAX_LENGTH),
                ])
                ->action(function (array $data): void {
                    app(InboxReplier::class)->queue($this->record, $data['body'], Auth::user());

                    Notification::make()
                        ->title('Reply queued')
                        ->body('It goes out within a minute. If it fails, you\'ll get a notification.')
                        ->success()
                        ->send();
                }),
            Action::make('label')
                ->icon(Heroicon::OutlinedTag)
                ->color('gray')
                ->authorize('update')
                ->fillForm(fn (): array => ['status' => in_array($this->lead()->status, LeadStatus::replyLabels(), true) ? $this->lead()->status->value : null])
                ->schema([
                    Select::make('status')
                        ->label('Label')
                        ->options(collect(LeadStatus::replyLabels())->mapWithKeys(fn (LeadStatus $status): array => [$status->value => $status->getLabel()])->all())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $status = $data['status'] instanceof LeadStatus ? $data['status'] : LeadStatus::from($data['status']);
                    $this->lead()->update(['status' => $status]);

                    Notification::make()->title("Labelled {$status->getLabel()}")->success()->send();
                }),
            Action::make('markUnread')
                ->label('Mark as unread')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('gray')
                ->authorize('update')
                ->action(function (): void {
                    $this->record->forceFill(['unread' => true])->save();
                    $this->redirect(InboxThreadResource::getUrl('index'));
                }),
            Action::make('viewLead')
                ->label('Lead')
                ->icon(Heroicon::OutlinedUser)
                ->color('gray')
                ->url(fn (): string => LeadResource::getUrl('view', ['record' => $this->record->lead_id])),
        ];
    }

    protected function lead(): Lead
    {
        return Lead::query()->findOrFail($this->record->lead_id);
    }

    protected function mailboxEmail(): ?string
    {
        return EmailAccount::query()->whereKey($this->record->email_account_id)->value('email');
    }

    protected function canWrite(): bool
    {
        return (bool) Auth::user()?->roleIn($this->record->workspace_id)?->canWrite();
    }
}
