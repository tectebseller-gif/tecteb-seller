<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * Which state may follow which. Written as data rather than as scattered ifs
 * so that "can this happen?" has exactly one answer, and an attempt to jump
 * (draft → approved, say) fails loudly instead of writing a row nobody
 * expected (plan §3.2).
 */
final class ApplicationStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'draft' => ['submitted'],
        'submitted' => ['in_review', 'changes_requested', 'approved', 'rejected'],
        'in_review' => ['changes_requested', 'approved', 'rejected'],
        'changes_requested' => ['submitted'],
        'approved' => ['suspended'],
        'rejected' => ['draft'],
        'suspended' => ['approved'],
    ];

    public function canTransition(ApplicationStatus $from, ApplicationStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    /** @throws VendorDomainException */
    public function assertTransition(ApplicationStatus $from, ApplicationStatus $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new VendorDomainException(sprintf('invalid transition %s → %s', $from->value, $to->value));
        }
    }

    /** @return list<ApplicationStatus> */
    public function nextStates(ApplicationStatus $from): array
    {
        return array_map(
            static fn (string $v): ApplicationStatus => ApplicationStatus::from($v),
            self::ALLOWED[$from->value] ?? []
        );
    }
}
