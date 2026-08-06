<?php
/**
 * Bootstrap for the unit suite.
 *
 * Loads the plugin's own classes with the handful of WordPress helpers they
 * touch, and nothing else. No database, no WordPress install, no network. That
 * keeps the suite fast enough to run on every save and runnable anywhere,
 * including on a server after an update.
 *
 * The integration suite loads real WordPress instead; see bootstrap.php.
 *
 * @package HilgVault
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/');
define('HILG_VAULT_DIR', dirname(__DIR__, 2) . '/');
define('HILG_VAULT_URL', 'https://example.test/plugins/hilg-vault/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);

if (!function_exists('__')) {
    function __(string $text, string $domain = ''): string { return $text; }
}

if (!function_exists('_n')) {
    function _n(string $s, string $p, int $n, string $d = ''): string { return $n === 1 ? $s : $p; }
}

if (!function_exists('esc_html')) {
    function esc_html(string $t): string { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $t): string { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('size_format')) {
    function size_format(int $b, int $d = 0): string { return number_format($b / 1048576, $d) . ' MB'; }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $h, mixed $v, mixed ...$a): mixed { return $v; }
}

if (!function_exists('get_option')) {
    function get_option(string $n, mixed $default = false): mixed { return $default; }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($args);
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = []): array
    {
        return ['response' => ['code' => 200], 'body' => '', 'headers' => []];
    }
}

if (!function_exists('wp_remote_head')) {
    function wp_remote_head(string $url, array $args = []): array
    {
        return ['response' => ['code' => 200], 'body' => '', 'headers' => []];
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = []): array
    {
        return ['response' => ['code' => 200], 'body' => '', 'headers' => []];
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request(string $url, array $args = []): array
    {
        return ['response' => ['code' => 200], 'body' => '', 'headers' => []];
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool { return $thing instanceof WP_Error; }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(array $r): int { return (int) ($r['response']['code'] ?? 0); }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(array $r): string { return (string) ($r['body'] ?? ''); }
}

if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header(array $r, string $h): string { return (string) ($r['headers'][$h] ?? ''); }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '') {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
    }
}

// The plugin's own autoloader, without booting the plugin itself.
spl_autoload_register(static function (string $class): void {
    $prefix = 'ClarityWeb\\HilgVault\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = HILG_VAULT_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_readable($path)) {
        require_once $path;
    }
});
