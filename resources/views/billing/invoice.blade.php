<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        body { font-family: Inter, -apple-system, "Segoe UI", Roboto, sans-serif; color: #18181b; background: #f4f4f5; margin: 0; padding: 32px 16px; }
        .sheet { max-width: 760px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 40px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 24px; margin: 0 0 4px; }
        .muted { color: #71717a; font-size: 14px; }
        .row { display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap; margin: 32px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { text-align: left; padding: 12px 0; border-bottom: 1px solid #e4e4e7; font-size: 14px; }
        th:last-child, td:last-child { text-align: right; }
        .total { font-size: 18px; font-weight: 700; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .paid { background: #d1fae5; color: #065f46; } .open { background: #fef3c7; color: #92400e; } .void { background: #e4e4e7; color: #3f3f46; }
        .actions { max-width: 760px; margin: 0 auto 16px; text-align: right; }
        button { font: inherit; padding: 8px 16px; border-radius: 8px; border: 1px solid #d4d4d8; background: #fff; cursor: pointer; }
        @media print { body { background: #fff; padding: 0; } .sheet { box-shadow: none; padding: 0; } .actions { display: none; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
    <div class="sheet">
        <div class="row" style="margin-top: 0;">
            <div>
                <h1>Invoice</h1>
                <div class="muted">{{ $invoice->number }}</div>
            </div>
            <div><span class="badge {{ $invoice->status->value }}">{{ $invoice->status->getLabel() }}</span></div>
        </div>

        <div class="row">
            <div>
                <div class="muted">From</div>
                <strong>{{ $seller['name'] }}</strong><br>
                @if (filled($seller['address'])){{ $seller['address'] }}<br>@endif
                @if (filled($seller['email'])){{ $seller['email'] }}@endif
            </div>
            <div>
                <div class="muted">Billed to</div>
                <strong>{{ $workspace->company_name ?: $workspace->name }}</strong><br>
                @if ($workspace->mailingAddress()){{ $workspace->mailingAddress() }}@endif
            </div>
            <div>
                <div class="muted">Issued</div>
                {{ $invoice->created_at->toFormattedDateString() }}<br>
                @if ($invoice->paid_at)
                    <div class="muted" style="margin-top: 8px;">Paid</div>
                    {{ $invoice->paid_at->toFormattedDateString() }}@if ($invoice->gateway) via {{ ucfirst($invoice->gateway) }}@endif
                @elseif ($invoice->due_at)
                    <div class="muted" style="margin-top: 8px;">Due</div>
                    {{ $invoice->due_at->toFormattedDateString() }}
                @endif
            </div>
        </div>

        <table>
            <thead><tr><th>Description</th><th>Period</th><th>Amount</th></tr></thead>
            <tbody>
                <tr>
                    <td>{{ $invoice->description }}</td>
                    <td>
                        @if ($invoice->period_start && $invoice->period_end)
                            {{ $invoice->period_start->toFormattedDateString() }} – {{ $invoice->period_end->toFormattedDateString() }}
                        @else
                            Starts on payment
                        @endif
                    </td>
                    <td>{{ $invoice->formattedAmount() }}</td>
                </tr>
                <tr><td></td><td class="total">Total</td><td class="total">{{ $invoice->formattedAmount() }}</td></tr>
            </tbody>
        </table>

        @if (isset($invoice->meta['charge']) && strtoupper($invoice->meta['charge']['currency']) !== strtoupper($invoice->currency))
            <p class="muted">Charged as {{ \App\Models\Plan::formatMoney((int) $invoice->meta['charge']['amount'], $invoice->meta['charge']['currency']) }}.</p>
        @endif
        @if (filled($invoice->meta['transaction_id'] ?? null))
            <p class="muted">Transaction: {{ $invoice->meta['transaction_id'] }}</p>
        @endif
    </div>
</body>
</html>
