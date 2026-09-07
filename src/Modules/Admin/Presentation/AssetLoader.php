<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation;

/**
 * Styles and script ONLY on the plugin's own admin pages (CORE-05):
 * nothing on Posts, the homepage or any other screen. No CDN.
 */
final class AssetLoader
{
    public const STYLE_HANDLE = 'tmc-admin';
    public const SCRIPT_HANDLE = 'tmc-admin';

    public function __construct(private MenuRegistrar $menu, private string $baseUrl, private string $version)
    {
    }

    public function enqueue(string $hookSuffix): void
    {
        if (!$this->isPluginScreen($hookSuffix)) {
            return;
        }
        wp_enqueue_style(self::STYLE_HANDLE, $this->baseUrl . 'assets/admin/tmc-admin.css', [], $this->version, 'all');
        wp_enqueue_script(self::SCRIPT_HANDLE, $this->baseUrl . 'assets/admin/tmc-admin.js', [], $this->version, ['in_footer' => true, 'strategy' => 'defer']);
    }

    public function isPluginScreen(string $hookSuffix): bool
    {
        return in_array($hookSuffix, $this->menu->hookSuffixes(), true);
    }
}
