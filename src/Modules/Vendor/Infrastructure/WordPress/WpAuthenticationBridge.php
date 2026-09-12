<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\Auth\AuthenticationBridgeInterface;

/**
 * The one place in the vendor module that talks to WordPress about identity.
 *
 * It only READS the session. There is no wp_signon(), no wp_set_auth_cookie()
 * and no cookie written here — the site's existing login stays the gate, and
 * the packaging test that forbids those calls in shipped source still holds.
 */
final class WpAuthenticationBridge implements AuthenticationBridgeInterface
{
    public function isLoggedIn(): bool
    {
        return is_user_logged_in();
    }

    public function currentUserId(): ?int
    {
        $id = get_current_user_id();
        return $id > 0 ? (int) $id : null;
    }

    public function displayName(int $userId): string
    {
        $user = get_userdata($userId);
        return $user ? (string) $user->display_name : '';
    }

    public function email(int $userId): string
    {
        $user = get_userdata($userId);
        return $user ? (string) $user->user_email : '';
    }

    public function loginUrl(string $redirectTo = ''): string
    {
        return wp_login_url($redirectTo);
    }
}
