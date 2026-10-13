<?php

namespace App\Filament\App\Widgets;

use App\Enums\DnsCheckStatus;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Pages\Warmup;
use App\Filament\App\Resources\Campaigns\CampaignResource;
use App\Filament\App\Resources\EmailAccounts\EmailAccountResource;
use App\Filament\App\Resources\Leads\LeadResource;
use App\Filament\App\Resources\SendingDomains\SendingDomainResource;
use App\Models\Campaign;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Entitlements;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * "Get started" checklist on the dashboard: the steps from a new
 * workspace to a first launched campaign. Hidden once everything is done
 * or the team dismisses it; never shown to the read-only Client role.
 */
class OnboardingChecklist extends Widget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 0;

    // Rendered with the page, not lazily: it's the first thing a new team sees.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.app.widgets.onboarding-checklist';

    public static function canView(): bool
    {
        $workspace = Filament::getTenant();
        $user = Auth::user();

        if (! $workspace instanceof Workspace || ! $user instanceof User || ! $user->roleIn($workspace)?->canWrite()) {
            return false;
        }

        return $workspace->onboarding_dismissed_at === null
            && collect(static::steps($workspace))->contains(fn (array $step): bool => ! $step['done']);
    }

    /**
     * @return list<array{key: string, label: string, description: string, done: bool, url: string}>
     */
    public static function steps(Workspace $workspace): array
    {
        $id = $workspace->getKey();

        $steps = [
            ['key' => 'mailbox', 'label' => 'Connect a mailbox', 'description' => 'Gmail, Microsoft 365 or any SMTP/IMAP inbox you send from.',
                'done' => EmailAccount::query()->where('workspace_id', $id)->exists(),
                'url' => EmailAccountResource::getUrl('index', tenant: $workspace)],
            ['key' => 'dns', 'label' => 'Pass the DNS checks', 'description' => 'SPF, DKIM and DMARC on your sending domain keep you out of spam.',
                'done' => SendingDomain::query()->where('workspace_id', $id)->where('status', DnsCheckStatus::Pass->value)->exists(),
                'url' => SendingDomainResource::getUrl('index', tenant: $workspace)],
            ['key' => 'address', 'label' => 'Add your mailing address', 'description' => 'Anti-spam law requires it in every email (Workspace settings).',
                'done' => $workspace->hasMailingAddress(),
                'url' => (string) Filament::getTenantProfileUrl(['tenant' => $workspace])],
        ];

        if (app(Entitlements::class)->allowsModule($workspace, 'warmup')) {
            $steps[] = ['key' => 'warmup', 'label' => 'Start warming up', 'description' => 'New mailboxes need a few weeks of warmup before cold email.',
                'done' => EmailAccount::query()->where('workspace_id', $id)->where('warmup_enabled', true)->exists(),
                'url' => Warmup::getUrl(tenant: $workspace)];
        }

        return [
            ...$steps,
            ['key' => 'leads', 'label' => 'Import leads', 'description' => 'Upload a CSV or Excel file, or use the lead finder.',
                'done' => Lead::query()->where('workspace_id', $id)->exists(),
                'url' => LeadResource::getUrl('index', tenant: $workspace)],
            ['key' => 'campaign', 'label' => 'Write your first campaign', 'description' => 'Start from the template library or from scratch.',
                'done' => Campaign::query()->where('workspace_id', $id)->where('is_template', false)->exists(),
                'url' => CampaignResource::getUrl('index', tenant: $workspace)],
            ['key' => 'launch', 'label' => 'Launch it', 'description' => 'Choose the audience and mailboxes, then launch. Sending starts at a safe pace.',
                'done' => Campaign::query()->where('workspace_id', $id)->whereNotNull('launched_at')->exists(),
                'url' => CampaignResource::getUrl('index', tenant: $workspace)],
        ];
    }

    public function dismiss(): void
    {
        $workspace = Filament::getTenant();
        $user = Auth::user();

        abort_unless($workspace instanceof Workspace && $user instanceof User && $user->roleIn($workspace)?->canWrite(), 403);

        $workspace->forceFill(['onboarding_dismissed_at' => now()])->save();

        $this->redirect(Dashboard::getUrl(tenant: $workspace));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $steps = static::steps(Filament::getTenant());

        return [
            'steps' => $steps,
            'done' => collect($steps)->where('done', true)->count(),
        ];
    }
}
