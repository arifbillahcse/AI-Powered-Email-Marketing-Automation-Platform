<?php

namespace App\Filament\App\Resources\AiGenerations\Pages;

use App\Enums\AiGenerationStatus;
use App\Filament\App\Resources\AiGenerations\AiGenerationResource;
use App\Services\Ai\AiGenerationService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Number;

class ListAiGenerations extends ListRecords
{
    protected static string $resource = AiGenerationResource::class;

    protected static ?string $title = 'AI review';

    /**
     * Progress across the workspace, e.g. "120 generating · 380 need review · 0 approved".
     */
    public function getSubheading(): ?string
    {
        $counts = AiGenerationService::counts((int) Filament::getTenant()->getKey());

        if ($counts === []) {
            return null;
        }

        return collect(AiGenerationStatus::cases())
            ->filter(fn (AiGenerationStatus $status): bool => ($counts[$status->value] ?? 0) > 0)
            ->map(fn (AiGenerationStatus $status): string => Number::format($counts[$status->value]).' '.mb_strtolower($status->getLabel()))
            ->implode(' · ');
    }
}
