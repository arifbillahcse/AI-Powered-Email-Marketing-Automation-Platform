<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MailProvider: string implements HasLabel
{
    case Google = 'google';
    case Microsoft = 'microsoft';
    case Zoho = 'zoho';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::Google => 'Google Workspace / Gmail',
            self::Microsoft => 'Microsoft 365 / Outlook',
            self::Zoho => 'Zoho Mail',
            self::Custom => 'Other (custom SMTP/IMAP)',
        };
    }

    /**
     * Server settings to pre-fill in the form.
     *
     * @return array<string, mixed>
     */
    public function preset(): array
    {
        return match ($this) {
            self::Google => [
                'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_encryption' => MailEncryption::Tls->value,
                'imap_host' => 'imap.gmail.com', 'imap_port' => 993, 'imap_encryption' => MailEncryption::Ssl->value,
            ],
            self::Microsoft => [
                'smtp_host' => 'smtp.office365.com', 'smtp_port' => 587, 'smtp_encryption' => MailEncryption::Tls->value,
                'imap_host' => 'outlook.office365.com', 'imap_port' => 993, 'imap_encryption' => MailEncryption::Ssl->value,
            ],
            self::Zoho => [
                'smtp_host' => 'smtp.zoho.com', 'smtp_port' => 465, 'smtp_encryption' => MailEncryption::Ssl->value,
                'imap_host' => 'imap.zoho.com', 'imap_port' => 993, 'imap_encryption' => MailEncryption::Ssl->value,
            ],
            self::Custom => [],
        };
    }

    public function setupHint(): ?string
    {
        return match ($this) {
            self::Google => 'Use an App Password (Google Account → Security → 2-Step Verification → App passwords), not your normal password. IMAP must be enabled in Gmail settings.',
            self::Microsoft => 'SMTP AUTH must be enabled for this mailbox in the Microsoft 365 admin center. Use an app password if MFA is on.',
            self::Zoho => 'Enable IMAP access in Zoho Mail settings. Use an app-specific password if 2FA is on.',
            self::Custom => null,
        };
    }

    /**
     * SPF include mechanism for this provider, if known.
     */
    public function spfInclude(): ?string
    {
        return match ($this) {
            self::Google => 'include:_spf.google.com',
            self::Microsoft => 'include:spf.protection.outlook.com',
            self::Zoho => 'include:zoho.com',
            self::Custom => null,
        };
    }

    /**
     * Form state may hold the enum itself or its string value.
     */
    public static function fromState(mixed $state): ?self
    {
        return $state instanceof self ? $state : self::tryFrom((string) $state);
    }

    public static function guessFromSmtpHost(?string $host): self
    {
        $host = strtolower((string) $host);

        return match (true) {
            str_contains($host, 'gmail') || str_contains($host, 'google') => self::Google,
            str_contains($host, 'office365') || str_contains($host, 'outlook') => self::Microsoft,
            str_contains($host, 'zoho') => self::Zoho,
            default => self::Custom,
        };
    }
}
