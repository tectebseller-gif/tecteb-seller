<?php
declare(strict_types=1);

use TmcWpStubs\State;
use TmcWpStubs\WpDieException;

// ---- hooks -----------------------------------------------------------------
function add_action(string $tag, callable $cb, int $priority = 10, int $acceptedArgs = 1): bool
{
    State::$hooks[$tag][$priority][] = $cb;
    return true;
}
function add_filter(string $tag, callable $cb, int $priority = 10, int $acceptedArgs = 1): bool
{
    return add_action($tag, $cb, $priority, $acceptedArgs);
}
function remove_all_actions(string $tag, int|false $priority = false): bool
{
    if ($priority === false) {
        unset(State::$hooks[$tag]);
    } else {
        unset(State::$hooks[$tag][$priority]);
    }
    return true;
}
function has_action(string $tag): bool
{
    return !empty(State::$hooks[$tag]);
}
function do_action(string $tag, mixed ...$args): void
{
    State::$firedActions[] = ['tag' => $tag, 'args' => $args];
    $byPriority = State::$hooks[$tag] ?? [];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as $cb) {
            $cb(...$args);
        }
    }
}
function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
{
    $byPriority = State::$hooks[$tag] ?? [];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as $cb) {
            $value = $cb($value, ...$args);
        }
    }
    return $value;
}
function register_activation_hook(string $file, callable $cb): void
{
    add_action('activate_' . plugin_basename($file), $cb);
}
function register_deactivation_hook(string $file, callable $cb): void
{
    add_action('deactivate_' . plugin_basename($file), $cb);
}

// ---- plugin paths / i18n ----------------------------------------------------
function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}
function plugin_dir_path(string $file): string
{
    return rtrim(dirname($file), '/\\') . '/';
}
function plugin_dir_url(string $file): string
{
    return State::$homeUrl . '/wp-content/plugins/' . basename(dirname($file)) . '/';
}
function load_plugin_textdomain(string $domain, mixed $deprecated = false, string $path = ''): bool
{
    return true;
}
function __(string $text, string $domain = 'default'): string
{
    return $text;
}
function _e(string $text, string $domain = 'default'): void
{
    echo $text;
}
function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}
function esc_attr__(string $text, string $domain = 'default'): string
{
    return esc_attr($text);
}
function esc_html_e(string $text, string $domain = 'default'): void
{
    echo esc_html($text);
}
function esc_attr_e(string $text, string $domain = 'default'): void
{
    echo esc_attr($text);
}

// ---- escaping / sanitising ---------------------------------------------------
function esc_html(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
}
function esc_attr(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
}
function esc_textarea(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
}
function esc_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^(https?:|/|\#|\?)#i', $url) !== 1) {
        return '';
    }
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8', false);
}
function wp_kses_post(string $html): string
{
    return strip_tags($html, '<p><a><strong><em><ul><ol><li><br><span><code><bdi><time>');
}
function sanitize_text_field(mixed $str): string
{
    $str = strip_tags((string) $str);
    $str = preg_replace('/[\x00-\x1F\x7F]/', '', $str) ?? '';
    return trim(preg_replace('/\s+/', ' ', $str) ?? '');
}
function sanitize_key(string $key): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
}
function maybe_serialize(mixed $data): mixed
{
    if (is_array($data) || is_object($data)) {
        return serialize($data);
    }
    return $data;
}
function maybe_unserialize(mixed $data): mixed
{
    if (is_string($data) && preg_match('/^[aOsbdiN]:/', $data) === 1) {
        $out = @unserialize($data);
        return $out === false && $data !== 'b:0;' ? $data : $out;
    }
    return $data;
}
function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false
{
    return json_encode($data, $flags, $depth);
}
function wp_unslash(mixed $value): mixed
{
    return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value);
}
function absint(mixed $v): int
{
    return abs((int) $v);
}
function checked(mixed $checked, mixed $current = true, bool $echo = true): string
{
    $out = (string) $checked === (string) $current ? " checked='checked'" : '';
    if ($echo) {
        echo $out;
    }
    return $out;
}
function selected(mixed $selected, mixed $current = true, bool $echo = true): string
{
    $out = (string) $selected === (string) $current ? " selected='selected'" : '';
    if ($echo) {
        echo $out;
    }
    return $out;
}

