<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Core\Environment\EnvironmentResolver;
use Tecteb\Marketplace\Core\Environment\EnvironmentType;

/**
 * The switch that lets the order path be EXERCISED while the financial rules
 * are still open — and that refuses to exist on a production site.
 *
 * The owner's instruction has two halves and this class is where they meet:
 * «قواعد مالی حل‌نشده همچنان مانع عملیاتی‌شدن سفارش جدید بازارگاه باشند» and
 * «داده و قواعد نمونه فقط برای آزمون استفاده شوند». A trial needs the path to
 * run end to end; the owner's marketplace must not sell on rules nobody
 * approved. So the unlock is:
 *
 *  - **off by default**, and stored as an option somebody has to set;
 *  - **refused** unless the environment RESOLVES to staging, development or
 *    local. Unknown counts as production, because an environment nobody
 *    declared might be one;
 *  - **loud**: the modules screen and the order pages say the module is
 *    running on trial rules, so no screenshot of it can be mistaken for the
 *    real thing.
 *
 * It never changes what is recorded. A trial order writes the same ledger
 * lines under the same rules as a real one; what the trial waives is only
 * the requirement that the decisions be closed.
 */
final class TrialUnlock implements OrderTrialInterface
{
    public const OPTION = 'tmc_order_trial_mode';

    /**
     * Environments allowed to honour the switch.
     *
     * A method rather than a `const` because fetching `->value` off an enum
     * inside a constant expression is a PHP 8.2 feature, and this plugin runs
     * on 8.1 — where it is a fatal at COMPILE time, so the whole site would
     * die rather than one page. Found by installing the built package on real
     * PHP 8.1 (the build machine is 8.4 and never noticed).
     *
     * @return list<string>
     */
    private static function allowed(): array
    {
        return [
            EnvironmentType::Staging->value,
            EnvironmentType::Development->value,
            EnvironmentType::Local->value,
        ];
    }

    public function __construct(
        private readonly OptionStoreInterface $options,
        private readonly EnvironmentResolver $environment
    ) {
    }

    /** Whether the switch is on AND this environment is allowed to honour it. */
    public function isActive(): bool
    {
        return $this->isRequested() && $this->isPermitted();
    }

    public function isRequested(): bool
    {
        return (bool) $this->options->get(self::OPTION, false);
    }

    public function isPermitted(): bool
    {
        return in_array($this->environment->resolve()->type->value, self::allowed(), true);
    }

    /** '', or why the switch is not being honoured. */
    public function refusal(): string
    {
        if (!$this->isRequested()) {
            return '';
        }
        return $this->isPermitted() ? '' : 'environment_' . $this->environment->resolve()->type->value;
    }

    public function environmentName(): string
    {
        return $this->environment->resolve()->type->value;
    }
}
