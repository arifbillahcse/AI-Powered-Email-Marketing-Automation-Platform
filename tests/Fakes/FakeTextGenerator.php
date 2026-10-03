<?php

namespace Tests\Fakes;

use App\Services\Ai\AiResult;
use App\Services\Ai\TextGenerator;
use Closure;
use Throwable;

class FakeTextGenerator implements TextGenerator
{
    /** @var list<array{system: string, user: string}> */
    public array $calls = [];

    /**
     * @param  string|Closure(string, string): string|Throwable  $reply
     */
    public function __construct(
        public string|Closure|Throwable $reply = 'Loved your recent launch.',
        public bool $refuse = false,
    ) {}

    public function generate(string $system, string $user): AiResult
    {
        $this->calls[] = ['system' => $system, 'user' => $user];

        if ($this->reply instanceof Throwable) {
            throw $this->reply;
        }

        $text = $this->reply instanceof Closure ? ($this->reply)($system, $user) : $this->reply;

        return new AiResult(
            text: $this->refuse ? '' : $text,
            model: 'claude-opus-5-5',
            inputTokens: 100,
            outputTokens: 20,
            cacheReadTokens: 50,
            refused: $this->refuse,
        );
    }
}
