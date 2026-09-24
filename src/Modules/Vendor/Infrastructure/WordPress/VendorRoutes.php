<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\Auth\AuthenticationBridgeInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\FlashStoreInterface;
use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Vendor\Application\DocumentRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\SaveApplicationDraft;
use Tecteb\Marketplace\Modules\Vendor\Application\SubmitApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\UploadApplicationDocument;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorCapabilities;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorWorkspaceFactory;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Presentation\ApplicationView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\DashboardView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorMessages;
use Tecteb\Marketplace\Modules\Vendor\Application\AcceptStaffInvitation;
use Tecteb\Marketplace\Modules\Vendor\Application\ManageStaff;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\UpdateStoreSettings;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorListsInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Presentation\InviteView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\StaffView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\StoreView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Modules\Vendor\Application\ChangeRequestRepositoryInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\ActionQueue;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffActivityReport;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings;
use Tecteb\Marketplace\Contracts\Files\PrivateFileStorageInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\MobileVerification;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaExtensions;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorNotice;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorShell;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * The vendor area's front controller: its own routes, its own page, outside
 * wp-admin (plan §6).
 *
 * Order of checks on every request, without exception: signed in → has the
 * capability → nonce valid → then act. Writes answer with a redirect (POST /
 * redirect / GET), so a refresh never repeats an upload or a submission.
 */
final class VendorRoutes
{
    public const QUERY_VAR = 'tmc_vendor';
    public const NONCE_ACTION = 'tmc_vendor_action';
    public const NONCE_FIELD = 'tmc_vendor_nonce';

    /** @var list<string> the views this module itself answers on */
    public const VIEWS = ['dashboard', 'application', 'store', 'staff', 'invite'];

