@extends('marketing.layout', ['title' => config('marketing.tagline')])

@section('content')
    <div class="hero">
        <div class="wrap">
            <h1>{{ config('marketing.tagline') }}</h1>
            <p>{{ config('marketing.description') }}</p>
            <div class="actions">
                <a class="btn btn-primary" href="{{ route('filament.app.auth.register') }}">Start free</a>
                @if ($hasPlans)
                    <a class="btn btn-ghost" href="{{ route('pricing') }}">See pricing</a>
                @endif
            </div>
        </div>
    </div>

    <section class="soft" id="features">
        <div class="wrap">
            <h2>Everything cold outreach needs</h2>
            <p class="lead">From the first mailbox to the meeting booked, in one place.</p>
            <div class="grid">
                @foreach ($features as $feature)
                    <div class="card">
                        <h3>{{ $feature['title'] }}</h3>
                        <p>{{ $feature['text'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section>
        <div class="wrap">
            <h2>Live in an afternoon</h2>
            <p class="lead">A checklist in the app walks you through it.</p>
            <div class="grid steps">
                <div class="card"><h3>Connect your mailboxes</h3><p>Sign in with Google or Microsoft, or add any SMTP/IMAP inbox. We check your DNS and start warming them up.</p></div>
                <div class="card"><h3>Add leads and write</h3><p>Import a spreadsheet or find leads, then start from a proven template. AI can personalize each email.</p></div>
                <div class="card"><h3>Launch and reply</h3><p>Emails go out at a safe pace across your mailboxes. Replies land in one inbox, sorted for you.</p></div>
            </div>
        </div>
    </section>

    <section class="soft">
        <div class="wrap" style="text-align: center;">
            <h2>Ready to send your first campaign?</h2>
            <p class="lead">Free to start. No credit card needed.</p>
            <a class="btn btn-primary" href="{{ route('filament.app.auth.register') }}">Create your account</a>
        </div>
    </section>
@endsection
