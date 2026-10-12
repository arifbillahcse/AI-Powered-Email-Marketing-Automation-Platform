<?php

namespace App\Filament\App\Tenancy;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Workspace;
use App\Services\Agency\Branding;
use App\Services\Agency\CustomDomainVerifier;
use App\Services\Billing\Entitlements;
use App\Support\Timezones;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Owners and admins only (WorkspacePolicy::update).
 */
class WorkspaceSettings extends EditTenantProfile
{
    protected static ?string $slug = 'settings';

    public static function getLabel(): string
    {
        return 'Workspace settings';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Workspace name')
                            ->required()
                            ->maxLength(100),
                        Select::make('timezone')
                            ->label('Time zone')
                            ->options(Timezones::options())
                            ->searchable()
                            ->required()
                            ->in(array_keys(Timezones::options())),
                    ]),
                Section::make('Mailing address')
                    ->description('Anti-spam laws (CAN-SPAM) require a physical postal address in every campaign email. It is added to your email footers automatically. Campaigns can\'t be sent until it\'s filled in.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('company_name')
                            ->label('Company name')
                            ->maxLength(150)
                            ->columnSpanFull(),
                        TextInput::make('address_line1')
                            ->label('Address line 1')
                            ->required(fn (): bool => $this->hasRunningCampaigns())
                            ->validationMessages(['required' => 'Required while campaigns are active or paused.'])
                            ->maxLength(150),
                        TextInput::make('address_line2')
                            ->label('Address line 2')
                            ->maxLength(150),
                        TextInput::make('city')
                            ->required(fn (): bool => $this->hasRunningCampaigns())
                            ->validationMessages(['required' => 'Required while campaigns are active or paused.'])
                            ->maxLength(100),
                        TextInput::make('state')
                            ->label('State / region')
                            ->maxLength(100),
                        TextInput::make('postal_code')
                            ->label('Postal code')
                            ->maxLength(20),
                        TextInput::make('country')
                            ->helperText('2-letter code, e.g. BD, US, GB.')
                            ->required(fn (): bool => $this->hasRunningCampaigns())
                            ->validationMessages(['required' => 'Required while campaigns are active or paused.'])
                            ->length(2)
                            ->regex('/^[A-Za-z]{2}$/')
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null),
                    ]),
                $this->brandingSection(),
            ]);
    }

    /**
     * White label: the agency's name, logo and color on its own and its
     * client workspaces, and its custom domain for signing in.
     */
    protected function brandingSection(): Section
    {
        $locked = fn (): bool => ! app(Entitlements::class)->allowsModule($this->tenant, 'white_label');
        $target = app(CustomDomainVerifier::class)->target();

        return Section::make('Branding (white label)')
            ->description('Your name, logo and color replace ours in the app, on client reports and in invitation emails. Client workspaces use the agency\'s branding.')
            ->visible(fn (): bool => ! $this->tenant->isClient())
            ->columns(2)
            ->schema([
                Callout::make('White label isn\'t included in your plan')
                    ->description(modules()->enabled('white_label') ? 'Upgrade to a plan with White Label to use your own branding and custom domain.' : 'White label is coming soon.')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->visible($locked)
                    ->columnSpanFull(),
                TextInput::make('brand_name')
                    ->label('Brand name')
                    ->placeholder('Acme Outreach')
                    ->maxLength(100)
                    ->disabled($locked),
                ColorPicker::make('brand_color')
                    ->label('Brand color')
                    ->regex(Branding::COLOR_PATTERN)
                    ->validationMessages(['regex' => 'Pick a color like #4f46e5.'])
                    ->disabled($locked),
                FileUpload::make('brand_logo_path')
                    ->label('Logo')
                    ->helperText('PNG, JPG or WebP, up to '.config('outreach.agency.logo_max_kb').' KB. Shown about 32px tall.')
                    ->disk('public')
                    ->directory('brand-logos')
                    ->visibility('public')
                    ->image()
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->maxSize((int) config('outreach.agency.logo_max_kb'))
                    ->disabled($locked),
                TextInput::make('custom_domain')
                    ->label('Custom domain')
                    ->placeholder('app.youragency.com')
                    ->helperText(fn (): string => $this->tenant->custom_domain_verified_at
                        ? 'Verified. Your sign-in page there shows your branding.'
                        : "Add a CNAME record pointing it to {$target}, save, then click \"Verify domain\". Your host must also serve this domain (an alias/addon domain with SSL).")
                    ->regex('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i')
                    ->notIn([$target])
                    ->validationMessages(['regex' => 'Enter a domain name like app.youragency.com.', 'not_in' => 'Use your own domain, not ours.'])
                    ->unique(Workspace::class, 'custom_domain', ignoreRecord: true)
                    ->maxLength(253)
                    ->disabled($locked),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verifyDomain')
                ->label('Verify domain')
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color('gray')
                ->visible(fn (): bool => filled($this->tenant->custom_domain)
                    && ! $this->tenant->isClient()
                    && app(Entitlements::class)->allowsModule($this->tenant, 'white_label'))
                ->action(function (): void {
                    $error = app(CustomDomainVerifier::class)->verify($this->tenant);

                    $error === null
                        ? Notification::make()->title('Domain verified')->body("{$this->tenant->custom_domain} now shows your branding.")->success()->send()
                        : Notification::make()->title('Not verified yet')->body($error)->warning()->send();
                }),
        ];
    }

    /**
     * The address can't be removed while campaigns may still send.
     */
    protected function hasRunningCampaigns(): bool
    {
        return Campaign::query()
            ->where('workspace_id', $this->tenant->getKey())
            ->whereIn('status', [CampaignStatus::Active->value, CampaignStatus::Paused->value])
            ->exists();
    }
}
