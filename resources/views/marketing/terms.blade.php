@extends('marketing.layout', ['title' => 'Terms of service'])

@php($company = config('marketing.company'))
@php($contact = config('marketing.contact_email'))

@section('content')
    <section>
        <div class="wrap prose">
            <h1>Terms of service</h1>
            <p>These terms apply to your use of {{ config('app.name') }} (the "service"), provided by {{ $company }}. By creating an account you agree to them.</p>

            <h2>Your account</h2>
            <p>You are responsible for your account, your team members and everything sent from the mailboxes you connect. Keep your password and connected mailboxes secure.</p>

            <h2>Acceptable use</h2>
            <p>The service is for legitimate, personal business outreach. You must not:</p>
            <ul>
                <li>email purchased, rented or scraped lists of people who have no reasonable business interest in your message;</li>
                <li>send misleading subjects or sender details, hide who you are, or remove the unsubscribe link and postal address we add;</li>
                <li>keep emailing anyone who unsubscribed or asked you to stop;</li>
                <li>send malware, phishing, or illegal, hateful or adult content;</li>
                <li>use the service in breach of anti-spam and privacy laws that apply to you, such as CAN-SPAM, GDPR, PECR or CASL.</li>
            </ul>
            <p>We monitor bounce and spam-complaint rates. Workspaces that put other customers' deliverability at risk can be paused or suspended.</p>

            <h2>Payment</h2>
            <p>Paid plans are billed in advance for each period. If a payment is overdue, sending pauses until the invoice is paid. You can cancel anytime; your plan stays active until the end of the paid period.</p>

            <h2>Your data</h2>
            <p>Your leads, emails and settings belong to you. You can export or delete them at any time. See the <a href="{{ route('legal.privacy') }}">privacy policy</a> for how we handle personal data.</p>

            <h2>Availability and liability</h2>
            <p>We work hard to keep the service running but can't promise it will be uninterrupted. To the extent the law allows, our liability is limited to the fees you paid in the last three months.</p>

            <h2>Changes</h2>
            <p>We may update these terms. We'll tell you about important changes before they take effect.</p>

            @if (filled($contact))
                <h2>Contact</h2>
                <p>Questions? Email <a href="mailto:{{ $contact }}">{{ $contact }}</a>.</p>
            @endif
        </div>
    </section>
@endsection
