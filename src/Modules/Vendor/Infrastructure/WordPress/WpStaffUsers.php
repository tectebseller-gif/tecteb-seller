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
            'role' => self::safeRole(),
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

    /**
     * The role a staff account is created with: the site's default, unless
     * that default can run the site.
     *
     * ⛔ §7 claim 6 — «دسترسی‌های مدیر وردپرس به او اعطا نشود». The old line
     * was `get_option('default_role', 'subscriber')` and the sentence above
     * it said «the account gets the site's default role». Both true, and
     * together a hole: `default_role` is a setting, a site can have it on
     * `administrator` or `editor`, and on such a site every staff account this
     * plugin created would have been handed the whole site — by a line whose
     * comment reads as a deliberate restraint.
     *
     * The floor is `subscriber` and it is decided from CAPABILITY rather than
     * from a list of role names: a custom role called anything at all is
     * refused if it can manage options, edit users, install plugins or edit
     * other people's posts. A site whose default is an ordinary customer role
     * — which is every normal shop — is answered exactly as before.
     */
    private static function safeRole(): string
    {
        $default = (string) get_option('default_role', 'subscriber');
        if ($default === '' || $default === 'subscriber') {
            return 'subscriber';
        }
        if (!function_exists('get_role')) {
            return 'subscriber';
        }
        $role = get_role($default);
        if ($role === null || !isset($role->capabilities) || !is_array($role->capabilities)) {
            return 'subscriber';
        }
        foreach (['manage_options', 'edit_users', 'promote_users', 'install_plugins', 'edit_others_posts', 'manage_woocommerce'] as $tooMuch) {
            if (!empty($role->capabilities[$tooMuch])) {
                return 'subscriber';
            }
        }
        return $default;
    }

    /**
     * Four refusals, and the account has to get past all of them.
     *
     * The verb is dangerous in general and safe only for the state the caller
     * is in: an account created in this request, never signed into, with no
     * membership and no content. Each clause below is one way the id could be
     * something else — a mistyped id, a reused variable, a real customer who
     * happens to hold that number tomorrow — and any one of them refuses.
     *
     * `wp_delete_user()` is required explicitly because it lives in
     * `wp-admin/includes/user.php`, which is not loaded on a front-end
     * request; without it the call would be a fatal error on the vendor's own
     * page rather than a refusal.
     */
    public function deleteCreated(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $user = get_userdata($userId);
        if ($user === false) {
            return false;       // already gone: say so rather than claim a delete
        }
        // (1) never an account that can run anything.
        foreach (['manage_options', 'edit_users', 'install_plugins', 'edit_others_posts', 'manage_woocommerce', 'edit_posts'] as $tooMuch) {
            if (user_can($userId, $tooMuch)) {
                return false;
            }
        }
        // (2) never an account that has written anything.
        if (function_exists('count_user_posts') && (int) count_user_posts($userId, 'any', true) > 0) {
            return false;
        }
        // (3) never an account that has signed in. An invitation has not been
        // accepted yet, so there is nothing to have signed in with.
        if (get_user_meta($userId, 'session_tokens', true) !== '') {
            return false;
        }
        // (4) and never without the function that does it.
        if (!function_exists('wp_delete_user')) {
            $path = defined('ABSPATH') ? ABSPATH . 'wp-admin/includes/user.php' : '';
            if ($path === '' || !is_readable($path)) {
                return false;
            }
            require_once $path;
            if (!function_exists('wp_delete_user')) {
                return false;
            }
        }
        wp_delete_user($userId);
        // READ BACK, never trusted: `wp_delete_user()` answers a boolean that
        // is true for «nothing to do» as well as for «done» (`alpha.28`).
        return get_userdata($userId) === false;
    }
}
