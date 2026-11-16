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

    /**
     * Undo the ONE account this same call created seconds ago, when the
     * membership row it was created for could not be written.
     *
     * ⛔ **THE ONLY WRITE IN THIS PLUGIN THAT CAN REMOVE A WORDPRESS USER, AND
     * IT IS HERE UNDER PROTEST.** Measured before it existed: `invite()`
     * creates the account and THEN inserts the membership row, so a storage
     * failure between the two left a WordPress user with the site's default
     * role, a password nobody knows, and no membership — and because
     * `email_taken()` then answers true, the person it was made for could
     * never be invited again. §7 claim 9 asks that a mid-way failure leave no
     * account without control; a permanently blocked email address is the
     * opposite.
     *
     * Every other way out was weighed and is worse: inserting the membership
     * row first needs a row that points at no user, and reporting the orphan
     * without removing it leaves the dead end in place.
     *
     * So the verb exists and the implementation is what makes it safe: it
     * REFUSES unless the account is demonstrably the inert thing this call
     * just made. `true` means it is gone; `false` means it is still there and
     * the caller must say so by name.
     */
    public function deleteCreated(int $userId): bool;
}
