<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Operations;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\ModuleInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Contracts\OutboxRepositoryInterface;
use Tecteb\Marketplace\Core\Environment\EnvironmentResolver;
use Tecteb\Marketplace\Core\Jobs\JobRunner;
use Tecteb\Marketplace\Infrastructure\Jobs\DbOutboxRepository;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Operations\Application\DeliverEventsJob;
use Tecteb\Marketplace\Modules\Operations\Application\RecordEvent;
use Tecteb\Marketplace\Modules\Operations\Infrastructure\Rest\MarketplaceController;
use Tecteb\Marketplace\Modules\Operations\Presentation\Admin\AuditPage;
use Tecteb\Marketplace\Modules\Operations\Presentation\Admin\QueuePage;
use Tecteb\Marketplace\Modules\Operations\Presentation\Admin\EventsPage;
use Tecteb\Marketplace\Modules\Operations\Presentation\Admin\SetupWizardPage;

/**
 * Running the thing, as opposed to selling with it: the queue, the trail and
 * the first-run path.
 *
 * It depends on `core` only, and NOT on WooCommerce. That is deliberate: the
 * three questions these pages answer — «is the queue moving», «who did what»
 * and «what is still unconfigured» — are exactly the questions somebody asks
 * when the shop is not working, which is often precisely when WooCommerce is
 * missing or off. A module that hid itself in that case would disappear at the
 * moment it was needed.
 *
 * It owns no tables. The jobs and outbox tables are core schema, because the
 * queue must exist before any module that queues work does.
 */
final class OperationsModule implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            'operations',
            $this->version(),
            'عملیات و ممیزی',
            ModuleKind::Infrastructure,
            ['core'],
            false,
            'صف کارهای زمان‌بر با ثبت نقطهٔ توقف، فهرست ممیزی قابل فیلتر، مسیر راه‌اندازی اولیه، و قرارداد خواندنی API و رویداد — با ارسال بیرونی بسته'
        );
    }

    /** The option holding the signing secret; created once, never shown. */
    public const SECRET_OPTION = 'tmc_event_secret';

    public function register(ContainerInterface $c): void
    {
        $c->bind(OutboxRepositoryInterface::class, static fn (ContainerInterface $c) => new DbOutboxRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(RecordEvent::class, static fn (ContainerInterface $c) => new RecordEvent(
            $c->get(OutboxRepositoryInterface::class),
            $c->get(ClockInterface::class),
            self::secret($c->get(OptionStoreInterface::class))
        ));
    }

    /**
     * The HMAC secret, made on first use and kept in one option.
     *
     * It is generated rather than configured because there is nobody to
     * configure it with: no endpoint exists to agree a shared secret against.
     * When one does, the secret is what both sides already have, and the
     * signatures on rows recorded before that day still verify — which is the
     * whole reason it is created now and not on the day of the first delivery.
     *
     * It is never printed on a page, never in an audit payload, and never in a
     * REST response. The contract endpoint publishes the ALGORITHM, which is
     * what an integrator needs, and not the key.
     */
    private static function secret(OptionStoreInterface $options): string
    {
        $stored = (string) $options->get(self::SECRET_OPTION, '');
        if ($stored !== '') {
            return $stored;
        }
        $fresh = bin2hex(random_bytes(32));
        $options->set(self::SECRET_OPTION, $fresh);
        return $fresh;
    }

    public function boot(ContainerInterface $c): void
    {
        $c->get(JobRunner::class)->register(new DeliverEventsJob(
            $c->get(OutboxRepositoryInterface::class),
            $c->get(EnvironmentResolver::class)
        ));

        $controller = new MarketplaceController($c);
        add_action('rest_api_init', [$controller, 'registerRoutes']);

        $pages = [
            [SetupWizardPage::SLUG, SetupWizardPage::CAPABILITY, new SetupWizardPage($c), SetupWizardPage::menuLabel()],
            [QueuePage::SLUG, QueuePage::CAPABILITY, new QueuePage($c), QueuePage::menuLabel()],
            [EventsPage::SLUG, EventsPage::CAPABILITY, new EventsPage($c), EventsPage::menuLabel()],
            [AuditPage::SLUG, AuditPage::CAPABILITY, new AuditPage($c), AuditPage::menuLabel()],
        ];
        add_filter(AdminExtensions::FILTER, static function (array $registered) use ($pages): array {
            foreach ($pages as [$slug, $capability, $page, $label]) {
                $registered[] = [
                    'slug' => $slug,
                    'page_title' => $label,
                    'menu_label' => $label,
                    'capability' => $capability,
                    'render' => [$page, 'render'],
                ];
            }
            return $registered;
        });
    }

    private function version(): string
    {
        return class_exists(\Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::class, false)
            ? \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::pluginVersion()
            : '0.0.0';
    }
}
