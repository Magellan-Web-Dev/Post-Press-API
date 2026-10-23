<?php
/**
 * Minimal in-memory WordPress for Post Press API tests.
 *
 * Only what the plugin uses is implemented, but behaviors that matter for
 * correctness are reproduced faithfully:
 *  - post meta is unslashed on write (like update_metadata) and scalars are
 *    stored as strings, so missing wp_slash() calls and type assumptions fail;
 *  - update_post_meta()/update_option() return false when the value is unchanged;
 *  - wp_insert_post() refuses empty content unless filtered, unslashes fields,
 *    and writes meta_input through update_post_meta();
 *  - wp_schedule_single_event() refuses duplicates within ten minutes and
 *    wp_schedule_event() refuses unknown recurrences;
 *  - limit_response_size truncates HTTP bodies like the Requests transport;
 *  - edit_post/delete_post/read_post map to primitive capabilities by the post's
 *    author and status, as map_meta_cap() does.
 */

declare(strict_types=1);

final class WpDieException extends RuntimeException
{
}

final class WPStub
{
    /** @var array<string, array{value: mixed, autoload: string}> */
    public static array $options = [];
    /** @var array<int, array<string, mixed>> */
    public static array $posts = [];
    /** @var array<int, array<string, list<mixed>>> */
    public static array $meta = [];
    public static int $next_post_id = 100;
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $filters = [];
    /** @var array<string, int> */
    public static array $did = [];
    /** @var array<int, array<string, mixed>> */
    public static array $users = [];
    public static int $current_user = 0;
    /** @var array<string, object> */
    public static array $post_types = [];
    /** @var array<int, array<string, array<string, array<string, mixed>>>> */
    public static array $cron = [];
    /** @var list<mixed> */
    public static array $http_queue = [];
    /** @var list<array{url: string, args: array<string, mixed>, safe: bool}> */
    public static array $http_log = [];
    /** @var list<string> */
    public static array $redirects = [];
    /** @var array<string, mixed> */
    public static array $cache = [];
    /** @var array<string, bool> Meta keys whose writes silently fail. */
    public static array $meta_fail = [];
    public static bool $insert_fail = false;
    public static bool $is_admin = true;
    /** @var array<string, array<string, mixed>> */
    public static array $rest_routes = [];
    /** @var array<string, string> */
    public static array $inline_scripts = [];
    /** @var array<string, array<string, mixed>> */
    public static array $menu = [];
    /** @var list<string> */
    public static array $enqueued = [];
    /** @var array<string, callable> */
    public static array $shortcodes = [];

    public static function reset(): void
    {
        self::$options      = [];
        self::$posts        = [];
        self::$meta         = [];
        self::$next_post_id = 100;
        self::$filters      = [];
        self::$did          = [];
        self::$users        = [];
        self::$current_user = 0;
        self::$post_types   = [];
        self::$cron         = [];
        self::$http_queue   = [];
        self::$http_log     = [];
        self::$redirects    = [];
        self::$cache        = [];
        self::$meta_fail    = [];
        self::$insert_fail  = false;
        self::$is_admin     = true;
        self::$rest_routes  = [];
        self::$inline_scripts = [];
        self::$menu           = [];
        self::$enqueued       = [];
        self::$shortcodes     = [];
        $_GET = $_POST = $_REQUEST = [];

        foreach (['post', 'page'] as $type) {
            register_post_type($type, ['label' => ucfirst($type), 'show_ui' => true]);
        }
        foreach (['attachment', 'revision', 'nav_menu_item', 'acf-field-group', 'wp_block'] as $type) {
            register_post_type($type, ['label' => $type, 'show_ui' => $type === 'acf-field-group' || $type === 'wp_block']);
        }
        self::$options['timezone_string'] = ['value' => 'UTC', 'autoload' => 'yes'];
    }

    /** Builds an HTTP response array. */
    public static function response(int $code, string $body, array $headers = [], string $message = 'OK'): array
    {
        return ['response' => ['code' => $code, 'message' => $message], 'headers' => array_change_key_case($headers, CASE_LOWER), 'body' => $body];
    }

    public static function json(mixed $data, int $code = 200, array $headers = []): array
    {
        return self::response($code, (string) json_encode($data), $headers + ['content-type' => 'application/json']);
    }

    /** Stored meta value as WordPress would return it. */
    public static function store_value(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return '';
        }
        return $value;
    }

    public static function posts_of_type(string $type): array
    {
        return array_values(array_filter(self::$posts, static fn ($post) => $post['post_type'] === $type));
    }
}

final class WP_Error
{
    /** @var array<string, list<string>> */
    public array $errors = [];
    /** @var array<string, mixed> */
    public array $error_data = [];

    public function __construct(string $code = '', string $message = '', mixed $data = '')
    {
        if ($code !== '') {
            $this->errors[$code][]   = $message;
            $this->error_data[$code] = $data;
        }
    }

