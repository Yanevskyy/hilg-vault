<?php
/**
 * Minimal WordPress stand-ins for unit tests.
 *
 * The plugin ships without Composer, and the WordPress test suite needs a
 * database, a checkout of core and a Composer install. None of that exists on
 * a production server, which is where someone might reasonably want to check
 * the code still behaves after an update.
 *
 * So the pure logic is tested against small stand-ins instead: the signing
 * algorithm, file name handling and permission resolution do not need
 * WordPress, only a handful of its helpers. Anything that genuinely needs a
 * database is covered by verify.sh against a running site.
 *
 * @package HilgVault
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);

if (!function_exists('__')) {
    function __(string $text, string $domain = ''): string
    {
        return $text;
    }
}

if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = ''): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_basename')) {
    function wp_basename(string $path, string $suffix = ''): string
    {
        return basename(str_replace('\\', '/', $path), $suffix);
    }
}

if (!function_exists('size_format')) {
    function size_format(int $bytes, int $decimals = 0): string
    {
        return number_format($bytes / 1048576, $decimals) . ' MB';
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['__test_options'][$name] ?? $default;
    }
}

if (!function_exists('random_bytes_stub')) {
    // random_bytes is native; this marker keeps the stub list honest.
}

/**
 * The smallest test runner that still reports usefully.
 *
 * Deliberately not PHPUnit: adding a dependency to run the tests would defeat
 * the point of a plugin that has none.
 */
final class TestRunner
{
    private static int $passed = 0;
    private static int $failed = 0;

    /** @var array<int,string> */
    private static array $failures = [];

    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n" . $name . "\n";
    }

    public static function assert(string $label, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            self::$passed++;
            printf("  [PASS] %s%s\n", $label, $detail !== '' ? " ({$detail})" : '');

            return;
        }

        self::$failed++;
        self::$failures[] = self::$group . ' > ' . $label . ($detail !== '' ? ": {$detail}" : '');
        printf("  [FAIL] %s%s\n", $label, $detail !== '' ? " ({$detail})" : '');
    }

    public static function same(string $label, mixed $expected, mixed $actual): void
    {
        $ok = $expected === $actual;

        self::assert(
            $label,
            $ok,
            $ok ? '' : sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true))
        );
    }

    public static function summary(): int
    {
        printf("\n%s\nPassed: %d   Failed: %d\n", str_repeat('-', 46), self::$passed, self::$failed);

        if (self::$failed > 0) {
            echo "\nFailures:\n";

            foreach (self::$failures as $failure) {
                echo '  - ' . $failure . "\n";
            }

            return 1;
        }

        echo "\nAll tests passed.\n";

        return 0;
    }
}
