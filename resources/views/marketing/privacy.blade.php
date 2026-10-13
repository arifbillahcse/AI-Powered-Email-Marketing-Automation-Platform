@extends('marketing.layout', ['title' => 'Privacy policy'])

@php($company = config('marketing.company'))
@php($contact = config('marketing.contact_email'))

@section('content')
    <section>
        <div class="wrap prose">
            <h1>Privacy policy</h1>
            <p>This policy explains how {{ $company }} handles personal data in {{ config('app.name') }}.</p>

            <h2>Data about you</h2>
            <p>Your name, email address, password (stored hashed), billing details and how you use the service. We use it to run your account, bill you and support you.</p>

            <h2>Data you bring: your leads</h2>
            <p>For the leads, emails and replies you store, you are the controller and we process them on your behalf, only to provide the service. We don't sell them or use them for anything else.</p>

            <h2>Mailbox access</h2>
            <p>Connected mailboxes are used to send your campaigns and to find replies to them. Mail that isn't related to your campaigns is never stored. Passwords and tokens are encrypted.</p>

            <h2>Email tracking</h2>
            <p>When enabled for a campaign, opens and clicks are recorded with a pixel and link redirects. Every email includes an unsubscribe link; unsubscribes are honored immediately.</p>

            <h2>Sub-processors</h2>
            <p>We use hosting, email and payment providers, and, when you use AI features, AI model providers, to run the service. They only process data as needed for that.</p>

            <h2>Retention and your rights</h2>
            <p>We keep data while your account is active. Old raw tracking events are archived. You can export or erase any lead's data from the app, and ask us for a copy or deletion of your own data.</p>

            @if (filled($contact))
                <h2>Contact</h2>
                <p>Email <a href="mailto:{{ $contact }}">{{ $contact }}</a> with any privacy question.</p>
            @endif
        </div>
    </section>
@endsection
