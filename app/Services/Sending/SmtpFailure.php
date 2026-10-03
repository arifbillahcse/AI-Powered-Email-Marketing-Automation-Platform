<?php

namespace App\Services\Sending;

use Throwable;

/**
 * Classifies an SMTP error from a send attempt.
 */
enum SmtpFailure
{
    /** The recipient address doesn't exist: suppress it. */
    case HardBounce;

    /** Our login was refused: the mailbox needs attention. */
    case MailboxAuth;

    /** Anything else (timeouts, greylisting, rate limits): try again later. */
    case Temporary;

    public static function classify(Throwable $exception): self
    {
        $code = (int) $exception->getCode();
        $message = strtolower($exception->getMessage());

        if (in_array($code, [530, 534, 535], true) || preg_match('/\b53[045]\b|authenticat|username and password/', $message)) {
            return self::MailboxAuth;
        }

        $recipientRejected = preg_match('/\b5\.1\.[0-9]\b|user unknown|no such user|does not exist|unknown recipient|recipient address rejected|mailbox unavailable|invalid recipient|address not found/', $message);

        if (in_array($code, [550, 551, 553], true) && $recipientRejected) {
            return self::HardBounce;
        }

        if ($code === 0 && $recipientRejected && preg_match('/\b55[013]\b/', $message)) {
            return self::HardBounce;
        }

        return self::Temporary;
    }
}
