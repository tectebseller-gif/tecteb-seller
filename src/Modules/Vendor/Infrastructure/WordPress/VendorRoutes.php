<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\Auth\AuthenticationBridgeInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
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

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly string $assetsBaseUrl,
        private readonly string $version
    ) {
    }

    public static function register(ContainerInterface $container, string $assetsBaseUrl, string $version): void
    {
        $routes = new self($container, $assetsBaseUrl, $version);
        add_action('init', [self::class, 'addRewriteRules']);
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;
            return $vars;
        });
        add_action('template_redirect', [$routes, 'handle']);
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
        return in_array($raw, ['dashboard', 'application'], true) ? $raw : 'dashboard';
    }

    private function urls(): VendorUrls
    {
        $pretty = (string) get_option('permalink_structure', '') !== '';
        if ($pretty) {
            return new VendorUrls(home_url('/vendor/'), home_url('/vendor/application/'));
        }
        return new VendorUrls(
            home_url('/?' . self::QUERY_VAR . '=dashboard'),
            home_url('/?' . self::QUERY_VAR . '=application')
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

        $result = match ($action) {
            'save_draft' => $this->container->get(SaveApplicationDraft::class)->handle($userId, $this->detailsFromPost($request)),
            'upload' => $this->container->get(UploadApplicationDocument::class)->handle(
                $userId,
                $request->postKey('type_slug'),
                $request->file('document')
            ),
            'submit' => $this->container->get(SubmitApplication::class)->handle($userId),
            default => null,
        };

        $target = $action === 'submit' && $result !== null && $result->ok ? $urls->dashboard() : $urls->application();
        wp_safe_redirect($urls->withNotice($target, $result?->code ?? 'forbidden'));
        exit;
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
        $notice = $request->queryKey('tmc_notice');
        $nonceField = wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD, true, false);

        $nav = [
            ['slug' => 'dashboard', 'label' => __('پیشخوان', 'tecteb-marketplace-core'), 'url' => $urls->dashboard()],
            ['slug' => 'application', 'label' => __('درخواست فروشندگی', 'tecteb-marketplace-core'), 'url' => $urls->application()],
        ];
        $storeName = $workspace->profile?->storeName ?? ($workspace->application?->details->storeName ?? '');

        $body = $view === 'application'
            ? ApplicationView::render($workspace, $urls, $nonceField, $notice)
            : DashboardView::render($workspace, $urls, $notice);

        $title = $view === 'application'
            ? __('درخواست فروشندگی', 'tecteb-marketplace-core')
            : __('پیشخوان فروشنده', 'tecteb-marketplace-core');

        status_header(200);
        nocache_headers();
        echo VendorShell::render(
            $title,
            $view,
            $nav,
            $storeName,
            $body,
            $this->assetsBaseUrl . 'assets/vendor/tmc-vendor.css',
            $this->version
        );
        exit;
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