    public function get_error_code(): string
    {
        return (string) (array_key_first($this->errors) ?? '');
    }

    public function get_error_message(string $code = ''): string
    {
        $code = $code === '' ? $this->get_error_code() : $code;
        return $this->errors[$code][0] ?? '';
    }

    public function get_error_data(string $code = ''): mixed
    {
        $code = $code === '' ? $this->get_error_code() : $code;
        return $this->error_data[$code] ?? null;
    }
}

final class WP_User
{
    public int $ID = 0;
    public string $user_login = '';
    public string $user_email = '';
    public string $display_name = '';
    /** @var array<string, bool> */
    public array $caps = [];

    public function exists(): bool
    {
        return $this->ID > 0;
    }

    public function has_cap(string $cap): bool
    {
        return !empty($this->caps[$cap]);
    }
}

#[\AllowDynamicProperties]
final class WP_Post
{
    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
    }
}

class WP_REST_Request implements ArrayAccess
{
    private array $params;
    private array $body;

    public function __construct(array $params = [], array $body = [])
    {
        $this->params = $params;
        $this->body   = $body;
    }

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? ($this->body[$key] ?? null);
    }

    public function get_body_params(): array
    {
        return $this->body;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->get_param((string) $offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get_param((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->params[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->params[(string) $offset]);
    }
}

class WP_REST_Response
{
    public function __construct(public mixed $data = null, public int $status = 200)
    {
    }

    public function get_data(): mixed
    {
        return $this->data;
    }
}

class WP_Http
{
    public static function make_absolute_url(string $maybe_relative, string $url): string
    {
        if (preg_match('#^https?://#i', $maybe_relative)) {
            return $maybe_relative;
        }
        $parts  = parse_url($url);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($maybe_relative, '//')) {
            return $parts['scheme'] . ':' . $maybe_relative;
        }
        if (str_starts_with($maybe_relative, '/')) {
            return $origin . $maybe_relative;
        }
        $path = $parts['path'] ?? '/';
        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $maybe_relative;
    }
}

/* Translation and escaping ------------------------------------------------ */

function __($text, $domain = 'default') { return $text; }
function _e($text, $domain = 'default') { echo $text; }
function _x($text, $context, $domain = 'default') { return $text; }
function _n($single, $plural, $number, $domain = 'default') { return (int) $number === 1 ? $single : $plural; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_textarea($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_html__($text, $domain = 'default') { return esc_html($text); }
function esc_html_e($text, $domain = 'default') { echo esc_html($text); }
function esc_attr__($text, $domain = 'default') { return esc_attr($text); }
function esc_attr_e($text, $domain = 'default') { echo esc_attr($text); }
function esc_url($url, $protocols = null) { return esc_url_raw($url, $protocols) === '' ? '' : htmlspecialchars(esc_url_raw($url, $protocols), ENT_QUOTES, 'UTF-8'); }
function esc_url_raw($url, $protocols = null) {
    $url = trim((string) $url);
    if ($url === '') { return ''; }
    if (str_starts_with($url, '/') || str_starts_with($url, '?') || str_starts_with($url, '#')) { return $url; }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $allowed = $protocols ?? ['http', 'https', 'mailto'];
    return in_array($scheme, $allowed, true) ? str_replace([' ', '"', "'", '<', '>'], ['%20', '%22', '%27', '%3C', '%3E'], $url) : '';
}
function wp_strip_all_tags($text, $remove_breaks = false) {
    $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
    $text = strip_tags((string) $text);
    if ($remove_breaks) { $text = preg_replace('/[\r\n\t ]+/', ' ', $text); }
    return trim((string) $text);
}
function wp_check_invalid_utf8($text, $strip = false) {
    $text = (string) $text;
    if ($text === '' || mb_check_encoding($text, 'UTF-8')) { return $text; }
    return $strip ? mb_convert_encoding($text, 'UTF-8', 'UTF-8') : '';
}
function sanitize_text_field($text) {
    $text = wp_check_invalid_utf8((string) $text);
    if (str_contains($text, '<')) { $text = wp_strip_all_tags($text, false); }
    $text = preg_replace('/[\r\n\t ]+/', ' ', $text);
    return trim((string) preg_replace('/%[a-f0-9]{2}/i', '', $text));
}
function sanitize_textarea_field($text) {
    $text = wp_check_invalid_utf8((string) $text);
    if (str_contains($text, '<')) { $text = strip_tags($text); }
    return trim($text);
}
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function sanitize_title($title) {
    $title = strtolower(trim(strip_tags((string) $title)));
    $title = preg_replace('/[^a-z0-9\s\-_]/', '', $title);
    $title = preg_replace('/[\s_]+/', '-', $title);
    return trim((string) preg_replace('/-+/', '-', $title), '-');
}
function sanitize_email($email) { return filter_var(trim((string) $email), FILTER_SANITIZE_EMAIL) ?: ''; }
function is_email($email) { return filter_var((string) $email, FILTER_VALIDATE_EMAIL) ? $email : false; }
function wp_kses_post($text) {
    $text = preg_replace('@<(script|style|iframe)[^>]*?>.*?</\\1>@si', '', (string) $text);
    $text = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $text);
    return (string) preg_replace('/javascript:/i', '', (string) $text);
}
function wp_kses($text, $allowed) { return strip_tags((string) $text, '<' . implode('><', array_keys((array) $allowed)) . '>'); }
function wp_slash($value) {
    if (is_array($value)) { return array_map('wp_slash', $value); }
    return is_string($value) ? addslashes($value) : $value;
}
function wp_unslash($value) {
    if (is_array($value)) { return array_map('wp_unslash', $value); }
    return is_string($value) ? stripslashes($value) : $value;
}
function wp_json_encode($data, $flags = 0, $depth = 512) { return json_encode($data, $flags, $depth); }
function absint($value) { return abs((int) $value); }
function maybe_unserialize($data) { return is_string($data) && preg_match('/^(a|O|s|i|b|d|N):/', $data) ? unserialize($data) : $data; }
function maybe_serialize($data) { return (is_array($data) || is_object($data)) ? serialize($data) : $data; }
function size_format($bytes, $decimals = 0) { return $bytes >= 1048576 ? round($bytes / 1048576, $decimals) . ' MB' : round($bytes / 1024, $decimals) . ' KB'; }
function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
function wp_generate_uuid4() { return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000, random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)); }
function wp_generate_password($length = 12, $special = true, $extra = false) { return substr(bin2hex(random_bytes($length)), 0, $length); }
function trailingslashit($value) { return rtrim((string) $value, '/\\') . '/'; }
function untrailingslashit($value) { return rtrim((string) $value, '/\\'); }
function wp_normalize_path($path) { return str_replace('\\', '/', (string) $path); }
function selected($a, $b = true, $echo = true) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ($echo) { echo $r; } return $r; }
function checked($a, $b = true, $echo = true) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ($echo) { echo $r; } return $r; }
function disabled($a, $b = true, $echo = true) { $r = (string) $a === (string) $b ? ' disabled="disabled"' : ''; if ($echo) { echo $r; } return $r; }
function get_bloginfo($show = '') { return $show === 'version' ? '6.6' : 'Test Site'; }
function is_admin() { return WPStub::$is_admin; }
function wp_doing_cron() { return false; }