    /** The only view a signed-out visitor may reach: the token vouches for them. */
    public const PUBLIC_VIEW = 'invite';

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly string $assetsBaseUrl,
        private readonly string $version
    ) {
    }

    public static function register(ContainerInterface $container, string $assetsBaseUrl, string $version): void
    {
        $routes = new self($container, $assetsBaseUrl, $version);
        // Before anything else: the panel is per-person and must never be
        // cached. Registered here so it is hooked whether or not a request
        // ever reaches `handle()`.
        VendorCacheControl::register();
        add_action('init', [self::class, 'addRewriteRules']);
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;
            return $vars;
        });
        add_action('template_redirect', [$routes, 'handle']);
        // The way in, from the page a customer already has. Registered here
        // rather than in the account module because THIS class is the one
        // that knows the address.
        VendorAccountLink::register($container);
    }

    /**
     * The dashboard's public address, for anything outside this router.
     *
     * Static and built from `home_url()` so nothing else has to repeat the
     * path or guess at the permalink setting — «نشانی را از مسیر معتبر
     * افزونه و دامنهٔ جاری بساز؛ staging را hardcode نکن».
     */
    public static function dashboardUrl(): string
    {
        return (string) get_option('permalink_structure', '') !== ''
            ? home_url('/vendor/')
            : home_url('/?' . self::QUERY_VAR . '=dashboard');
    }

    public static function addRewriteRules(): void
    {
        add_rewrite_rule('^vendor/?$', 'index.php?' . self::QUERY_VAR . '=dashboard', 'top');
        add_rewrite_rule('^vendor/([a-z\-]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
    }

    public function handle(): void
    {
        $request = Request::capture();
        $view = $this->requestedView($request);
        $docId = $request->queryInt('tmc_doc');
        if ($view === null && $docId <= 0) {
            return;
        }

        // Nothing under /vendor/ may be cached, and saying so has to happen
        // HERE — before the login redirect, before any render — because that
        // is when a page cache decides.
        //
        // The owner saw the panel report «no application» right after signing
        // in again, while the manager was looking at the same file approved.
        // This code cannot produce that pair: `findApplicationByUser()` is an
        // uncached `SELECT … WHERE user_id = %d`, and a signed-out visitor is
        // redirected to the login form rather than shown an empty panel. So
        // the HTML in that tab was not produced by this request — which is
        // what a page cache does, and their site runs one (WP Rocket).
        //
        // That is a hypothesis, not a diagnosis, and it is written down as
        // one. What is NOT a hypothesis: `nocache_headers()` alone was being
        // called at the very end of the render, and page caches do not read
        // `Cache-Control` — they read these constants. `DONOTCACHEPAGE` is
        // the contract WP Rocket, W3 Total Cache, LiteSpeed and WP Super
        // Cache all honour, and until now the plugin never set it.
        $this->refuseCaching();

        // The invitation page is the one door a signed-out person may open:
        // they have no account they can log into yet, and the token — long,
        // hashed at rest, single use and expiring — is what vouches for them.
        if ($view === self::PUBLIC_VIEW) {
            $this->handleInvitation($request);
            return;
        }

        $auth = $this->container->get(AuthenticationBridgeInterface::class);
        if (!$auth->isLoggedIn()) {
            wp_safe_redirect($auth->loginUrl($this->currentUrl()));
            exit;
        }
        if (!current_user_can(VendorCapabilities::APPLY)) {
            $this->deny();
        }
        $userId = (int) $auth->currentUserId();

        if ($docId > 0) {
            $this->serveDocument($request, $docId, $userId);
        }

        if ($request->isPost()) {
            $this->handlePost($request, $userId);
        }

        $this->renderPage($request, $view ?? 'dashboard', $userId);
    }

    /**
     * «Do not cache this», in the three forms page caches actually look at.
     *
     * Constants rather than headers: by the time a header is sent, a cache
     * that decided at `init` has already decided. Defined, never redefined —
     * another plugin may have set them first and it means the same thing.
     */
    private function refuseCaching(): void
    {
        // The real refusal happens at `plugins_loaded` — see
        // VendorCacheControl, and the note there about why `template_redirect`
        // is both too late and only half the problem. This stays as the
        // second belt: it costs nothing, and it still covers a request that
        // arrives through some path where that hook did not run.
        VendorCacheControl::refuseIfVendorRequest();
        if (!headers_sent()) {
            nocache_headers();
        }
    }

    // ---------------------------------------------------------------- routing

    private function requestedView(Request $request): ?string
    {
        $raw = sanitize_key((string) get_query_var(self::QUERY_VAR, ''));
        if ($raw === '') {
            $raw = $request->queryKey(self::QUERY_VAR);
        }
        if ($raw === '') {
            return null;
        }
        if ($request->queryKey('view') === 'application') {
            return 'application';
        }
        if (in_array($raw, self::VIEWS, true) || $this->extension($raw) !== null) {
            return $raw;
        }
        return 'dashboard';
    }

    private function urls(): VendorUrls
    {
        $pretty = (string) get_option('permalink_structure', '') !== '';
        if ($pretty) {
            return new VendorUrls(
                home_url('/vendor/'),
                home_url('/vendor/application/'),
                home_url('/vendor/store/'),
                home_url('/vendor/staff/'),
                home_url('/vendor/invite/'),
                home_url('/vendor/products/')
            );
        }
        $byQuery = static fn (string $view): string => home_url('/?' . self::QUERY_VAR . '=' . $view);
        return new VendorUrls(
            $byQuery('dashboard'),
            $byQuery('application'),
            $byQuery('store'),
            $byQuery('staff'),
            $byQuery('invite'),
            $byQuery('products')
        );
    }

    private function currentUrl(): string
    {
        return home_url(add_query_arg([]));
    }

    // ----------------------------------------------------------------- writes

    private function handlePost(Request $request, int $userId): void
    {
        $urls = $this->urls();
        $action = $request->postKey('tmc_vendor_action');
        if (!$request->nonceOk(self::NONCE_FIELD, self::NONCE_ACTION)) {
            wp_safe_redirect($urls->withNotice($urls->application(), 'forbidden'));
            exit;
        }

        $handled = $this->handleExtensionPost($action, $request, $userId);
        if ($handled !== null) {
            wp_safe_redirect($this->flashAndTarget($handled, $userId));
            exit;
        }

        $result = match ($action) {
            'save_draft' => $this->container->get(SaveApplicationDraft::class)->handle($userId, $this->detailsFromPost($request)),
            'upload' => $this->container->get(UploadApplicationDocument::class)->handle(
                $userId,
                $request->postKey('type_slug'),
                $request->file('document')
            ),
            'submit' => $this->container->get(SubmitApplication::class)->handle($userId),
            'save_store' => $this->saveStore($request, $userId),
            'request_rename' => $this->container->get(UpdateStoreSettings::class)
                ->requestRename($userId, $userId, $request->postText('store_name')),
            'request_bank' => $this->container->get(UpdateStoreSettings::class)
                ->requestBankChange($userId, $userId, $request->postText('iban'), $request->postText('holder'), 0),
            'invite_staff' => $this->inviteStaff($request, $userId),
            'suspend_staff' => $this->container->get(ManageStaff::class)->suspend($userId, $request->postInt('staff_id')),
            'reinstate_staff' => $this->container->get(ManageStaff::class)->reinstate($userId, $request->postInt('staff_id')),
            default => null,
        };

        $target = match (true) {
            in_array($action, ['save_store', 'request_rename', 'request_bank'], true)
                => add_query_arg('tab', $request->postKey('tab') ?: 'general', $urls->store()),
            in_array($action, ['invite_staff', 'suspend_staff', 'reinstate_staff'], true) => $urls->staff(),
            $action === 'submit' && $result !== null && $result->ok => $urls->dashboard(),
            default => $urls->application(),
        };
        $code = $result?->code ?? 'forbidden';
        // The numbers in the sentence are the server's, so they travel in the
        // flash store rather than in the URL the applicant can edit. One
        // minute is longer than a redirect and shorter than a second attempt.
        if ($result !== null && $result->context !== []) {
            $this->container->get(FlashStoreInterface::class)
                ->put(self::flashKey($userId), ['code' => $code, 'context' => $result->context], MINUTE_IN_SECONDS);
        }
        wp_safe_redirect($urls->withNotice($target, $code));
        exit;
    }

    /** The vendor edits only their own store: the id is never taken from the post. */
    private function saveStore(Request $request, int $userId): OperationResult
    {
        $lists = $this->container->get(VendorListsInterface::class);
        // The public page's cache is NOT forgotten here. It used to be, and
        // it was wrong twice over: it ran BEFORE the write, so a read landing
        // in between re-cached the old page under the new version; and it
        // made invalidation a property of this route rather than of the
        // change, so the same save from anywhere else left the page stale.
        // `DbStoreRepository::save()` now announces the write once it has
        // succeeded, and `StoreCacheInvalidation` listens.
        $this->imageRefusal = '';
        $result = $this->container->get(UpdateStoreSettings::class)->save(
            $userId,
            $userId,
            [
                'tab' => $request->postKey('tab'),
                'city' => $request->postText('city'),
                'intro' => $request->postTextarea('intro'),
                'logo_id' => $this->pickedImage($request, $userId, 'logo'),
                'banner_id' => $this->pickedImage($request, $userId, 'banner'),
                'preparation_days' => $request->postInt('preparation_days'),
                'origin_warehouse' => $request->postText('origin_warehouse'),
                'carriers' => $request->postTextList('carriers'),
                'closed' => $request->postChecked('closed'),
                'closed_from' => $request->postText('closed_from'),
                'closed_to' => $request->postText('closed_to'),
                'reopen_message' => $request->postText('reopen_message'),
                'social' => $request->postMap('social'),
            ],
            array_keys($lists->networks()),
            array_keys($lists->carriers())
        );

        // The text saved and the picture did not. Reporting the save as a
        // failure would be a lie and would throw away work that is safely
        // stored; reporting only the save hides the loss. So the save stands,
        // the notice names the upload, and the picture already there is
        // untouched — the same three-way answer the product form gives.
        if ($this->imageRefusal !== '' && $result->ok) {
            return OperationResult::failure($this->imageRefusal, ['saved' => 1]);
        }
        return $result;
    }

    /**
     * The logo or banner: a file the vendor chose, or the id already stored.
     *
     * Since `alpha.5` these two fields were bare number inputs whose own hint
     * said an image picker «would be added in the products stage». It never
     * was, so the only way to set a shop's logo was to find an attachment id
     * in wp-admin and type it — which a vendor cannot do, because they have no
     * wp-admin. The upload path is the one the product form has used since
     * `alpha.6`; nothing new is invented here, it is just finally wired up.
     *
     * An empty file input means «leave it alone», so the stored id is carried
     * through. A failed upload also leaves it alone rather than clearing it:
     * losing the logo you already had is not an acceptable outcome of a
     * refused upload.
     */
    /**
     * Why the last picture refused to upload, or '' when none did.
     *
     * A failed upload used to be perfectly silent: the id was kept, the text
     * fields saved, the page said «ذخیره شد» and no picture appeared. That is
     * the same silence the product form was rewritten in `alpha.14` to stop,
     * reintroduced in `alpha.25` on a different form — so the refusal now
     * travels out with the save rather than being swallowed here.
     */
    private string $imageRefusal = '';

    private function pickedImage(Request $request, int $userId, string $field): int
    {
        $current = $request->postInt($field . '_id');
        $file = $request->file($field . '_file');
        if ($file->tempPath === '' || $file->errorCode === UPLOAD_ERR_NO_FILE) {
            // PHP itself refused it before our code ran — «too large» and
            // «interrupted» are different instructions to the person, so the
            // policy names them rather than one catch-all.
            if ($file->errorCode !== UPLOAD_ERR_OK && $file->errorCode !== UPLOAD_ERR_NO_FILE) {
                $this->imageRefusal = in_array($file->errorCode, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'image_too_large'
                    : 'transfer_failed';
            }
            return $current;
        }
        $uploaded = $this->container->get(ManageProducts::class)->uploadImage(
            $userId,
            $userId,
            $file,
            $field === 'logo'
                ? __('لوگوی فروشگاه', 'tecteb-marketplace-core')
                : __('بنر فروشگاه', 'tecteb-marketplace-core')
        );
        if ($uploaded->ok) {
            return (int) $uploaded->context['media_id'];
        }
        $this->imageRefusal = $uploaded->code;
        return $current;                        // the picture already there stays
    }

    private function inviteStaff(Request $request, int $userId): OperationResult
    {
        $preset = StaffRolePreset::tryFrom($request->postKey('preset')) ?? StaffRolePreset::Custom;
        return $this->container->get(ManageStaff::class)->invite(
            $userId,
            $userId,
            $request->postText('first_name'),
            $request->postText('last_name'),
            $request->postKey('username'),
            $request->postEmail('email'),
            $request->postText('mobile'),
            $preset
        );
    }

    /**
     * The invitation page, for somebody who is not signed in. Everything it
     * can do is bounded by the token: show the form, or set a password.
     */
    private function handleInvitation(Request $request): void
    {
        $urls = $this->urls();
        $service = $this->container->get(AcceptStaffInvitation::class);
        $token = $request->isPost() ? $request->postKey('token') : $request->queryKey('token');
        $notice = null;

        if ($request->isPost()) {
            if (!$request->nonceOk(self::NONCE_FIELD, self::NONCE_ACTION)) {
                $notice = VendorNotice::of('forbidden');
            } else {
                $result = $service->accept($token, $request->postRaw('password'));
                $notice = VendorNotice::of($result->code, $result->context);
                if ($result->ok) {
                    $this->renderShell(
                        __('فعال‌سازی حساب پرسنل', 'tecteb-marketplace-core'),
                        'invite',
                        InviteView::render('', '', '', '', $notice) . VendorUi::button(wp_login_url(), __('ورود به حساب', 'tecteb-marketplace-core')),
                        ''
                    );
                }
            }
        }

        $found = $service->inspect($token);
        $this->renderShell(
            __('فعال‌سازی حساب پرسنل', 'tecteb-marketplace-core'),
            'invite',
            InviteView::render(
                $found->ok ? $token : '',
                (string) ($found->context['display_name'] ?? ''),
                (string) ($found->context['username'] ?? ''),
                wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD, true, false),
                $notice,
                AcceptStaffInvitation::MIN_PASSWORD
            ),
            ''
        );
    }

    private function detailsFromPost(Request $request): ApplicantDetails
    {
        return new ApplicantDetails(
            $request->postText('store_name'),
            $request->postText('legal_name'),
            $request->postEmail('contact_email'),
            $request->postText('contact_mobile'),
            $request->postTextarea('address'),
            $request->postChecked('terms')
        );
    }

    /**
     * Gives a module's own POST action to the module that declared it.
     *
     * The nonce and the capability have already been checked above, so an
     * extension never re-implements them — and never gets to skip them.
     */
    private function handleExtensionPost(string $action, Request $request, int $userId): ?VendorAreaOutcome
    {
        foreach (VendorAreaExtensions::views() as $view) {
            if ($view['handle'] === null || !in_array($action, $view['actions'], true)) {
                continue;
            }
            if ($view['requires_vendor'] && $this->storeFor($userId) === null) {
                return new VendorAreaOutcome('not_a_vendor', $this->urls()->dashboard());
            }
            $outcome = ($view['handle'])($action, $request, $userId, $this->urls());
            return $outcome instanceof VendorAreaOutcome ? $outcome : null;
        }
        return null;
    }

    /** Stashes the values a sentence needs, then hands back the redirect URL. */
    private function flashAndTarget(VendorAreaOutcome $outcome, int $userId): string
    {
        if ($outcome->context !== []) {
            $this->container->get(FlashStoreInterface::class)
                ->put(self::flashKey($userId), ['code' => $outcome->code, 'context' => $outcome->context], MINUTE_IN_SECONDS);
        }
        return $this->urls()->withNotice($outcome->target, $outcome->code);
    }

    /** The address of one view, with or without pretty permalinks. */
    private function viewUrl(string $slug): string
    {
        return (string) get_option('permalink_structure', '') !== ''
            ? home_url('/vendor/' . $slug . '/')
            : home_url('/?' . self::QUERY_VAR . '=' . $slug);
    }

    /** @return array{slug:string,label:string,title:string,requires_vendor:bool,render:callable,actions:list<string>,handle:?callable,url:?callable,nav:bool}|null */
    private function extension(string $slug): ?array
    {
        foreach (VendorAreaExtensions::views() as $view) {
            if ($view['slug'] === $slug) {
                return $view;
            }
        }
        return null;
    }

    /**
     * The shop this user may act in — their own, or the one they are staff of.
     * Staff reach the extension pages; only an owner reaches store and staff.
     */
    private function storeFor(int $userId): ?int
    {
        return $this->container->get(StaffAccess::class)->storeFor($userId);
    }

    // ------------------------------------------------------------- downloads

    /**
     * A private document leaves the server only here, and only after three
     * checks: the nonce for THIS document, the capability, and ownership —
     * either the applicant's own file, or a reviewer's right to see it.
     */
    private function serveDocument(Request $request, int $documentId, int $userId): void
    {
        if (!$request->queryNonceOk('tmc_vendor_doc_' . $documentId)) {
            $this->deny();
        }
        $documents = $this->container->get(DocumentRepositoryInterface::class);
        $document = $documents->find($documentId);
        if ($document === null) {
            $this->deny(404);
        }
        $applications = $this->container->get(\Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface::class);
        $application = $applications->findApplication($document->applicationId);
        $isOwner = $application !== null && $application->userId === $userId;
        if (!$isOwner && !current_user_can(VendorCapabilities::REVIEW)) {
            $this->deny();
        }

        $storage = $this->container->get(\Tecteb\Marketplace\Contracts\Files\PrivateFileStorageInterface::class);
        $bytes = $storage->read($document->storedPath);
        if ($bytes === null) {
            $this->deny(404);
        }
        $this->container->get(AuditLogger::class)->log(
            AuditEventCatalog::VENDOR_DOCUMENT_DOWNLOADED,
            $userId,
            'vendor_document',
            (string) $documentId,
            ['application_id' => $document->applicationId, 'document_id' => $documentId, 'type' => $document->typeSlug]
        );

        nocache_headers();
        header('Content-Type: ' . $document->mime);
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: attachment; filename="' . $document->originalName . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        echo $bytes;
        exit;
    }

    // ---------------------------------------------------------------- render

    private function renderPage(Request $request, string $view, int $userId): void
    {
        $urls = $this->urls();
        $workspace = $this->container->get(VendorWorkspaceFactory::class)->forUser($userId);
        $notice = VendorNotice::fromRequest(
            $request->queryKey('tmc_notice'),
            $this->container->get(FlashStoreInterface::class)->take(self::flashKey($userId))
        );
        $nonceField = wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD, true, false);
        $storeName = $workspace->profile?->storeName ?? ($workspace->application?->details->storeName ?? '');

        // The store and staff pages belong to an approved vendor. Anyone else
        // — an applicant mid-review, or a staff member who followed a link —
        // is sent back rather than shown an empty shell.
        $access = $this->container->get(StaffAccess::class);
        if (in_array($view, ['store', 'staff'], true) && !$access->canManageStore($userId, $userId)) {
            wp_safe_redirect($urls->withNotice($urls->dashboard(), 'not_a_vendor'));
            exit;
        }

        $extension = $this->extension($view);
        if ($extension !== null) {
            // A staff member with product rights belongs on the product page
            // even though they may not touch store settings, so the gate here
            // is "acts in a shop", not "owns one".
            if ($extension['requires_vendor'] && $access->storeFor($userId) === null) {
                wp_safe_redirect($urls->withNotice($urls->dashboard(), 'not_a_vendor'));
                exit;
            }
            $body = (string) ($extension['render'])(
                new VendorAreaView($request, $userId, $urls, $nonceField, $notice)
            );
            $this->renderShell(
                $extension['title'],
                $extension['slug'],
                $body,
                $storeName,
                $this->navFor($urls, $access->canManageStore($userId, $userId), $access->storeFor($userId) !== null)
            );
        }

        [$title, $body] = match ($view) {
            'application' => [
                __('درخواست فروشندگی', 'tecteb-marketplace-core'),
                ApplicationView::render($workspace, $urls, $nonceField, $notice),
            ],
            'store' => [
                __('تنظیمات فروشگاه', 'tecteb-marketplace-core'),
                $this->storeBody($request, $urls, $nonceField, $userId, $notice),
            ],
            'staff' => [
                __('پرسنل فروشگاه', 'tecteb-marketplace-core'),
                $this->staffBody($urls, $nonceField, $userId, $notice),
            ],
            default => [
                __('پیشخوان فروشنده', 'tecteb-marketplace-core'),
                DashboardView::render($workspace, $urls, $notice, $this->actionQueueFor($userId)),
            ],
        };

        $this->renderShell(
            $title,
            $view,
            $body,
            $storeName,
            $this->navFor($urls, $access->canManageStore($userId, $userId), $access->storeFor($userId) !== null)
        );
    }

    /** @return list<array{slug:string,label:string,url:string}> */
    private function navFor(VendorUrls $urls, bool $isOwner, bool $actsInStore = false): array
    {
        $nav = [
            ['slug' => 'dashboard', 'label' => __('پیشخوان', 'tecteb-marketplace-core'), 'url' => $urls->dashboard()],
            ['slug' => 'application', 'label' => __('درخواست فروشندگی', 'tecteb-marketplace-core'), 'url' => $urls->application()],
        ];
        if ($isOwner) {
            $nav[] = ['slug' => 'store', 'label' => __('تنظیمات فروشگاه', 'tecteb-marketplace-core'), 'url' => $urls->store()];
            $nav[] = ['slug' => 'staff', 'label' => __('پرسنل', 'tecteb-marketplace-core'), 'url' => $urls->staff()];
        }
        foreach (VendorAreaExtensions::views() as $view) {
            if (!$view['nav'] || ($view['requires_vendor'] && !$actsInStore && !$isOwner)) {
                continue;
            }
            $nav[] = [
                'slug' => $view['slug'],
                'label' => $view['label'],
                'url' => $view['url'] !== null ? (string) ($view['url'])($urls) : $this->viewUrl($view['slug']),
            ];
        }
        return $nav;
    }

    /**
     * The shop's «صف اقدام», or nothing at all.
     *
     * Wrapped whole: the queue asks four other modules for counts, and a
     * vendor's first screen must not go blank because one of them is not
     * loaded on this site today.
     *
     * @return list<array{key:string, count:int, tone:string, url:string}>
     */
    private function actionQueueFor(int $userId): array
    {
        try {
            $vendorUserId = $this->container->get(StaffAccess::class)->storeFor($userId);
            if ($vendorUserId === null) {
                return [];
            }
            return $this->container->get(ActionQueue::class)->forVendor(
                $vendorUserId,
                (string) get_option('permalink_structure', '') !== ''
                    ? home_url('/vendor/')
                    : home_url('/?tmc_vendor=')
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function storeBody(Request $request, VendorUrls $urls, string $nonceField, int $userId, ?VendorNotice $notice): string
    {
        $stores = $this->container->get(StoreRepositoryInterface::class);
        $lists = $this->container->get(VendorListsInterface::class);
        $tab = $request->queryKey('tab');
        return StoreView::render(
            $stores->find($userId) ?? new StoreSettings(),
            array_key_exists($tab, StoreView::tabs()) ? $tab : 'general',
            $urls,
            $nonceField,
            $lists->carriers(),
            $lists->networks(),
            $stores->bank($userId),
            $this->container->get(ChangeRequestRepositoryInterface::class)->forVendor($userId),
            $this->container->get(MobileVerification::class)->available(),
            $notice
        );
    }

    private function staffBody(VendorUrls $urls, string $nonceField, int $userId, ?VendorNotice $notice): string
    {
        // The plain token exists for exactly one render: it came back from the
        // invite call in the flash, and is never read from storage again.
        $inviteUrl = '';
        if ($notice !== null && $notice->code === 'staff_invited' && ($notice->context['token'] ?? '') !== '') {
            $inviteUrl = $urls->invite((string) $notice->context['token']);
        }
        return StaffView::render(
            $this->container->get(StaffRepositoryInterface::class)->forVendor($userId),
            $this->container->get(SettingsService::class)->load()->maxStaff,
            $urls,
            $nonceField,
            $notice,
            $inviteUrl,
            // The report gates itself on `canManageStore`, the same rule that
            // let this page render at all — asked again rather than assumed,
            // because a view that trusts its caller is a view that leaks the
            // day somebody renders it from somewhere else.
            $this->container->get(StaffActivityReport::class)->forVendor($userId, $userId)
        );
    }

    /** @param list<array{slug:string,label:string,url:string}> $nav */
    private function renderShell(string $title, string $view, string $body, string $storeName, array $nav = []): void
    {
        // One shell script on every view, and it does exactly one thing: open
        // the collapsed menu when the viewport is wide. With scripts off the
        // menu stays collapsed and one click away — which is still better than
        // the five rows of buttons it replaced.
        $scripts = [$this->assetsBaseUrl . 'assets/vendor/tmc-vendor.js'];
        // And one more, on one view. The products screen is the only place in
        // this area where losing a tab costs an afternoon's typing.
        if ($view === 'products') {
            $scripts[] = $this->assetsBaseUrl . 'assets/vendor/tmc-product-form.js';
            // And the category suggestions. Separate file, separate job: the
            // picker has to keep working on a page where the draft store is
            // off, and the draft has to keep working on a shop with no
            // WooCommerce to have categories.
            $scripts[] = $this->assetsBaseUrl . 'assets/vendor/tmc-category-picker.js';
        }
        status_header(200);
        nocache_headers();
        echo VendorShell::render(
            $title,
            $view,
            $nav,
            $storeName,
            $body,
            $this->assetsBaseUrl . 'assets/vendor/tmc-vendor.css',
            $this->version,
            $scripts
        );
        exit;
    }

    /** One flash per user: a message belongs to whoever just wrote. */
    private static function flashKey(int $userId): string
    {
        return 'vendor_notice_' . $userId;
    }

    private function deny(int $code = 403): void
    {
        status_header($code);
        nocache_headers();
        wp_die(
            esc_html__('برای دیدن این صفحه دسترسی لازم را ندارید.', 'tecteb-marketplace-core'),
            esc_html__('دسترسی نیست', 'tecteb-marketplace-core'),
            ['response' => $code]
        );
    }
}
