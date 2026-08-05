<?php
/**
 * Folder tree operations.
 *
 * The tree stores a materialised `path` on every row (for example
 * "/reports/2026/"). Writes pay a little on move, reads pay nothing: listing a
 * branch or resolving an ancestor is a single indexed query instead of a
 * recursive walk. Reads outnumber moves by orders of magnitude in a file
 * library, so the trade sits on the right side.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Model;

use ClarityWeb\HilgVault\Access\AccessPolicy;
use ClarityWeb\HilgVault\Install\Schema;

defined('ABSPATH') || exit;

final class FolderRepository
{
    private const MAX_DEPTH = 32;

    /**
     * @param array{
     *     name:string,
     *     parent_id?:int|null,
     *     access_mode?:string,
     *     password?:string|null,
     *     description?:string|null
     * } $data
     *
     * @return int|\WP_Error Folder id on success.
     */
    public static function create(array $data): int|\WP_Error
    {
        global $wpdb;

        $name = trim($data['name'] ?? '');

        if ($name === '') {
            return new \WP_Error('empty_name', __('A folder needs a name.', 'hilg-vault'));
        }

        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : 0;
        $parent   = $parentId > 0 ? AccessPolicy::folder($parentId) : null;

        if ($parentId > 0 && $parent === null) {
            return new \WP_Error('parent_missing', __('The parent folder no longer exists.', 'hilg-vault'));
        }

        $depth = $parent === null ? 0 : ((int) $parent['depth'] + 1);

        if ($depth > self::MAX_DEPTH) {
            return new \WP_Error(
                'too_deep',
                sprintf(
                    /* translators: %d: maximum folder nesting depth. */
                    __('Folders can only be nested %d levels deep.', 'hilg-vault'),
                    self::MAX_DEPTH
                )
            );
        }

        $mode = $data['access_mode'] ?? AccessPolicy::MODE_PRIVATE;

        if (!self::isValidMode($mode)) {
            return new \WP_Error('bad_mode', __('Unknown access mode.', 'hilg-vault'));
        }

        $password = $data['password'] ?? null;

        if ($mode === AccessPolicy::MODE_PASSWORD && ($password === null || $password === '')) {
            return new \WP_Error(
                'password_required',
                __('A password protected folder needs a password.', 'hilg-vault')
            );
        }

        $slug       = self::uniqueSlug($name, $parentId ?: null);
        $parentPath = $parent === null ? '/' : (string) $parent['path'];

        $inserted = $wpdb->insert(
            Schema::tableFolders(),
            [
                'parent_id'     => $parentId ?: null,
                'name'          => $name,
                'slug'          => $slug,
                'path'          => $parentPath . $slug . '/',
                'depth'         => $depth,
                'access_mode'   => $mode,
                'password_hash' => $mode === AccessPolicy::MODE_PASSWORD ? wp_hash_password((string) $password) : null,
                'description'   => $data['description'] ?? null,
                'created_by'    => get_current_user_id() ?: null,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d']
        );

        if ($inserted === false) {
            return new \WP_Error('db_error', __('The folder could not be saved.', 'hilg-vault'));
        }

        AccessPolicy::flushCache();

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(int $folderId, array $data): true|\WP_Error
    {
        global $wpdb;

        $folder = AccessPolicy::folder($folderId);

        if ($folder === null) {
            return new \WP_Error('not_found', __('Folder not found.', 'hilg-vault'));
        }

        $fields  = [];
        $formats = [];

        if (isset($data['name']) && trim((string) $data['name']) !== '') {
            $fields['name'] = trim((string) $data['name']);
            $formats[]      = '%s';
        }

        if (array_key_exists('description', $data)) {
            $fields['description'] = $data['description'];
            $formats[]             = '%s';
        }

        if (isset($data['access_mode'])) {
            $mode = (string) $data['access_mode'];

            if (!self::isValidMode($mode)) {
                return new \WP_Error('bad_mode', __('Unknown access mode.', 'hilg-vault'));
            }

            $fields['access_mode'] = $mode;
            $formats[]             = '%s';

            // Leaving password mode clears the stored hash. A dormant password
            // that silently comes back if the mode is switched again later is
            // the kind of surprise that turns into an access incident.
            if ($mode !== AccessPolicy::MODE_PASSWORD) {
                $fields['password_hash'] = null;
                $formats[]               = '%s';
            }
        }

        if (isset($data['password']) && $data['password'] !== '') {
            $fields['password_hash'] = wp_hash_password((string) $data['password']);
            $formats[]               = '%s';
        }

        if ($fields === []) {
            return true;
        }

        $wpdb->update(Schema::tableFolders(), $fields, ['id' => $folderId], $formats, ['%d']);

        AccessPolicy::flushCache();

        return true;
    }

    /**
     * Moves a folder and rewrites the materialised path of everything beneath it.
     */
    public static function move(int $folderId, ?int $newParentId): true|\WP_Error
    {
        global $wpdb;

        $folder = AccessPolicy::folder($folderId);

        if ($folder === null) {
            return new \WP_Error('not_found', __('Folder not found.', 'hilg-vault'));
        }

        $oldPath = (string) $folder['path'];

        $newParent = $newParentId ? AccessPolicy::folder($newParentId) : null;

        if ($newParentId && $newParent === null) {
            return new \WP_Error('parent_missing', __('The destination folder no longer exists.', 'hilg-vault'));
        }

        // A folder cannot be moved inside its own subtree. Without this check
        // the branch detaches from the tree and its contents become invisible.
        if ($newParent !== null && str_starts_with((string) $newParent['path'], $oldPath)) {
            return new \WP_Error('cycle', __('A folder cannot be moved into itself.', 'hilg-vault'));
        }

        $slug       = self::uniqueSlug((string) $folder['name'], $newParentId, $folderId);
        $parentPath = $newParent === null ? '/' : (string) $newParent['path'];
        $newPath    = $parentPath . $slug . '/';
        $newDepth   = $newParent === null ? 0 : ((int) $newParent['depth'] + 1);

        $table = Schema::tableFolders();

        $wpdb->update(
            $table,
            [
                'parent_id' => $newParentId ?: null,
                'slug'      => $slug,
                'path'      => $newPath,
                'depth'     => $newDepth,
            ],
            ['id' => $folderId],
            ['%d', '%s', '%s', '%d'],
            ['%d']
        );

        // Rewrite descendants in one statement rather than row by row.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET path = CONCAT(%s, SUBSTRING(path, %d)),
                     depth = depth + %d
                 WHERE path LIKE %s AND id <> %d",
                $newPath,
                strlen($oldPath) + 1,
                $newDepth - (int) $folder['depth'],
                $wpdb->esc_like($oldPath) . '%',
                $folderId
            )
        );

        AccessPolicy::flushCache();

        return true;
    }

    /**
     * Soft delete. The objects stay in the bucket until the folder is purged,
     * so an accidental delete is recoverable.
     */
    public static function delete(int $folderId): true|\WP_Error
    {
        global $wpdb;

        $folder = AccessPolicy::folder($folderId);

        if ($folder === null) {
            return new \WP_Error('not_found', __('Folder not found.', 'hilg-vault'));
        }

        $now     = current_time('mysql');
        $folders = Schema::tableFolders();
        $files   = Schema::tableFiles();
        $path    = $wpdb->esc_like((string) $folder['path']) . '%';

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$folders} SET deleted_at = %s WHERE (id = %d OR path LIKE %s) AND deleted_at IS NULL",
                $now,
                $folderId,
                $path
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$files} SET deleted_at = %s
                 WHERE deleted_at IS NULL
                   AND folder_id IN (SELECT id FROM {$folders} WHERE id = %d OR path LIKE %s)",
                $now,
                $folderId,
                $path
            )
        );

        AccessPolicy::flushCache();

        return true;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function children(?int $parentId): array
    {
        global $wpdb;

        $table = Schema::tableFolders();

        $rows = $parentId
            ? $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE parent_id = %d AND deleted_at IS NULL ORDER BY sort_order, name",
                    $parentId
                ),
                ARRAY_A
            )
            : $wpdb->get_results(
                "SELECT * FROM {$table} WHERE parent_id IS NULL AND deleted_at IS NULL ORDER BY sort_order, name",
                ARRAY_A
            );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Ancestor chain for breadcrumbs, resolved from the materialised path in
     * one query rather than one query per level.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public static function breadcrumbs(int $folderId): array
    {
        $folder = AccessPolicy::folder($folderId);

        if ($folder === null) {
            return [];
        }

        $slugs = array_values(array_filter(explode('/', (string) $folder['path'])));

        if ($slugs === []) {
            return [];
        }

        global $wpdb;

        $table        = Schema::tableFolders();
        $placeholders = implode(',', array_fill(0, count($slugs), '%s'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, depth FROM {$table}
                 WHERE slug IN ({$placeholders}) AND deleted_at IS NULL
                 ORDER BY depth",
                $slugs
            ),
            ARRAY_A
        );

        $trail = [];

        foreach ((array) $rows as $row) {
            $trail[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return $trail;
    }

    /**
     * Total size of a branch, for the storage report an administrator needs
     * when the library is measured in terabytes.
     */
    public static function branchSize(int $folderId): int
    {
        global $wpdb;

        $folder = AccessPolicy::folder($folderId);

        if ($folder === null) {
            return 0;
        }

        $folders = Schema::tableFolders();
        $files   = Schema::tableFiles();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(f.size_bytes), 0) FROM {$files} f
                 WHERE f.deleted_at IS NULL
                   AND f.status = 'available'
                   AND f.folder_id IN (SELECT id FROM {$folders} WHERE id = %d OR path LIKE %s)",
                $folderId,
                $wpdb->esc_like((string) $folder['path']) . '%'
            )
        );
    }

    private static function isValidMode(string $mode): bool
    {
        return in_array(
            $mode,
            [AccessPolicy::MODE_PUBLIC, AccessPolicy::MODE_PASSWORD, AccessPolicy::MODE_PRIVATE, 'inherit'],
            true
        );
    }

    /**
     * Slugs are unique among siblings, so a path identifies exactly one folder.
     */
    private static function uniqueSlug(string $name, ?int $parentId, ?int $ignoreId = null): string
    {
        global $wpdb;

        $base = sanitize_title($name);

        if ($base === '') {
            $base = 'folder';
        }

        $table    = Schema::tableFolders();
        $slug     = $base;
        $attempt  = 1;

        while ($attempt < 200) {
            $sql = $parentId
                ? $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE parent_id = %d AND slug = %s AND id <> %d",
                    $parentId,
                    $slug,
                    (int) $ignoreId
                )
                : $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE parent_id IS NULL AND slug = %s AND id <> %d",
                    $slug,
                    (int) $ignoreId
                );

            if ($wpdb->get_var($sql) === null) {
                return $slug;
            }

            $slug = $base . '-' . (++$attempt);
        }

        return $base . '-' . wp_generate_password(6, false, false);
    }
}
