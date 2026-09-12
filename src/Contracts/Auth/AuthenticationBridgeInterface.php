<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts\Auth;

/**
 * The only thing the vendor module is allowed to know about "who is asking".
 *
 * WordPress stays the gate: this contract READS the session WordPress already
 * established and never creates one. There is deliberately no login(), no
 * logout() and no way to mark an identity verified from here — verification
 * has its own path (OtpProviderInterface) and a number that was merely typed
 * into a form is never verified (plan §4, A.4/OTP-01).
 */
interface AuthenticationBridgeInterface
{
    public function isLoggedIn(): bool;

    /** null when nobody is signed in. */
    public function currentUserId(): ?int;

    public function displayName(int $userId): string;

    public function email(int $userId): string;

    /** Absolute URL of the site's existing login screen, with a return path. */
    public function loginUrl(string $redirectTo = ''): string;
}
