<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;

/**
 * The manager's side of the document question: define types, or state on the
 * record that none are needed. Nothing here invents a document — the labels
 * come from whoever runs the marketplace.
 */
final class ConfigureDocumentTypes
{
    /** A file bigger than this is refused before it reaches the policy. */
    public const MAX_ALLOWED_BYTES = 20 * 1024 * 1024;

    /** @var list<string> */
    public const SUPPORTED_MIME = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private readonly DocumentTypeRepositoryInterface $types,
        private readonly AuditLogger $audit,
        private readonly CapabilityCheckerInterface $capabilities
    ) {
    }

    /** @param list<string> $allowedMime */
    public function add(string $label, bool $required, array $allowedMime, int $maxBytes, string $instructions = ''): OperationResult
    {
        if (!$this->capabilities->can(VendorCapabilities::MANAGE_DOCUMENTS)) {
            return OperationResult::failure('forbidden');
        }
        $label = trim($label);
        if ($label === '') {
            return OperationResult::failure('label_required');
        }
        $allowedMime = array_values(array_intersect($allowedMime, self::SUPPORTED_MIME));
        if ($allowedMime === []) {
            return OperationResult::failure('mime_required');
        }
        if ($maxBytes <= 0 || $maxBytes > self::MAX_ALLOWED_BYTES) {
            return OperationResult::failure('bad_size', ['max' => self::MAX_ALLOWED_BYTES]);
        }
        $id = $this->types->add($label, $required, $allowedMime, $maxBytes, trim($instructions));
        if ($id <= 0) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::VENDOR_REQUIREMENTS_CHANGED, $this->capabilities->currentUserId(), 'vendor_document_type', (string) $id, [
            'action' => 'added',
            'type' => $label,
            'mode' => $this->types->requirementSet()->mode()->value,
        ]);
        return OperationResult::success('type_added', ['type_id' => $id]);
    }

    public function remove(int $typeId): OperationResult
    {
        if (!$this->capabilities->can(VendorCapabilities::MANAGE_DOCUMENTS)) {
            return OperationResult::failure('forbidden');
        }
        if (!$this->types->remove($typeId)) {
            return OperationResult::failure('not_found');
        }
        $this->audit->log(AuditEventCatalog::VENDOR_REQUIREMENTS_CHANGED, $this->capabilities->currentUserId(), 'vendor_document_type', (string) $typeId, [
            'action' => 'removed',
            'mode' => $this->types->requirementSet()->mode()->value,
        ]);
        return OperationResult::success('type_removed');
    }

    public function declareNoDocumentsNeeded(bool $on): OperationResult
    {
        if (!$this->capabilities->can(VendorCapabilities::MANAGE_DOCUMENTS)) {
            return OperationResult::failure('forbidden');
        }
        $this->types->setExplicitlyNone($on);
        $this->audit->log(AuditEventCatalog::VENDOR_REQUIREMENTS_CHANGED, $this->capabilities->currentUserId(), 'vendor_document_type', 'none', [
            'action' => $on ? 'declared_none' : 'withdrew_none',
            'mode' => $this->types->requirementSet()->mode()->value,
        ]);
        return OperationResult::success($on ? 'declared_none' : 'withdrew_none');
    }
}
