<?php

namespace App\Services\Sending;

/**
 * Parses a delivery status notification (RFC 3464 bounce email). Phase 7's
 * inbox reader passes bounce emails here; SMTP-time rejections are handled
 * directly by the send job.
 */
class BounceClassifier
{
    /**
     * @return array{recipient: ?string, status: string, hard: bool, diagnostic: ?string}|null
     */
    public function classify(string $rawMessage): ?array
    {
        if (! preg_match('/^Status:\s*([245])\.(\d{1,3})\.(\d{1,3})/mi', $rawMessage, $status)) {
            return null;
        }

        $code = "{$status[1]}.{$status[2]}.{$status[3]}";

        preg_match('/^(?:Final|Original)-Recipient:\s*rfc822;\s*<?([^\s>]+@[^\s>]+)>?/mi', $rawMessage, $recipient);
        preg_match('/^Diagnostic-Code:\s*(?:smtp;)?\s*(.+)$/mi', $rawMessage, $diagnostic);

        return [
            'recipient' => isset($recipient[1]) ? strtolower(trim($recipient[1])) : null,
            'status' => $code,
            'hard' => $this->isHard($code),
            'diagnostic' => isset($diagnostic[1]) ? trim($diagnostic[1]) : null,
        ];
    }

    /**
     * 5.x.x is permanent, except policy/spam blocks (5.7.x) and full
     * mailboxes (5.2.2), which say nothing about the address itself.
     */
    public function isHard(string $status): bool
    {
        if (! str_starts_with($status, '5.')) {
            return false;
        }

        return ! str_starts_with($status, '5.7.') && $status !== '5.2.2';
    }
}
