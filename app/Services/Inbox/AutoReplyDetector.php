<?php

namespace App\Services\Inbox;

/**
 * Out-of-office and other automatic replies, by rules: the standard
 * headers (RFC 3834 Auto-Submitted, Microsoft and other X- headers,
 * Precedence) and subject patterns in several languages. They are shown
 * in the Unibox but don't count as replies, so sequences keep going.
 */
class AutoReplyDetector
{
    /**
     * @param  list<string>  $subjectPatterns  Regex fragments (case-insensitive)
     */
    public function __construct(
        protected array $subjectPatterns = [],
    ) {}

    public function isAutoReply(ParsedEmail $email): bool
    {
        $autoSubmitted = strtolower((string) $email->header('Auto-Submitted'));

        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        foreach (['X-Autoreply', 'X-Autorespond', 'X-Autoresponder'] as $header) {
            if ($email->header($header) !== null) {
                return true;
            }
        }

        if (in_array(strtolower((string) $email->header('Precedence')), ['auto_reply', 'bulk', 'junk'], true)) {
            return true;
        }

        $subject = (string) $email->subject;

        foreach ($this->subjectPatterns as $pattern) {
            if (@preg_match('/'.str_replace('/', '\/', $pattern).'/iu', $subject) === 1) {
                return true;
            }
        }

        return false;
    }
}
