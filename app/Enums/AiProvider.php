<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AiProvider: string implements HasLabel
{
    /** The platform's own Anthropic key, with a monthly token allowance. */
    case Platform = 'platform';

    /** Your own Anthropic (Claude) API key. */
    case Anthropic = 'anthropic';

    /** Your own OpenAI API key. */
    case OpenAi = 'openai';

    public function getLabel(): string
    {
        return match ($this) {
            self::Platform => 'Included AI credits (Claude)',
            self::Anthropic => 'My own Anthropic (Claude) API key',
            self::OpenAi => 'My own OpenAI API key',
        };
    }

    /**
     * Form state may hold the enum or its value, depending on the field.
     */
    public static function fromState(mixed $state): ?self
    {
        return $state instanceof self ? $state : self::tryFrom((string) $state);
    }

    public function usesClaude(): bool
    {
        return $this !== self::OpenAi;
    }
}
