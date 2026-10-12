<?php

namespace App\Filament\App\Resources\Integrations;

use App\Enums\IntegrationType;
use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Filament\App\Resources\Integrations\Pages\ManageIntegrations;
use App\Jobs\DeliverIntegrationEvent;
use App\Models\Integration;
use App\Services\Integrations\IntegrationEvents;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Webhooks, Slack, Telegram, HubSpot, Pipedrive and Google Sheets. Secret
 * fields (tokens, keys, Slack URLs) are never sent back to the browser:
 * leave them blank to keep the saved value.
 */
class IntegrationResource extends Resource
{
    use ScopedToWorkspace;

    protected static ?string $model = Integration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 30;

    /**
     * Config keys per type, and which of them are secret.
     *
     * @var array<string, array<string, bool>>
     */
    public const FIELDS = [
        'webhook' => ['url' => false],
        'slack' => ['url' => true],
        'telegram' => ['bot_token' => true, 'chat_id' => false],
        'hubspot' => ['token' => true],
        'pipedrive' => ['company_domain' => false, 'token' => true],
        'google_sheets' => ['spreadsheet_id' => false, 'sheet_name' => false, 'service_account' => true],
    ];

    public static function form(Schema $schema): Schema
    {
        $type = fn (Get $get): ?string => $get('type') instanceof IntegrationType ? $get('type')->value : $get('type');
        $shows = fn (string ...$types): Closure => fn (Get $get): bool => in_array($type($get), $types, true);
        $secret = fn (TextInput|Textarea $field): TextInput|Textarea => $field
            ->formatStateUsing(fn (): ?string => null)
            ->dehydrated(fn (?string $state): bool => filled($state))
            ->required(fn (string $operation): bool => $operation === 'create')
            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Saved and hidden. Leave blank to keep it.' : null);

        return $schema->components([
            Select::make('type')
                ->options(IntegrationType::class)
                ->required()
                ->live()
                ->disabledOn('edit')
                ->afterStateUpdated(function (mixed $state, callable $set): void {
                    $type = $state instanceof IntegrationType ? $state : IntegrationType::tryFrom((string) $state);
                    $set('events', $type?->defaultEvents() ?? []);
                    $set('name', $type ? Str::before($type->getLabel(), ' (') : null);
                }),
            TextInput::make('name')->required()->maxLength(100),

            TextInput::make('config.url')
                ->label('Webhook URL')
                ->placeholder('https://hooks.zapier.com/hooks/catch/...')
                ->helperText('Zapier "Catch Hook", Make "Custom webhook", n8n, or your own endpoint. Each request is signed (X-Outreach-Signature).')
                ->url()
                ->required()
                ->visible($shows('webhook')),
            $secret(TextInput::make('config.slack_url'))
                ->label('Slack incoming webhook URL')
                ->placeholder('https://hooks.slack.com/services/...')
                ->rule('starts_with:https://hooks.slack.com/')
                ->visible($shows('slack')),
            $secret(TextInput::make('config.bot_token'))
                ->label('Bot token')
                ->placeholder('123456:ABC-DEF...')
                ->password()
                ->visible($shows('telegram')),
            TextInput::make('config.chat_id')
                ->label('Chat ID')
                ->helperText('Add the bot to your group (or message it), then use the chat\'s ID, e.g. -1001234567890.')
                ->required()
                ->visible($shows('telegram')),
            $secret(TextInput::make('config.token'))
                ->label(fn (Get $get): string => $type($get) === 'hubspot' ? 'Private app access token' : 'API token')
                ->helperText(fn (Get $get): string => $type($get) === 'hubspot'
                    ? 'HubSpot → Settings → Integrations → Private apps, with the crm.objects.contacts.write scope.'
                    : 'Pipedrive → Personal preferences → API.')
                ->password()
                ->visible($shows('hubspot', 'pipedrive')),
            TextInput::make('config.company_domain')
                ->label('Pipedrive company name')
                ->prefix('https://')
                ->suffix('.pipedrive.com')
                ->regex('/^[a-z0-9-]+$/i')
                ->required()
                ->visible($shows('pipedrive')),
            TextInput::make('config.spreadsheet_id')
                ->label('Spreadsheet ID')
                ->helperText('The long part of the sheet\'s URL: docs.google.com/spreadsheets/d/<ID>/edit')
                ->required()
                ->visible($shows('google_sheets')),
            TextInput::make('config.sheet_name')
                ->label('Tab name')
                ->default('Sheet1')
                ->visible($shows('google_sheets')),
            $secret(Textarea::make('config.service_account'))
                ->label('Service account JSON key')
                ->helperText('Google Cloud → IAM → Service accounts → Keys → JSON. Then share the sheet with the service account\'s email as an editor.')
                ->rows(4)
                ->rule('json')
                ->visible($shows('google_sheets')),

            CheckboxList::make('events')
                ->options(IntegrationEvents::EVENTS)
                ->columns(2)
                ->required(),
            Toggle::make('is_active')->label('Active')->default(true),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->description(fn (Integration $record): string => $record->type->getLabel()),
                TextColumn::make('events')->badge()->color('gray'),
                ToggleColumn::make('is_active')->label('Active'),
                TextColumn::make('last_delivered_at')->label('Last delivery')->since()->placeholder('Never'),
                TextColumn::make('last_error')
                    ->label('Last error')
                    ->limit(60)
                    ->color('danger')
                    ->description(fn (Integration $record): ?string => $record->failure_count > 0 ? "{$record->failure_count} failures in a row" : null)
                    ->placeholder('—'),
            ])
            ->emptyStateHeading('No integrations yet')
            ->emptyStateDescription('Send replies and interested leads to Zapier, Make, Slack, Telegram, HubSpot, Pipedrive or Google Sheets.')
            ->recordActions([
                Action::make('test')
                    ->label('Send test')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('gray')
                    ->authorize('update')
                    ->rateLimit(10)
                    ->action(function (Integration $record): void {
                        DeliverIntegrationEvent::dispatch($record->getKey(), 'reply.interested', app(IntegrationEvents::class)->sample($record->workspace_id));

                        Notification::make()->title('Test sent')->body('The result shows in "Last delivery" / "Last error" in a moment.')->success()->send();
                    }),
                ActionGroup::make([
                    EditAction::make()
                        // Only non-secret settings go back to the form.
                        ->mutateRecordDataUsing(fn (array $data, Integration $record): array => [...$data, 'config' => collect(self::FIELDS[$record->type->value])
                            ->reject(fn (bool $isSecret): bool => $isSecret)
                            ->mapWithKeys(fn (bool $isSecret, string $key): array => [$key => $record->setting($key)])
                            ->all()])
                        ->using(fn (Integration $record, array $data): Integration => static::save($record, $data)),
                    Action::make('secret')
                        ->label('Signing secret')
                        ->icon(Heroicon::OutlinedKey)
                        ->authorize('update')
                        ->visible(fn (Integration $record): bool => $record->type === IntegrationType::Webhook)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->modalContent(fn (Integration $record): HtmlString => new HtmlString(
                            '<p style="font-size:.875rem">Verify requests by computing HMAC-SHA256 of <code>t.body</code> with this secret and comparing it to <code>v1</code> in the <code>X-Outreach-Signature</code> header.</p>'
                            .'<p style="margin-top:.75rem; font-family:monospace; word-break:break-all;">'.e((string) $record->setting('secret')).'</p>'
                        )),
                    Action::make('deliveries')
                        ->label('Recent deliveries')
                        ->icon(Heroicon::OutlinedListBullet)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->modalContent(fn (Integration $record): HtmlString => new HtmlString(
                            $record->deliveries()->latest('id')->limit(15)->get()
                                ->map(fn ($delivery): string => '<li>'.e($delivery->created_at?->diffForHumans().' · '.$delivery->event.' · '
                                    .($delivery->success ? 'OK' : 'Failed').($delivery->status_code ? " ({$delivery->status_code})" : '')
                                    .($delivery->error ? ': '.Str::limit($delivery->error, 120) : '')).'</li>')
                                ->whenEmpty(fn ($items) => $items->push('<li>No deliveries yet.</li>'))
                                ->pipe(fn ($items): string => '<ul style="font-size:.875rem; display:grid; gap:.25rem;">'.$items->implode('').'</ul>')
                        )),
                    DeleteAction::make(),
                ]),
            ]);
    }

