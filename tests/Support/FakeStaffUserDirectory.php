<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Vendor\Application\StaffUserDirectoryInterface;

/**
 * User accounts, in memory.
 *
 * The database suite is about OUR tables and OUR rules; creating real
 * WordPress users would drag in wp_users, roles and mail, and prove nothing
 * extra about either.
 */
final class FakeStaffUserDirectory implements StaffUserDirectoryInterface
{
    /** @var array<int,array{username:string,email:string,password:string}> */
    public array $users = [];

    private int $nextId = 1000;

    public function isEmail(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public function usernameTaken(string $username): bool
    {
        foreach ($this->users as $user) {
            if ($user['username'] === $username) {
                return true;
            }
        }
        return false;
    }

    public function emailTaken(string $email): bool
    {
        foreach ($this->users as $user) {
            if ($user['email'] === $email) {
                return true;
            }
        }
        return false;
    }

    public function create(string $username, string $email, string $firstName, string $lastName): int
    {
        $id = ++$this->nextId;
        $this->users[$id] = ['username' => $username, 'email' => $email, 'password' => ''];
        return $id;
    }

    public function timestampInSeconds(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + $seconds);
    }

    public function setPassword(int $userId, string $password): bool
    {
        if (!isset($this->users[$userId])) {
            return false;
        }
        $this->users[$userId]['password'] = $password;
        return true;
    }

    public function exists(int $userId): bool
    {
        return isset($this->users[$userId]);
    }
}
