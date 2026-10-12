@php
    $brand = $report['brand'];
    $color = $brand?->color ?? '#4f46e5';
    $metrics = [
        ['Emails sent', number_format($report['totals']['sent']), null],
        ['Opened', number_format($report['totals']['opened']), $report['rates']['open'].'%'],
        ['Clicked', number_format($report['totals']['clicked']), $report['rates']['click'].'%'],
        ['Replied', number_format($report['totals']['replied']), $report['rates']['reply'].'%'],
        ['Bounced', number_format($report['totals']['bounced']), $report['rates']['bounce'].'%'],
        ['Unsubscribed', number_format($report['totals']['unsubscribed']), $report['rates']['unsubscribe'].'%'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $report['workspace'] }}: outreach report</title>
    <style>
        :root { --brand: {{ $color }}; }
        body { font-family: Inter, -apple-system, "Segoe UI", Roboto, sans-serif; color: #18181b; background: #f4f4f5; margin: 0; padding: 32px 16px; }
        .sheet { max-width: 960px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,.08); border-top: 4px solid var(--brand); }
        header { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
        header img { max-height: 40px; max-width: 200px; }
        h1 { font-size: 22px; margin: 16px 0 4px; }
        .muted { color: #71717a; font-size: 14px; }
        nav a { display: inline-block; padding: 6px 12px; border-radius: 999px; font-size: 13px; color: #3f3f46; text-decoration: none; border: 1px solid #e4e4e7; margin-left: 4px; }
        nav a.active { background: var(--brand); border-color: var(--brand); color: #fff; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin: 24px 0; }
        .stat { border: 1px solid #e4e4e7; border-radius: 10px; padding: 16px; }
        .stat .value { font-size: 24px; font-weight: 700; margin-top: 4px; }
        .stat .rate { color: var(--brand); font-weight: 600; font-size: 14px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: right; padding: 10px 8px; border-bottom: 1px solid #e4e4e7; font-size: 14px; white-space: nowrap; }
        th:first-child, td:first-child { text-align: left; white-space: normal; }
        th { color: #71717a; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
        footer { max-width: 960px; margin: 16px auto 0; text-align: center; }
        @media (prefers-color-scheme: dark) {
            body { background: #09090b; color: #f4f4f5; }
            .sheet { background: #18181b; box-shadow: none; }
            .stat, th, td, nav a { border-color: #3f3f46; }
            nav a { color: #d4d4d8; }
        }
        @media print { body { background: #fff; padding: 0; } .sheet { box-shadow: none; } nav { display: none; } }
    </style>
</head>
<body>
    <div class="sheet">
        <header>
            <div>
                @if ($brand?->logoUrl)
                    <img src="{{ $brand->logoUrl }}" alt="{{ $brand->name }}">
                @else
                    <strong>{{ $brand?->name ?? config('app.name') }}</strong>
                @endif
            </div>
            <nav aria-label="Period">
                @foreach (\App\Services\Agency\ClientReport::PERIODS as $days => $label)
                    <a href="{{ route('reports.show', ['token' => $token, 'days' => $days]) }}" @class(['active' => $report['days'] === $days])>{{ $label }}</a>
                @endforeach
            </nav>
        </header>

        <h1>{{ $report['workspace'] }}</h1>
        <div class="muted">Outreach results, {{ $report['period'] }}</div>

        <div class="grid">
            @foreach ($metrics as [$label, $value, $rate])
                <div class="stat">
                    <div class="muted">{{ $label }}</div>
                    <div class="value">{{ $value }}</div>
                    @if ($rate)<div class="rate">{{ $rate }}</div>@endif
                </div>
            @endforeach
        </div>

        <h2 style="font-size: 16px;">Campaigns</h2>
        @if ($report['campaigns'] === [])
            <p class="muted">No emails were sent in this period.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Campaign</th><th>Sent</th><th>Opened</th><th>Replied</th><th>Reply rate</th><th>Bounced</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($report['campaigns'] as $campaign)
                            <tr>
                                <td>{{ $campaign['name'] }}</td>
                                <td>{{ number_format($campaign['sent']) }}</td>
                                <td>{{ number_format($campaign['opened']) }}</td>
                                <td>{{ number_format($campaign['replied']) }}</td>
                                <td>{{ $campaign['reply_rate'] }}%</td>
                                <td>{{ number_format($campaign['bounced']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    <footer class="muted">Opens are estimates: many email apps block or pre-load tracking images.</footer>
</body>
</html>
