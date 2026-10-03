@php
    /** @var array{subject: string, html: ?string, text: string, missing: list<string>, reply_in_thread: bool} $message */
    $from = $mailbox ? "{$mailbox->from_name} <{$mailbox->email}>" : 'No mailbox selected yet';
    $to = $lead->fullName() ? $lead->fullName().' <'.$lead->email.'>' : $lead->email;
    $fallbackExample = $message['missing'] !== [] ? '{{'.$message['missing'][0].'|there}}' : null;

    $bodyStyle = "font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; font-size: 14px; line-height: 1.5; color: #18181b; padding: 16px;";

    // The iframe document. The email HTML goes in as-is (it's sandboxed);
    // plain text is escaped. Blade escapes the whole thing for the attribute.
    $document = $message['html'] !== null
        ? '<!doctype html><meta charset="utf-8"><body style="'.$bodyStyle.'">'.$message['html'].'</body>'
        : '<!doctype html><meta charset="utf-8"><body style="'.$bodyStyle.'"><pre style="font: inherit; white-space: pre-wrap; margin: 0;">'.e($message['text']).'</pre></body>';
@endphp

<div style="display: grid; gap: 12px;">
    @if ($fallbackExample)
        <div style="padding: 10px 12px; border-radius: 8px; background: rgba(245, 158, 11, 0.12); color: rgb(180, 83, 9); font-size: 13px;">
            This lead has no value for: <strong>{{ implode(', ', $message['missing']) }}</strong>.
            Add a fallback like <code>{{ $fallbackExample }}</code>.
        </div>
    @endif

    <dl style="display: grid; grid-template-columns: max-content 1fr; gap: 4px 12px; font-size: 13px; margin: 0;">
        <dt style="opacity: .6;">From</dt>
        <dd style="margin: 0;">{{ $from }}</dd>
        <dt style="opacity: .6;">To</dt>
        <dd style="margin: 0;">{{ $to }}</dd>
        <dt style="opacity: .6;">Subject</dt>
        <dd style="margin: 0; font-weight: 600;">
            {{ $message['subject'] }}
            @if ($message['reply_in_thread'])
                <span style="opacity: .6; font-weight: 400;">(reply in same thread)</span>
            @endif
        </dd>
    </dl>

    {{-- sandbox="" : the email HTML can never run scripts or reach the app. --}}
    <iframe
        sandbox=""
        title="Email preview"
        style="width: 100%; min-height: 360px; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 8px; background: #fff;"
        srcdoc="{{ $document }}"
    ></iframe>
</div>
