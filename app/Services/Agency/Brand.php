<?php

namespace App\Services\Agency;

/**
 * How a white-labelled workspace presents itself: name, logo and color.
 */
final class Brand
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $logoUrl = null,
        public readonly ?string $color = null, // #rrggbb, validated on save
    ) {}
}
