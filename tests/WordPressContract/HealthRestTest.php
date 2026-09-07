<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Health\Infrastructure\Rest\HealthController;
use TmcWpStubs\Rest;
use TmcWpStubs\State;

/** CORE-06: one private GET route; 401/403/200; exact schema; no-store; nothing sensitive. */
final class HealthRestTest extends ContractTestCase
{
    private function call(): \WP_Error|\WP_REST_Response
    {
        return Rest::call(HealthController::NAMESPACE, HealthController::ROUTE);
    }

    public function testRouteIsRegisteredAsReadOnlyWithPermissionCallback(): void
    {
        $this->bootPlugin(false);
        do_action('rest_api_init');
        self::assertCount(1, State::$restRoutes, 'exactly one public route in phase 1');
        $args = State::$restRoutes['tmc/v1/health'];
        self::assertSame('GET', $args['methods']);
        self::assertIsCallable($args['permission_callback']);
        self::assertIsCallable($args['callback']);
    }

    public function testGuestGets401AndSubscriberGets403(): void
    {
        $this->bootPlugin(false);
        do_action('rest_api_init');

        State::logout(); // also what WordPress does for a missing/invalid REST nonce
        $r = $this->call();
        self::assertInstanceOf(\WP_Error::class, $r);
        self::assertSame(401, $r->get_error_data()['status']);

        State::loginAs(5, ['read']);
        $r = $this->call();
        self::assertInstanceOf(\WP_Error::class, $r);
        self::assertSame(403, $r->get_error_data()['status']);
        self::assertStringContainsString('مجوز', $r->get_error_message());

        State::loginAs(6, ['read', 'edit_products', 'dokandar']); // existing seller role without tmc capability
        self::assertSame(403, $this->call()->get_error_data()['status']);

        State::loginAs(7, ['manage_options']); // nonce/cookie alone (even an admin) is not authorisation
        self::assertSame(403, $this->call()->get_error_data()['status']);
    }

    public function testAuthorizedResponseMatchesContractExactly(): void
    {
        $this->bootPlugin(false);
        do_action('rest_api_init');
        State::loginAs(1, ['tmc_view_health']);
        $r = $this->call();
        self::assertInstanceOf(\WP_REST_Response::class, $r);
        self::assertSame(200, $r->get_status());
        $data = $r->get_data();
        self::assertSame(['schema_version', 'plugin', 'environment', 'dependencies', 'outbound', 'modules', 'checked_at'], array_keys($data));
        self::assertSame('1', $data['schema_version']);
        self::assertSame(['version' => self::VERSION], $data['plugin']);
        self::assertSame(['resolved', 'source'], array_keys($data['environment']));
        self::assertSame(['available' => false, 'version' => null], $data['dependencies']['woocommerce']);
        self::assertSame(['enabled' => null], $data['dependencies']['hpos']);
        self::assertSame(['tmc' => 'blocked', 'other_plugins' => 'unknown'], $data['outbound']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['checked_at']);
        foreach ($data['modules'] as $m) {
            self::assertSame(['id', 'status'], array_keys($m));
            self::assertContains($m['status'], ['planned', 'active', 'degraded', 'blocked']);
        }
        self::assertContains(['id' => 'health', 'status' => 'active'], $data['modules']);

        $headers = $r->get_headers();
        self::assertStringContainsString('no-store', $headers['Cache-Control']);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);

        $json = json_encode($data, JSON_THROW_ON_ERROR);
        foreach (['/srv/', 'wp-content', 'ABSPATH', 'php_version', PHP_VERSION, 'user', 'email', 'exception', 'trace'] as $needle) {
            self::assertStringNotContainsString($needle, $json, "response must not contain {$needle}");
        }
    }

    public function testHposEnabledIsReportedAsGivenNeverCoerced(): void
    {
        $this->bootPlugin(true, '11.0.1', false);
        do_action('rest_api_init');
        State::loginAs(1, ['tmc_view_health']);
        $data = $this->call()->get_data();
        self::assertSame(['available' => true, 'version' => '11.0.1'], $data['dependencies']['woocommerce']);
        self::assertFalse($data['dependencies']['hpos']['enabled']);
    }
}
