<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Widgets\Analytics\ActivityChart;
use App\Filament\App\Widgets\Analytics\AnalyticsStats;
use App\Filament\App\Widgets\Analytics\CampaignBreakdown;
use App\Filament\App\Widgets\Analytics\MailboxBreakdown;
use App\Filament\App\Widgets\Analytics\StepBreakdown;
use App\Models\Campaign;
use App\Models\EmailAccount;
use App\Models\Workspace;
use App\Services\Analytics\AnalyticsCsv;
use App\Services\Analytics\AnalyticsFilters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Campaign analytics: KPIs, trend, and breakdowns per campaign, sequence
 * email and mailbox (with inbox health), for any period.
 */
class Analytics extends BaseDashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'analytics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Analytics';

    protected static ?string $title = 'Analytics';

    public function getWidgets(): array
    {
        return [
            AnalyticsStats::class,
            ActivityChart::class,
            CampaignBreakdown::class,
            StepBreakdown::class,
            MailboxBreakdown::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                ->schema([
                    Select::make('range')
                        ->label('Period')
                        ->options(AnalyticsFilters::RANGES)
                        ->default('30d')
                        ->selectablePlaceholder(false)
                        ->live(),
                    DatePicker::make('from')
                        ->label('From')
                        ->maxDate(now())
                        ->visible(fn (Get $get): bool => $get('range') === 'custom'),
                    DatePicker::make('to')
                        ->label('To')
                        ->maxDate(now())
                        ->visible(fn (Get $get): bool => $get('range') === 'custom'),
                    Select::make('campaign_id')
                        ->label('Campaign')
                        ->placeholder('All campaigns')
                        ->options(fn (): array => Campaign::query()
                            ->where('workspace_id', $this->workspace()->getKey())
                            ->where('is_template', false)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable(),
                    Select::make('email_account_id')
                        ->label('Mailbox')
                        ->placeholder('All mailboxes')
                        ->options(fn (): array => EmailAccount::query()
                            ->where('workspace_id', $this->workspace()->getKey())
                            ->orderBy('email')
                            ->pluck('email', 'id')
                            ->all())
                        ->searchable(),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->modalDescription(fn (): string => 'Uses the filters on this page ('.$this->currentFilters()->label().').')
                ->modalSubmitActionLabel('Download')
                ->schema([
                    Select::make('report')
                        ->options(AnalyticsCsv::REPORTS)
                        ->default('campaigns')
                        ->required(),
                ])
                ->action(function (array $data): StreamedResponse {
                    $filters = $this->currentFilters();
                    $csv = app(AnalyticsCsv::class)->toString($data['report'], $filters);
                    $name = 'analytics-'.$data['report'].'-'.$filters->from->setTimezone($filters->timezone)->format('Ymd')
                        .'-'.$filters->to->setTimezone($filters->timezone)->format('Ymd').'.csv';

                    return response()->streamDownload(fn () => print ($csv), $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
        ];
    }

    public function currentFilters(): AnalyticsFilters
    {
        return AnalyticsFilters::fromState($this->workspace(), $this->filters);
    }

    protected function workspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }
}
