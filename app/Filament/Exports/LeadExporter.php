<?php

namespace App\Filament\Exports;

use App\Models\Lead;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class LeadExporter extends Exporter
{
    protected static ?string $model = Lead::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('email'),
            ExportColumn::make('first_name'),
            ExportColumn::make('last_name'),
            ExportColumn::make('company'),
            ExportColumn::make('title'),
            ExportColumn::make('phone'),
            ExportColumn::make('website'),
            ExportColumn::make('linkedin_url')->label('LinkedIn URL'),
            ExportColumn::make('city'),
            ExportColumn::make('country'),
            ExportColumn::make('timezone'),
            ExportColumn::make('status')->formatStateUsing(fn ($state): string => $state?->getLabel() ?? ''),
            ExportColumn::make('tags')
                ->state(fn (Lead $record): string => $record->tags->pluck('name')->implode(', ')),
            ExportColumn::make('custom_fields')
                ->label('Custom fields')
                ->state(fn (Lead $record): string => $record->custom_fields ? json_encode($record->custom_fields) : '')
                ->enabledByDefault(false),
            ExportColumn::make('created_at')->label('Added'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with('tags');
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your lead export is ready: '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).'.';

        if ($failed = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failed).' '.str('row')->plural($failed).' failed.';
        }

        return $body;
    }

    public function getJobQueue(): ?string
    {
        return 'imports';
    }
}
