<?php

namespace App\Services\Integrations\Drivers;

use App\Models\Integration;
use App\Services\Integrations\IntegrationException;

/**
 * Sends a message from your Telegram bot to a chat (Bot API sendMessage).
 */
class TelegramDriver extends BaseDriver
{
    public function deliver(Integration $integration, string $event, array $payload): ?int
    {
        $token = (string) $integration->setting('bot_token');
        $chat = (string) $integration->setting('chat_id');

        if (! preg_match('/^\d+:[A-Za-z0-9_-]+$/', $token) || $chat === '') {
            throw new IntegrationException('Check the bot token (from @BotFather) and the chat ID.', retryable: false);
        }

        return $this->send(fn () => $this->http()->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chat,
            'text' => $this->headline($event, $payload),
            'disable_web_page_preview' => true,
        ]))->status();
    }
}
