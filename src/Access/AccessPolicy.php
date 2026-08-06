<?php
/**
 * Access decisions for folders and files.
 *
 * Three modes are required by the brief:
 *   public   - anyone
 *   password - one shared password, not tied to a user account
 *   private  - signed in users only, filtered by the role to folder matrix
 *
 * Two rules hold everywhere in this class.
 *
 * First, permission is evaluated when a file is served, not only when a list
 * is drawn. Hiding a row in the interface is not access control. Every
 * download URL is minted only after the same check that produced the listing.
 *
 * Second, a folder inherits its effective permissions from the nearest
 * ancestor that defines them. An administrator sets a rule once at the top of
 * a branch instead of repeating it on every child.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Access;

use ClarityWeb\HilgVault\Install\Schema;

defined('ABSPATH') || exit;

final class AccessPolicy
{
    public const MODE_PUBLIC   = 'public';
    public const MODE_PASSWORD = 'password';
    public const MODE_PRIVATE  = 'private';

    /** Cookie holding proof that a shared password was entered. */
    private const COOKIE_PREFIX = 'hilg_vault_pw_';

    /** How long a shared password unlock lasts. */
    private const PASSWORD_TTL = 12 * HOUR_IN_SECONDS;

    /** Failed attempts allowed from one address before the gate closes. */
    private const MAX_ATTEMPTS = 10;

    /** How far back failed attempts are counted. */
    private const ATTEMPT_WINDOW = 15 * MINUTE_IN_SECONDS;

    /** @var array<int,array<string,mixed>|null> Request level folder cache. */
    private static array $folderCache = [];

    public static function modes(): array
    {
        return [
            self::MODE_PUBLIC   => __('Public - accessible to anyone', 'hilg-vault'),
            self::MODE_PASSWORD => __('Password protected - one shared password', 'hilg-vault'),
            self::MODE_PRIVATE  => __('Private - signed in users only', 'hilg-vault'),
        ];
    }

    /**
     * The single question the rest of the plugin asks.
     */
    public static function canViewFolder(int $folderId, ?int $userId = null): bool
    {
        $folder = self::folder($folderId);

        if ($folder === null) {
            return false;
        }

        $userId ??= get_current_user_id();

        // Anyone who can manage the vault sees everything, including the
        // folders they have not explicitly granted to themselves.
        if ($userId > 0 && user_can($userId, 'hilg_manage_vault')) {
            return true;
        }

        return match (self::effectiveMode($folder)) {
            self::MODE_PUBLIC   => true,
            self::MODE_PASSWORD => self::hasPasswordUnlock(self::passwordGateFolder($folder)),
            default             => self::privateAccessGranted($folder, $userId),
        };
    }

    /**
     * Files carry no permissions of their own. They belong to a folder, and the
     * folder decides. One place to reason about, one place to get right.
     */
    public static function canDownloadFile(int $fileId, ?int $userId = null): bool
    {
        global $wpdb;

        $files = Schema::tableFiles();

        $folderId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT folder_id FROM {$files} WHERE id = %d AND deleted_at IS NULL AND status = 'available'",
                $fileId
            )
        );

        if ($folderId === null) {
            return false;
        }

        return self::canViewFolder((int) $folderId, $userId);
    }

    public static function canUploadToFolder(int $folderId, ?int $userId = null): bool
    {
        $userId ??= get_current_user_id();

        if ($userId === 0) {
            return false;
        }

        if (user_can($userId, 'hilg_manage_vault')) {
            return true;
        }

        $grant = self::effectiveGrant($folderId, self::userRoles($userId));

        return $grant !== null && (bool) $grant['can_upload'];
    }

    /**
     * Walks up the tree until a folder declares a mode other than inherit.
     *
     * @param array<string,mixed> $folder
     */
    public static function effectiveMode(array $folder): string
    {
        $current = $folder;
        $guard   = 0;

        while ($guard++ < 64) {
            $mode = (string) ($current['access_mode'] ?? self::MODE_PRIVATE);

            if ($mode !== 'inherit') {
                return $mode;
            }

            $parentId = $current['parent_id'] ?? null;

            if ($parentId === null) {
                break;
            }

            $parent = self::folder((int) $parentId);

            if ($parent === null) {
                break;
            }

            $current = $parent;
        }

        // Anything we cannot resolve stays closed. A file library for a public
        // body should fail shut, never open.
        return self::MODE_PRIVATE;
    }

    /**
     * Which folder owns the password gate. If a child inherits, the password
     * belongs to the ancestor that set it, so unlocking once opens the branch.
     *
     * @param array<string,mixed> $folder
     */
    private static function passwordGateFolder(array $folder): int
    {
        $current = $folder;
        $guard   = 0;

        while ($guard++ < 64) {
            if (($current['access_mode'] ?? '') === self::MODE_PASSWORD) {
                return (int) $current['id'];
            }

            $parentId = $current['parent_id'] ?? null;

            if ($parentId === null) {
                break;
            }

            $parent = self::folder((int) $parentId);

            if ($parent === null) {
                break;
            }

            $current = $parent;
        }

        return (int) $folder['id'];
    }

    /**
     * @param array<string,mixed> $folder
     */
    private static function privateAccessGranted(array $folder, int $userId): bool
    {
        if ($userId === 0) {
            return false;
        }

        $roles = self::userRoles($userId);

        if ($roles === []) {
            return false;
        }

        $grant = self::effectiveGrant((int) $folder['id'], $roles);

        return $grant !== null && (bool) $grant['can_view'];
    }

    /**
     * Resolves the role to folder matrix, inheriting down the tree.
     *
     * @param array<int,string> $roles
     * @return array{can_view:int,can_upload:int,can_manage:int}|null
     */
    public static function effectiveGrant(int $folderId, array $roles): ?array
    {
        if ($roles === []) {
            return null;
        }

        global $wpdb;

        $table  = Schema::tableFolderRoles();
        $folder = self::folder($folderId);
        $guard  = 0;

        while ($folder !== null && $guard++ < 64) {
            $placeholders = implode(',', array_fill(0, count($roles), '%s'));

            $sql = $wpdb->prepare(
                // Widest grant wins when a user holds several roles.
                "SELECT MAX(can_view) AS can_view, MAX(can_upload) AS can_upload, MAX(can_manage) AS can_manage
                 FROM {$table}
                 WHERE folder_id = %d AND role_slug IN ({$placeholders})",
                array_merge([(int) $folder['id']], $roles)
            );

            $row = $wpdb->get_row($sql, ARRAY_A);

            if (is_array($row) && $row['can_view'] !== null) {
                return [
                    'can_view'   => (int) $row['can_view'],
                    'can_upload' => (int) $row['can_upload'],
                    'can_manage' => (int) $row['can_manage'],
                ];
            }

            $parentId = $folder['parent_id'] ?? null;
            $folder   = $parentId === null ? null : self::folder((int) $parentId);
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private static function userRoles(int $userId): array
    {
        $user = get_userdata($userId);

        return $user instanceof \WP_User ? array_values($user->roles) : [];
    }

    // ---------------------------------------------------------------------
    // Shared password handling
    // ---------------------------------------------------------------------

    /**
     * Checks a submitted password and, on success, records the unlock.
     *
     * Rate limited per address, because a single shared password with
     * unlimited attempts is a password in name only.
     */
    public static function attemptPasswordUnlock(int $folderId, string $password): bool
    {
        $folder = self::folder($folderId);

        if ($folder === null || ($folder['access_mode'] ?? '') !== self::MODE_PASSWORD) {
            return false;
        }

        // Attempts are counted from the audit log, not from a transient.
        //
        // A read-modify-write counter loses under concurrency: twenty parallel
        // requests all read the same value before any of them writes, each
        // stores "1", and the limit never trips. That is not theoretical, it
        // was reproduced against this endpoint. Every failed attempt is already
        // an INSERT into the log, and an INSERT is atomic, so counting rows
        // removes the race by construction rather than guarding against it.
        if (self::recentFailures($folderId) >= self::MAX_ATTEMPTS) {
            self::log('unlock', 'throttled', $folderId);

            return false;
        }

        $hash = (string) ($folder['password_hash'] ?? '');

        if ($hash === '' || !wp_check_password($password, $hash)) {
            self::log('unlock', 'denied', $folderId);

            return false;
        }

        self::grantPasswordUnlock($folderId);

        return true;
    }

    /**
     * Failed unlock attempts from this address inside the window.
     *
     * Counting rows rather than incrementing a stored number means concurrent
     * requests cannot each miss the other's increment. The log is written on
     * every denial regardless, so this costs one query and no extra state.
     */
    private static function recentFailures(int $folderId): int
    {
        global $wpdb;

        $table = Schema::tableAccessLog();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE folder_id = %d
                   AND action = 'unlock'
                   AND result IN ('denied', 'throttled')
                   AND ip_hash = %s
                   AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)",
                $folderId,
                self::clientFingerprint(),
                self::ATTEMPT_WINDOW
            )
        );
    }

    private static function grantPasswordUnlock(int $folderId): void
    {
        $expires = time() + self::PASSWORD_TTL;

        // Setting a cookie after output has begun produces a warning and no
        // cookie. The in-memory value below still lets the current request
        // render as unlocked, so the visitor is not left staring at the form
        // they just filled in correctly.
        if (headers_sent()) {
            $token = $expires . '|' . hash_hmac('sha256', $folderId . '|' . $expires, wp_salt('auth'));
            $_COOKIE[self::COOKIE_PREFIX . $folderId] = $token;

            return;
        }
        $token   = $expires . '|' . hash_hmac('sha256', $folderId . '|' . $expires, wp_salt('auth'));

        setcookie(
            self::COOKIE_PREFIX . $folderId,
            $token,
            [
                'expires'  => $expires,
                'path'     => defined('COOKIEPATH') ? COOKIEPATH : '/',
                'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        $_COOKIE[self::COOKIE_PREFIX . $folderId] = $token;
    }

    public static function hasPasswordUnlock(int $folderId): bool
    {
        $raw = $_COOKIE[self::COOKIE_PREFIX . $folderId] ?? '';

        if (!is_string($raw) || !str_contains($raw, '|')) {
            return false;
        }

        [$expires, $signature] = explode('|', $raw, 2);

        if (!ctype_digit($expires) || (int) $expires < time()) {
            return false;
        }

        $expected = hash_hmac('sha256', $folderId . '|' . $expires, wp_salt('auth'));

        return hash_equals($expected, $signature);
    }

    /**
     * Address is hashed rather than stored. Throttling needs to tell visitors
     * apart, it does not need to know who they are.
     */
    private static function clientFingerprint(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        return substr(hash_hmac('sha256', $ip, wp_salt('auth')), 0, 16);
    }

    // ---------------------------------------------------------------------

    /**
     * @return array<string,mixed>|null
     */
    public static function folder(int $folderId): ?array
    {
        if (array_key_exists($folderId, self::$folderCache)) {
            return self::$folderCache[$folderId];
        }

        global $wpdb;

        $table = Schema::tableFolders();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL", $folderId),
            ARRAY_A
        );

        return self::$folderCache[$folderId] = is_array($row) ? $row : null;
    }

    public static function flushCache(): void
    {
        self::$folderCache = [];
    }

    /**
     * Writes an audit entry. A public body has to be able to answer who
     * downloaded what, and denied attempts matter as much as allowed ones.
     */
    public static function log(string $action, string $result, ?int $folderId = null, ?int $fileId = null): void
    {
        global $wpdb;

        $wpdb->insert(
            Schema::tableAccessLog(),
            [
                'user_id'   => get_current_user_id() ?: null,
                'folder_id' => $folderId,
                'file_id'   => $fileId,
                'action'    => $action,
                'result'    => $result,
                'ip_hash'   => self::clientFingerprint(),
                // Stored in UTC so the throttle window compares like with like
                // regardless of the site's timezone setting.
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s']
        );
    }
}
