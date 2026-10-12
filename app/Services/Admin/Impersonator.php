<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Lets a super admin see the app as a customer. The admin's id is kept in
 * the session so they can switch back; super admins can't be
 * impersonated, and every start/stop is logged.
 */
class Impersonator
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(User $admin, User $target): void
    {
        if (! $admin->is_super_admin) {
            throw new InvalidArgumentException('Only super admins can impersonate.');
        }

        if ($target->is_super_admin || $target->is($admin)) {
            throw new InvalidArgumentException('Super admins can\'t be impersonated.');
        }

        if ($target->suspended_at !== null) {
            throw new InvalidArgumentException('This user is suspended.');
        }

        Log::notice('Impersonation started', ['admin_id' => $admin->getKey(), 'user_id' => $target->getKey()]);

        $this->switchTo($target);
        session()->put(self::SESSION_KEY, $admin->getKey());
    }

    /**
     * @return User|null The admin switched back to
     */
    public function stop(): ?User
    {
        $adminId = session()->pull(self::SESSION_KEY);
        $admin = $adminId ? User::query()->find($adminId) : null;

        if (! $admin?->is_super_admin) {
            return null;
        }

        Log::notice('Impersonation stopped', ['admin_id' => $admin->getKey(), 'user_id' => Auth::id()]);
        $this->switchTo($admin);

        return $admin;
    }

    public function impersonating(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    protected function switchTo(User $user): void
    {
        Auth::guard('web')->login($user);
        session()->regenerate();
        // AuthenticateSession compares this hash on the next request.
        session()->put('password_hash_web', $user->getAuthPassword());
    }
}
