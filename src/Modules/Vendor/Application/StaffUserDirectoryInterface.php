<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

/**
 * The user accounts staff sign in with.
 *
 * A contract rather than a direct call into WordPress, so the rule that
 * matters stays visible: this plugin CREATES a customer-level account and
 * never touches roles the site already has — including Dokan's — and never
 * grants the vendor the ability to do it themselves.
 */
interface StaffUserDirectoryInterface
{
    public function isEmail(string $email): bool;

    public function usernameTaken(string $username): bool;

    public function emailTaken(string $email): bool;

    /** @return int new user id, or 0 when the directory refused */
    public function create(string $username, string $email, string $firstName, string $lastName): int;

    /** 'Y-m-d H:i:s' this many seconds from now. */
    public function timestampInSeconds(int $seconds): string;

    /** Sets the password chosen when an invitation is accepted. */
    public function setPassword(int $userId, string $password): bool;

    public function exists(int $userId): bool;
}