/* Hooks -------------------------------------------------------------------- */

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    WPStub::$filters[$hook][$priority][] = [$callback, (int) $accepted_args];
    return true;
}
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { return add_filter($hook, $callback, $priority, $accepted_args); }
function remove_filter($hook, $callback, $priority = 10) {
    foreach (WPStub::$filters[$hook][$priority] ?? [] as $index => [$registered]) {
        if ($registered === $callback) {
            unset(WPStub::$filters[$hook][$priority][$index]);
            return true;
        }
    }
    return false;
}
function remove_action($hook, $callback, $priority = 10) { return remove_filter($hook, $callback, $priority); }
function has_filter($hook, $callback = false) {
    if (empty(WPStub::$filters[$hook])) { return false; }
    if ($callback === false) { return true; }
    foreach (WPStub::$filters[$hook] as $priority => $callbacks) {
        foreach ($callbacks as [$registered]) {
            if ($registered === $callback) { return $priority; }
        }
    }
    return false;
}
function has_action($hook, $callback = false) { return has_filter($hook, $callback); }
function apply_filters($hook, $value, ...$args) {
    if (empty(WPStub::$filters[$hook])) { return $value; }
    $all = WPStub::$filters[$hook];
    ksort($all);
    foreach ($all as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $value = call_user_func_array($callback, array_slice([$value, ...$args], 0, max(1, $accepted)));
        }
    }
    return $value;
}
function do_action($hook, ...$args) {
    WPStub::$did[$hook] = (WPStub::$did[$hook] ?? 0) + 1;
    if (empty(WPStub::$filters[$hook])) { return; }
    $all = WPStub::$filters[$hook];
    ksort($all);
    foreach ($all as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            call_user_func_array($callback, array_slice($args, 0, $accepted));
        }
    }
}
function did_action($hook) { return WPStub::$did[$hook] ?? 0; }
function __return_false() { return false; }
function __return_true() { return true; }

/* Options, transients, cache ---------------------------------------------- */

