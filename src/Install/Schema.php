<?php
/**
 * Database schema.
 *
 * Folders and files live in dedicated tables rather than in wp_posts.
 * On a library of hundreds of thousands of files, every postmeta lookup
 * becomes a join against a table with millions of rows. Dedicated tables
 * with proper indexes keep listing a folder at constant cost regardless
 * of how large the library grows.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Install;

defined('ABSPATH') || exit;

final class Schema
{
    /**
     * Bumped whenever the table definitions change, so upgrades can run
     * dbDelta without a plugin reinstall.
     */
    public const DB_VERSION = '1.0.0';

    public const OPTION_DB_VERSION = 'hilg_vault_db_version';

    public static function tableFolders(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'hilg_folders';
    }

    public static function tableFiles(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'hilg_files';
    }

    public static function tableFolderRoles(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'hilg_folder_roles';
    }

    public static function tableAccessLog(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'hilg_access_log';
    }

    public static function activate(): void
    {
        self::installTables();
        self::registerRoles();

        add_option(self::OPTION_DB_VERSION, self::DB_VERSION);
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);

        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        // Tables and files are intentionally preserved on deactivation.
        // Destructive cleanup belongs in uninstall.php, behind an explicit
        // opt-in, so an accidental deactivation never destroys a library.
        flush_rewrite_rules();
    }

    /**
     * Runs on activation and on version bumps.
     */
    public static function installTables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $folders = self::tableFolders();
        $files   = self::tableFiles();
        $roles   = self::tableFolderRoles();
        $log     = self::tableAccessLog();

        // `path` is a materialised ancestor path (for example "/reports/2026/").
        // It costs a little on move operations and saves recursive queries on
        // every single read, which is the operation that actually happens at scale.
        $sql = [];

        $sql[] = "CREATE TABLE {$folders} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id BIGINT UNSIGNED NULL DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            path VARCHAR(1000) NOT NULL DEFAULT '/',
            depth SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            access_mode VARCHAR(20) NOT NULL DEFAULT 'private',
            password_hash VARCHAR(255) NULL DEFAULT NULL,
            description TEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_parent (parent_id, deleted_at),
            KEY idx_path (path(191)),
            KEY idx_access (access_mode),
            UNIQUE KEY uniq_sibling_slug (parent_id, slug)
        ) {$charset};";

        // object_key is the key inside the bucket. The file never becomes a
        // WordPress attachment, which is what keeps the media library out of
        // the picture entirely and removes the upload size ceiling.
        $sql[] = "CREATE TABLE {$files} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            folder_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            object_key VARCHAR(1024) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            mime_type VARCHAR(191) NULL DEFAULT NULL,
            extension VARCHAR(20) NULL DEFAULT NULL,
            checksum VARCHAR(191) NULL DEFAULT NULL,
            upload_id VARCHAR(255) NULL DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            download_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by BIGINT UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_folder (folder_id, deleted_at, name(100)),
            KEY idx_status (status),
            KEY idx_object (object_key(191))
        ) {$charset};";

        // The role to folder matrix required by the brief: an administrator
        // decides which role sees which folder. Permissions inherit down the
        // tree unless a descendant defines its own row.
        $sql[] = "CREATE TABLE {$roles} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            folder_id BIGINT UNSIGNED NOT NULL,
            role_slug VARCHAR(100) NOT NULL,
            can_view TINYINT(1) NOT NULL DEFAULT 1,
            can_upload TINYINT(1) NOT NULL DEFAULT 0,
            can_manage TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_folder_role (folder_id, role_slug),
            KEY idx_role (role_slug)
        ) {$charset};";

        // Audit trail. A public body needs to answer "who downloaded this".
        $sql[] = "CREATE TABLE {$log} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL DEFAULT NULL,
            folder_id BIGINT UNSIGNED NULL DEFAULT NULL,
            file_id BIGINT UNSIGNED NULL DEFAULT NULL,
            action VARCHAR(40) NOT NULL,
            result VARCHAR(20) NOT NULL DEFAULT 'allowed',
            ip_hash VARCHAR(64) NULL DEFAULT NULL,
            context TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_file (file_id, created_at),
            KEY idx_user (user_id, created_at),
            KEY idx_action (action, created_at)
        ) {$charset};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }

    /**
     * Registers the network member role described in the brief: an external
     * participant who manages their own profile and their own content, and
     * who can be granted access to admin-selected folders.
     */
    public static function registerRoles(): void
    {
        $capabilities = [
            'read'                   => true,
            'upload_files'           => false,
            'hilg_vault_access'      => true,
            'hilg_manage_own_profile' => true,
            'hilg_manage_own_content' => true,
        ];

        // add_role is a no-op when the role already exists, so capabilities
        // are applied explicitly to survive plugin upgrades.
        add_role('hilg_network_member', __('HILG Network Member', 'hilg-vault'), $capabilities);

        $member = get_role('hilg_network_member');

        if ($member instanceof \WP_Role) {
            foreach ($capabilities as $capability => $granted) {
                if ($granted) {
                    $member->add_cap($capability);
                } else {
                    $member->remove_cap($capability);
                }
            }
        }

        // Administrators and editors manage the vault itself.
        foreach (['administrator', 'editor'] as $roleSlug) {
            $role = get_role($roleSlug);

            if (!$role instanceof \WP_Role) {
                continue;
            }

            $role->add_cap('hilg_vault_access');
            $role->add_cap('hilg_manage_vault');
        }
    }

    /**
     * Called on every request so a manually updated plugin still migrates.
     */
    public static function maybeUpgrade(): void
    {
        if (get_option(self::OPTION_DB_VERSION) === self::DB_VERSION) {
            return;
        }

        self::installTables();
        self::registerRoles();
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }
}
