<?php

use App\Enums\AiProvider;
use App\Models\AiSetting;
use App\Services\Ai\AiException;
use App\Services\Ai\ClaudeGenerator;
use App\Services\Ai\OpenAiGenerator;
use App\Services\Ai\TextGeneratorFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeHttpClient;

function claudeMessage(array $overrides = []): array
{
    return array_merge([
        'id' => 'msg_1',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-opus-5-5',
        'content' => [['type' => 'text', 'text' => 'Loved your talk at WordCamp Dhaka.', 'citations' => null]],
        'stop_reason' => 'end_turn',
        'stop_sequence' => null,
        'usage' => [
            'input_tokens' => 320,
            'output_tokens' => 24,
            'cache_read_input_tokens' => 1200,
            'cache_creation_input_tokens' => 0,
        ],
    ], $overrides);
}

function claudeError(string $type): array
{
    return ['type' => 'error', 'error' => ['type' => $type, 'message' => 'Nope']];
}

it('calls Claude with low effort, refusal fallback and a cached system prompt', function () {
    $http = (new FakeHttpClient)->push(200, claudeMessage());

    $result = (new ClaudeGenerator('sk-ant-test', 'claude-opus-5-5', transporter: $http, maxRetries: 0))
        ->generate('You write cold emails.', '<lead>Name: Rahim</lead>');

    $request = $http->requests[0];
    $body = $http->lastBody();

    expect((string) $request->getUri())->toContain('/v1/messages')
        ->and($request->getHeaderLine('x-api-key'))->toBe('sk-ant-test')
        ->and($request->getHeaderLine('anthropic-beta'))->toContain('server-side-fallback-2026-07-01')
        ->and($body['model'])->toBe('claude-opus-5-5')
        ->and($body['max_tokens'])->toBe(16000)
        ->and($body['output_config'])->toBe(['effort' => 'low'])
        ->and($body['fallbacks'])->toBe('default')
        ->and($body['system'][0])->toMatchArray(['type' => 'text', 'text' => 'You write cold emails.', 'cache_control' => ['type' => 'ephemeral']])
        ->and($body['messages'])->toBe([['role' => 'user', 'content' => '<lead>Name: Rahim</lead>']])
        ->and($result->text)->toBe('Loved your talk at WordCamp Dhaka.')
        ->and($result->model)->toBe('claude-opus-5-5')
        ->and($result->inputTokens)->toBe(320)
        ->and($result->outputTokens)->toBe(24)
        ->and($result->cacheReadTokens)->toBe(1200)
        ->and($result->refused)->toBeFalse();
});

it('leaves out effort and the fallback beta for Haiku', function () {
    $http = (new FakeHttpClient)->push(200, claudeMessage(['model' => 'claude-haiku-4-5']));

    (new ClaudeGenerator('sk-ant-test', 'claude-haiku-4-5', transporter: $http, maxRetries: 0))->generate('System', 'User');

    expect($http->lastBody())->not->toHaveKeys(['output_config', 'fallbacks'])
        ->and($http->requests[0]->getHeaderLine('anthropic-beta'))->not->toContain('server-side-fallback');
});

it('reports refusals', function () {
    $http = (new FakeHttpClient)->push(200, claudeMessage(['content' => [], 'stop_reason' => 'refusal']));

    $result = (new ClaudeGenerator('sk-ant-test', 'claude-opus-5-5', transporter: $http, maxRetries: 0))->generate('System', 'User');

    expect($result->refused)->toBeTrue()->and($result->text)->toBe('');
});

it('turns Anthropic errors into retryable or final failures', function (int $status, string $type, bool $retryable) {
    $http = (new FakeHttpClient)->push($status, claudeError($type));

    try {
        (new ClaudeGenerator('sk-ant-test', 'claude-opus-5-5', transporter: $http, maxRetries: 0))->generate('System', 'User');
        $this->fail('Expected an AiException');
    } catch (AiException $exception) {
        expect($exception->retryable)->toBe($retryable)
            ->and($exception->getMessage())->not->toContain('sk-ant-test');
    }
})->with([
    'bad key' => [401, 'authentication_error', false],
    'rate limited' => [429, 'rate_limit_error', true],
    'overloaded' => [529, 'overloaded_error', true],
    'bad request' => [400, 'invalid_request_error', false],
]);

it('calls OpenAI with the workspace key', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'model' => 'gpt-test',
        'choices' => [['message' => ['content' => '  Saw your new Dhaka office. '], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 12],
    ])]);

    $result = (new OpenAiGenerator('sk-openai', 'gpt-test'))->generate('System', 'User');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk-openai')
        && $request['model'] === 'gpt-test'
        && $request['messages'][0] === ['role' => 'system', 'content' => 'System']);

    expect($result->text)->toBe('Saw your new Dhaka office.')
        ->and($result->inputTokens)->toBe(90)
        ->and($result->outputTokens)->toBe(12);
});

it('treats OpenAI rate limits as retryable and bad keys as final', function () {
    Http::fakeSequence('api.openai.com/*')->push([], 429)->push([], 401);

    $generator = new OpenAiGenerator('sk-openai', 'gpt-test');

    expect(fn () => $generator->generate('S', 'U'))->toThrow(fn (AiException $e) => expect($e->retryable)->toBeTrue());
    expect(fn () => $generator->generate('S', 'U'))->toThrow(fn (AiException $e) => expect($e->retryable)->toBeFalse());
});

it('picks the provider and refuses to run without a key', function () {
    config(['outreach.ai.platform_api_key' => null]);
    $factory = new TextGeneratorFactory;

    $setting = new AiSetting;
    expect(fn () => $factory->for($setting))->toThrow(AiException::class, 'Included AI credits');

    $setting->provider = AiProvider::Anthropic;
    $setting->api_key = 'sk-ant-own';
    expect($factory->for($setting))->toBeInstanceOf(ClaudeGenerator::class)
        ->and($setting->effectiveModel())->toBe('claude-opus-5-5');

    $setting->provider = AiProvider::OpenAi;
    $setting->model = null;
    expect(fn () => $factory->for($setting))->toThrow(AiException::class, 'Choose a model');

    $setting->model = 'gpt-test';
    expect($factory->for($setting))->toBeInstanceOf(OpenAiGenerator::class);
});
