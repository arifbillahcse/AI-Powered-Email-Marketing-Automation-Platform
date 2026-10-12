<?php

namespace App\Filament\Admin\Pages;

use App\Services\Admin\SystemHealth as Health;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class SystemHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $title = 'System health';

    protected static ?string $slug = 'system-health';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema(collect(app(Health::class)->checks())->map(fn (array $check): Grid => Grid::make(['default' => 1, 'md' => 4])->schema([
                Text::make($check['label'])
                    ->weight(FontWeight::SemiBold)
                    ->icon(match ($check['status']) {
                        Health::OK => Heroicon::OutlinedCheckCircle,
                        Health::WARNING => Heroicon::OutlinedExclamationTriangle,
                        default => Heroicon::OutlinedXCircle,
                    })
                    ->color($check['status']),
                Text::make($check['detail'])->columnSpan(['md' => 3]),
            ]))->all()),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->icon(Heroicon::OutlinedArrowPath)->color('gray')->action(fn () => null),
        ];
    }
}
