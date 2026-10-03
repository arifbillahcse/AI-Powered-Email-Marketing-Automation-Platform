<?php

use App\Services\Sending\BounceClassifier;
use App\Services\Sending\SendWindow;
use App\Services\Sending\SmtpFailure;
use Carbon\CarbonImmutable;
use Symfony\Component\Mailer\Exception\TransportException;

it('classifies SMTP failures', function (string $message, int $code, SmtpFailure $expected) {
    expect(SmtpFailure::classify(new TransportException($message, $code)))->toBe($expected);
})->with([
    'unknown user' => ['550 5.1.1 The email account that you tried to reach does not exist', 550, SmtpFailure::HardBounce],
    'auth' => ['535 5.7.8 Username and Password not accepted', 535, SmtpFailure::MailboxAuth],
    'spam block is not a bounce' => ['550 5.7.1 Message rejected as spam', 550, SmtpFailure::Temporary],
    'greylisting' => ['421 4.7.0 Try again later', 421, SmtpFailure::Temporary],
    'connection' => ['Connection could not be established with host "smtp.x"', 0, SmtpFailure::Temporary],
]);

it('parses delivery status notifications', function () {
    $dsn = "Content-Type: message/delivery-status\n\nReporting-MTA: dns; mx.google.com\n\nFinal-Recipient: rfc822; Jane@Acme.com\nAction: failed\nStatus: 5.1.1\nDiagnostic-Code: smtp; 550 5.1.1 user unknown\n";
    $full = "Status: 5.2.2\nFinal-Recipient: rfc822; bob@acme.com\n";

    expect((new BounceClassifier)->classify($dsn))->toBe([
        'recipient' => 'jane@acme.com',
        'status' => '5.1.1',
        'hard' => true,
        'diagnostic' => '550 5.1.1 user unknown',
    ])
        ->and((new BounceClassifier)->classify($full)['hard'])->toBeFalse()
        ->and((new BounceClassifier)->classify('Just a normal reply'))->toBeNull();
});

it('checks send windows in the right time zone', function () {
    $now = CarbonImmutable::parse('2026-10-07 10:00:00', 'UTC'); // Wednesday

    expect(SendWindow::isOpen($now, 'UTC', [3], '09:00', '17:00'))->toBeTrue()
        ->and(SendWindow::isOpen($now, 'UTC', [1, 2], '09:00', '17:00'))->toBeFalse()
        ->and(SendWindow::isOpen($now, 'Asia/Dhaka', [3], '09:00', '17:00'))->toBeTrue() // 16:00
        ->and(SendWindow::isOpen($now, 'Asia/Tokyo', [3], '09:00', '17:00'))->toBeFalse() // 19:00
        ->and(SendWindow::startOfDay($now, 'Asia/Dhaka')->toDateTimeString())->toBe('2026-10-06 18:00:00');
});
