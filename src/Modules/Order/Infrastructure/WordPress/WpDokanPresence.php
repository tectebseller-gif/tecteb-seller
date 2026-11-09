<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\WordPress;

use Tecteb\Marketplace\Modules\Order\Application\DokanPresenceInterface;

/**
 * Four signals, each a positive detection of Dokan, asked cheapest first.
 *
 * Nothing here touches the database and nothing here calls Dokan: a plugin
 * that is mid-fatal must still be detectable, so the test is about what
 * WordPress has loaded and what WordPress has been told to load — never
 * about whether Dokan can answer a question.
 *
 * ⛔ **And never about Dokan's tables**, for the reason written on
 * `DokanPresenceInterface`: the exit plan keeps them on purpose.
 */
final class WpDokanPresence implements DokanPresenceInterface
{
    /**
     * Plugin files that mean Dokan, matched against the active-plugin list.
     *
     * Both halves are named because they ship and are licensed separately and
     * either one splits an order: Lite registers the vendor model, Pro adds
     * the modules on top of it.
     *
     * @var list<string>
     */
    private const PLUGIN_FILES = ['dokan-lite/dokan.php', 'dokan/dokan.php', 'dokan-pro/dokan-pro.php'];

    /** Memoised per request: nothing here can change inside one. */
    private ?string $signal = null;

    public function isRunning(): bool
    {
        return $this->signal() !== '';
    }

    public function signal(): string
    {
        if ($this->signal !== null) {
            return $this->signal;
        }
        return $this->signal = $this->detect();
    }

    private function detect(): string
    {
        // The class Dokan Lite's bootstrap defines. `false` for the autoload
        // argument on purpose: asking an autoloader to FIND Dokan would be a
        // different question, and on some installs it would answer yes for a
        // plugin that is not running.
        if (class_exists('WeDevs_Dokan', false)) {
            return 'class_WeDevs_Dokan';
        }
        if (function_exists('dokan')) {
            return 'function_dokan';
        }
        if (defined('DOKAN_PLUGIN_VERSION') || defined('DOKAN_PRO_PLUGIN_VERSION')) {
            return 'constant_DOKAN_PLUGIN_VERSION';
        }
        // ⛔ The fail-closed signal. An ACTIVE Dokan that never finished
        // loading defines none of the above — `alpha.53` measured that its
        // very first line `require`s a SourceGuardian-encrypted loader, which
        // fatals outright where the extension is missing. A plugin that
        // fatals halfway may already have added filters, and in any case a
        // site whose vendor plugin is broken is not a site that should be
        // recording money through a second one.
        $active = $this->activePlugins();
        foreach (self::PLUGIN_FILES as $file) {
            if (in_array($file, $active, true)) {
                return 'active_plugin_' . $file;
            }
        }
        // And one spelling this list cannot enumerate: a renamed directory.
        // Matched on the basename alone, which is Dokan's own file name.
        foreach ($active as $file) {
            $base = basename((string) $file);
            if ($base === 'dokan.php' || $base === 'dokan-pro.php') {
                return 'active_plugin_' . (string) $file;
            }
        }
        // ⛔ AND «I COULD NOT FIND OUT» IS NOT «NO DOKAN».
        //
        // This matters more from the release that emptied
        // `OrderOperationsGate::OPEN_DECISIONS`: until then a build could not
        // sell anyway while those names were listed, so a failed read here
        // cost nothing. Now this probe is the only thing between a
        // transferred product and two systems recording one sale, and a
        // reader that answers «running: no» because it could not read the
        // option list is the fail-OPEN direction on the one question that
        // must never fail open (`alpha.97`).
        //
        // An empty array is a real answer — a site with no active plugins —
        // so emptiness is NOT treated as unknown. What is treated as unknown
        // is a list that could not be obtained at all.
        if (!$this->pluginListWasReadable()) {
            return 'active_plugin_list_unreadable';
        }
        return '';
    }

    /**
     * Whether the active-plugin list could be obtained at all.
     *
     * Separate from `activePlugins()` because the two questions have
     * different answers: that method returns `[]` both for «no plugins are
     * active» and for «the option could not be read», and only the second one
     * is a reason to refuse. `get_option()` returns the default on a missing
     * row and `false` on some failure paths, so a non-array answer to a call
     * that passed `[]` as the default is the signal.
     */
    private function pluginListWasReadable(): bool
    {
        if (!function_exists('get_option')) {
            // No WordPress at all: a unit test or a very early hook. The
            // class-, function- and constant-based signals above are the real
            // answer in that world, and claiming «unreadable» here would make
            // every pure test of this class refuse.
            return true;
        }
        return is_array(get_option('active_plugins', []));
    }

    /** @return list<string> */
    private function activePlugins(): array
    {
        $plugins = [];
        if (function_exists('get_option')) {
            $option = get_option('active_plugins', []);
            if (is_array($option)) {
                $plugins = array_values(array_filter(array_map('strval', $option)));
            }
        }
        // Multisite: a plugin active for the whole network is not in the
        // per-site list. CORE-04 declares Multisite out of scope, which is a
        // statement about what is SUPPORTED, not a reason for a safety check
        // to look away.
        if (function_exists('get_site_option')) {
            $network = get_site_option('active_sitewide_plugins', []);
            if (is_array($network)) {
                foreach (array_keys($network) as $file) {
                    $plugins[] = (string) $file;
                }
            }
        }
        return $plugins;
    }
}
