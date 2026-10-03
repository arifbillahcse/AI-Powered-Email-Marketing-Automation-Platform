<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What the AI writes. Each type is available in emails as a variable.
 */
enum AiContentType: string implements HasLabel
{
    case FirstLine = 'first_line';
    case SubjectLine = 'subject_line';
    case EmailBody = 'email_body';

    public function getLabel(): string
    {
        return match ($this) {
            self::FirstLine => 'Personalized first line',
            self::SubjectLine => 'Subject line',
            self::EmailBody => 'Full email body',
        };
    }

    /**
     * The {{variable}} that inserts this content into a campaign email.
     */
    public function variable(): string
    {
        return match ($this) {
            self::FirstLine => 'ai_first_line',
            self::SubjectLine => 'ai_subject',
            self::EmailBody => 'ai_email',
        };
    }

    /**
     * Form state may hold the enum or its value, depending on the field.
     */
    public static function fromState(mixed $state): ?self
    {
        return $state instanceof self ? $state : self::tryFrom((string) $state);
    }

    public static function fromVariable(string $variable): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->variable() === strtolower($variable)) {
                return $case;
            }
        }

        return null;
    }
}