function get_option($name, $default = false) {
    return array_key_exists($name, WPStub::$options) ? WPStub::$options[$name]['value'] : $default;
}
function update_option($name, $value, $autoload = null) {
    $exists = array_key_exists($name, WPStub::$options);
    $flag   = $autoload === null ? ($exists ? WPStub::$options[$name]['autoload'] : 'yes') : (($autoload === true || $autoload === 'yes' || $autoload === 'on') ? 'yes' : 'no');
    if ($exists && maybe_serialize(WPStub::$options[$name]['value']) === maybe_serialize($value) && WPStub::$options[$name]['autoload'] === $flag) {
        return false;
    }
    WPStub::$options[$name] = ['value' => $value, 'autoload' => $flag];
    return true;
}
function add_option($name, $value = '', $deprecated = '', $autoload = 'yes') {
    if (array_key_exists($name, WPStub::$options)) { return false; }
    WPStub::$options[$name] = ['value' => $value, 'autoload' => ($autoload === true || $autoload === 'yes') ? 'yes' : 'no'];
    return true;
}
function delete_option($name) {
    if (!array_key_exists($name, WPStub::$options)) { return false; }
    unset(WPStub::$options[$name]);
    return true;
}
function set_transient($name, $value, $expiration = 0) {
    update_option('_transient_' . $name, $value, false);
    if ($expiration) { update_option('_transient_timeout_' . $name, time() + $expiration, false); }
    return true;
}
function get_transient($name) {
    $timeout = get_option('_transient_timeout_' . $name, 0);
    if ($timeout && $timeout < time()) { delete_transient($name); return false; }
    return get_option('_transient_' . $name, false);
}
function delete_transient($name) { delete_option('_transient_timeout_' . $name); return delete_option('_transient_' . $name); }
function set_site_transient($name, $value, $expiration = 0) { return set_transient('site_' . $name, $value, $expiration); }
function get_site_transient($name) { return get_transient('site_' . $name); }
function delete_site_transient($name) { return delete_transient('site_' . $name); }
function wp_cache_get($key, $group = '') { return WPStub::$cache[$group . ':' . $key] ?? false; }
function wp_cache_set($key, $value, $group = '', $expire = 0) { WPStub::$cache[$group . ':' . $key] = $value; return true; }
function wp_cache_delete($key, $group = '') { unset(WPStub::$cache[$group . ':' . $key]); return true; }

/* Users and capabilities -------------------------------------------------- */

function wpstub_add_user(int $id, string $login, array $caps, string $email = ''): void {
    WPStub::$users[$id] = ['ID' => $id, 'user_login' => $login, 'user_email' => $email ?: $login . '@example.test', 'display_name' => ucfirst($login), 'caps' => array_fill_keys($caps, true)];
}
function wpstub_admin_caps(): array {
    return ['manage_options', 'upload_files', 'edit_posts', 'publish_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'update_plugins', 'unfiltered_html', 'read'];
}
/** Primitive capabilities a meta capability requires, like map_meta_cap() for posts. */
function wpstub_map_meta_cap(string $cap, int $user_id, array $args): array {
    if (!in_array($cap, ['edit_post', 'delete_post', 'read_post'], true)) { return [$cap]; }
    $post = WPStub::$posts[(int) ($args[0] ?? 0)] ?? null;
    if ($post === null) { return ['do_not_allow']; }
    $prefix = $cap === 'delete_post' ? 'delete' : 'edit';
    $status = $post['post_status'] === 'trash' ? (string) (WPStub::$meta[$post['ID']]['_wp_trash_meta_status'][0] ?? 'draft') : $post['post_status'];
    if ($cap === 'read_post' && $status !== 'private') { return ['read']; }
    if ((int) $post['post_author'] === $user_id) {
        return in_array($status, ['publish', 'future'], true) ? [$prefix . '_published_posts'] : [$prefix . '_posts'];
    }
    $caps = [$prefix . '_others_posts'];
    if (in_array($status, ['publish', 'future'], true)) { $caps[] = $prefix . '_published_posts'; }
    elseif ($status === 'private') { $caps[] = $prefix . '_private_posts'; }
    return $caps;
}
function get_userdata($id) {
    $data = WPStub::$users[(int) $id] ?? null;
    if ($data === null) { return false; }
    $user = new WP_User();
    foreach ($data as $key => $value) { $user->$key = $value; }
    return $user;
}
function get_user_by($field, $value) {
    foreach (WPStub::$users as $data) {
        if (($field === 'email' && $data['user_email'] === $value) || ($field === 'login' && $data['user_login'] === $value) || ($field === 'id' && $data['ID'] === (int) $value)) {
            return get_userdata($data['ID']);
        }
    }
    return false;
}
function wp_set_current_user($id) { WPStub::$current_user = (int) $id; return wp_get_current_user(); }
function get_current_user_id() { return WPStub::$current_user; }
function wp_get_current_user() { return get_userdata(WPStub::$current_user) ?: new WP_User(); }
function user_can($user, $cap, ...$args) {
    $user = $user instanceof WP_User ? $user : get_userdata((int) $user);
    if (!$user) { return false; }
    foreach (wpstub_map_meta_cap((string) $cap, $user->ID, $args) as $required) {
        if (!$user->has_cap($required)) { return false; }
    }
    return true;
}
function current_user_can($cap, ...$args) { return user_can(WPStub::$current_user, $cap, ...$args); }
function is_user_logged_in() { return WPStub::$current_user > 0; }

/* Nonces, redirects, dying ------------------------------------------------ */

function wp_create_nonce($action = -1) { return 'n' . substr(md5($action . '|' . get_current_user_id()), 0, 10); }
function wp_verify_nonce($nonce, $action = -1) { return hash_equals(wp_create_nonce($action), (string) $nonce) ? 1 : false; }
function check_admin_referer($action = -1, $query_arg = '_wpnonce') {
    $nonce = $_REQUEST[$query_arg] ?? '';
    if (!wp_verify_nonce($nonce, $action)) { wp_die('The link you followed has expired.', '', ['response' => 403]); }
    return 1;
}
function wp_nonce_url($url, $action = -1, $name = '_wpnonce') { return add_query_arg($name, wp_create_nonce($action), $url); }
function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true) {
    $field = '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '">';
    if ($echo) { echo $field; }
    return $field;
}
function wp_die($message = '', $title = '', $args = []) { throw new WpDieException((string) $message, (int) ($args['response'] ?? 500)); }
function wp_safe_redirect($url, $status = 302) { WPStub::$redirects[] = $url; return true; }

