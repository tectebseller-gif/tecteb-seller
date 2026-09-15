<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\EngagementRepositoryInterface;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Coupon;
use Tecteb\Marketplace\Modules\Marketplace\Domain\PriceTier;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Ticket;
use Tecteb\Marketplace\Modules\Marketplace\Domain\TicketMessage;
use Tecteb\Marketplace\Modules\Marketplace\Domain\WholesaleAccount;
use Tecteb\Marketplace\Modules\Marketplace\Domain\WholesaleStatus;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\Migrations\M0009EngagementTables as T;

/**
 * The phase-7 tables, on the plugin's own schema.
 *
 * Two shapes are worth naming. A coupon's use count is a SUM over
 * `tmc_coupon_uses` rather than a column on the coupon, so a crash between
 * "take the discount" and "increment the counter" cannot leave a limit
 * wrong. And a ticket message has an insert and a hide, and no update at all
 * — the absence of that method is the enforcement (UX §10.1).
 */
final class DbEngagementRepository implements EngagementRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    // --- coupons ------------------------------------------------------------

    public function createCoupon(Coupon $coupon): int
    {
        $now = $this->now();
        $written = $this->db->execute(
            'INSERT INTO `' . $this->t(T::COUPONS) . '`
             (code, vendor_user_id, kind, value, min_subtotal_minor, max_discount_minor,
              usage_limit, per_customer_limit, starts_at, ends_at, status, created_by, created_at, updated_at)
             VALUES (%s, %d, %s, %d, %d, ' . ($coupon->maxDiscountMinor === null ? 'NULL' : '%d') . ',
                     %d, %d, ' . ($coupon->startsAt === null ? 'NULL' : '%s') . ',
                     ' . ($coupon->endsAt === null ? 'NULL' : '%s') . ', %s, %d, %s, %s)',
            array_values(array_filter([
                $coupon->code,
                $coupon->vendorUserId,
                $coupon->kind,
                $coupon->value,
                $coupon->minSubtotalMinor,
                $coupon->maxDiscountMinor,
                $coupon->usageLimit,
                $coupon->perCustomerLimit,
                $coupon->startsAt,
                $coupon->endsAt,
                $coupon->status,
                $coupon->createdBy,
                $now,
                $now,
            ], static fn ($v): bool => $v !== null))
        );
        return $written === null ? 0 : (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
    }

    public function findCoupon(int $id): ?Coupon
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->t(T::COUPONS) . '` WHERE id = %d', [$id]);
        return $row === null ? null : $this->hydrateCoupon($row);
    }

    public function findCouponByCode(string $code): ?Coupon
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->t(T::COUPONS) . '` WHERE code = %s', [$code]);
        return $row === null ? null : $this->hydrateCoupon($row);
    }

    public function couponsForVendor(int $vendorUserId, int $limit = 100): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->t(T::COUPONS) . '` WHERE vendor_user_id = %d ORDER BY id DESC LIMIT %d',
            [$vendorUserId, max(1, $limit)]
        );
        return array_map([$this, 'hydrateCoupon'], $rows);
    }

    public function setCouponStatus(int $id, string $status): bool
    {
        return $this->db->execute(
            'UPDATE `' . $this->t(T::COUPONS) . '` SET status = %s, updated_at = %s WHERE id = %d',
            [$status, $this->now(), $id]
        ) !== null;
    }

    public function recordCouponUse(int $couponId, int $wcOrderId, int $customerUserId, int $amountMinor): bool
    {
        // INSERT IGNORE, not a check-then-insert: the unique key decides, so
        // two callbacks arriving together cannot both count a use.
        $written = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->t(T::COUPON_USES) . '`
             (coupon_id, wc_order_id, customer_user_id, amount_minor, created_at)
             VALUES (%d, %d, %d, %d, %s)',
            [$couponId, $wcOrderId, $customerUserId, $amountMinor, $this->now()]
        );
        return $written !== null && $written > 0;
    }

    public function couponUseCount(int $couponId, int $customerUserId = 0): int
    {
        $sql = 'SELECT COUNT(*) FROM `' . $this->t(T::COUPON_USES) . '` WHERE coupon_id = %d';
        $params = [$couponId];
        if ($customerUserId > 0) {
            $sql .= ' AND customer_user_id = %d';
            $params[] = $customerUserId;
        }
        return (int) $this->db->getVar($sql, $params);
    }

    // --- wholesale ----------------------------------------------------------

    public function upsertWholesaleAccount(WholesaleAccount $account): int
    {
        $existing = $this->findWholesaleAccount($account->userId);
        if ($existing !== null) {
            return $existing->id;
        }
        $now = $this->now();
        $written = $this->db->execute(
            'INSERT INTO `' . $this->t(T::B2B_ACCOUNTS) . '`
             (user_id, status, company, registration_id, note, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s, %s)',
            [
                $account->userId,
                $account->status->value,
                $account->company,
                $account->registrationId,
                $account->note,
                $now,
                $now,
            ]
        );
        return $written === null ? 0 : (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
    }

    public function findWholesaleAccount(int $userId): ?WholesaleAccount
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->t(T::B2B_ACCOUNTS) . '` WHERE user_id = %d', [$userId]);
        return $row === null ? null : $this->hydrateAccount($row);
    }

    public function wholesaleAccounts(?WholesaleStatus $status = null, int $limit = 100): array
    {
        $sql = 'SELECT * FROM `' . $this->t(T::B2B_ACCOUNTS) . '`';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE status = %s';
            $params[] = $status->value;
        }
        $sql .= ' ORDER BY id DESC LIMIT %d';
        $params[] = max(1, $limit);
        return array_map([$this, 'hydrateAccount'], $this->db->getResults($sql, $params));
    }

    public function setWholesaleStatus(int $userId, WholesaleStatus $status, int $actorId, string $note): bool
    {
        $now = $this->now();
        return $this->db->execute(
            'UPDATE `' . $this->t(T::B2B_ACCOUNTS) . '`
             SET status = %s, decided_by = %d, decided_at = %s, note = %s, updated_at = %s
             WHERE user_id = %d',
            [$status->value, $actorId, $now, $note, $now, $userId]
        ) !== null;
    }

    // --- price tiers --------------------------------------------------------

    public function replaceTiers(int $productId, int $vendorUserId, array $tiers): bool
    {
        if ($this->db->execute('DELETE FROM `' . $this->t(T::PRICE_TIERS) . '` WHERE product_id = %d', [$productId]) === null) {
            return false;
        }
        $now = $this->now();
        foreach ($tiers as $tier) {
            $written = $this->db->execute(
                'INSERT INTO `' . $this->t(T::PRICE_TIERS) . '`
                 (product_id, vendor_user_id, min_quantity, unit_price_minor, created_at, updated_at)
                 VALUES (%d, %d, %d, %d, %s, %s)',
                [$productId, $vendorUserId, $tier->minQuantity, $tier->unitPriceMinor, $now, $now]
            );
            if ($written === null) {
                return false;
            }
        }
        return true;
    }

    public function tiersFor(int $productId): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->t(T::PRICE_TIERS) . '` WHERE product_id = %d ORDER BY min_quantity ASC',
            [$productId]
        );
        return array_map(static fn (array $row): PriceTier => new PriceTier(
            (int) $row['id'],
            (int) $row['product_id'],
            (int) $row['vendor_user_id'],
            (int) $row['min_quantity'],
            (int) $row['unit_price_minor']
        ), $rows);
    }

    // --- tickets ------------------------------------------------------------

    public function openTicket(Ticket $ticket): int
    {
        $now = $this->now();
        $written = $this->db->execute(
            'INSERT INTO `' . $this->t(T::TICKETS) . '`
             (vendor_user_id, subject, status, order_ref, locked, opened_by, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %d, %d, %s, %s)',
            [
                $ticket->vendorUserId,
                $ticket->subject,
                $ticket->status,
                $ticket->orderRef,
                $ticket->locked ? 1 : 0,
                $ticket->openedBy,
                $now,
                $now,
            ]
        );
        return $written === null ? 0 : (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
    }

    public function findTicket(int $id): ?Ticket
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->t(T::TICKETS) . '` WHERE id = %d', [$id]);
        return $row === null ? null : $this->hydrateTicket($row);
    }

    public function ticketsForVendor(int $vendorUserId, int $limit = 100): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->t(T::TICKETS) . '` WHERE vendor_user_id = %d ORDER BY id DESC LIMIT %d',
            [$vendorUserId, max(1, $limit)]
        );
        return array_map([$this, 'hydrateTicket'], $rows);
    }

    public function allTickets(?string $status = null, int $limit = 100): array
    {
        $sql = 'SELECT * FROM `' . $this->t(T::TICKETS) . '`';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE status = %s';
            $params[] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT %d';
        $params[] = max(1, $limit);
        return array_map([$this, 'hydrateTicket'], $this->db->getResults($sql, $params));
    }

    public function countTickets(?string $status = null, int $vendorUserId = 0): int
    {
        $sql = 'SELECT COUNT(*) FROM `' . $this->t(T::TICKETS) . '` WHERE 1 = 1';
        $params = [];
        if ($status !== null) {
            $sql .= ' AND status = %s';
            $params[] = $status;
        }
        if ($vendorUserId > 0) {
            $sql .= ' AND vendor_user_id = %d';
            $params[] = $vendorUserId;
        }
        return (int) $this->db->getVar($sql, $params);
    }

    public function addTicketMessage(TicketMessage $message): int
    {
        $written = $this->db->execute(
            'INSERT INTO `' . $this->t(T::TICKET_MESSAGES) . '`
             (ticket_id, author_id, author_role, body, created_at)
             VALUES (%d, %d, %s, %s, %s)',
            [$message->ticketId, $message->authorId, $message->authorRole, $message->body, $this->now()]
        );
        return $written === null ? 0 : (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
    }

    public function ticketMessages(int $ticketId): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->t(T::TICKET_MESSAGES) . '` WHERE ticket_id = %d ORDER BY id ASC',
            [$ticketId]
        );
        return array_map(static fn (array $row): TicketMessage => new TicketMessage(
            (int) $row['id'],
            (int) $row['ticket_id'],
            (int) $row['author_id'],
            (string) $row['author_role'],
            (string) ($row['body'] ?? ''),
            (int) $row['hidden'] === 1,
            (string) $row['hidden_reason'],
            $row['hidden_by'] === null ? null : (int) $row['hidden_by'],
            (string) $row['created_at']
        ), $rows);
    }

    public function setTicketState(int $id, string $status, bool $locked, ?string $lastReplyAt, string $lastReplyRole): bool
    {
        $sets = ['status = %s', 'locked = %d', 'updated_at = %s'];
        $params = [$status, $locked ? 1 : 0, $this->now()];
        if ($lastReplyAt !== null) {
            $sets[] = 'last_reply_at = %s';
            $sets[] = 'last_reply_role = %s';
            $params[] = $lastReplyAt;
            $params[] = $lastReplyRole;
        }
        $params[] = $id;
        return $this->db->execute(
            'UPDATE `' . $this->t(T::TICKETS) . '` SET ' . implode(', ', $sets) . ' WHERE id = %d',
            $params
        ) !== null;
    }

    public function hideTicketMessage(int $messageId, int $actorId, string $reason): bool
    {
        // The body is NOT cleared. Hiding decides who may read a message, and
        // a moderation that destroyed the text would also destroy the record
        // of what was moderated.
        return $this->db->execute(
            'UPDATE `' . $this->t(T::TICKET_MESSAGES) . '`
             SET hidden = 1, hidden_reason = %s, hidden_by = %d WHERE id = %d',
            [$reason, $actorId, $messageId]
        ) !== null;
    }

    // --- hydration ----------------------------------------------------------

    /** @param array<string,mixed> $row */
    private function hydrateCoupon(array $row): Coupon
    {
        return new Coupon(
            (int) $row['id'],
            (string) $row['code'],
            (int) $row['vendor_user_id'],
            (string) $row['kind'],
            (int) $row['value'],
            (int) $row['min_subtotal_minor'],
            $row['max_discount_minor'] === null ? null : (int) $row['max_discount_minor'],
            (int) $row['usage_limit'],
            (int) $row['per_customer_limit'],
            $row['starts_at'] === null ? null : (string) $row['starts_at'],
            $row['ends_at'] === null ? null : (string) $row['ends_at'],
            (string) $row['status'],
            (int) $row['created_by'],
            (string) $row['created_at']
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateAccount(array $row): WholesaleAccount
    {
        return new WholesaleAccount(
            (int) $row['id'],
            (int) $row['user_id'],
            WholesaleStatus::tryFrom((string) $row['status']) ?? WholesaleStatus::Requested,
            (string) $row['company'],
            (string) $row['registration_id'],
            (string) ($row['note'] ?? ''),
            $row['decided_by'] === null ? null : (int) $row['decided_by'],
            $row['decided_at'] === null ? null : (string) $row['decided_at'],
            (string) $row['created_at']
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateTicket(array $row): Ticket
    {
        return new Ticket(
            (int) $row['id'],
            (int) $row['vendor_user_id'],
            (string) $row['subject'],
            (string) $row['status'],
            (string) $row['order_ref'],
            (int) $row['locked'] === 1,
            (int) $row['opened_by'],
            $row['last_reply_at'] === null ? null : (string) $row['last_reply_at'],
            (string) $row['last_reply_role'],
            (string) $row['created_at']
        );
    }

    private function t(string $suffix): string
    {
        return T::table($this->db, $suffix);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
