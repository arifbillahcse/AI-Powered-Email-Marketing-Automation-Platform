<?php

namespace Database\Factories;

use App\Enums\EmailAccountStatus;
use App\Enums\MailEncryption;
use App\Enums\MailProvider;
use App\Models\EmailAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailAccount>
 */
class EmailAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'email' => fake()->unique()->userName().'@example.com',
            'from_name' => fake()->name(),
            'provider' => MailProvider::Custom,
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => MailEncryption::Tls,
            'smtp_password' => 'smtp-secret',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => MailEncryption::Ssl,
            'daily_limit' => 30,
            'min_delay_seconds' => 90,
            'max_delay_seconds' => 300,
            'send_window_start' => '09:00',
            'send_window_end' => '17:00',
            'send_days' => [1, 2, 3, 4, 5],
        ];
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['status' => EmailAccountStatus::Paused]);
    }

    public function failing(string $error = 'SMTP: login failed'): static
    {
        return $this->state(fn (): array => ['status' => EmailAccountStatus::Error, 'last_error' => $error]);
    }
}
