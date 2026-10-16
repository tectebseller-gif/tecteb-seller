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
    /**
     * Shortcode callbacks by tag. A registry, not a hook: WordPress keeps one
     * callback per tag and a second `add_shortcode()` replaces the first.
     *
     * @var array<string,callable>
     */
    public static array $shortcodes = [];
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

    /** `admin_init` also fires on `admin-ajax.php`; false is an ordinary page. */
    public static bool $doingAjax = false;
    /** When true, option writes fail the way a storage error would. */
    public static bool $failOptionWrites = false;
    /**
     * When true, the option functions read and write the REAL options table
     * through $wpdb instead of the in-process array. The database suite turns
     * this on so that options and the guarded single-statement writes share
     * one storage layer, as they do in WordPress.
     */
    public static bool $optionsBackedByWpdb = false;
    /**
     * How many decimal places the stub storefront keeps its prices to.
     *
     * Backs `wc_get_price_decimals()`. Zero is WooCommerce's setting on a
     * ریال/تومان shop and the value every existing test assumed without ever
     * saying so; `alpha.39` made the assumption a value a test can change,
     * which is what lets the money boundary be exercised for a decimal
     * currency at all.
     */
    public static int $priceDecimals = 0;
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
    /**
     * User meta, as `user_id => [key => value]`.
     *
     * Added in `alpha.36`: nothing stubbed user meta before, which meant the
     * draft store (`alpha.13`) and now the review-seen store had no test that
     * could reach them at all.
     *
     * @var array<int,array<string,mixed>>
     */
    public static array $userMeta = [];
    /**
     * WordPress posts, as `id => ['status' => …, 'title' => …]`.
     *
     * Added in `alpha.36` for the «مشاهدهٔ محصول» button: the three branches it
     * has — published, a draft that exists, and no WooCommerce post at all —
     * are three answers from `get_post_status()`, and until now nothing in the
     * stub environment could reach any of them.
     *
     * @var array<int,array{status:string,title?:string}>
     */
    public static array $posts = [];
    /**
     * Post meta, as `post id => [key => value]`.
     *
     * Added in `alpha.42`, with the product stubs, for the same reason: the
     * projector's ownership stamps ARE post meta, so without this nothing in
     * the stub environment could reach `writeOwned()` at all — and that is the
     * method that decides whether the manager's text is overwritten.
     *
     * @var array<int,array<string,mixed>>
     */
    public static array $postMeta = [];
    /**
     * The same meta, addressed by ROW id — `meta_id => {meta_id, post_id,
     * meta_key, meta_value}`.
     *
     * WordPress has two ways to change a meta row and WooCommerce uses both:
     * `update_post_meta()` by key, and `update_metadata_by_mid()` by row,
     * which is what `WC_Data_Store_WP::update_meta()` calls. Without a row
     * table a stub cannot model the second, and a guard that misses it looks
     * complete — which is how `alpha.58` shipped with that door open.
     *
     * @var array<int,array{meta_id:int,post_id:int,meta_key:string,meta_value:mixed}>
     */
    public static array $metaRows = [];
    /** Which post the loop is on, for `get_the_ID()`. */
    public static int $currentPostId = 0;
    /** Next id wp_insert_user() will hand out. */
    public static int $nextUserId = 500;
    public static ?string $uploadBaseDir = null;
    /** @var list<string> */
    public static array $sentHeaders = [];
    public static ?int $statusHeader = null;

    /**
     * The real `postmeta` table to MIRROR meta writes into, or `null`.
     *
     * The stubs keep post meta in `self::$postMeta`, which is right for the
     * code that reads it through `get_post_meta()` — and wrong for code that
     * reads the ROWS. `CommissionRuleWriter` locks and verifies the two
     * commission rows with SQL, on purpose (`alpha.55`: `get_post_meta()` is
     * not a row read), so against an array it sees nothing and reports that
     * nothing was stored.
     *
     * A stub that cannot show a row makes a correct writer look broken, which
     * is the mirror image of `alpha.59`, where a stub that did not model the
     * row-addressed verbs made an incomplete guard look complete. So when the
     * suite has a real `postmeta` table, every write goes to both — and when
     * it has none (the unit and contract suites), the answer is `null` and
     * nothing changes.
     *
     * Asked once per test: `reset()` clears it, because a suite that creates
     * or drops the table between tests would otherwise be answered from a
     * cache about a table that is no longer the one on disk.
     */
    /** `null` = not asked, `''` = asked and absent, otherwise the table name. */
    private static ?string $metaMirror = null;

    public static function metaMirror(): ?string
    {
        if (self::$metaMirror !== null) {
            return self::$metaMirror === '' ? null : self::$metaMirror;
        }
        self::$metaMirror = '';
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (!is_object($wpdb) || !property_exists($wpdb, 'postmeta') || !method_exists($wpdb, 'pdo')) {
            return null;
        }
        try {
            $stmt = $wpdb->pdo()->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$wpdb->postmeta]);
            if ((int) $stmt->fetchColumn() > 0) {
                self::$metaMirror = (string) $wpdb->postmeta;
            }
        } catch (\Throwable) {
            return null;
        }
        return self::$metaMirror === '' ? null : self::$metaMirror;
    }

    /**
     * One meta key written to the mirror the way `update_post_meta()` writes
     * it: every row of the key replaced by one, or removed when `$value` is
     * `null`.
     */
    public static function mirrorMeta(int $postId, string $key, mixed $value): void
    {
        $table = self::metaMirror();
        if ($table === null) {
            return;
        }
        try {
            $pdo = $GLOBALS['wpdb']->pdo();
            $delete = $pdo->prepare('DELETE FROM `' . $table . '` WHERE post_id = ? AND meta_key = ?');
            $delete->execute([$postId, $key]);
            if ($value === null) {
                return;
            }
            $insert = $pdo->prepare(
                'INSERT INTO `' . $table . '` (post_id, meta_key, meta_value) VALUES (?, ?, ?)'
            );
            $insert->execute([$postId, $key, is_scalar($value) ? (string) $value : (string) maybe_serialize($value)]);
        } catch (\Throwable) {
            // The table went away under us — a suite that drops it between
            // tests. Forget the answer so the next write asks again; the
            // in-memory half above has already happened either way.
            self::$metaMirror = null;
        }
    }

    /**
     * One post's meta re-read from the mirror table, replacing whatever the
     * in-memory copy held.
     *
     * What `wp_cache_delete($id, 'post_meta')` means when there are real rows
     * to go back to: the cached copy is gone and the next read is the row.
     * With no mirror there is nothing to go back to, and the array is left
     * exactly as it was — which is how every suite without a `postmeta` table
     * behaved before.
     */
    public static function reloadMetaFromMirror(int $postId): void
    {
        $table = self::metaMirror();
        if ($table === null || $postId <= 0) {
            return;
        }
        try {
            $stmt = $GLOBALS['wpdb']->pdo()->prepare(
                'SELECT meta_key, meta_value FROM `' . $table . '` WHERE post_id = ?'
            );
            $stmt->execute([$postId]);
            $fresh = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $fresh[(string) $row['meta_key']] = $row['meta_value'];
            }
            self::$postMeta[$postId] = $fresh;
        } catch (\Throwable) {
            self::$metaMirror = null;
        }
    }

    public static function reset(): void
    {
        // The mirror's answer, so a suite that creates or drops the real
        // `postmeta` table between tests is not answered from a stale cache.
        self::$metaMirror = null;
        self::$options = [];
        self::$transients = [];
        self::$cache = [];
        self::$hooks = [];
        self::$shortcodes = [];
        self::$firedActions = [];
        self::$roles = ['administrator' => ['manage_options' => true, 'activate_plugins' => true]];
        self::$currentUserCaps = [];
        self::$currentUserId = 0;
        self::$multisite = false;
        self::$isAdmin = true;
        self::$doingAjax = false;
        self::$failOptionWrites = false;
        self::$priceDecimals = 0;
        self::$optionsBackedByWpdb = false;
        self::$environmentType = 'production';
        self::$homeUrl = 'https://example.test';
        self::$menus = [];
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
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
        self::$userMeta = [];
        self::$posts = [];
        self::$postMeta = [];
        self::$metaRows = [];
        self::$currentPostId = 0;
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
        self::$shortcodes = [];
        self::$firedActions = [];
        self::$menus = [];
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
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
