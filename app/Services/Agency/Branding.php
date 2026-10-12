<?php

namespace App\Services\Agency;

use App\Models\Workspace;
use App\Services\Billing\Entitlements;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The brand to show: the current workspace's (a client workspace uses its
 * agency's), or the one whose verified custom domain the visitor is on,
 * e.g. the agency's sign-in page at app.agency.com. Null means the
 * platform's own brand. Only with the `white_label` module in the plan.
 */
class Branding
{
    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    public function __construct(
        protected Entitlements $entitlements,
    ) {}

    public function current(): ?Brand
    {
        $tenant = Filament::getTenant();
        $brand = $tenant instanceof Workspace ? $this->for($tenant) : null;

        return $brand ?? $this->forHost(request()->getHost());
    }

    public function for(?Workspace $workspace): ?Brand
    {
        if (! $workspace) {
            return null;
        }

        $source = $workspace->isClient() ? (Workspace::query()->find($workspace->parent_id) ?? $workspace) : $workspace;

        if (blank($source->brand_name) && blank($source->brand_logo_path) && blank($source->brand_color)) {
            return null;
        }

        if (! $this->entitlements->allowsModule($source, 'white_label')) {
            return null;
        }

        return new Brand(
            name: filled($source->brand_name) ? (string) $source->brand_name : (string) config('app.name'),
            logoUrl: filled($source->brand_logo_path) ? Storage::disk('public')->url((string) $source->brand_logo_path) : null,
            color: is_string($source->brand_color) && preg_match(self::COLOR_PATTERN, $source->brand_color) ? $source->brand_color : null,
        );
    }

    public function forHost(string $host): ?Brand
    {
        $host = Str::lower($host);

        if ($host === '' || $host === Str::lower((string) parse_url((string) config('app.url'), PHP_URL_HOST))) {
            return null;
        }

        return $this->for(Workspace::query()
            ->where('custom_domain', $host)
            ->whereNotNull('custom_domain_verified_at')
            ->first());
    }

    /**
     * The name to use in emails about a workspace (invitations, reports).
     */
    public function nameFor(?Workspace $workspace): string
    {
        return $this->for($workspace)?->name ?? (string) config('app.name');
    }
}
