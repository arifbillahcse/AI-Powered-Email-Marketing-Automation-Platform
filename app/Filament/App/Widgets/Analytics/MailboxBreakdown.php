<?php

namespace App\Filament\App\Widgets\Analytics;

use App\Models\EmailAccount;
use App\Services\Analytics\MailboxHealth;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Collection;

/**
 * KPIs per mailbox, with each mailbox's health score (last 30 days, not
 * affected by the date filter). Mailboxes that sent nothing in the period
 * still appear, so a failing one can't hide.
 */
class MailboxBreakdown extends BreakdownTable
{
    protected static ?int $sort = 5;

    protected static ?string $heading = 'By mailbox';

    protected function rows(): Collection
    {
        $filters = $this->analyticsFilters();
        $stats = $this->report()->byMailbox($filters);
        $health = app(MailboxHealth::class)->forWorkspace($filters->workspaceId);
        $empty = ['sent' => 0, 'opened' => 0, 'clicked' => 0, 'replied' => 0, 'bounced' => 0, 'unsubscribed' => 0,
            'open_rate' => 0.0, 'click_rate' => 0.0, 'reply_rate' => 0.0, 'bounce_rate' => 0.0, 'unsubscribe_rate' => 0.0];
        $emails = EmailAccount::query()->whereIn('id', $health->keys())->pluck('email', 'id');

        return $health
            ->when($filters->mailboxId, fn (Collection $rows) => $rows->only([$filters->mailboxId]))
            ->map(fn (array $score, int $id): array => [
                ...($stats[$id] ?? ['name' => $emails[$id] ?? 'Mailbox', ...$empty]),
                'health' => $score['score'],
                'health_grade' => $score['grade'],
                'health_color' => $score['color'],
                'health_issues' => $score['issues'],
            ]);
    }

    protected function nameLabel(): string
    {
        return 'Mailbox';
    }

    protected function extraColumns(): array
    {
        return [
            TextColumn::make('health')
                ->label('Health')
                ->badge()
                ->color(fn (array $record): string => $record['health_color'])
                ->formatStateUsing(fn ($state, array $record): string => "{$state} · {$record['health_grade']}")
                ->tooltip(fn (array $record): ?string => $record['health_issues'] === [] ? 'No problems found.' : implode("\n", $record['health_issues']))
                ->description(fn (array $record): ?string => $record['health_issues'][0] ?? null)
                ->wrap()
                ->sortable(),
        ];
    }
}
