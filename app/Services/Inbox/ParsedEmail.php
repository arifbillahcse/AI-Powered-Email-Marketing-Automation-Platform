<?php

namespace App\Services\Inbox;

use App\Services\Campaigns\CampaignMessageBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * The parts of a received email that reply detection uses. Message IDs are
 * stored without angle brackets, like `email_messages.message_id`.
 */
final class ParsedEmail
{
    /**
     * @param  list<string>  $references
     * @param  array<string, string>  $headers  Lower-cased header name => raw value
     */
    public function __construct(
        public readonly ?string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly ?string $fromEmail,
        public readonly ?string $fromName,
        public readonly ?string $toEmail,
        public readonly ?string $subject,
        public readonly ?CarbonImmutable $date,
        public readonly string $text,
        public readonly array $headers,
        public readonly string $contentType,
        public readonly string $raw,
    ) {}

    public static function fromRaw(string $raw): self
    {
        $message = (new MailMimeParser)->parse($raw, false);

        $text = (string) $message->getTextContent();

        if (trim($text) === '' && ($html = $message->getHtmlContent()) !== null) {
            $text = app(CampaignMessageBuilder::class)->htmlToText($html);
        }

        $headers = [];

        foreach ($message->getAllHeaders() as $header) {
            $headers[strtolower($header->getName())] ??= $header->getRawValue();
        }

        $from = $message->getHeader('From');
        $to = $message->getHeader('To');

        return new self(
            messageId: self::ids($headers['message-id'] ?? '')[0] ?? null,
            inReplyTo: self::ids($headers['in-reply-to'] ?? '')[0] ?? null,
            references: self::ids($headers['references'] ?? ''),
            fromEmail: $from instanceof AddressHeader && $from->getEmail() ? strtolower($from->getEmail()) : null,
            fromName: $from instanceof AddressHeader ? ($from->getPersonName() ?: null) : null,
            toEmail: $to instanceof AddressHeader && $to->getEmail() ? strtolower($to->getEmail()) : null,
            subject: self::clean($message->getSubject()),
            date: self::date($message),
            text: Str::limit(str_replace(["\r\n", "\r"], "\n", trim($text)), 100_000, ''),
            headers: $headers,
            contentType: strtolower($message->getContentType()),
            raw: $raw,
        );
    }

    /**
     * Every <message-id> in a header value, without brackets.
     *
     * @return list<string>
     */
    public static function ids(string $value, bool $bareFallback = true, int $limit = 50): array
    {
        preg_match_all('/<([^<>\s]+@[^<>\s]+)>/', $value, $matches);

        // Some clients send a bare ID without brackets.
        $ids = $matches[1] !== [] || ! $bareFallback ? $matches[1] : array_filter([trim($value)]);

        return array_slice(array_values(array_unique(array_map(fn (string $id): string => mb_substr($id, 0, 255), $ids))), -$limit);
    }

    /**
     * The IDs this message replies to, nearest first.
     *
     * @return list<string>
     */
    public function threadIds(): array
    {
        return array_values(array_unique(array_filter([$this->inReplyTo, ...array_reverse($this->references)])));
    }

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;

        return $value === null ? null : trim($value);
    }

    /**
     * A delivery status notification (bounce).
     */
    public function isDeliveryReport(): bool
    {
        if (str_contains($this->contentType, 'multipart/report')
            && str_contains(strtolower($this->header('Content-Type') ?? ''), 'delivery-status')) {
            return true;
        }

        return (bool) preg_match('/^(mailer-daemon|postmaster)@/i', (string) $this->fromEmail);
    }

    /**
     * The reply without the quoted earlier conversation, for previews.
     */
    public function snippet(int $length = 200): string
    {
        $lines = [];

        foreach (explode("\n", $this->text) as $line) {
            if (preg_match('/^(On .+wrote:|-----Original Message-----|From: .+)$/i', trim($line))) {
                break;
            }

            if (! str_starts_with(ltrim($line), '>') && trim($line) !== '') {
                $lines[] = trim($line);
            }
        }

        return Str::limit(implode(' ', $lines), $length);
    }

    protected static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    protected static function date(IMessage $message): ?CarbonImmutable
    {
        try {
            $value = $message->getHeaderValue('Date');

            return $value ? CarbonImmutable::parse($value)->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
