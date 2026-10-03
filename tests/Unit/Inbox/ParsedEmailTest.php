<?php

use App\Services\Inbox\AutoReplyDetector;
use App\Services\Inbox\ParsedEmail;

it('reads the headers reply detection needs', function () {
    $email = ParsedEmail::fromRaw(rawEmail([
        'From' => '=?UTF-8?B?UmFoaW0gVWRkaW4=?= <Rahim@Acme.TEST>',
        'Message-ID' => '<reply-1@acme.test>',
        'In-Reply-To' => '<sent-2@softorio.com>',
        'References' => '<sent-1@softorio.com> <sent-2@softorio.com>',
    ]));

    expect($email)
        ->fromEmail->toBe('rahim@acme.test')
        ->fromName->toBe('Rahim Uddin')
        ->messageId->toBe('reply-1@acme.test')
        ->subject->toBe('Re: Quick question')
        ->and($email->threadIds())->toBe(['sent-2@softorio.com', 'sent-1@softorio.com'])
        ->and($email->date?->toIso8601String())->toBe('2026-10-02T10:00:00+00:00')
        ->and($email->snippet())->toBe('Sounds interesting, tell me more.');
});

it('falls back to the HTML part as plain text', function () {
    $email = ParsedEmail::fromRaw(rawEmail(['Content-Type' => 'text/html; charset=UTF-8'], '<p>Yes <b>please</b></p><p>Call me</p>'));

    expect($email->text)->toBe("Yes please\n\nCall me");
});

it('detects automatic replies by headers and subject', function (array $headers, bool $auto) {
    $detector = new AutoReplyDetector(config('outreach.inbox.auto_reply_subjects'));

    expect($detector->isAutoReply(ParsedEmail::fromRaw(rawEmail($headers))))->toBe($auto);
})->with([
    'person' => [[], false],
    'auto-submitted: no' => [['Auto-Submitted' => 'no'], false],
    'auto-submitted' => [['Auto-Submitted' => 'auto-replied'], true],
    'x-autoreply' => [['X-Autoreply' => 'yes'], true],
    'out of office' => [['Subject' => 'Out of Office: Quick question'], true],
    'french' => [['Subject' => 'Réponse automatique : Quick question'], true],
    'not an auto reply' => [['Subject' => 'Re: your automation tool'], false],
]);
