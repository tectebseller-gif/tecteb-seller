<?php
declare(strict_types=1);

namespace TmcWpStubs;

final class State
{
    /**
     * Taxonomy terms, keyed by taxonomy then term id.
     *
     * Added for the category picker: the thing under test reads
     * `product_cat`, so a stub that cannot hold a hierarchy cannot test it.
     *
     * @var array<string,array<int,array{name:string,slug:string,parent:int,count:int}>>
     */
    public static array $terms = [];

    /** @var array<string,mixed> */
    public static array $options = [];
    /** @var array<string,mixed> */
    public static array $transients = [];
    /** @var array<string,array<string,mixed>> */
    public static array $cache = [];
    /** @var array<string,array<int,list<callable>>> */
    public static array $hooks = [];
    /** @var list<array{tag:string,args:array}> */
    public static array $firedActions = [];
    /** @var array<string,array<string,bool>> */
    public static array $roles = ['administrator' => ['manage_options' => true, 'activate_plugins' => true]];
    /** @var list<string> */
    public static array $currentUserCaps = [];
    public static int $currentUserId = 0;
    public static bool $multisite = false;
    /** wp-admin or the front end; the contract tests load the plugin as wp-admin does. */
    public static bool $isAdmin = true;
    /** When true, option writes fail the way a storage error would. */
    public static bool $failOptionWrites = false;
    /**
     * When true, the option functions read and write the REAL options table
     * through $wpdb instead of the in-process array. The database suite turns
     * this on so that options and the guarded single-statement writes share
     * one storage layer, as they do in WordPress.
     */
    public static bool $optionsBackedByWpdb = false;
    public static string $environmentType = 'production';
    public static string $homeUrl = 'https://example.test';
    public static string $wpVersion = 'stub';
    /** @var list<array<string,mixed>> */
    public static array $menus = [];
    /** @var array<string,array<string,mixed>> */
    public static array $registeredSettings = [];
    /** @var list<array{setting:string,code:string,message:string,type:string}> */
    public static array $settingsErrors = [];
    /** @var array<string,array<string,mixed>> */
    public static array $enqueued = ['style' => [], 'script' => []];
    /** @var array<string,array<string,mixed>> */
    public static array $restRoutes = [];
    /** @var list<string> */
    public static array $clearedScheduledHooks = [];

    /** hook => timestamp, as WP-Cron keeps it. */
    public static array $scheduled = [];

    /** hook => recurrence name, so a test can assert WHICH schedule was asked for. */
    public static array $scheduledRecurrences = [];
    /** @var list<string> */
    public static array $output = [];
    /** @var list<string> */
    public static array $wpDieCalls = [];

    // Vendor phase: the front-end route, its redirects and its file storage.
    /** @var array<string,string> */
    public static array $rewriteRules = [];
    public static int $rewriteFlushes = 0;
    /** @var array<string,mixed> */
    public static array $queryVars = [];
    /** @var list<array{location:string,status:int}> */
    public static array $redirects = [];
    /** @var array<int,array<string,mixed>> */
    /** What `is_404()` answers. The Dokan redirect only ever runs on true. */
    /** When true, `wp_safe_redirect()` throws instead of returning — see RedirectedException. */
    /**
     * User ids `dokan_is_user_seller()` answers TRUE for.
     *
     * The one Dokan function this plugin calls, and it only ever reads. A
     * shop in this list is one Dokan is going to serve, so nothing of ours may
     * touch its URL.
     */
    public static array $dokanSellers = [];

    /**
     * Template files the active theme provides, as `locate_template()` sees
     * them. A CLASSIC theme has header.php and footer.php; a BLOCK theme
     * usually has neither, and that difference decides whether the shop page
     * can be wrapped in the theme at all.
     */
    public static array $themeTemplates = ['header.php', 'footer.php'];

    public static bool $throwOnRedirect = false;
    public static bool $is404 = false;
    /**
     * The path of the request being served, as `add_query_arg([])` reports it.
     *
     * Core's `add_query_arg()` with no URL rebuilds the CURRENT request, which
     * is why a caller can use it instead of reading the request superglobal
     * directly. A stub that ignored it would let a test pass against a code
     * path that never sees a real URL.
     */
    public static string $requestUri = '/';

    public static array $users = [];
    /** Next id wp_insert_user() will hand out. */
    public static int $nextUserId = 500;
    public static ?string $uploadBaseDir = null;
    /** @var list<string> */
    public static array $sentHeaders = [];
    public static ?int $statusHeader = null;

    public static function reset(): void
    {
        self::$options = [];
        self::$transients = [];
        self::$cache = [];
        self::$hooks = [];
        self::$firedActions = [];
        self::$roles = ['administrator' => ['manage_options' => true, 'activate_plugins' => true]];
        self::$currentUserCaps = [];
        self::$currentUserId = 0;
        self::$multisite = false;
        self::$isAdmin = true;
        self::$failOptionWrites = false;
        self::$optionsBackedByWpdb = false;
        self::$environmentType = 'production';
        self::$homeUrl = 'https://example.test';
        self::$menus = [];
        self::$registeredSettings = [];
        self::$settingsErrors = [];
        self::$enqueued = ['style' => [], 'script' => []];
        self::$restRoutes = [];
        self::$clearedScheduledHooks = [];
        self::$scheduled = [];
        self::$scheduledRecurrences = [];
        self::$rewriteRules = [];
        self::$rewriteFlushes = 0;
        self::$queryVars = [];
        self::$redirects = [];
        self::$dokanSellers = [];
        self::$themeTemplates = ['header.php', 'footer.php'];
        self::$throwOnRedirect = false;
        self::$is404 = false;
        self::$requestUri = '/';
        self::$users = [];
        self::$nextUserId = 500;
        self::$uploadBaseDir = null;
        self::$sentHeaders = [];
        self::$statusHeader = null;
        self::$output = [];
        self::$wpDieCalls = [];
        $_REQUEST = [];
        $_POST = [];
        $_GET = [];
    }

    /**
     * Starts a NEW REQUEST: clears everything WordPress rebuilds per request
     * (hooks, menus, enqueued assets, routes, settings errors) while keeping
     * what actually persists (options, transients, roles, object cache, the
     * logged-in user). Lets one test process model "activate, then the next
     * page load" the way WordPress really sequences it.
     */
    public static function newRequest(): void
    {
        self::$hooks = [];
        self::$firedActions = [];
        self::$menus = [];
        self::$registeredSettings = [];
        self::$settingsErrors = [];
        self::$enqueued = ['style' => [], 'script' => []];
        self::$restRoutes = [];
        self::$output = [];
        self::$wpDieCalls = [];
    }

    /** Log in a synthetic user with the given capabilities. */
    public static function loginAs(int $id, array $caps): void
    {
        self::$currentUserId = $id;
        self::$currentUserCaps = $caps;
    }

    public static function logout(): void
    {
        self::$currentUserId = 0;
        self::$currentUserCaps = [];
    }
}
