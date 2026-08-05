<?php
/**
 * Plugin Name:       HILG Vault
 * Plugin URI:        https://github.com/clarityweb/hilg-vault
 * Description:       Cloud file sharing for WordPress. Stores files in S3-compatible object storage instead of the media library, with folder based permissions, role to folder mapping and page embedding.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            ClarityWeb
 * Author URI:        https://clarityweb.ie
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hilg-vault
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault;

if (!defined('ABSPATH')) {
    exit;
}

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;

define('HILG_VAULT_DIR', plugin_dir_path(__FILE__));
define('HILG_VAULT_URL', plugin_dir_url(__FILE__));

/**
 * Minimal PSR-4 style autoloader.
 *
 * The plugin ships without a Composer vendor directory so it can be dropped
 * into any WordPress install. Classes live in src/ and mirror the namespace.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path     = HILG_VAULT_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_readable($path)) {
        require_once $path;
    }
});

register_activation_hook(__FILE__, [Install\Schema::class, 'activate']);
register_deactivation_hook(__FILE__, [Install\Schema::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});
