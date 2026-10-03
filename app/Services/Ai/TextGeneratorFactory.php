<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;
use App\Models\AiSetting;
use Psr\Http\Client\ClientInterface;

class TextGeneratorFactory
{
    /**
     * @param  ClientInterface|null  $claudeTransporter  PSR-18 client for the
     *                                                   Anthropic SDK (tests inject a fake)
     */
    public function __construct(
        protected ?ClientInterface $claudeTransporter = null,
    ) {}

    /**
     * @throws AiException When the workspace's AI isn't configured.
     */
    public function for(AiSetting $setting, ?float $timeout = null): TextGenerator
    {
        $key = $setting->effectiveApiKey();
        $model = $setting->effectiveModel();
        $timeout ??= (float) config('outreach.ai.timeout');

        if (blank($key)) {
            throw new AiException($setting->provider === AiProvider::Platform
                ? 'Included AI credits aren\'t available on this server. Add your own API key in AI settings.'
                : 'Add your API key in AI settings first.');
        }

        if (blank($model)) {
            throw new AiException('Choose a model in AI settings first.');
        }

        return $setting->provider->usesClaude()
            ? new ClaudeGenerator($key, $model, $timeout, (int) config('outreach.ai.max_retries', 0), $this->claudeTransporter)
            : new OpenAiGenerator($key, $model, $timeout);
    }
}
