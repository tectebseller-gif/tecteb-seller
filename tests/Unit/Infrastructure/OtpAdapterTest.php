<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Contracts\Otp\OtpSendRequest;
use Tecteb\Marketplace\Contracts\Otp\OtpStatus;
use Tecteb\Marketplace\Contracts\Otp\OtpVerifyRequest;
use Tecteb\Marketplace\Infrastructure\Otp\KamangirSmartLoginAdapter;

/**
 * WHAT THIS PROVES: the gateway adapter cannot send, and cannot be made to.
 *
 * The interesting assertion is the last one. It is easy to write an adapter
 * that returns `Unavailable` today and starts sending the day somebody adds a
 * setting; these tests pin the refusal to the CODE rather than to a
 * configuration, by asserting that the class contains no outbound call at all.
 */
final class OtpAdapterTest extends TestCase
{
    public function testEveryCallIsUnavailable(): void
    {
        $adapter = new KamangirSmartLoginAdapter();
        self::assertSame(OtpStatus::Unavailable, $adapter->sendChallenge(new OtpSendRequest('09120000000', 'login'))->status);
        self::assertSame(OtpStatus::Unavailable, $adapter->verifyChallenge(new OtpVerifyRequest('09120000000', '123456', 'login'))->status);
    }

    public function testProbeNeverReportsItUsableAndAlwaysNamesWhy(): void
    {
        $probe = KamangirSmartLoginAdapter::probe();
        self::assertFalse($probe['usable'], 'no branch of probe() may call this usable');
        self::assertSame('no_published_php_contract', $probe['reason']);
    }

    public function testTheAdapterContainsNoOutboundCallAtAll(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Infrastructure/Otp/KamangirSmartLoginAdapter.php'
        );
        // Strip the docblocks: they DESCRIBE the wire contract, and naming
        // `wp_remote_post` in a sentence about why it is not called must not
        // fail the test that says it is not called.
        $code = (string) preg_replace('#/\*\*.*?\*/#s', '', $source);
        foreach ([
            'wp_remote_post', 'wp_remote_get', 'wp_remote_request',
            'curl_exec', 'file_get_contents(\'http', 'fsockopen', 'stream_socket_client',
            'admin-ajax.php', 'do_action', 'apply_filters',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                'the adapter must not reach the gateway or the network: ' . $forbidden
            );
        }
    }

    /** The description is data for the handoff, so it has to stay complete. */
    public function testObservedContractNamesTheEncoderAndTheBlocker(): void
    {
        $contract = KamangirSmartLoginAdapter::observedContract();
        self::assertFalse($contract['php_source_readable']);
        self::assertSame('sourceguardian', $contract['php_encoder']);
        self::assertSame([], $contract['hooks_published'], 'the package publishes no PHP hook');
        self::assertSame('no_published_php_contract', $contract['blocker']);
        self::assertArrayHasKey('send_handler', $contract['operations']);
    }
}
