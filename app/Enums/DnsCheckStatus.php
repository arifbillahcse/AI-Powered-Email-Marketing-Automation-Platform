<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DnsCheckStatus: string implements HasColor, HasLabel
{
    case Pass = 'pass';
    case Warning = 'warning';
    case Fail = 'fail';
    case Pending = 'pending';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pass => 'Healthy',
            self::Warning => 'Needs attention',
            self::Fail => 'Failing',
            self::Pending => 'Not checked',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pass => 'success',
            self::Warning => 'warning',
            self::Fail => 'danger',
            self::Pending => 'gray',
        };
    }

    /**
     * @param  array<self>  $statuses
     */
    public static function worst(array $statuses): self
    {
        foreach ([self::Fail, self::Warning, self::Pending] as $candidate) {
            if (in_array($candidate, $statuses, true)) {
                return $candidate;
            }
        }

        return self::Pass;
    }
}
