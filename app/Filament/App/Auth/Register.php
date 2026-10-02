<?php

namespace App\Filament\App\Auth;

use Filament\Auth\Pages\Register as BaseRegister;

/**
 * Sign-up page that pre-fills the email when arriving from a team invitation.
 */
class Register extends BaseRegister
{
    public const INVITATION_EMAIL_SESSION_KEY = 'invitation.email';

    public function mount(): void
    {
        parent::mount();

        if ($email = session(self::INVITATION_EMAIL_SESSION_KEY)) {
            $this->form->fill(['email' => $email]);
        }
    }
}
