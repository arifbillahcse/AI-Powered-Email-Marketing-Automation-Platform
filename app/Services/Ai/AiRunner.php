<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;
use App\Models\AiSetting;
use App\Models\AiUsage;
use App\Services\Billing\Entitlements;

/**
 * One AI request for a workspace, outside the personalization review queue
 * (reply classification and drafts, enrichment, the campaign copilot):
 * checks the plan and the included-credit allowance, runs the request with
 * the workspace's provider, and records the tokens used.
 */
class AiRunner
{
    public function __construct(
        protected TextGeneratorFactory $generators,
        protected Entitlements $entitlements,
    ) {}

    /**
     * Whether AI can run for this workspace at all (module, plan, provider set up).
     */
    public function available(int $workspaceId): bool
    {
        if (! $this->entitlements->allowsModule($workspaceId, 'ai')) {
            return false;
        }

        try {
            $this->generators->for(AiSetting::for($workspaceId));
        } catch (AiException) {
            return false;
        }

        return true;
    }

    /**
     * @throws AiException
     */
    public function run(int $workspaceId, string $system, string $user, string $purpose, ?float $timeout = null): AiResult
    {
        if (! $this->entitlements->allowsModule($workspaceId, 'ai')) {
            throw new AiException('AI isn\'t included in your plan.');
        }

        $setting = AiSetting::for($workspaceId);

        if ($setting->provider === AiProvider::Platform
            && AiUsage::tokensThisMonth($workspaceId, AiProvider::Platform) >= (int) config('outreach.ai.platform_monthly_tokens')) {
            throw new AiException('This month\'s included AI credits are used up. Add your own API key in AI settings to continue.');
        }

        $result = $this->generators->for($setting, $timeout)->generate($system, $user);

        (new AiUsage)->forceFill([
            'workspace_id' => $workspaceId,
            'provider' => $setting->provider->value,
            'model' => mb_substr($result->model, 0, 255),
            'purpose' => $purpose,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cache_read_tokens' => $result->cacheReadTokens,
            'cache_write_tokens' => $result->cacheWriteTokens,
            'created_at' => now(),
        ])->save();

        if ($result->refused || trim($result->text) === '') {
            throw new AiException('The AI declined this request.');
        }

        return $result;
    }

    /**
     * The first JSON object in an AI answer (models sometimes wrap it in
     * prose or a code fence).
     *
     * @return array<string, mixed>|null
     */
    public static function json(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $data = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Neutralize text that pretends to close our data tags.
     */
    public static function untrusted(string $text, int $limit = 4000): string
    {
        $text = preg_replace('/<\/?\s*(lead|reply|conversation|website|email|context)[^>]*>/i', '', $text) ?? $text;

        return mb_substr(trim($text), 0, $limit);
    }
}
