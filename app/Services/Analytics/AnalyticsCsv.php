<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;

/**
 * CSV exports of the analytics views. Cells that a spreadsheet would run
 * as a formula (=, +, -, @) are prefixed with an apostrophe.
 */
class AnalyticsCsv
{
    public const REPORTS = [
        'campaigns' => 'By campaign',
        'steps' => 'By email in the sequence (needs a campaign filter)',
        'mailboxes' => 'By mailbox',
        'daily' => 'Per day',
    ];

    public function __construct(
        protected AnalyticsReport $report,
    ) {}

    /**
     * @return list<list<string|int|float>>
     */
    public function rows(string $type, AnalyticsFilters $filters): array
    {
        if ($type === 'daily') {
            $rows = [['Date (UTC)', 'Sent', 'Opened', 'Clicked', 'Replied']];

            foreach ($this->report->daily($filters) as $day => $counts) {
                $rows[] = [$day, $counts['sent'], $counts['opened'], $counts['clicked'], $counts['replied']];
            }

            return $rows;
        }

        /** @var Collection<int, array<string, mixed>> $data */
        $data = match ($type) {
            'steps' => $this->report->byStep($filters),
            'mailboxes' => $this->report->byMailbox($filters),
            default => $this->report->byCampaign($filters),
        };

        $rows = [[match ($type) {
            'steps' => 'Email',
            'mailboxes' => 'Mailbox',
            default => 'Campaign',
        }, 'Sent', 'Opened', 'Open %', 'Clicked', 'Click %', 'Replied', 'Reply %', 'Bounced', 'Bounce %', 'Unsubscribed', 'Unsubscribe %']];

        foreach ($data as $row) {
            $rows[] = [
                $this->safe((string) $row['name']),
                $row['sent'], $row['opened'], $row['open_rate'], $row['clicked'], $row['click_rate'],
                $row['replied'], $row['reply_rate'], $row['bounced'], $row['bounce_rate'],
                $row['unsubscribed'], $row['unsubscribe_rate'],
            ];
        }

        return $rows;
    }

    public function toString(string $type, AnalyticsFilters $filters): string
    {
        $handle = fopen('php://temp', 'r+');

        foreach ($this->rows($type, $filters) as $row) {
            fputcsv($handle, $row, escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    protected function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'{$value}" : $value;
    }
}
