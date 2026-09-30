<?php

namespace App\Filament\App\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Placeholder for the Inbox Warmup module (ROADMAP Phase 14).
 *
 * While `modules.warmup` is disabled the page stays in the navigation with
 * a "Soon" badge and renders a locked state. Phase 14 replaces the view.
 */
class Warmup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.app.pages.warmup';

    public static function getNavigationBadge(): ?string
    {
        return modules()->enabled('warmup') ? null : 'Soon';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    public function isLocked(): bool
    {
        return modules()->disabled('warmup');
    }
}
