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

    /** The key inside the stored grant that names the site it was granted on. */
    private const HOST = 'host';
    /** The key that says the grant is on. */
    private const GRANTED = 'granted';

    /**
     * Whether the switch is on, this environment may honour it, AND the grant
     * was made on THIS site.
     *
     * ⛔ The third condition is the one added this round, and it is the only
     * guard here that a wrong `TMC_ENVIRONMENT` cannot talk its way past.
     *
     * The resolution order is constant → option → platform (CORE-04), so a
     * production site whose `wp-config.php` says `TMC_ENVIRONMENT` is
     * `staging` — or whose `WP_ENVIRONMENT_TYPE` does — resolves to staging
     * and `isPermitted()` says yes. That is not a defect in the resolver:
     * a declared environment must win over a guess. But it means the FIRST
     * two conditions are both carried by things a site copy carries: the
     * option travels in the database and the constant travels in the file.
     * And a copy is not hypothetical here — `PRODUCTION-CUTOVER-FA.md` is a
     * plan to clone a tested staging site onto production.
     *
     * The host does not travel. So the grant records the site it was made on
     * and is refused anywhere else, with a named reason on the screen.
     */
    public function isActive(): bool
    {
        return $this->isRequested() && $this->isPermitted() && $this->isForThisSite();
    }

    public function isRequested(): bool
    {
        $stored = $this->options->get(self::OPTION, false);
        if (is_array($stored)) {
            return (bool) ($stored[self::GRANTED] ?? false);
        }
        // A scalar truthy value is a grant written by `alpha.97` or earlier,
        // before grants carried a host. It still means «somebody asked» —
        // `isForThisSite()` is what refuses it.
        return (bool) $stored;
    }

    public function isPermitted(): bool
    {
        return in_array($this->environment->resolve()->type->value, self::allowed(), true);
    }

    /**
     * Whether the stored grant names this site.
     *
     * ⛔ **Fail-closed, and that has a cost that is paid on purpose.** A grant
     * with no host recorded — every grant that exists today — answers `false`,
     * so the first thing this round does on the owner's staging site is turn
     * the trial off. The screen says exactly why and the button re-grants it
     * in one press. The alternative was to treat an unhosted grant as valid
     * everywhere, which is the state this guard exists to end.
     *
     * A site whose own host cannot be read answers `false` too: «I do not
     * know which site this is» is not a reason to record money under sample
     * rules.
     */
    public function isForThisSite(): bool
    {
        $stored = $this->options->get(self::OPTION, false);
        if (!is_array($stored)) {
            return false;
        }
        $granted = strtolower(trim((string) ($stored[self::HOST] ?? '')));
        $here = $this->environment->host();
        return $granted !== '' && $here !== null && hash_equals($granted, $here);
    }

    /** '', or why the switch is not being honoured. */
    public function refusal(): string
    {
        if (!$this->isRequested()) {
            return '';
        }
        if (!$this->isPermitted()) {
            return 'environment_' . $this->environment->resolve()->type->value;
        }
        if (!$this->isForThisSite()) {
            // Two reasons, not one: «this grant was made somewhere else» and
            // «this grant does not say where it was made» are different pieces
            // of work. The first is a copied database and wants a human to
            // look; the second is simply an old grant and wants one press.
            $stored = $this->options->get(self::OPTION, false);
            return is_array($stored) && (string) ($stored[self::HOST] ?? '') !== ''
                ? 'host_changed'
                : 'host_unrecorded';
        }
        return '';
    }

    public function environmentName(): string
    {
        return $this->environment->resolve()->type->value;
    }

    /**
     * Ask for the switch, or stop asking.
     *
     * Until `alpha.23` the option had no writer anywhere in the plugin: it
     * could only be set from a terminal, by a tool that refuses to run off the
     * disposable install. So the owner's own staging site could satisfy every
     * other condition and still never open the order module — and the guide
     * had to end in «ask us to run a command», which is exactly the hidden
     * developer preparation the owner ruled out.
     *
     * Requesting is deliberately NOT the same as being granted. This writes
     * the request and nothing else; `isPermitted()` still decides whether it
     * is honoured, so a production site can hold the option at `1` for ever
     * and sell nothing. That separation is why the setter is safe to expose:
     * the dangerous half was never in the option.
     */
    public function request(bool $on): bool
    {
        if (!$on) {
            return $this->options->delete(self::OPTION);
        }
        // The host is recorded WITH the grant, in one write, because a grant
        // and the site it was granted on are one fact. A second row would be
        // a second thing to go missing.
        $here = $this->environment->host();
        if ($here === null) {
            // Nothing to bind the grant to, so no grant. Refusing here rather
            // than storing an unbound one keeps `isForThisSite()` the only
            // place that decides, and keeps the stored shape honest.
            return false;
        }
        return $this->options->set(self::OPTION, [self::GRANTED => true, self::HOST => $here]);
    }
}
