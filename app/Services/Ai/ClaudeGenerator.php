<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\RequestOptions;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Client\ClientInterface;

/**
 * Claude via the official Anthropic PHP SDK.
 *
 * - Effort "low": short copywriting doesn't need deep reasoning, and lower
 *   effort means fewer (billed) thinking tokens. Not sent to Haiku 4.5,
 *   which doesn't support it.
 * - Server-side refusal fallback ("default") on Opus 5.5 / Sonnet 5.5: if
 *   a safety classifier declines, the API retries on Anthropic's recommended
 *   fallback model inside the same call.
 * - The system prompt (instructions shared by every lead in a batch) is
 *   prompt-cached; per-lead data goes in the user message after it.
 */
class ClaudeGenerator implements TextGenerator
{
    private const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];

    private const NO_EFFORT_MODELS = ['claude-haiku-4-5'];

    public function __construct(
        protected string $apiKey,
        protected string $model,
        protected float $timeout = 40,
        protected int $maxRetries = 1,
        protected ?ClientInterface $transporter = null,
    ) {}

    public function generate(string $system, string $user): AiResult
    {
        $client = new Client(
            apiKey: $this->apiKey,
            requestOptions: RequestOptions::with(
                timeout: $this->timeout,
                maxRetries: $this->maxRetries,
                // The SDK leaves timeouts to the HTTP client, so give it one
                // that enforces them (queued jobs must finish in under 50s).
                transporter: $this->transporter ?? new GuzzleClient([
                    'timeout' => $this->timeout,
                    'connect_timeout' => min(10, $this->timeout),
                ]),
            ),
        );

        $params = [
            'model' => $this->model,
            'maxTokens' => 16000,
            'system' => [
                ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $user],
            ],
        ];

        if (! in_array($this->model, self::NO_EFFORT_MODELS, true)) {
            $params['outputConfig'] = ['effort' => 'low'];
        }

        if (in_array($this->model, self::FALLBACK_MODELS, true)) {
            $params['fallbacks'] = 'default';
            $params['betas'] = ['server-side-fallback-2026-07-01'];
        }

        try {
            $message = $client->beta->messages->create(...$params);
        } catch (AuthenticationException|PermissionDeniedException $exception) {
            throw new AiException('The Anthropic API key was rejected. Check it in AI settings.', previous: $exception);
        } catch (RateLimitException $exception) {
            throw new AiException('Anthropic rate limit reached. Retrying shortly.', retryable: true, previous: $exception);
        } catch (InternalServerException|APIConnectionException $exception) {
            throw new AiException('Anthropic is temporarily unavailable. Retrying shortly.', retryable: true, previous: $exception);
        } catch (APIStatusException $exception) {
            throw new AiException('Anthropic rejected the request: '.$exception->getMessage(), previous: $exception);
        }

        $text = '';

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new AiResult(
            text: trim($text),
            model: $message->model,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            cacheReadTokens: $message->usage->cacheReadInputTokens ?? 0,
            cacheWriteTokens: $message->usage->cacheCreationInputTokens ?? 0,
            refused: $message->stopReason === 'refusal',
        );
    }
}