/* URLs -------------------------------------------------------------------- */

function add_query_arg(...$args) {
    if (is_array($args[0])) {
        $params = $args[0];
        $url    = $args[1] ?? '';
    } else {
        $params = [$args[0] => $args[1]];
        $url    = $args[2] ?? '';
    }
    [$base, $query] = array_pad(explode('?', (string) $url, 2), 2, '');
    parse_str($query, $existing);
    foreach ($params as $key => $value) {
        if ($value === false || $value === null) { unset($existing[$key]); } else { $existing[$key] = $value; }
    }
    return $base . ($existing ? '?' . http_build_query($existing) : '');
}
function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim((string) $path, '/'); }
function self_admin_url($path = '') { return admin_url($path); }
function home_url($path = '') { return 'https://example.test/' . ltrim((string) $path, '/'); }
function plugin_dir_path($file) { return trailingslashit(dirname((string) $file)); }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/' . basename(dirname((string) $file)) . '/'; }
function plugin_basename($file) { return basename(dirname((string) $file)) . '/' . basename((string) $file); }
function get_edit_post_link($id) { return admin_url('post.php?post=' . (int) $id . '&action=edit'); }

/* Time -------------------------------------------------------------------- */

function wp_timezone() { return new DateTimeZone((string) (get_option('timezone_string') ?: 'UTC')); }
function wp_date($format, $timestamp = null, $timezone = null) {
    return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($timezone ?? wp_timezone())->format($format);
}
function current_time($type, $gmt = 0) { return $type === 'timestamp' ? time() : wp_date($type === 'mysql' ? 'Y-m-d H:i:s' : $type); }
function get_gmt_from_date($date, $format = 'Y-m-d H:i:s') {
    return (new DateTimeImmutable((string) $date, wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format($format);
}

/* Post types and statuses ------------------------------------------------- */

function register_post_type($name, $args = []) {
    WPStub::$post_types[$name] = (object) [
        'name'    => $name,
        'label'   => $args['label'] ?? $name,
        'labels'  => (object) ['name' => $args['label'] ?? $name, 'singular_name' => $args['label'] ?? $name],
        'show_ui' => $args['show_ui'] ?? true,
        'cap'     => (object) [
            'create_posts'      => $args['cap_prefix'] ?? 'edit_posts',
            'edit_posts'        => $args['cap_prefix'] ?? 'edit_posts',
            'publish_posts'     => $args['publish_cap'] ?? 'publish_posts',
            'edit_others_posts' => 'edit_others_posts',
        ],
    ];
    return WPStub::$post_types[$name];
}
function get_post_types($args = [], $output = 'names') {
    $types = array_filter(WPStub::$post_types, static fn ($type) => !isset($args['show_ui']) || $type->show_ui === $args['show_ui']);
    return $output === 'objects' ? $types : array_combine(array_keys($types), array_keys($types));
}
function post_type_exists($name) { return isset(WPStub::$post_types[$name]); }
function get_post_type_object($name) { return WPStub::$post_types[$name] ?? null; }
function get_post_stati($args = [], $output = 'names') {
    $all = ['publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit'];
    return array_combine($all, $all);
}
function get_post_status_object($status) {
    return in_array($status, ['publish', 'future', 'draft', 'pending', 'private', 'trash'], true) ? (object) ['name' => $status, 'label' => ucfirst($status)] : null;
}

/* Posts ------------------------------------------------------------------- */

function wp_insert_post($postarr, $wp_error = false, $fire_after_hooks = true) {
    $raw  = $postarr;
    $data = wp_unslash($postarr);
    unset($data['meta_input']);

    if (!empty($data['ID'])) {
        $id = (int) $data['ID'];
        if (!isset(WPStub::$posts[$id])) { return $wp_error ? new WP_Error('invalid_post', 'Invalid post ID.') : 0; }
        WPStub::$posts[$id] = array_merge(WPStub::$posts[$id], $data);
        $update = true;
    } else {
        if (WPStub::$insert_fail) { return $wp_error ? new WP_Error('db_insert_error', 'Could not insert post into the database.') : 0; }
        $maybe_empty = trim((string) ($data['post_title'] ?? '')) === '' && trim((string) ($data['post_content'] ?? '')) === '' && trim((string) ($data['post_excerpt'] ?? '')) === '';
        if (apply_filters('wp_insert_post_empty_content', $maybe_empty, $postarr)) {
            return $wp_error ? new WP_Error('empty_content', 'Content, title, and excerpt are empty.') : 0;
        }
        $id = WPStub::$next_post_id++;
        WPStub::$posts[$id] = array_merge([
            'ID' => $id, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => '', 'post_content' => '',
            'post_excerpt' => '', 'post_name' => '', 'post_author' => get_current_user_id(),
            'post_date' => gmdate('Y-m-d H:i:s'), 'post_date_gmt' => gmdate('Y-m-d H:i:s'),
        ], $data, ['ID' => $id]);
        $update = false;
    }

    if (WPStub::$posts[$id]['post_status'] === 'publish' && WPStub::$posts[$id]['post_name'] === '') {
        WPStub::$posts[$id]['post_name'] = sanitize_title(WPStub::$posts[$id]['post_title']);
    }

    foreach ((array) ($raw['meta_input'] ?? []) as $key => $value) {
        update_post_meta($id, $key, $value);
    }

    do_action('save_post', $id, get_post($id), $update);
    return $id;
}
function wp_update_post($postarr = [], $wp_error = false) {
    if (empty($postarr['ID']) || !isset(WPStub::$posts[(int) $postarr['ID']])) {
        return $wp_error ? new WP_Error('invalid_post', 'Invalid post ID.') : 0;
    }
    unset($postarr['edit_date']);
    return wp_insert_post($postarr, $wp_error);
}
function get_post($id) { return isset(WPStub::$posts[(int) $id]) ? new WP_Post(WPStub::$posts[(int) $id]) : null; }
function get_post_status($id) { return WPStub::$posts[(int) $id]['post_status'] ?? false; }
function wp_trash_post($id) {
    if (!isset(WPStub::$posts[$id])) { return false; }
    update_post_meta($id, '_wp_trash_meta_status', WPStub::$posts[$id]['post_status']);
    WPStub::$posts[$id]['post_status'] = 'trash';
    return get_post($id);
}
function wp_untrash_post($id) {
    if (($id = (int) $id) === 0 || (WPStub::$posts[$id]['post_status'] ?? '') !== 'trash') { return false; }
    WPStub::$posts[$id]['post_status'] = 'draft';
    delete_post_meta($id, '_wp_trash_meta_status');
    return get_post($id);
}
function get_posts($args = []) {
    $types    = (array) ($args['post_type'] ?? 'post');
    $statuses = $args['post_status'] ?? 'publish';
    $statuses = $statuses === 'any' ? ['publish', 'future', 'draft', 'pending', 'private'] : (array) $statuses;
    $matches  = [];
    foreach (WPStub::$posts as $id => $post) {
        if (!in_array($post['post_type'], $types, true) || !in_array($post['post_status'], $statuses, true)) { continue; }
        if (isset($args['meta_key'])) {
            $values = WPStub::$meta[$id][$args['meta_key']] ?? [];
            if ($values === []) { continue; }
            if (array_key_exists('meta_value', $args) && !in_array((string) $args['meta_value'], array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $values), true)) { continue; }
        }
        $matches[] = $id;
    }
    sort($matches);
    $limit = (int) ($args['posts_per_page'] ?? 5);
    if ($limit > 0) { $matches = array_slice($matches, 0, $limit); }
    return ($args['fields'] ?? '') === 'ids' ? $matches : array_map('get_post', $matches);
}

/* Post meta --------------------------------------------------------------- */

function update_post_meta($post_id, $key, $value, $prev = '') {
    $post_id = (int) $post_id;
    if (!isset(WPStub::$posts[$post_id])) { return false; }
    $key   = wp_unslash($key);
    $value = WPStub::store_value(wp_unslash($value));
    if (!empty(WPStub::$meta_fail[$key])) { return false; }
    if (isset(WPStub::$meta[$post_id][$key])) {
        if (maybe_serialize(WPStub::$meta[$post_id][$key][0]) === maybe_serialize($value)) { return false; }
        WPStub::$meta[$post_id][$key] = [$value];
        return true;
    }
    WPStub::$meta[$post_id][$key] = [$value];
    return random_int(1, 100000);
}
function add_post_meta($post_id, $key, $value, $unique = false) {
    $value = WPStub::store_value(wp_unslash($value));
    if ($unique && isset(WPStub::$meta[$post_id][$key])) { return false; }
    WPStub::$meta[(int) $post_id][$key][] = $value;
    return random_int(1, 100000);
}
function get_post_meta($post_id, $key = '', $single = false) {
    $all = WPStub::$meta[(int) $post_id] ?? [];
    if ($key === '') { return $all; }
    if (!isset($all[$key])) { return $single ? '' : []; }
    return $single ? $all[$key][0] : $all[$key];
}
function delete_post_meta($post_id, $key, $value = '') {
    if (!isset(WPStub::$meta[(int) $post_id][$key])) { return false; }
    unset(WPStub::$meta[(int) $post_id][$key]);
    return true;
}
function metadata_exists($type, $object_id, $key) { return isset(WPStub::$meta[(int) $object_id][$key]); }

/* Cron -------------------------------------------------------------------- */

function wp_get_schedules() {
    return apply_filters('cron_schedules', [
        'hourly'     => ['interval' => 3600, 'display' => 'Once Hourly'],
        'twicedaily' => ['interval' => 43200, 'display' => 'Twice Daily'],
        'daily'      => ['interval' => 86400, 'display' => 'Once Daily'],
        'weekly'     => ['interval' => 604800, 'display' => 'Once Weekly'],
    ]);
}
function wp_schedule_event($timestamp, $recurrence, $hook, $args = [], $wp_error = false) {
    $schedules = wp_get_schedules();
    if (!isset($schedules[$recurrence])) { return $wp_error ? new WP_Error('invalid_schedule', 'Event schedule does not exist.') : false; }
    WPStub::$cron[(int) $timestamp][$hook][md5(serialize($args))] = ['schedule' => $recurrence, 'args' => $args, 'interval' => $schedules[$recurrence]['interval']];
    return true;
}
function wp_schedule_single_event($timestamp, $hook, $args = [], $wp_error = false) {
    $key = md5(serialize($args));
    foreach (WPStub::$cron as $ts => $hooks) {
        if (isset($hooks[$hook][$key]) && abs($ts - $timestamp) <= 600) {
            return $wp_error ? new WP_Error('duplicate_event', 'A duplicate event already exists.') : false;
        }
    }
    WPStub::$cron[(int) $timestamp][$hook][$key] = ['schedule' => false, 'args' => $args];
    return true;
}
function wp_next_scheduled($hook, $args = []) {
    $key   = md5(serialize($args));
    $times = [];
    foreach (WPStub::$cron as $ts => $hooks) {
        if (isset($hooks[$hook][$key])) { $times[] = $ts; }
    }
    return $times ? min($times) : false;
}
function wp_get_scheduled_event($hook, $args = [], $timestamp = null) {
    $next = wp_next_scheduled($hook, $args);
    if ($next === false) { return false; }
    $event = WPStub::$cron[$next][$hook][md5(serialize($args))];
    return (object) ['hook' => $hook, 'timestamp' => $next, 'schedule' => $event['schedule'], 'args' => $args, 'interval' => $event['interval'] ?? null];
}
function wp_clear_scheduled_hook($hook, $args = [], $wp_error = false) {
    $key   = md5(serialize($args));
    $count = 0;
    foreach (WPStub::$cron as $ts => $hooks) {
        if (isset($hooks[$hook][$key])) {
            unset(WPStub::$cron[$ts][$hook][$key]);
            $count++;
        }
    }
    return $count;
}
function wp_unschedule_hook($hook, $wp_error = false) {
    $count = 0;
    foreach (WPStub::$cron as $ts => $hooks) {
        if (isset($hooks[$hook])) {
            $count += count($hooks[$hook]);
            unset(WPStub::$cron[$ts][$hook]);
        }
    }
    return $count;
}
function _get_cron_array() { return WPStub::$cron; }
function wpstub_count_events(string $hook, ?array $args = null): int {
    $count = 0;
    foreach (WPStub::$cron as $hooks) {
        if ($args === null) { $count += count($hooks[$hook] ?? []); }
        elseif (isset($hooks[$hook][md5(serialize($args))])) { $count++; }
    }
    return $count;
}

/* HTTP -------------------------------------------------------------------- */

function wpstub_http(string $url, array $args, bool $safe) {
    WPStub::$http_log[] = ['url' => $url, 'args' => $args, 'safe' => $safe];
    if (WPStub::$http_queue === []) { throw new RuntimeException('Unexpected HTTP request to ' . $url); }
    $next = array_shift(WPStub::$http_queue);
    if (is_callable($next)) { $next = $next($url, $args); }
    if (is_array($next) && !empty($args['limit_response_size']) && strlen($next['body']) > $args['limit_response_size']) {
        $next['body'] = substr($next['body'], 0, (int) $args['limit_response_size']);
    }
    return $next;
}
function wp_safe_remote_request($url, $args = []) { return wpstub_http((string) $url, (array) $args, true); }
function wp_remote_get($url, $args = []) { return wpstub_http((string) $url, (array) $args, false); }
function wp_http_validate_url($url) {
    $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url((string) $url, PHP_URL_SCHEME)), ['http', 'https'], true)) { return false; }
    if ($host === 'localhost' || preg_match('/^(127\.|10\.|192\.168\.|169\.254\.)/', $host)) { return false; }
    return $url;
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_remote_retrieve_response_code($response) { return is_array($response) ? $response['response']['code'] : ''; }
function wp_remote_retrieve_response_message($response) { return is_array($response) ? $response['response']['message'] : ''; }
function wp_remote_retrieve_body($response) { return is_array($response) ? $response['body'] : ''; }
function wp_remote_retrieve_header($response, $header) { return is_array($response) ? ($response['headers'][strtolower($header)] ?? '') : ''; }
function wp_update_plugins() { return null; }