// ---- options / transients / cache -------------------------------------------
/** @return mixed the stored value, or $default when the option is absent */
function get_option(string $option, mixed $default = false): mixed
{
    if (State::$optionsBackedByWpdb) {
        $wpdb = $GLOBALS['wpdb'];
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option));
        return $value === null ? $default : maybe_unserialize($value);
    }
    return array_key_exists($option, State::$options) ? State::$options[$option] : $default;
}
function tmc_stub_option_exists(string $option): bool
{
    if (State::$optionsBackedByWpdb) {
        $wpdb = $GLOBALS['wpdb'];
        return $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option)) !== null;
    }
    return array_key_exists($option, State::$options);
}
function tmc_stub_option_put(string $option, mixed $value): bool
{
    if (!State::$optionsBackedByWpdb) {
        State::$options[$option] = $value;
        return true;
    }
    $wpdb = $GLOBALS['wpdb'];
    $stored = maybe_serialize($value);
    $sql = $wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
         ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
        $option,
        $stored
    );
    return $wpdb->query($sql) !== false;
}
function add_option(string $option, mixed $value = '', string $deprecated = '', bool|string $autoload = 'yes'): bool
{
    // Real add_option() sanitises too — this is the second pass for a new option.
    $value = apply_filters('sanitize_option_' . $option, $value, $option);
    if (tmc_stub_option_exists($option)) {
        return false;
    }
    if (State::$failOptionWrites) {
        return false; // models a storage-layer failure, as WordPress reports it
    }
    tmc_stub_option_put($option, $value);
    do_action('add_option_' . $option, $option, $value);
    do_action('added_option', $option, $value);
    return true;
}
function update_option(string $option, mixed $value, bool|string|null $autoload = null): bool
{
    // Mirrors wp-includes/option.php: sanitize first, then add_option() when
    // the option does not exist yet — which sanitises a SECOND time.
    $value = apply_filters('sanitize_option_' . $option, $value, $option);
    if (!tmc_stub_option_exists($option)) {
        return add_option($option, $value, '', false);
    }
    $old = get_option($option);
    if ($old === $value) {
        return false; // WordPress quirk: unchanged value → false, no action fires
    }
    if (State::$failOptionWrites) {
        return false; // the DB write failed; no update_option_ action fires
    }
    tmc_stub_option_put($option, $value);
    do_action('update_option_' . $option, $old, $value, $option);
    do_action('updated_option', $option, $old, $value);
    return true;
}
function delete_option(string $option): bool
{
    if (!tmc_stub_option_exists($option)) {
        return false;
    }
    if (State::$optionsBackedByWpdb) {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $option));
        return true;
    }
    unset(State::$options[$option]);
    return true;
}
function get_transient(string $key): mixed
{
    return State::$transients[$key] ?? false;
}
function set_transient(string $key, mixed $value, int $expiration = 0): bool
{
    State::$transients[$key] = $value;
    return true;
}
function delete_transient(string $key): bool
{
    unset(State::$transients[$key]);
    return true;
}
function wp_cache_get(string $key, string $group = ''): mixed
{
    return State::$cache[$group][$key] ?? false;
}
function wp_cache_set(string $key, mixed $data, string $group = '', int $expire = 0): bool
{
    State::$cache[$group][$key] = $data;
    return true;
}
function wp_cache_delete(string $key, string $group = ''): bool
{
    unset(State::$cache[$group][$key]);
    return true;
}
function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    State::$clearedScheduledHooks[] = $hook;
    return 0;
}

// ---- users / roles / capabilities -------------------------------------------
function get_role(string $role): ?WP_Role
{
    return isset(State::$roles[$role]) ? new WP_Role($role, State::$roles[$role]) : null;
}
function current_user_can(string $capability, mixed ...$args): bool
{
    return in_array($capability, State::$currentUserCaps, true);
}
function get_current_user_id(): int
{
    return State::$currentUserId;
}
function is_user_logged_in(): bool
{
    return State::$currentUserId > 0;
}
function is_multisite(): bool
{
    return State::$multisite;
}
function wp_get_environment_type(): string
{
    return State::$environmentType;
}

// ---- urls / info ------------------------------------------------------------
function home_url(string $path = ''): string
{
    return State::$homeUrl . '/' . ltrim($path, '/');
}
function admin_url(string $path = ''): string
{
    return State::$homeUrl . '/wp-admin/' . ltrim($path, '/');
}
function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}
function get_bloginfo(string $show = ''): string
{
    return $show === 'version' ? State::$wpVersion : '';
}

