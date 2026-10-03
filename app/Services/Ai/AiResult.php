<?php

namespace App\Services\Ai;

final readonly class AiResult
{
    public function __construct(
        public string $text,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
        public bool $refused = false,
    ) {}
}
