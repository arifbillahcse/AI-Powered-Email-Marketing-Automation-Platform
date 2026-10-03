<?php

namespace App\Filament\App\Widgets\Analytics;

use Filament\Support\Facades\FilamentColor;
use Filament\Widgets\ChartWidget;

/**
 * Emails sent and first opens, clicks and replies per day.
 */
class ActivityChart extends ChartWidget
{
    use ReadsAnalyticsFilters;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Activity per day';

    protected ?string $description = 'Days in UTC. Opens, clicks and replies are counted on the day they happened.';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $daily = $this->report()->daily($this->analyticsFilters());

        // The panel's semantic colors, so the chart follows the theme.
        $series = fn (string $metric, string $label, string $color): array => [
            'label' => $label,
            'data' => array_values(array_column($daily, $metric)),
            'borderColor' => FilamentColor::getColor($color)[500] ?? null,
            'backgroundColor' => FilamentColor::getColor($color)[500] ?? null,
            'tension' => 0.3,
            'pointRadius' => count($daily) > 31 ? 0 : 2,
        ];

        return [
            'datasets' => [
                $series('sent', 'Sent', 'primary'),
                $series('opened', 'Opened', 'info'),
                $series('clicked', 'Clicked', 'warning'),
                $series('replied', 'Replied', 'success'),
            ],
            'labels' => array_map(fn (string $day): string => date('M j', strtotime($day)), array_keys($daily)),
        ];
    }
}
