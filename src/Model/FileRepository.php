<?php
/**
 * File records.
 *
 * A file exists in two stages. It is registered as `pending` before the browser
 * starts uploading, and promoted to `available` only after the bucket confirms
 * the object is really there and reports its size. Nothing that failed halfway
 * ever appears in a listing, and the recorded size is the bucket's answer
 * rather than the browser's claim.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Model;

use ClarityWeb\HilgVault\Install\Schema;

defined('ABSPATH') || exit;

final class FileRepository
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_FAILED    = 'failed';

    /**
     * Extensions that are never accepted, whatever the upload claims to be.
     * A file library for a public body has no reason to host executable code.
     */
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar',
        'exe', 'com', 'bat', 'cmd', 'msi', 'scr', 'cpl', 'jar',
        'sh', 'bash', 'zsh', 'ps1', 'psm1', 'vbs', 'js', 'jse', 'wsf', 'hta',
        'htaccess', 'htpasswd',
    ];

    /**
     * Registers an intent to upload and returns the row plus the object key
     * the browser should write to.
     *
     * @return array{id:int,object_key:string}|\WP_Error
     */
    public static function registerPending(
        int $folderId,
        string $filename,
        int $sizeBytes = 0,
        ?string $mimeType = null,
    ): array|\WP_Error {
        global $wpdb;

        $filename = self::sanitiseFilename($filename);

        if ($filename === '') {
            return new \WP_Error('bad_filename', __('That file name cannot be used.', 'hilg-vault'));
        }

        $blocked = self::blockedExtension($filename);

        if ($blocked !== null) {
            return new \WP_Error(
                'blocked_type',
                sprintf(
                    /* translators: %s: file extension. */
                    __('Files of type .%s cannot be uploaded.', 'hilg-vault'),
                    $blocked
                )
            );
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        $maxBytes = self::maxUploadBytes();

        if ($maxBytes > 0 && $sizeBytes > $maxBytes) {
            return new \WP_Error(
                'too_large',
                sprintf(
                    /* translators: %s: human readable size limit. */
                    __('That file is larger than the %s limit.', 'hilg-vault'),
                    size_format($maxBytes)
                )
            );
        }

        $objectKey = self::buildObjectKey($folderId, $extension);

        $inserted = $wpdb->insert(
            Schema::tableFiles(),
            [
                'folder_id'   => $folderId,
                'name'        => $filename,
                'object_key'  => $objectKey,
                'size_bytes'  => max(0, $sizeBytes),
                'mime_type'   => $mimeType,
                'extension'   => $extension !== '' ? $extension : null,
                'status'      => self::STATUS_PENDING,
                'uploaded_by' => get_current_user_id() ?: null,
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d']
        );

        if ($inserted === false) {
            return new \WP_Error('db_error', __('The file could not be registered.', 'hilg-vault'));
        }

        return ['id' => (int) $wpdb->insert_id, 'object_key' => $objectKey];
    }

    public static function attachUploadId(int $fileId, string $uploadId): void
    {
        global $wpdb;

        $wpdb->update(
            Schema::tableFiles(),
            ['upload_id' => $uploadId],
            ['id' => $fileId],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Promotes a pending row once the object is confirmed in the bucket.
     */
    public static function markAvailable(int $fileId, int $sizeBytes, ?string $checksum = null): void
    {
        global $wpdb;

        $wpdb->update(
            Schema::tableFiles(),
            [
                'status'     => self::STATUS_AVAILABLE,
                'size_bytes' => $sizeBytes,
                'checksum'   => $checksum,
                'upload_id'  => null,
            ],
            ['id' => $fileId],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );
    }

    public static function markFailed(int $fileId): void
    {
        global $wpdb;

        $wpdb->update(
            Schema::tableFiles(),
            ['status' => self::STATUS_FAILED],
            ['id' => $fileId],
            ['%s'],
            ['%d']
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $fileId): ?array
    {
        global $wpdb;

        $table = Schema::tableFiles();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL", $fileId),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * How many files one page of a listing holds.
     *
     * Without a limit, a folder of a few thousand documents renders as a single
     * page of well over a megabyte. It survives, but it is slow on a phone,
     * expensive on a metered connection, and unusable with a screen reader that
     * has to walk every row to reach the end.
     */
    public const PER_PAGE = 60;

    /**
     * Counts files in a folder, for pagination.
     */
    public static function countByFolder(int $folderId, string $search = ''): int
    {
        global $wpdb;

        $table = self::tableName();

        if ($search !== '') {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE folder_id = %d AND deleted_at IS NULL AND status = %s AND name LIKE %s",
                    $folderId,
                    self::STATUS_AVAILABLE,
                    '%' . $wpdb->esc_like($search) . '%'
                )
            );
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE folder_id = %d AND deleted_at IS NULL AND status = %s",
                $folderId,
                self::STATUS_AVAILABLE
            )
        );
    }

    /**
     * Counts every available file, regardless of folder.
     *
     * Deliberately separate from countByFolder rather than a zero meaning
     * "everywhere": folder zero is reachable on the public listing route, and a
     * magic value there would turn "show me folder 0" into "show me the entire
     * library" for anyone who typed it.
     */
    public static function countAll(string $search = ''): int
    {
        global $wpdb;

        $table = self::tableName();

        if ($search !== '') {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE deleted_at IS NULL AND status = %s AND name LIKE %s",
                    self::STATUS_AVAILABLE,
                    '%' . $wpdb->esc_like($search) . '%'
                )
            );
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NULL AND status = %s",
                self::STATUS_AVAILABLE
            )
        );
    }

    /**
     * Every available file across the whole library, newest first, paginated.
     *
     * Used only by the management screen, which is why it carries folder_id:
     * a listing that spans folders is useless without saying which folder each
     * row came from.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listAll(
        string $search = '',
        int $page = 1,
        int $perPage = self::PER_PAGE,
        string $orderBy = 'newest',
    ): array {
        global $wpdb;

        $table = self::tableName();
        $order = self::orderClause($orderBy);

        $perPage = $perPage <= 0 ? self::PER_PAGE : min(max($perPage, 1), 500);
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        if ($search !== '') {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE deleted_at IS NULL AND status = %s AND name LIKE %s
                     ORDER BY {$order} LIMIT %d OFFSET %d",
                    self::STATUS_AVAILABLE,
                    '%' . $wpdb->esc_like($search) . '%',
                    $perPage,
                    $offset
                ),
                ARRAY_A
            ) ?: [];
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE deleted_at IS NULL AND status = %s
                 ORDER BY {$order} LIMIT %d OFFSET %d",
                self::STATUS_AVAILABLE,
                $perPage,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Turns a requested ordering into SQL.
     *
     * A whitelist, not an escape. The value reaches SQL unquoted because
     * ORDER BY cannot be parameterised, so the only safe form is one where
     * nothing from the request is ever interpolated: an unknown key falls back
     * to a known clause rather than being cleaned up and used.
     */
    private static function orderClause(string $orderBy, string $fallback = 'newest'): string
    {
        $allowed = [
            'name'   => 'name ASC',
            'size'   => 'size_bytes DESC',
            'newest' => 'created_at DESC',
            'oldest' => 'created_at ASC',
            'type'   => 'extension ASC, name ASC',
        ];

        return $allowed[$orderBy] ?? $allowed[$fallback];
    }

    private static function tableName(): string
    {
        return Schema::tableFiles();
    }

    /**
     * @param int $perPage Zero means every file, used by exports and reports.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listByFolder(
        int $folderId,
        string $search = '',
        string $orderBy = 'name',
        int $page = 1,
        int $perPage = self::PER_PAGE,
    ): array {
        global $wpdb;

        $table = Schema::tableFiles();

        $order = self::orderClause($orderBy, 'name');

        // A page size of zero means "everything", which exports and reports
        // need. Anything else is clamped so a crafted request cannot ask the
        // database for a million rows.
        $perPage = $perPage <= 0 ? 0 : min(max($perPage, 1), 500);
        $page    = max(1, $page);
        $offset  = $perPage > 0 ? ($page - 1) * $perPage : 0;

        $limitClause = $perPage > 0 ? $wpdb->prepare('LIMIT %d OFFSET %d', $perPage, $offset) : '';

        if ($search !== '') {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, name, size_bytes, mime_type, extension, download_count, created_at
                     FROM {$table}
                     WHERE folder_id = %d AND deleted_at IS NULL AND status = %s AND name LIKE %s
                     ORDER BY {$order} {$limitClause}",
                    $folderId,
                    self::STATUS_AVAILABLE,
                    '%' . $wpdb->esc_like($search) . '%'
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, name, size_bytes, mime_type, extension, download_count, created_at
                     FROM {$table}
                     WHERE folder_id = %d AND deleted_at IS NULL AND status = %s
                     ORDER BY {$order} {$limitClause}",
                    $folderId,
                    self::STATUS_AVAILABLE
                ),
                ARRAY_A
            );
        }

        return is_array($rows) ? $rows : [];
    }

    public static function rename(int $fileId, string $newName): true|\WP_Error
    {
        $newName = self::sanitiseFilename($newName);

        if ($newName === '') {
            return new \WP_Error('bad_filename', __('That file name cannot be used.', 'hilg-vault'));
        }

        // Renaming is checked against the same block list as uploading.
        //
        // It was not, which left the block list decorative: upload a harmless
        // report.txt, rename it to report.php, and the name a browser is handed
        // at download time ends in .php. Nothing here executes it, but the
        // download sets Content-Disposition from this name, and whatever the
        // visitor saves is now named like something their own machine might
        // run. A gate that only guards the front door is not a gate.
        $blocked = self::blockedExtension($newName);

        if ($blocked !== null) {
            return new \WP_Error(
                'blocked_type',
                sprintf(
                    /* translators: %s: file extension. */
                    __('Files cannot be renamed to .%s.', 'hilg-vault'),
                    $blocked
                )
            );
        }

        global $wpdb;

        // The extension column travels with the name. It drives the type badge
        // and the "by type" sort, so leaving it behind makes a renamed file
        // display and sort as whatever it used to be.
        $extension = strtolower((string) pathinfo($newName, PATHINFO_EXTENSION));

        // Only the database row changes. The object key stays fixed, so
        // renaming a ten gigabyte file costs one UPDATE instead of a copy.
        $wpdb->update(
            Schema::tableFiles(),
            [
                'name'      => $newName,
                'extension' => $extension !== '' ? $extension : null,
            ],
            ['id' => $fileId],
            ['%s', '%s'],
            ['%d']
        );

        return true;
    }

    /**
     * The first blocked extension a name carries, or null when it is clean.
     */
    private static function blockedExtension(string $filename): ?string
    {
        foreach (self::extensionCandidates($filename) as $candidate) {
            if (in_array($candidate, self::BLOCKED_EXTENSIONS, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Moves a file to another folder.
     *
     * The stored object is not touched. Because the key is random rather than
     * derived from the folder or the name, a move is a metadata change, and a
     * ten gigabyte file moves as fast as a one kilobyte one.
     */
    public static function moveToFolder(int $fileId, int $folderId): true|\WP_Error
    {
        global $wpdb;

        $updated = $wpdb->update(
            Schema::tableFiles(),
            ['folder_id' => $folderId],
            ['id' => $fileId],
            ['%d'],
            ['%d']
        );

        if ($updated === false) {
            return new \WP_Error('db_error', __('The file could not be moved.', 'hilg-vault'));
        }

        return true;
    }

    public static function softDelete(int $fileId): void
    {
        global $wpdb;

        // UTC, like every other timestamp this plugin writes.
        //
        // current_time('mysql') returns the site's local time, while the bin
        // window is measured with UTC_TIMESTAMP. On a site set to Dublin that
        // is an hour of drift in summer: a file deleted at 00:30 on the last
        // day would be purged an hour early, or kept an hour late, depending
        // which way the offset ran. Small, silent, and impossible to explain
        // to whoever lost the file.
        $wpdb->update(
            Schema::tableFiles(),
            ['deleted_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $fileId],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Puts a file back, and its folder with it when the folder went too.
     *
     * Restoring the row alone produced a file nobody could reach: it was live,
     * but its folder was still in the bin, so it appeared in no listing and no
     * search. Present in the database, absent from the product. Deleting a
     * folder takes its files down with it, so putting one back has to be able
     * to bring the folder back up.
     */
    public static function restore(int $fileId): void
    {
        global $wpdb;

        $files   = Schema::tableFiles();
        $folders = Schema::tableFolders();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT folder_id FROM {$files} WHERE id = %d", $fileId),
            ARRAY_A
        );

        if (is_array($row)) {
            // The whole chain up to the root, because restoring a folder whose
            // own parent is still deleted moves the problem rather than fixing
            // it.
            $folderId = (int) $row['folder_id'];
            $guard    = 0;

            while ($folderId > 0 && $guard++ < 64) {
                $folder = $wpdb->get_row(
                    $wpdb->prepare("SELECT id, parent_id, deleted_at FROM {$folders} WHERE id = %d", $folderId),
                    ARRAY_A
                );

                if (!is_array($folder)) {
                    break;
                }

                if ($folder['deleted_at'] !== null) {
                    $wpdb->update($folders, ['deleted_at' => null], ['id' => $folderId], ['%s'], ['%d']);
                }

                $folderId = $folder['parent_id'] === null ? 0 : (int) $folder['parent_id'];
            }
        }

        $wpdb->update(
            $files,
            ['deleted_at' => null],
            ['id' => $fileId],
            ['%s'],
            ['%d']
        );

        \ClarityWeb\HilgVault\Access\AccessPolicy::flushCache();
    }

    /**
     * Pending rows older than a day are abandoned uploads. Cleaning them keeps
     * the table honest and lets the bucket lifecycle rule reclaim the parts.
     *
     * @return int Number of rows cleaned.
     */
    public static function purgeStalePending(int $olderThanHours = 24): int
    {
        global $wpdb;

        $table = Schema::tableFiles();

        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = %s
                 WHERE status = %s AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)",
                self::STATUS_FAILED,
                self::STATUS_PENDING,
                $olderThanHours
            )
        );
    }

    /**
     * Object keys are random and permanent. They never contain the display
     * name, which removes path traversal, name collisions, and the need to
     * copy an object when a file is renamed.
     */
    private static function buildObjectKey(int $folderId, string $extension): string
    {
        $random = bin2hex(random_bytes(16));
        $suffix = $extension !== '' ? '.' . $extension : '';

        return sprintf('folders/%d/%s/%s%s', $folderId, gmdate('Y/m'), $random, $suffix);
    }

    /**
     * Cleans a display name without mangling it.
     *
     * sanitize_file_name() is deliberately not used here. It exists to make a
     * name safe as a path on disk, which is why it turns spaces into hyphens.
     * Nothing in this plugin uses the name as a path: the object key is random
     * and the name is only ever rendered as text. So "Annual Report 2026.pdf"
     * stays exactly that, and the parts that could actually cause harm, path
     * separators and control characters, are removed.
     */
    private static function sanitiseFilename(string $filename): string
    {
        $filename = trim($filename);

        // Separators are replaced, not cut at.
        //
        // Cutting everything before the last slash defeats path traversal, but
        // it also silently eats part of a legitimate name: "Q1/Q2
        // comparison.pdf" would arrive as "Q2 comparison.pdf" and the user
        // would never know. Replacing keeps the whole name visible while making
        // it impossible for the name to describe a path. Nothing here is used
        // as a path anyway, since the storage key is random.
        $filename = str_replace(['\\', '/'], '-', $filename);

        // Control characters, including newlines that would break a header.
        $filename = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $filename);

        // Zero width characters are invisible and are used to make two
        // different names look identical in a listing.
        $filename = (string) preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $filename);

        // Collapse runs of whitespace so the name stays on one visual line.
        $filename = (string) preg_replace('/\s+/u', ' ', $filename);

        return trim(mb_substr($filename, 0, 200));
    }

    /**
     * Extracts the extension for the block list check.
     *
     * pathinfo() is not enough on its own. A dotfile such as ".htaccess" has
     * its whole name treated as an extension by some inputs and as none by
     * others depending on what stripped the leading dot first, which is exactly
     * how a blocked file slips through. Here the leading dots are considered
     * part of the name, and the check looks at both the trailing extension and
     * the bare dotfile name.
     *
     * @return array<int,string> Every candidate that must be checked.
     */
    private static function extensionCandidates(string $filename): array
    {
        $candidates = [];

        $trimmed = ltrim($filename, '.');

        // ".htaccess" -> "htaccess" as a whole-name candidate.
        if ($trimmed !== $filename && !str_contains($trimmed, '.')) {
            $candidates[] = strtolower($trimmed);
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        if ($extension !== '') {
            $candidates[] = $extension;
        }

        return array_unique($candidates);
    }

    /**
     * Zero means no ceiling. The default is deliberately generous because the
     * upload does not pass through PHP, so the usual server limits do not apply.
     *
     * Worth being precise about what this does and does not do. The browser
     * declares a size, we check it here, and the presigned PUT that follows is
     * not itself size limited: a client that lied could send more. Enforcing it
     * at the bucket needs a presigned POST policy with content-length-range,
     * which every S3 provider supports but which changes the upload handshake.
     * Since completion verifies the real size against the bucket before a file
     * is ever listed, an oversized upload is recorded as failed rather than
     * accepted, so the gap is billing rather than access. Documented here
     * rather than left for someone to discover.
     */
    public static function maxUploadBytes(): int
    {
        $configured = (int) get_option('hilg_vault_max_upload_bytes', 0);

        return (int) apply_filters('hilg_vault_max_upload_bytes', $configured);
    }
}