    /**
     * Merge the form into the integration: secrets left blank keep their
     * saved value; a webhook gets a signing secret.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(Integration $integration, array $data): Integration
    {
        $type = $data['type'] ?? $integration->type;
        $type = $type instanceof IntegrationType ? $type : IntegrationType::from((string) $type);
        $input = $data['config'] ?? [];

        // The Slack URL is stored as "url" (the form keeps it separate so it can be secret).
        if (array_key_exists('slack_url', $input)) {
            $input['url'] = $input['slack_url'];
            unset($input['slack_url']);
        }

        $config = $integration->exists && $integration->config ? $integration->config->getArrayCopy() : [];

        foreach (self::FIELDS[$type->value] as $key => $isSecret) {
            if (array_key_exists($key, $input) && (! $isSecret || filled($input[$key]))) {
                $config[$key] = is_string($input[$key]) ? trim($input[$key]) : $input[$key];
            }
        }

        if ($type === IntegrationType::Webhook && blank($config['secret'] ?? null)) {
            $config['secret'] = 'whsec_'.Str::random(40);
        }

        $integration->fill([
            'type' => $type,
            'name' => $data['name'],
            'events' => array_values($data['events'] ?? []),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $integration->config = $config;
        $integration->failure_count = 0;
        $integration->save();

        return $integration;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageIntegrations::route('/'),
        ];
    }
}