/* Shortcodes: WordPress's own name rules and matching (wp-includes/shortcodes.php,
 * 7.1), without attribute parsing or the pass over HTML attributes ------------ */

function add_shortcode($tag, $callback) {
    // WordPress refuses these names; fail loudly so tests only use names it accepts.
    if (trim((string) $tag) === '' || preg_match('@[<>&/\[\]\x00-\x20=]@', (string) $tag)) {
        throw new InvalidArgumentException('WordPress would not register the shortcode ' . var_export($tag, true));
    }
    WPStub::$shortcodes[(string) $tag] = $callback;
}
function do_shortcode($content, $ignore_html = false) {
    $content = (string) $content;
    if (!str_contains($content, '[') || WPStub::$shortcodes === []) { return $content; }
    preg_match_all('@\[([^<>&/\[\]\x00-\x20=]++)@', $content, $matches);
    $tagnames = array_intersect(array_keys(WPStub::$shortcodes), $matches[1]);
    if ($tagnames === []) { return $content; }
    return (string) preg_replace_callback('/' . get_shortcode_regex($tagnames) . '/', 'do_shortcode_tag', $content);
}
function get_shortcode_regex($tagnames = null) {
    $tagregexp = implode('|', array_map('preg_quote', $tagnames ?? array_keys(WPStub::$shortcodes)));
    return '\\[(\\[?)(' . $tagregexp . ')(?![\\w-])([^\\]\\/]*(?:\\/(?!\\])[^\\]\\/]*)*?)'
        . '(?:(\\/)\\]|\\](?:([^\\[]*+(?:\\[(?!\\/\\2\\])[^\\[]*+)*+)\\[\\/\\2\\])?)(\\]?)';
}
function do_shortcode_tag($m) {
    if ($m[1] === '[' && $m[6] === ']') { return substr($m[0], 1, -1); } // [[tag]] escape
    return $m[1] . call_user_func(WPStub::$shortcodes[$m[2]], [], $m[5] ?? null, $m[2]) . $m[6];
}

