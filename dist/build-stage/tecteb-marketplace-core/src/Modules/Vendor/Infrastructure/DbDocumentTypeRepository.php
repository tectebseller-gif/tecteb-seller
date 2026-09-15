<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\DocumentTypeRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\DocumentType;
use Tecteb\Marketplace\Modules\Vendor\Domain\RequirementSet;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables as T;

/**
 * Document types, plus the one option that carries the manager's explicit
 * "no documents needed" decision.
 *
 * Why an option and not a row: the decision is about the ABSENCE of types,
 * so it cannot live in the types table. Its default is unset, which is what
 * makes «تعریف‌نشده» the honest starting state.
 */
final class DbDocumentTypeRepository implements DocumentTypeRepositoryInterface
{
    public const EXPLICIT_NONE_OPTION = 'tmc_vendor_documents_none';

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly OptionStoreInterface $options,
        private readonly ClockInterface $clock
    ) {
    }

    public function requirementSet(): RequirementSet
    {
        $rows = $this->db->getResults('SELECT * FROM `' . $this->table() . '` ORDER BY sort_order ASC, id ASC');
        $types = [];
        foreach ($rows as $row) {
            $types[] = new DocumentType(
                (int) $row['id'],
                (string) $row['slug'],
                (string) $row['label'],
                (bool) $row['required'],
                array_values(array_filter(explode(',', (string) $row['allowed_mime']))),
                (int) $row['max_bytes'],
                (string) ($row['instructions'] ?? ''),
                (int) $row['sort_order']
            );
        }
        return new RequirementSet($types, (bool) $this->options->get(self::EXPLICIT_NONE_OPTION, false));
    }

    public function add(string $label, bool $required, array $allowedMime, int $maxBytes, string $instructions = ''): int
    {
        $slug = $this->uniqueSlug($label);
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $next = (int) $this->db->getVar('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM `' . $this->table() . '`');
        $ok = $this->db->execute(
            'INSERT INTO `' . $this->table() . '` (slug, label, required, allowed_mime, max_bytes, instructions, sort_order, created_at)
             VALUES (%s, %s, %d, %s, %d, %s, %d, %s)',
            [$slug, $label, $required ? 1 : 0, implode(',', $allowedMime), $maxBytes, $instructions, $next, $now]
        );
        if ($ok === null) {
            return 0;
        }
        return (int) $this->db->getVar('SELECT id FROM `' . $this->table() . '` WHERE slug = %s', [$slug]);
    }

    public function remove(int $typeId): bool
    {
        $affected = $this->db->execute('DELETE FROM `' . $this->table() . '` WHERE id = %d', [$typeId]);
        return $affected !== null && $affected > 0;
    }

    public function setExplicitlyNone(bool $on): void
    {
        $this->options->set(self::EXPLICIT_NONE_OPTION, $on);
    }

    /**
     * Slugs are derived from the label but must stay unique and ASCII-safe:
     * a Persian label produces no ASCII at all, so it falls back to a
     * numbered slug rather than an empty string.
     */
    private function uniqueSlug(string $label): string
    {
        $base = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $label) ?? '', '-'));
        if ($base === '') {
            $base = 'doc';
        }
        $slug = $base;
        $n = 1;
        while ((int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->table() . '` WHERE slug = %s', [$slug]) > 0) {
            $n++;
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    private function table(): string
    {
        return T::table($this->db, T::DOCUMENT_TYPES);
    }
}
