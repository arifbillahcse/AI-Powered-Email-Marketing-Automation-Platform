<?php

namespace App\Filament\App\Actions;

use App\Enums\EmailAccountStatus;
use App\Filament\App\Resources\InboxThreads\InboxThreadResource;
use App\Models\CampaignLead;
use App\Models\EmailAccount;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Inbox\InboxReplier;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * "Write email": a one-off email to a single lead, outside any campaign.
 * Quick by default: the likely mailbox is picked, the greeting is typed,
 * saved message templates fill the rest, and Ctrl/Cmd+Enter sends. It is
 * queued, goes out right away (campaign schedules and limits don't apply),
 * and starts a Unibox conversation so the lead's reply shows up there.
 */
class SendLeadEmailAction extends Action
{
    public const GREETING = "Hi {{first_name|there}},\n\n";

    public static function getDefaultName(): ?string
    {
        return 'sendEmail';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Write email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->authorize('update')
            ->modalHeading(fn (Lead $record): string => 'Email '.($record->fullName() ?: $record->email))
            ->modalDescription(fn (Lead $record): string => $this->context($record))
            ->modalSubmitActionLabel('Send email')
            ->modalSubmitAction(fn (Action $action): Action => $action->keyBindings(['mod+enter']))
            ->fillForm(fn (Lead $record): array => [
                'email_account_id' => $this->defaultMailbox($record),
                'body' => self::GREETING,
            ])
            ->schema(fn (Lead $record): array => [
                Select::make('template_id')
                    ->label('Template')
                    ->placeholder('Write from scratch')
                    ->options(fn (): array => $this->templates($record))
                    ->helperText(fn (): ?string => $this->templates($record) === [] ? 'Save the emails you send often under Outreach → Message templates.' : null)
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set) use ($record): void {
                        $template = filled($state) ? $this->template($record, (int) $state) : null;

                        if ($template) {
                            $set('subject', $template->subject);
                            $set('body', $template->body);
                        }
                    }),
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
                    ->rows(10)
                    ->autofocus()
                    ->required()
                    ->maxLength(InboxReplier::MAX_LENGTH)
                    ->helperText('Plain text; your mailbox signature is added. Variables work as in campaigns: {{first_name|there}}, {{company}}, {{sender_first_name}}. Ctrl+Enter (⌘+Enter on Mac) sends.'),
                Toggle::make('save_template')
                    ->label('Save as a message template')
                    ->live(),
                TextInput::make('template_name')
                    ->label('Template name')
                    ->placeholder('Intro: web development')
                    ->visible(fn (Get $get): bool => (bool) $get('save_template'))
                    ->required(fn (Get $get): bool => (bool) $get('save_template'))
                    ->maxLength(100)
                    // A plain rule: Filament's unique() would ignore the lead's id on this table.
                    ->rules([Rule::unique('message_templates', 'name')->where('workspace_id', $record->workspace_id)])
                    ->validationMessages(['unique' => 'You already have a template with this name.']),
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

                if (filled($data['template_id'] ?? null)) {
                    MessageTemplate::query()->where('workspace_id', $record->workspace_id)->whereKey($data['template_id'])->increment('times_used');
                }

                if ($data['save_template'] ?? false) {
                    $template = new MessageTemplate([
                        'name' => $data['template_name'],
                        'subject' => $data['subject'],
                        'body' => $data['body'],
                    ]);
                    $template->workspace_id = $record->workspace_id;
                    $template->save();
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
     * Active mailboxes of the lead's workspace.
     *
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

    /**
     * The mailbox the lead already knows (the last one that emailed them),
     * else the one you used last, else the first active one.
     */
    public function defaultMailbox(Lead $lead, ?User $user = null): ?int
    {
        $active = $this->mailboxes($lead)->keys()->map(fn ($id): int => (int) $id);
        $user ??= Auth::user();

        $candidates = [
            InboxThread::query()->where('lead_id', $lead->getKey())->whereNotNull('email_account_id')->latest('last_message_at')->value('email_account_id'),
            CampaignLead::query()->where('lead_id', $lead->getKey())->whereNotNull('email_account_id')->latest('updated_at')->value('email_account_id'),
            $user ? InboxMessage::query()
                ->where('workspace_id', $lead->workspace_id)
                ->where('user_id', $user->getKey())
                ->where('direction', InboxMessage::OUTBOUND)
                ->whereNotNull('email_account_id')
                ->latest('id')
                ->value('email_account_id') : null,
        ];

        foreach ($candidates as $id) {
            if ($id !== null && $active->contains((int) $id)) {
                return (int) $id;
            }
        }

        return $active->first();
    }

    /**
     * One line so you don't repeat yourself: when the lead was last
     * contacted and the latest message in your conversations with them.
     */
    public function context(Lead $lead): string
    {
        $parts = [$lead->last_contacted_at ? 'Last contacted '.$lead->last_contacted_at->diffForHumans().'.' : 'Not contacted yet.'];

        $latest = InboxMessage::query()
            ->whereIn('inbox_thread_id', InboxThread::query()->select('id')->where('lead_id', $lead->getKey()))
            ->latest('id')
            ->first(['direction', 'subject', 'created_at']);

        if ($latest) {
            $who = $latest->direction === InboxMessage::OUTBOUND ? 'You wrote' : 'They replied';
            $parts[] = "{$who} \"".str($latest->subject ?: '(no subject)')->limit(60).'" '.$latest->created_at?->diffForHumans().'.';
        }

        $parts[] = 'Sent once, right away; the reply lands in the Unibox.';

        return implode(' ', $parts);
    }

    /**
     * @return array<int, string>
     */
    protected function templates(Lead $lead): array
    {
        return MessageTemplate::query()
            ->where('workspace_id', $lead->workspace_id)
            ->orderByDesc('times_used')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected function template(Lead $lead, int $id): ?MessageTemplate
    {
        return MessageTemplate::query()->where('workspace_id', $lead->workspace_id)->find($id);
    }
}
