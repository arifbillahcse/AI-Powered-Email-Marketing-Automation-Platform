<?php

namespace App\Filament\App\Actions;

use App\Models\Lead;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Star a lead that matters (follow up, warm, important client).
 */
class ToggleLeadStarAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'toggleStar';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (Lead $record): string => $record->starred ? 'Remove star' : 'Star lead')
            ->icon(fn (Lead $record): Heroicon => $record->starred ? Heroicon::Star : Heroicon::OutlinedStar)
            ->color('gray')
            ->authorize('update')
            ->action(fn (Lead $record) => $record->forceFill(['starred' => ! $record->starred])->save());
    }
}
