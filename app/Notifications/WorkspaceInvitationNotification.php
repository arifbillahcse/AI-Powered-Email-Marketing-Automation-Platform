<?php

namespace App\Notifications;

use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public WorkspaceInvitation $invitation,
        #[\SensitiveParameter] public string $token,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspace = $this->invitation->workspace;
        $inviter = $this->invitation->inviter?->name ?? 'A teammate';

        return (new MailMessage)
            ->subject("{$inviter} invited you to {$workspace->name} on ".config('app.name'))
            ->greeting('You\'re invited!')
            ->line("{$inviter} invited you to join the **{$workspace->name}** workspace as **{$this->invitation->role->getLabel()}**.")
            ->action('Accept invitation', $this->acceptUrl())
            ->line('This invitation expires in '.WorkspaceInvitation::EXPIRES_AFTER_DAYS.' days. If you weren\'t expecting it, you can ignore this email.');
    }

    public function acceptUrl(): string
    {
        return route('invitations.accept', ['token' => $this->token]);
    }
}
