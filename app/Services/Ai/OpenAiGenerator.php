<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI (bring your own key) via the Chat Completions HTTP API.
 */
class OpenAiGenerator implements TextGenerator
{
    public const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        protected string $apiKey,
        protected string $model,
        protected float $timeout = 40,
    ) {}

    public function generate(string $system, string $user): AiResult
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeout)
                ->post(self::ENDPOINT, [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new AiException('OpenAI is unreachable. Retrying shortly.', retryable: true, previous: $exception);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new AiException('The OpenAI API key was rejected. Check it in AI settings.');
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new AiException('OpenAI is busy or rate limited. Retrying shortly.', retryable: true);
        }

        if ($response->failed()) {
            throw new AiException('OpenAI rejected the request: '.($response->json('error.message') ?? $response->status()));
        }

        return new AiResult(
            text: trim((string) $response->json('choices.0.message.content')),
            model: (string) ($response->json('model') ?? $this->model),
            inputTokens: (int) $response->json('usage.prompt_tokens'),
            outputTokens: (int) $response->json('usage.completion_tokens'),
            cacheReadTokens: (int) $response->json('usage.prompt_tokens_details.cached_tokens'),
            refused: $response->json('choices.0.finish_reason') === 'content_filter',
        );
    }
}
