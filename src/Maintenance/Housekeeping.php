<?php
/**
 * Scheduled housekeeping.
 *
 * Two jobs that nothing else was doing, both of which cost real money on a
 * library measured in terabytes.
 *
 * Storage was never reclaimed. Deleting a file removed the database row and
 * left the object in the bucket forever, so the interface said "deleted" while
 * the bill kept growing. Objects are now removed once the record has been in
 * the bin long enough to be past recovery.
 *
 * Abandoned uploads accumulated. A browser that closes mid-upload leaves a
 * pending row and, for large files, an unfinished multipart upload that most
 * providers charge for until it is aborted.
 *
 * Both run on a schedule rather than during a request: an editor deleting a
 * file should not wait on a storage round trip, and a failure here must not
 * surface as a failure to delete.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Maintenance;

use ClarityWeb\HilgVault\Install\Schema;
use ClarityWeb\HilgVault\Model\FileRepository;
use ClarityWeb\HilgVault\Plugin;

defined('ABSPATH') || exit;

final class Housekeeping
{
    public const CRON_HOOK = 'hilg_vault_housekeeping';

    /**
     * How long a deleted file stays recoverable.
     *
     * Long enough that "I deleted the wrong folder" is fixable on Monday after
     * a Friday mistake, short enough that storage is not paid for indefinitely.
     */
    private const BIN_DAYS = 30;

    /** Pending uploads older than this are abandoned. */
    private const STALE_UPLOAD_HOURS = 24;

    /** Objects removed per run, so a large cleanup cannot stall the schedule. */
    private const BATCH = 200;

    public static function register(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'run']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);

        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * @return array{purged:int,reclaimed:int,aborted:int,failed:int,bytes:int}
     */
    public static function run(): array
    {
        $result = [
            'purged'    => FileRepository::purgeStalePending(self::STALE_UPLOAD_HOURS),
            'reclaimed' => 0,
            'aborted'   => 0,
            'failed'    => 0,
            'bytes'     => 0,
        ];

        $storage = Plugin::instance()->storage();

        if ($storage === null) {
            // Nothing to reclaim without storage, and nothing to report as
            // broken either: this is a legitimate configuration.
            update_option('hilg_vault_last_housekeeping', $result + ['at' => current_time('mysql', true)], false);

            return $result;
        }

        global $wpdb;

        $table = Schema::tableFiles();

        // Files whose deletion is past the recovery window.
        $expired = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, object_key, size_bytes FROM {$table}
                 WHERE deleted_at IS NOT NULL
                   AND deleted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
                 LIMIT %d",
                self::BIN_DAYS,
                self::BATCH
            ),
            ARRAY_A
        );

        foreach ((array) $expired as $file) {
            if ($storage->deleteObject((string) $file['object_key'])) {
                // The row goes only after the object is gone. If it went first
                // and the delete failed, the object would be orphaned with
                // nothing left pointing at it.
                $wpdb->delete($table, ['id' => (int) $file['id']], ['%d']);

                $result['reclaimed']++;
                $result['bytes'] += (int) $file['size_bytes'];

                continue;
            }

            $result['failed']++;
        }

        // Multipart uploads that were started and never finished. Providers
        // charge for the stored parts until the upload is aborted.
        $abandoned = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, object_key, upload_id FROM {$table}
                 WHERE upload_id IS NOT NULL
                   AND status <> %s
                   AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
                 LIMIT %d",
                FileRepository::STATUS_AVAILABLE,
                self::STALE_UPLOAD_HOURS,
                self::BATCH
            ),
            ARRAY_A
        );

        foreach ((array) $abandoned as $upload) {
            $storage->abortMultipartUpload((string) $upload['object_key'], (string) $upload['upload_id']);

            $wpdb->update(
                $table,
                ['upload_id' => null, 'status' => FileRepository::STATUS_FAILED],
                ['id' => (int) $upload['id']],
                ['%s', '%s'],
                ['%d']
            );

            $result['aborted']++;
        }

        update_option('hilg_vault_last_housekeeping', $result + ['at' => current_time('mysql', true)], false);

        return $result;
    }

    /**
     * What the last run did, for the admin screen.
     *
     * @return array<string,mixed>|null
     */
    public static function lastRun(): ?array
    {
        $stored = get_option('hilg_vault_last_housekeeping', null);

        return is_array($stored) ? $stored : null;
    }

    /**
     * Files currently in the bin, with how long they have left.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function binContents(int $limit = 100): array
    {
        global $wpdb;

        $files   = Schema::tableFiles();
        $folders = Schema::tableFolders();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT f.id, f.name, f.size_bytes, f.deleted_at, f.folder_id,
                        d.name AS folder_name,
                        DATEDIFF(DATE_ADD(f.deleted_at, INTERVAL %d DAY), UTC_TIMESTAMP()) AS days_left
                 FROM {$files} f
                 LEFT JOIN {$folders} d ON d.id = f.folder_id
                 WHERE f.deleted_at IS NOT NULL
                 ORDER BY f.deleted_at DESC
                 LIMIT %d",
                self::BIN_DAYS,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public static function binDays(): int
    {
        return self::BIN_DAYS;
    }
}