// ---- wp_die / nonces --------------------------------------------------------
function wp_die(string $message = '', string $title = '', array|int $args = []): never
{
    State::$wpDieCalls[] = $message;
    throw new WpDieException($message, $title, is_array($args) ? $args : ['response' => $args]);
}
function wp_create_nonce(string $action): string
{
    return substr(md5('stub-nonce|' . $action . '|' . State::$currentUserId), 0, 10);
}
function wp_verify_nonce(string $nonce, string $action): int|false
{
    return hash_equals(wp_create_nonce($action), $nonce) ? 1 : false;
}
function check_admin_referer(string $action, string $queryArg = '_wpnonce'): int
{
    $nonce = (string) ($_REQUEST[$queryArg] ?? '');
    $result = wp_verify_nonce($nonce, $action);
    if ($result === false) {
        wp_die('The link you followed has expired.', '', ['response' => 403]);
    }
    return $result;
}
function wp_nonce_field(string $action, string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
{
    $field = '<input type="hidden" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '" />';
    if ($echo) {
        echo $field;
    }
    return $field;
}

// ---- admin menu / settings api -----------------------------------------------
function add_menu_page(string $pageTitle, string $menuTitle, string $capability, string $slug, callable|string $callback = '', string $icon = '', int|float|null $position = null): string
{
    $hook = 'toplevel_page_' . $slug;
    State::$menus[] = compact('pageTitle', 'menuTitle', 'capability', 'slug', 'callback', 'icon', 'position', 'hook') + ['parent' => null];
    return $hook;
}
function add_submenu_page(string $parent, string $pageTitle, string $menuTitle, string $capability, string $slug, callable|string $callback = '', int|float|null $position = null): string
{
    $hook = 'tecteb-marketplace_page_' . $slug;
    State::$menus[] = compact('parent', 'pageTitle', 'menuTitle', 'capability', 'slug', 'callback', 'position', 'hook');
    return $hook;
}
function register_setting(string $group, string $name, array $args = []): void
{
    State::$registeredSettings[$name] = ['group' => $group, 'args' => $args];
    if (isset($args['sanitize_callback']) && is_callable($args['sanitize_callback'])) {
        add_filter('sanitize_option_' . $name, static fn ($value) => call_user_func($args['sanitize_callback'], $value), 10, 2);
    }
}
function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
{
    State::$settingsErrors[] = compact('setting', 'code', 'message', 'type');
}
function get_settings_errors(string $setting = '', bool $sanitize = false): array
{
    if ($setting === '') {
        return State::$settingsErrors;
    }
    return array_values(array_filter(State::$settingsErrors, static fn ($e) => $e['setting'] === $setting));
}
function settings_errors(string $setting = '', bool $sanitize = false, bool $hideOnUpdate = false): void
{
    foreach (get_settings_errors($setting) as $e) {
        echo '<div class="notice notice-' . esc_attr($e['type']) . '"><p>' . esc_html($e['message']) . '</p></div>';
    }
}
function settings_fields(string $group): void
{
    echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '" />';
    echo '<input type="hidden" name="action" value="update" />';
    wp_nonce_field($group . '-options');
}

/**
 * Simulates wp-admin/options.php for one submitted option, in WordPress's
 * real order: verify the nonce, check the page capability, update the option
 * (which applies the sanitize filter and fires the persistence actions), then
 * hand the collected settings errors to the pre_set_transient filter before
 * redirecting. That last step is where a plugin learns whether the write
 * actually happened.
 */
function tmc_stub_submit_options(string $group, string $option, array $value): void
{
    check_admin_referer($group . '-options');
    $capability = apply_filters('option_page_capability_' . $group, 'manage_options');
    if (!current_user_can($capability)) {
        wp_die('Sorry, you are not allowed to manage options for this site.', '', ['response' => 403]);
    }
    update_option($option, $value); // applies the sanitize filter itself
    $errors = apply_filters('pre_set_transient_settings_errors', get_settings_errors());
    set_transient('settings_errors', $errors, 30);
    State::$settingsErrors = is_array($errors) ? $errors : State::$settingsErrors;
}

// ---- assets -------------------------------------------------------------------
function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all'): void
{
    State::$enqueued['style'][$handle] = compact('src', 'deps', 'ver', 'media');
}
function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, array|bool $args = []): void
{
    State::$enqueued['script'][$handle] = compact('src', 'deps', 'ver', 'args');
}
function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
{
    State::$enqueued['script'][$handle]['inline'][] = $data;
    return true;
}

// ---- REST ---------------------------------------------------------------------
function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
{
    State::$restRoutes[$namespace . $route] = $args;
    return true;
}
function rest_authorization_required_code(): int
{
    return is_user_logged_in() ? 403 : 401;
}
function rest_ensure_response(mixed $response): WP_Error|WP_REST_Response
{
    if ($response instanceof WP_Error || $response instanceof WP_REST_Response) {
        return $response;
    }
    return new WP_REST_Response($response);
}
function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}
