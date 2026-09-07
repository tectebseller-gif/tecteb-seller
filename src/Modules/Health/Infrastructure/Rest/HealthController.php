<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Health\Infrastructure\Rest;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Health\Application\HealthReportBuilder;

/**
 * GET /wp-json/tmc/v1/health (CORE-06).
 * permission_callback = tmc_view_health. Cookie auth in wp-admin requires a
 * valid REST nonce for the user to be recognised at all; the nonce alone is
 * never authorisation — the capability decides.
 */
final class HealthController
{
    public const NAMESPACE = 'tmc/v1';
    public const ROUTE = '/health';

    public function __construct(private HealthReportBuilder $builder, private CapabilityCheckerInterface $caps)
    {
    }

    public function registerRoute(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'handle'],
            'permission_callback' => [$this, 'permission'],
            'schema' => [$this, 'schema'],
        ]);
    }

    public function permission(): bool|\WP_Error
    {
        if ($this->caps->can(Capabilities::VIEW_HEALTH)) {
            return true;
        }
        return new \WP_Error(
            'tmc_forbidden',
            __('شما مجوز مشاهده وضعیت سلامت را ندارید.', 'tecteb-marketplace-core'),
            ['status' => rest_authorization_required_code()]
        );
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $response = new \WP_REST_Response($this->builder->build()->toContractArray(), 200);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    /** JSON schema of the response; also published in docs/public-contracts.md. */
    public function schema(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-04/schema#',
            'title' => 'tmc-health',
            'type' => 'object',
            'properties' => [
                'schema_version' => ['type' => 'string', 'enum' => ['1']],
                'plugin' => ['type' => 'object', 'properties' => ['version' => ['type' => 'string']]],
                'environment' => ['type' => 'object', 'properties' => [
                    'resolved' => ['type' => 'string', 'enum' => ['production', 'staging', 'development', 'local', 'unknown']],
                    'source' => ['type' => 'string', 'enum' => ['constant', 'option', 'platform', 'none']],
                ]],
                'dependencies' => ['type' => 'object', 'properties' => [
                    'woocommerce' => ['type' => 'object', 'properties' => [
                        'available' => ['type' => 'boolean'],
                        'version' => ['type' => ['string', 'null']],
                    ]],
                    'hpos' => ['type' => 'object', 'properties' => ['enabled' => ['type' => ['boolean', 'null']]]],
                ]],
                'outbound' => ['type' => 'object', 'properties' => [
                    'tmc' => ['type' => 'string', 'enum' => ['blocked']],
                    'other_plugins' => ['type' => 'string', 'enum' => ['unknown']],
                ]],
                'modules' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['planned', 'active', 'degraded', 'blocked']],
                ]]],
                'checked_at' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];
    }
}
