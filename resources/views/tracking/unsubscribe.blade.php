<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Unsubscribe</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; background: #fafafa; color: #18181b; }
        @media (prefers-color-scheme: dark) { body { background: #09090b; color: #fafafa; } .card { background: #18181b !important; border-color: #27272a !important; } }
        .card { max-width: 420px; margin: 16px; padding: 32px; background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; text-align: center; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { margin: 0 0 20px; line-height: 1.5; opacity: .8; }
        button { font: inherit; font-weight: 600; padding: 10px 20px; border: 0; border-radius: 8px; background: #4f46e5; color: #fff; cursor: pointer; }
    </style>
</head>
<body>
    <main class="card">
        @if (! $valid)
            <h1>Link not recognised</h1>
            <p>This unsubscribe link is invalid or has expired. Reply to the email and ask to be removed instead.</p>
        @elseif ($done)
            <h1>You're unsubscribed</h1>
            <p>You won't receive any more emails{{ $sender ? ' from '.$sender : '' }}.</p>
        @else
            <h1>Unsubscribe?</h1>
            <p>Stop receiving emails{{ $sender ? ' from '.$sender : '' }}.</p>
            <form method="post" action="/u/{{ $token }}">
                <button type="submit">Unsubscribe</button>
            </form>
        @endif
    </main>
</body>
</html>
