<?php
declare(strict_types=1);

namespace TmcWpStubs;

final class State
{
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
    /** @var list<string> */
    public static array $output = [];
    /** @var list<string> */
    public static array $wpDieCalls = [];

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
        self::$environmentType = 'production';
        self::$homeUrl = 'https://example.test';
        self::$menus = [];
        self::$registeredSettings = [];
        self::$settingsErrors = [];
        self::$enqueued = ['style' => [], 'script' => []];
        self::$restRoutes = [];
        self::$clearedScheduledHooks = [];
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
