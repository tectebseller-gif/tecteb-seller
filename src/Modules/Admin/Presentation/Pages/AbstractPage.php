<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation\Pages;

use Tecteb\Marketplace\Contracts\ContainerInterface;

abstract class AbstractPage
{
    public const SLUG = '';
    public const CAPABILITY = '';

    public function __construct(protected ContainerInterface $container)
    {
    }

    abstract public static function menuLabel(): string;

    abstract public static function pageTitle(): string;

    /** Renders or dies with a neutral Persian 403 (UX §17: no data existence leaked). */
    public function render(): void
    {
        if (!current_user_can(static::CAPABILITY)) {
            wp_die(
                esc_html__('برای مشاهده این صفحه مجوز لازم را ندارید.', 'tecteb-marketplace-core'),
                esc_html__('دسترسی غیرمجاز', 'tecteb-marketplace-core'),
                ['response' => 403]
            );
        }
        $this->renderAuthorized();
    }

    abstract protected function renderAuthorized(): void;
}
