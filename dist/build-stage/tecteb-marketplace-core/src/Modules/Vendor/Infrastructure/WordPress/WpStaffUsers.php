<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Modules\Vendor\Application\StaffUserDirectoryInterface;

/**
 * Staff accounts, created through WordPress's own user API.
 *
 * Two rules are visible in what this class does NOT do. It never assigns a
 * marketplace role — the account gets the site's default role and every
 * marketplace permission comes from our own table — so no role the site
 * already has, Dokan's included, is touched. And it never signs anybody in:
 * the invitation sets a password, and WordPress's normal login does the rest.
 */
final class WpStaffUsers implements StaffUserDirectoryInterface
{
    public function isEmail(string $email): bool
    {
        return (bool) is_email($email);
    }

    /**
     * `username_exists()` returns the user id or FALSE — not null. Comparing
     * against null made every name look taken and no invitation could ever be
     * created; the browser run on the packaged plugin is what caught it.
     */
    public function usernameTaken(string $username): bool
    {
        return (bool) username_exists($username);
    }

    public function emailTaken(string $email): bool
    {
        return (bool) email_exists($email);
    }

    public function create(string $username, string $email, string $firstName, string $lastName): int
    {
        // A long random password nobody is told: the account cannot be used
        // until the invitation is accepted and a real one is chosen.
        $userId = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => wp_generate_password(32, true, true),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => trim($firstName . ' ' . $lastName),
            'role' => get_option('default_role', 'subscriber'),
        ]);
        return is_wp_error($userId) ? 0 : (int) $userId;
    }

    public function timestampInSeconds(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + max(60, $seconds));
    }

    public function setPassword(int $userId, string $password): bool
    {
        if (!$this->exists($userId)) {
            return false;
        }
        wp_set_password($password, $userId);
        return true;
    }

    public function exists(int $userId): bool
    {
        return $userId > 0 && get_userdata($userId) !== false;
    }
}
