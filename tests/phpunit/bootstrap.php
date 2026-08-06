<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the WordPress test library, then the plugin. The library location is
 * taken from WP_TESTS_DIR, which the install script sets; in CI that is
 * /tmp/wordpress-tests-lib.
 *
 * If the library is missing the run stops with an explanation rather than a
 * stack trace, because "tests could not run" and "tests failed" are different
 * outcomes and only one of them means the code is wrong.
 *
 * @package HilgVault
 */

declare(strict_types=1);

$testsDirectory = getenv('WP_TESTS_DIR') ?: rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';

if (!file_exists($testsDirectory . '/includes/functions.php')) {
    fwrite(
        STDERR,
        "WordPress test library not found at {$testsDirectory}.\n\n" .
        "Install it with:\n" .
        "  bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest\n\n" .
        "For checks that need no database at all, run: php tests/run.php\n"
    );

    exit(1);
}

require_once $testsDirectory . '/includes/functions.php';

/**
 * Loads the plugin into the test WordPress before it boots.
 */
tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/hilg-vault.php';
});

require $testsDirectory . '/includes/bootstrap.php';
