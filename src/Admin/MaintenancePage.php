<?php
/**
 * Maintenance screen: storage use, the bin, and the access log.
 *
 * Everything here was already implemented and unreachable. A deleted file could
 * be restored by the code but not by a person; the access log recorded every
 * download and could only be read with SQL; folder sizes were calculated and
 * never shown.
 *
 * For a public body the access log is not a nicety. "Who downloaded the board
 * papers, and when" is a question that gets asked, and answering it should not
 * require a developer.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Admin;

use ClarityWeb\HilgVault\Install\Schema;
use ClarityWeb\HilgVault\Maintenance\Housekeeping;
use ClarityWeb\HilgVault\Model\FileRepository;
use ClarityWeb\HilgVault\Model\FolderRepository;

defined('ABSPATH') || exit;

final class MaintenancePage
{
    public const SLUG = 'hilg-vault-maintenance';

    private const NONCE = 'hilg_vault_maintenance';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu'], 20);
        add_action('admin_post_hilg_vault_restore', [self::class, 'handleRestore']);
        add_action('admin_post_hilg_vault_housekeeping', [self::class, 'handleHousekeeping']);
    }

    public static function addMenu(): void
    {
        add_submenu_page(
            AdminPage::SLUG,
            __('Storage & History', 'hilg-vault'),
            __('Storage & History', 'hilg-vault'),
            'hilg_manage_vault',
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function handleRestore(): void
    {
        if (!current_user_can('hilg_manage_vault')) {
            wp_die(esc_html__('You do not have permission to restore files.', 'hilg-vault'));
        }

        check_admin_referer(self::NONCE);

        $fileId = isset($_POST['file_id']) ? absint(wp_unslash($_POST['file_id'])) : 0;

        if ($fileId > 0) {
            FileRepository::restore($fileId);
        }

        wp_safe_redirect(add_query_arg('restored', '1', menu_page_url(self::SLUG, false)));
        exit;
    }

    public static function handleHousekeeping(): void
    {
        if (!current_user_can('hilg_manage_vault')) {
            wp_die(esc_html__('You do not have permission to do that.', 'hilg-vault'));
        }

        check_admin_referer(self::NONCE);

        Housekeeping::run();

        wp_safe_redirect(add_query_arg('cleaned', '1', menu_page_url(self::SLUG, false)));
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can('hilg_manage_vault')) {
            wp_die(esc_html__('You do not have permission to open this page.', 'hilg-vault'));
        }

        ?>
        <div class="wrap hilg-maintenance">
            <h1><?php esc_html_e('Storage & History', 'hilg-vault'); ?></h1>

            <?php if (isset($_GET['restored'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('File restored.', 'hilg-vault'); ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['cleaned'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Housekeeping finished.', 'hilg-vault'); ?></p></div>
            <?php endif; ?>

            <?php
            self::renderStorage();
            self::renderBin();
            self::renderLog();
            self::renderHousekeeping();
            ?>
        </div>
        <?php
    }

    private static function renderStorage(): void
    {
        global $wpdb;

        $files = Schema::tableFiles();

        $total = (int) $wpdb->get_var(
            "SELECT COALESCE(SUM(size_bytes), 0) FROM {$files} WHERE deleted_at IS NULL AND status = 'available'"
        );

        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$files} WHERE deleted_at IS NULL AND status = 'available'"
        );

        // Space held by deleted files is shown separately, because it is still
        // being paid for until housekeeping reclaims it.
        $binned = (int) $wpdb->get_var(
            "SELECT COALESCE(SUM(size_bytes), 0) FROM {$files} WHERE deleted_at IS NOT NULL"
        );

        ?>
        <h2 class="title"><?php esc_html_e('Storage in use', 'hilg-vault'); ?></h2>

        <div class="hilg-stats">
            <div class="hilg-stat">
                <span class="hilg-stat__value"><?php echo esc_html(size_format($total, 1)); ?></span>
                <span class="hilg-stat__label"><?php esc_html_e('live files', 'hilg-vault'); ?></span>
            </div>
            <div class="hilg-stat">
                <span class="hilg-stat__value"><?php echo esc_html(number_format($count)); ?></span>
                <span class="hilg-stat__label"><?php esc_html_e('files', 'hilg-vault'); ?></span>
            </div>
            <div class="hilg-stat<?php echo $binned > 0 ? ' hilg-stat--pending' : ''; ?>">
                <span class="hilg-stat__value"><?php echo esc_html(size_format($binned, 1)); ?></span>
                <span class="hilg-stat__label"><?php esc_html_e('in the bin, still billed', 'hilg-vault'); ?></span>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Folder', 'hilg-vault'); ?></th>
                    <th scope="col" style="width:130px"><?php esc_html_e('Size', 'hilg-vault'); ?></th>
                    <th scope="col" style="width:110px"><?php esc_html_e('Files', 'hilg-vault'); ?></th>
                    <th scope="col" style="width:130px"><?php esc_html_e('Downloads', 'hilg-vault'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $folders = FolderRepository::children(null);

            if ($folders === []) {
                echo '<tr><td colspan="4">' . esc_html__('No folders yet.', 'hilg-vault') . '</td></tr>';
            }

            foreach ($folders as $folder) {
                $id = (int) $folder['id'];

                // Branch size counts everything below the folder, which is the
                // figure someone actually wants when deciding what to archive.
                $size = FolderRepository::branchSize($id);

                $stats = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT COUNT(*) AS files, COALESCE(SUM(download_count), 0) AS downloads
                         FROM {$files} f
                         WHERE f.deleted_at IS NULL AND f.status = 'available'
                           AND f.folder_id IN (
                               SELECT id FROM " . Schema::tableFolders() . "
                               WHERE id = %d OR path LIKE %s
                           )",
                        $id,
                        $wpdb->esc_like((string) $folder['path']) . '%'
                    ),
                    ARRAY_A
                );

                printf(
                    '<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    esc_html((string) $folder['name']),
                    esc_html(size_format($size, 1)),
                    esc_html(number_format((int) ($stats['files'] ?? 0))),
                    esc_html(number_format((int) ($stats['downloads'] ?? 0)))
                );
            }
            ?>
            </tbody>
        </table>
        <?php
    }

    private static function renderBin(): void
    {
        $binned = Housekeeping::binContents(50);

        ?>
        <h2 class="title"><?php esc_html_e('Recently deleted', 'hilg-vault'); ?></h2>
        <p class="description">
            <?php
            printf(
                /* translators: %d: number of days a deleted file is kept. */
                esc_html__('Deleted files are kept for %d days and can be put back. After that the file is removed from storage and the space is released.', 'hilg-vault'),
                Housekeeping::binDays()
            );
            ?>
        </p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('File', 'hilg-vault'); ?></th>
                    <th scope="col" style="width:170px"><?php esc_html_e('Was in', 'hilg-vault'); ?></th>
                    <th scope="col" style="width:110px"><?php esc_html_e('Size', 'hilg-vault'); ?></th>
                    <th scope="col" style="width:140px"><?php esc_html_e('Removed in', 'hilg-vault'); ?></th>
                    <th scope="col" style="width:120px"><span class="screen-reader-text"><?php esc_html_e('Actions', 'hilg-vault'); ?></span></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($binned === []) : ?>
                <tr><td colspan="5"><?php esc_html_e('Nothing deleted.', 'hilg-vault'); ?></td></tr>
            <?php else : ?>
                <?php foreach ($binned as $file) : ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) $file['name']); ?></strong></td>
                        <td><?php echo esc_html((string) ($file['folder_name'] ?? '—')); ?></td>
                        <td><?php echo esc_html(size_format((int) $file['size_bytes'], 1)); ?></td>
                        <td>
                            <?php
                            $daysLeft = (int) $file['days_left'];

                            echo $daysLeft > 0
                                ? esc_html(sprintf(
                                    /* translators: %d: days remaining before permanent deletion. */
                                    _n('%d day', '%d days', $daysLeft, 'hilg-vault'),
                                    $daysLeft
                                ))
                                : '<span class="hilg-due">' . esc_html__('next cleanup', 'hilg-vault') . '</span>';
                            ?>
                        </td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="hilg_vault_restore">
                                <input type="hidden" name="file_id" value="<?php echo (int) $file['id']; ?>">
                                <?php wp_nonce_field(self::NONCE); ?>
                                <button type="submit" class="button button-small">
                                    <?php esc_html_e('Put back', 'hilg-vault'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function renderLog(): void
    {
        global $wpdb;

        $log     = Schema::tableAccessLog();
        $files   = Schema::tableFiles();
        $folders = Schema::tableFolders();

        $rows = $wpdb->get_results(
            "SELECT l.created_at, l.action, l.result, l.user_id,
                    f.name AS file_name, d.name AS folder_name
             FROM {$log} l
             LEFT JOIN {$files} f ON f.id = l.file_id
             LEFT JOIN {$folders} d ON d.id = l.folder_id
             ORDER BY l.id DESC
             LIMIT 60",
            ARRAY_A
        );

        ?>
        <h2 class="title"><?php esc_html_e('Access history', 'hilg-vault'); ?></h2>
        <p class="description">
            <?php esc_html_e('Downloads, unlock attempts and refusals. Refused attempts are recorded too: knowing what was tried and blocked matters as much as knowing what succeeded.', 'hilg-vault'); ?>
        </p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width:170px"><?php esc_html_e('When', 'hilg-vault'); ?></th>
                    <th scope="col" style="width:130px"><?php esc_html_e('What', 'hilg-vault'); ?></th>
                    <th scope="col"><?php esc_html_e('Item', 'hilg-vault'); ?></th>
                    <th scope="col" style="width:150px"><?php esc_html_e('Who', 'hilg-vault'); ?></th>
                    <th scope="col" style="width:120px"><?php esc_html_e('Result', 'hilg-vault'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows) : ?>
                <tr><td colspan="5"><?php esc_html_e('Nothing recorded yet.', 'hilg-vault'); ?></td></tr>
            <?php else : ?>
                <?php foreach ($rows as $entry) : ?>
                    <tr>
                        <td>
                            <?php
                            $timestamp = strtotime((string) $entry['created_at'] . ' UTC');

                            echo esc_html($timestamp
                                ? wp_date(get_option('date_format') . ', ' . get_option('time_format'), $timestamp)
                                : (string) $entry['created_at']);
                            ?>
                        </td>
                        <td><?php echo esc_html(self::actionLabel((string) $entry['action'])); ?></td>
                        <td><?php echo esc_html((string) ($entry['file_name'] ?? $entry['folder_name'] ?? '—')); ?></td>
                        <td>
                            <?php
                            $userId = (int) $entry['user_id'];
                            $user   = $userId > 0 ? get_userdata($userId) : null;

                            echo esc_html($user instanceof \WP_User
                                ? $user->display_name
                                : __('not signed in', 'hilg-vault'));
                            ?>
                        </td>
                        <td>
                            <span class="hilg-result hilg-result--<?php echo esc_attr((string) $entry['result']); ?>">
                                <?php echo esc_html(self::resultLabel((string) $entry['result'])); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function renderHousekeeping(): void
    {
        $last = Housekeeping::lastRun();

        ?>
        <h2 class="title"><?php esc_html_e('Housekeeping', 'hilg-vault'); ?></h2>
        <p class="description">
            <?php esc_html_e('Runs daily. Removes files whose recovery window has passed, releasing the storage they hold, and abandons uploads that were never finished.', 'hilg-vault'); ?>
        </p>

        <?php if ($last !== null) : ?>
            <p>
                <?php
                $timestamp = isset($last['at']) ? strtotime((string) $last['at'] . ' UTC') : false;

                printf(
                    /* translators: 1: date, 2: files removed, 3: space released, 4: uploads abandoned. */
                    esc_html__('Last run %1$s: %2$d files removed, %3$s released, %4$d abandoned uploads cleared.', 'hilg-vault'),
                    esc_html($timestamp ? wp_date(get_option('date_format') . ', ' . get_option('time_format'), $timestamp) : '—'),
                    (int) ($last['reclaimed'] ?? 0),
                    esc_html(size_format((int) ($last['bytes'] ?? 0), 1)),
                    (int) ($last['aborted'] ?? 0)
                );
                ?>
            </p>

            <?php if ((int) ($last['failed'] ?? 0) > 0) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %d: number of objects that could not be removed. */
                            esc_html__('%d objects could not be removed from storage and will be retried on the next run.', 'hilg-vault'),
                            (int) $last['failed']
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>
        <?php else : ?>
            <p class="description"><?php esc_html_e('Has not run yet.', 'hilg-vault'); ?></p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="hilg_vault_housekeeping">
            <?php wp_nonce_field(self::NONCE); ?>
            <button type="submit" class="button"><?php esc_html_e('Run now', 'hilg-vault'); ?></button>
        </form>
        <?php
    }

    private static function actionLabel(string $action): string
    {
        return match ($action) {
            'download'   => __('Download', 'hilg-vault'),
            'upload'     => __('Upload', 'hilg-vault'),
            'unlock'     => __('Password entry', 'hilg-vault'),
            'delete'     => __('Delete', 'hilg-vault'),
            'list_files' => __('Open folder', 'hilg-vault'),
            default      => $action,
        };
    }

    private static function resultLabel(string $result): string
    {
        return match ($result) {
            'allowed'     => __('allowed', 'hilg-vault'),
            'denied'      => __('refused', 'hilg-vault'),
            'throttled'   => __('rate limited', 'hilg-vault'),
            'unavailable' => __('storage down', 'hilg-vault'),
            default       => $result,
        };
    }
}