/* REST, scripts, admin rendering ------------------------------------------- */

function register_rest_route($namespace, $route, $args = [], $override = false) { WPStub::$rest_routes[$namespace . $route] = $args; return true; }
function rest_ensure_response($response) { return $response instanceof WP_REST_Response ? $response : new WP_REST_Response($response); }
function rest_authorization_required_code() { return is_user_logged_in() ? 403 : 401; }
function wp_enqueue_script($handle, ...$args) { WPStub::$enqueued[] = 'script:' . $handle; }
function wp_enqueue_style($handle, ...$args) { WPStub::$enqueued[] = 'style:' . $handle; }
function wp_add_inline_script($handle, $data, $position = 'after') { WPStub::$inline_scripts[$handle] = (WPStub::$inline_scripts[$handle] ?? '') . $data; return true; }
function wp_dropdown_users($args = []) { echo '<select name="' . esc_attr($args['name'] ?? 'user') . '" id="' . esc_attr($args['id'] ?? 'user') . '"></select>'; }
function load_plugin_textdomain(...$args) { return true; }
function wp_clean_plugins_cache($clear = true) {}
function post_type_supports($type, $feature) { return true; }
function register_activation_hook($file, $callback) { add_action('activate_' . plugin_basename($file), $callback); }
function register_deactivation_hook($file, $callback) { add_action('deactivate_' . plugin_basename($file), $callback); }
function site_url($path = '') { return home_url($path); }
function add_menu_page($page_title, $menu_title, $capability, $slug, $callback = '', $icon = '', $position = null) { WPStub::$menu[$slug] = ['parent' => null, 'title' => $menu_title, 'cap' => $capability]; return 'toplevel_page_' . $slug; }
function add_submenu_page($parent, $page_title, $menu_title, $capability, $slug, $callback = '', $position = null) { WPStub::$menu[$slug . '@' . $parent] = ['parent' => $parent, 'title' => $menu_title, 'cap' => $capability]; return 'post-press-api_page_' . $slug; }
