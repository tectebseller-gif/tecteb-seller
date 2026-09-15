<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\EnvironmentProbeInterface;
use Tecteb\Marketplace\Core\Config\SettingsService;

final class WpEnvironmentProbe implements EnvironmentProbeInterface
{
    public const CONSTANT = 'TMC_ENVIRONMENT';

    public function __construct(private SettingsService $settings)
    {
    }

    public function constantValue(): ?string
    {
        if (!defined(self::CONSTANT)) {
            return null;
        }
        $value = constant(self::CONSTANT);
        return is_scalar($value) ? (string) $value : '';
    }

    public function optionValue(): ?string
    {
        return $this->settings->load()->environmentOverride;
    }

    public function platformEnvironment(): ?string
    {
        return function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : null;
    }

    public function hostname(): ?string
    {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : null;
    }
}
