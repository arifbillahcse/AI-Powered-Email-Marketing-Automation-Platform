<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum IntegrationType: string implements HasLabel
{
    case Webhook = 'webhook';
    case Slack = 'slack';
    case Telegram = 'telegram';
    case HubSpot = 'hubspot';
    case Pipedrive = 'pipedrive';
    case GoogleSheets = 'google_sheets';

    public function getLabel(): string
    {
        return match ($this) {
            self::Webhook => 'Webhook (Zapier, Make, n8n, your app)',
            self::Slack => 'Slack',
            self::Telegram => 'Telegram',
            self::HubSpot => 'HubSpot',
            self::Pipedrive => 'Pipedrive',
            self::GoogleSheets => 'Google Sheets',
        };
    }

    /**
     * CRM and sheet integrations act on leads; chat ones post messages.
     *
     * @return list<string>
     */
    public function defaultEvents(): array
    {
        return match ($this) {
            self::Webhook => ['lead.replied', 'reply.interested'],
            self::Slack, self::Telegram => ['reply.interested'],
            self::HubSpot, self::Pipedrive, self::GoogleSheets => ['reply.interested'],
        };
    }
}
