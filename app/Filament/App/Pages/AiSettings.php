<?php

namespace App\Filament\App\Pages;

use App\Enums\AiProvider;
use App\Models\AiSetting;
use App\Models\AiUsage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\AiException;
use App\Services\Ai\TextGeneratorFactory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use UnitEnum;

/**
 * Which AI writes this workspace's content: the included credits or the
 * workspace's own Anthropic/OpenAI key. Owners and admins only.
 *
 * The saved API key is never put back into the form: leave it blank to
 * keep it.
 *
 * @property-read Schema $form
 */
class AiSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'AI settings';

    protected static ?string $title = 'AI settings';

    protected static ?string $slug = 'ai-settings';

    protected string $view = 'filament.app.pages.ai-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /** Per request only: never sent to the browser. */
    protected ?AiSetting $setting = null;

    public static function canAccess(): bool
    {
        $workspace = Filament::getTenant();
        $user = Auth::user();

        return $workspace instanceof Workspace
            && $user instanceof User
            && (bool) $user->roleIn($workspace)?->canManageTeam();
    }

    public static function getNavigationBadge(): ?string
    {
        return modules()->enabled('ai') ? null : 'Soon';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    public function isLocked(): bool
    {
        return modules()->disabled('ai');
    }

    public function mount(): void
    {
        $setting = $this->setting();

        $this->form->fill([
            'provider' => $setting->provider->value,
            'api_key' => null,
            'model' => $setting->model ?? ($setting->provider === AiProvider::OpenAi ? null : 'claude-opus-5-5'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $claudeModels = collect(config('outreach.ai.claude_models'))->mapWithKeys(fn (array $model, string $id): array => [$id => $model['label']])->all();

        return $schema
            ->components([
                Form::make([
                    Section::make('Provider')
                        ->schema([
                            Radio::make('provider')
                                ->label('Who writes your AI content?')
                                ->options(AiProvider::class)
                                ->descriptions([
                                    AiProvider::Platform->value => 'Claude, billed by us. '.Number::format((int) config('outreach.ai.platform_monthly_tokens')).' tokens a month are included.',
                                    AiProvider::Anthropic->value => 'Your own key from console.anthropic.com. You pay Anthropic directly, no monthly limit.',
                                    AiProvider::OpenAi->value => 'Your own key from platform.openai.com. You pay OpenAI directly, no monthly limit.',
                                ])
                                ->required()
                                ->live(),
                            TextInput::make('api_key')
                                ->label('API key')
                                ->password()
                                ->autocomplete('off')
                                ->maxLength(500)
                                ->helperText(fn (Get $get): string => $this->hasSavedKeyFor($get('provider'))
                                    ? 'A key is saved (encrypted). Leave blank to keep it.'
                                    : 'Stored encrypted. Only used to write your content.')
                                ->required(fn (Get $get): bool => $this->isOwnKey($get('provider')) && ! $this->hasSavedKeyFor($get('provider')))
                                ->visible(fn (Get $get): bool => $this->isOwnKey($get('provider'))),
                            Select::make('model')
                                ->label('Claude model')
                                ->options($claudeModels)
                                ->helperText('Opus 5.5 writes the best copy. Sonnet 5.5 costs about half, Haiku 4.5 about a quarter.')
                                ->required()
                                ->in(array_keys($claudeModels))
                                ->visible(fn (Get $get): bool => AiProvider::fromState($get('provider')) === AiProvider::Anthropic),
                            TextInput::make('model')
                                ->label('OpenAI model')
                                ->helperText('The model ID from your OpenAI account.')
                                ->required()
                                ->maxLength(100)
                                ->rule('regex:/^[A-Za-z0-9._:-]+$/')
                                ->visible(fn (Get $get): bool => AiProvider::fromState($get('provider')) === AiProvider::OpenAi),
                        ]),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                            Action::make('test')
                                ->label('Test connection')
                                ->color('gray')
                                ->icon(Heroicon::OutlinedBolt)
                                ->action(fn () => $this->test()),
                        ]),
                    ]),
                Section::make('Usage this month')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('usage_tokens')
                            ->label('Tokens used')
                            ->state(fn (): string => Number::format(AiUsage::tokensThisMonth($this->workspace()->getKey()))),
                        TextEntry::make('usage_included')
                            ->label('Included credits left')
                            ->state(fn (): string => Number::format(max(0, (int) config('outreach.ai.platform_monthly_tokens')
                                - AiUsage::tokensThisMonth($this->workspace()->getKey(), AiProvider::Platform))).' tokens'),
                        TextEntry::make('usage_cost')
                            ->label('Estimated Claude cost')
                            ->helperText('At list prices. Your Anthropic bill is the source of truth.')
                            ->state(fn (): string => Number::currency($this->estimatedClaudeCost(), 'USD')),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $setting = $this->setting();
        $provider = AiProvider::fromState($data['provider']) ?? AiProvider::Platform;

        if ($provider !== $setting->provider) {
            // A key belongs to one provider: never send it to another.
            $setting->api_key = null;
        }

        $setting->provider = $provider;
        $setting->model = $provider === AiProvider::Platform ? null : ($data['model'] ?? null);

        if ($provider !== AiProvider::Platform && filled($data['api_key'] ?? null)) {
            $setting->api_key = trim($data['api_key']);
        }

        if ($provider === AiProvider::Platform) {
            $setting->api_key = null;
        }

        $setting->save();

        $this->data['api_key'] = null;

        Notification::make()->title('AI settings saved')->success()->send();
    }

    /**
     * One tiny request with the saved settings.
     */
    public function test(): void
    {
        abort_unless(static::canAccess(), 403);

        $setting = $this->setting();

        try {
            $result = app(TextGeneratorFactory::class)
                ->for($setting, timeout: 20)
                ->generate('You are a connection test. Reply with the single word OK.', 'Are you there?');
        } catch (AiException $exception) {
            Notification::make()->title('AI connection failed')->body($exception->getMessage())->danger()->send();

            return;
        }

        (new AiUsage)->forceFill([
            'workspace_id' => $this->workspace()->getKey(),
            'provider' => $setting->provider->value,
            'model' => mb_substr($result->model, 0, 255),
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cache_read_tokens' => $result->cacheReadTokens,
            'cache_write_tokens' => $result->cacheWriteTokens,
            'created_at' => now(),
        ])->save();

        Notification::make()
            ->title('AI is connected')
            ->body("{$result->model} answered. Save any changes before testing them.")
            ->success()
            ->send();
    }

    protected function estimatedClaudeCost(): float
    {
        $prices = config('outreach.ai.claude_models');

        return (float) AiUsage::query()
            ->where('workspace_id', $this->workspace()->getKey())
            ->where('created_at', '>=', now()->startOfMonth())
            ->whereIn('provider', [AiProvider::Platform->value, AiProvider::Anthropic->value])
            ->toBase()
            ->selectRaw('model, sum(input_tokens) as input, sum(output_tokens) as output, sum(cache_read_tokens) as cache_read, sum(cache_write_tokens) as cache_write')
            ->groupBy('model')
            ->get()
            ->sum(function ($row) use ($prices): float {
                $price = $prices[$row->model] ?? $prices['claude-opus-5-5'];

                // Cache writes cost 1.25x input (5-minute cache).
                return ((int) $row->input * $price['input']
                    + (int) $row->cache_write * $price['input'] * 1.25
                    + (int) $row->cache_read * $price['cache_read']
                    + (int) $row->output * $price['output']) / 1_000_000;
            });
    }

    protected function isOwnKey(mixed $provider): bool
    {
        return in_array(AiProvider::fromState($provider), [AiProvider::Anthropic, AiProvider::OpenAi], true);
    }

    protected function hasSavedKeyFor(mixed $provider): bool
    {
        $setting = $this->setting();

        return $setting->exists && $setting->provider === AiProvider::fromState($provider) && filled($setting->api_key);
    }

    protected function setting(): AiSetting
    {
        return $this->setting ??= AiSetting::for($this->workspace());
    }

    protected function workspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }
}
