<?php

namespace App\Listeners;

use App\Models\User;
use App\Security\SecurityLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * The security log's entries from the sign-in events Laravel and Fortify
 * fire: the website and the admin panel. The iPhone API signs in without
 * them, so SignInController writes its own.
 */
class RecordSecurityEvents
{
    public function __construct(private readonly SecurityLog $log) {}

    public function handleRegistered(Registered $event): void
    {
        $this->log->record(SecurityLog::Signup, $this->user($event->user));
    }

    public function handleLogin(Login $event): void
    {
        $this->log->record(SecurityLog::Login, $this->user($event->user), channel: $this->channel($event->guard));
    }

    public function handleFailed(Failed $event): void
    {
        // The typed address's domain only; never anything else from the credentials.
        $email = $event->credentials['email'] ?? null;

        $this->log->record(SecurityLog::LoginFailed, $this->user($event->user), email: is_string($email) ? $email : null, channel: $this->channel($event->guard));
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->log->record(SecurityLog::TwoFactorFailed, $this->user($event->user));
    }

    public function handleResetLinkSent(PasswordResetLinkSent $event): void
    {
        // A scripted request was logged as blocked, and its mail held.
        if (request()->attributes->get(SecurityLog::BlockedFlag) === true) {
            return;
        }

        $this->log->record(SecurityLog::ResetRequested, $this->user($event->user));
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->log->record(SecurityLog::PasswordReset, $this->user($event->user));
    }

    public function handleVerified(Verified $event): void
    {
        $this->log->record(SecurityLog::EmailVerified, $this->user($event->user));
    }

    private function user(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }

    private function channel(string $guard): ?string
    {
        return $guard === 'admin' ? 'admin' : null;
    }
}
