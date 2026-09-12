<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration;

/**
 * Database schema version — INDEPENDENT of the plugin release version (F-01).
 * Bump TARGET only when structure changes; a release without structural
 * change runs no migration at all.
 */
final class SchemaVersion
{
    public const TARGET = 2;

    public const OPTION = 'tmc_schema_version';

    public const LAST_ERROR_OPTION = 'tmc_migration_last_error';
}
