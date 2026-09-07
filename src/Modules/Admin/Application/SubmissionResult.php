<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Application;

use Tecteb\Marketplace\Core\Audit\AuditResult;
use Tecteb\Marketplace\Core\Config\Settings;

final class SubmissionResult
{
    /**
     * @param array<string,string> $errors  input field => error code
     * @param array<string,array{old:mixed,new:mixed}> $changes
     */
    public function __construct(
        public readonly bool $authorized,
        public readonly Settings $settings,
        public readonly array $errors,
        public readonly array $changes,
        public readonly ?AuditResult $audit
    ) {
    }

    public function auditFailed(): bool
    {
        return $this->audit !== null && !$this->audit->ok;
    }
}
