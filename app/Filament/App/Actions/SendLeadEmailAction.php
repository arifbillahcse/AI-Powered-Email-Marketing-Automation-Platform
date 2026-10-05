<?php

namespace App\Filament\App\Actions;

use App\Enums\EmailAccountStatus;
use App\Filament\App\Resources\InboxThreads\InboxThreadResource;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Services\Inbox\InboxReplier;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * A one-off email to a single lead, outside any campaign. It is queued,
 * goes out right away (campaign schedules and limits don't apply), and
 * starts a Unibox conversation so the lead's reply shows up there.
 */
class SendLeadEmailAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sendEmail';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Send email')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->authorize('update')
            ->modalHeading(fn (Lead $record): string => 'Email '.$record->email)
            ->modalDescription('Sent once, right away, from the mailbox you choose. Replies show up in the Unibox.')
            ->modalSubmitActionLabel('Send email')
            ->fillForm(fn (Lead $record): array => [
                'email_account_id' => $this->mailboxes($record)->keys()->first(),
            ])
            ->schema(fn (Lead $record): array => [
                Select::make('email_account_id')
                    ->label('From')
                    ->options($this->mailboxes($record)->all())
                    ->required()
                    ->helperText($this->mailboxes($record)->isEmpty() ? 'No active mailbox. Connect one under Infrastructure → Email accounts.' : null),
                TextInput::make('subject')
                    ->required()
                    ->maxLength(250),
                Textarea::make('body')
                    ->label('Message')
                    ->rows(12)
                    ->required()
                    ->maxLength(InboxReplier::MAX_LENGTH)
                    ->helperText('Plain text; your mailbox signature is added. Variables work as in campaigns: {{first_name|there}}, {{company}}, {{sender_first_name}}.'),
            ])
            ->action(function (Lead $record, array $data, Action $action): void {
                $mailbox = EmailAccount::query()->where('workspace_id', $record->workspace_id)->find($data['email_account_id']);

                try {
                    if (! $mailbox) {
                        throw new InvalidArgumentException('Choose a mailbox from this workspace.');
                    }

                    $message = app(InboxReplier::class)->compose($record, $mailbox, $data['subject'], $data['body'], Auth::user());
                } catch (InvalidArgumentException $exception) {
                    Notification::make()->title('Email not sent')->body($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Email queued')
                    ->body('It goes out within a minute. If it fails, you\'ll get a notification.')
                    ->actions([
                        Action::make('open')
                            ->label('Open conversation')
                            ->url(InboxThreadResource::getUrl('view', ['record' => $message->inbox_thread_id])),
                    ])
                    ->success()
                    ->send();
            });
    }

    /**
     * @return Collection<int, string>
     */
    protected function mailboxes(Lead $lead): Collection
    {
        return EmailAccount::query()
            ->where('workspace_id', $lead->workspace_id)
            ->where('status', EmailAccountStatus::Active->value)
            ->orderBy('email')
            ->pluck('email', 'id');
    }
}
