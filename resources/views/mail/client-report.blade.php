@php
    $color = $report['brand']?->color ?? '#4f46e5';
@endphp
<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;border-top:4px solid {{ $color }};">
        <tr><td style="padding:24px;">
            <p style="margin:0 0 4px;font-size:13px;color:#71717a;">{{ $senderName }}</p>
            <h1 style="margin:0 0 4px;font-size:20px;">{{ $report['workspace'] }}</h1>
            <p style="margin:0 0 20px;font-size:14px;color:#71717a;">Outreach results, {{ $report['period'] }}</p>
            <table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="font-size:14px;border-collapse:collapse;">
                <tr><td style="border-bottom:1px solid #e4e4e7;">Emails sent</td><td align="right" style="border-bottom:1px solid #e4e4e7;"><strong>{{ number_format($report['totals']['sent']) }}</strong></td></tr>
                <tr><td style="border-bottom:1px solid #e4e4e7;">Replies</td><td align="right" style="border-bottom:1px solid #e4e4e7;"><strong>{{ number_format($report['totals']['replied']) }}</strong> ({{ $report['rates']['reply'] }}%)</td></tr>
                <tr><td style="border-bottom:1px solid #e4e4e7;">Opened</td><td align="right" style="border-bottom:1px solid #e4e4e7;">{{ number_format($report['totals']['opened']) }} ({{ $report['rates']['open'] }}%)</td></tr>
                <tr><td>Bounced</td><td align="right">{{ number_format($report['totals']['bounced']) }} ({{ $report['rates']['bounce'] }}%)</td></tr>
            </table>
            <p style="margin:24px 0 0;"><a href="{{ $url }}" style="display:inline-block;padding:10px 18px;border-radius:6px;background:{{ $color }};color:#ffffff;text-decoration:none;font-weight:bold;">View the full report</a></p>
        </td></tr>
    </table>
</body>
</html>
