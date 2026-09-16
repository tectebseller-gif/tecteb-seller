<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\Otp;

use Tecteb\Marketplace\Contracts\Otp\OtpProviderInterface;
use Tecteb\Marketplace\Contracts\Otp\OtpSendRequest;
use Tecteb\Marketplace\Contracts\Otp\OtpSendResult;
use Tecteb\Marketplace\Contracts\Otp\OtpStatus;
use Tecteb\Marketplace\Contracts\Otp\OtpVerifyRequest;
use Tecteb\Marketplace\Contracts\Otp\OtpVerifyResult;

/**
 * The adapter for «دروازه» (Kamangir Smart Login) — which detects it, describes
 * exactly how it would be talked to, and sends nothing.
 *
 * ## What the reference copy actually contains
 *
 * All 162 of the plugin's own PHP files, and both bundled Kamangir SDK modules,
 * are **SourceGuardian-encoded** — every one begins with the `sg_load` stub and
 * needs the `ixed` loader extension for the exact PHP version to run at all.
 * So there is no PHP source to read: not a function signature, not an option
 * key, and — measured, not assumed — **not a single `do_action` or
 * `apply_filters` anywhere in the package**. A grep for both across the whole
 * archive returns nothing, because there is nothing in the encoded bytes for it
 * to find.
 *
 * That is the finding, and it decides this class. An adapter is written against
 * a documented public API (CORE-09 / OTP-01). This plugin publishes none.
 *
 * ## The contract that IS observable, read from the shipped JavaScript
 *
 * The browser-facing wire is fully legible, because the JS is not encoded:
 *
 *  - **Transport.** One POST to `ksmAjaxData.ajax_url` carrying the single
 *    action in `ksmAjaxData.action`, the nonce in `security`, and an
 *    `operation` field that selects the sub-command. The response is read as
 *    `responseJSON.data`, i.e. WordPress's own `wp_send_json_*` envelope.
 *  - **`operation: 'send_handler'`** with `receiver` and `key_action` answers
 *    `{send: bool, message: string, mobile?: string, code?: string}`; `code`
 *    can be `otp_already_sent`.
 *  - **`operation: 'mv_verifyotp'`** with `mobile` and `otp` answers
 *    `{status: 'success'|…, message}`.
 *  - **`operation: 'wc_integration_verify_otp'`** with `receiver` and `otp`
 *    answers `{success: bool, message}`; on success the checkout form gains a
 *    hidden `ksm-otp` field carrying the verified code.
 *  - **`KsmOtpConfig`** supplies `otp_length` and `resend_timer`.
 *  - **The only deliberate extension points** are two jQuery document events,
 *    `ksm/otp/confirm/{operatorName}` (with form, receiver, otp, handler) and
 *    `ksm/otp/back/{operatorName}`. Those are fired for third parties on
 *    purpose, and they are in the browser, not in PHP.
 *
 * ## Why knowing all that still does not make this send
 *
 * Every one of those operations is the plugin's own private interface with its
 * own JavaScript, guarded by a nonce minted for a browser session. Calling it
 * from PHP would mean forging that nonce and depending on internal operation
 * names that the vendor has never promised to keep — a shape that works on the
 * day it is written and breaks silently on their next release, in the flow that
 * decides whether somebody can log in. And it would be a plugin reaching into
 * another plugin's private endpoint, which is the thing this project refuses to
 * do to Dokan and will not do here either.
 *
 * So `sendChallenge()` answers `Unavailable` with `reason_code =
 * no_published_php_contract`, and it does so whether or not the gateway is
 * installed and active. That is not a placeholder: it is the correct answer
 * until the vendor publishes a PHP API, and `probe()` gives a manager the exact
 * facts to take to them.
 *
 * Nothing in this file was copied from the reference. It was read — its
 * JavaScript and its plugin header — and described. Its code is not executed,
 * not bundled, and its licence section is untouched.
 */
final class KamangirSmartLoginAdapter implements OtpProviderInterface
{
    /** The plugin's own text domain, which is how it is recognised. */
    public const TEXT_DOMAIN = 'ak-sm-sdk';

    /** Set by its bootstrap; present iff the plugin is loaded. */
    public const FILE_CONSTANT = 'SMARTLOGIN_FILE';

    /** The version of the reference copy this description was read from. */
    public const OBSERVED_VERSION = '2.2.3.1';

    /** The single admin-ajax sub-commands its own JavaScript uses. */
    public const OBSERVED_OPERATIONS = [
        'send_handler' => ['receiver', 'key_action'],
        'mv_verifyotp' => ['mobile', 'otp'],
        'wc_integration_verify_otp' => ['receiver', 'otp'],
    ];

    /** The two jQuery events it fires for third parties. Browser-side only. */
    public const OBSERVED_JS_EVENTS = [
        'ksm/otp/confirm/{operator}',
        'ksm/otp/back/{operator}',
    ];

    public const REASON = 'no_published_php_contract';

    public function sendChallenge(OtpSendRequest $request): OtpSendResult
    {
        return new OtpSendResult(OtpStatus::Unavailable, null, null);
    }

    public function verifyChallenge(OtpVerifyRequest $request): OtpVerifyResult
    {
        return new OtpVerifyResult(OtpStatus::Unavailable);
    }

    /**
     * Is the gateway on this site, and does anything about it change the
     * answer above?
     *
     * The second half is the useful half, and it is always no. A manager
     * looking at «دروازه نصب است ولی استفاده نمی‌شود» deserves to know that this
     * is a deliberate refusal with a named cause, not a configuration they
     * forgot.
     *
     * @return array{installed:bool, active:bool, version:string, usable:bool, reason:string}
     */
    public static function probe(): array
    {
        $active = defined(self::FILE_CONSTANT);
        $installed = $active;
        if (!$installed && function_exists('get_plugins')) {
            foreach (array_keys((array) get_plugins()) as $plugin) {
                if (str_contains((string) $plugin, 'kamangir-smart-login')) {
                    $installed = true;
                    break;
                }
            }
        }
        return [
            'installed' => $installed,
            'active' => $active,
            'version' => $active && defined('SMARTLOGIN_VERSION')
                ? (string) constant('SMARTLOGIN_VERSION')
                : '',
            // Never true in this release, and not because of a setting.
            'usable' => false,
            'reason' => self::REASON,
        ];
    }

    /**
     * The contract as read, for the handoff document and the health page.
     *
     * @return array<string,mixed>
     */
    public static function observedContract(): array
    {
        return [
            'source' => 'javascript_only',
            'php_source_readable' => false,
            'php_encoder' => 'sourceguardian',
            'hooks_published' => [],
            'observed_version' => self::OBSERVED_VERSION,
            'transport' => 'admin-ajax single action with operation field and security nonce',
            'operations' => self::OBSERVED_OPERATIONS,
            'js_events' => self::OBSERVED_JS_EVENTS,
            'checkout_field' => 'ksm-otp',
            'blocker' => self::REASON,
        ];
    }
}
