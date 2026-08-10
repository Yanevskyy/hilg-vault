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
        add_action('admin_post_hilg_vault_export_log', [self::class, 'handleExportLog']);
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

    /**
     * Streams the access log as CSV.
     *
     * "Who downloaded the board papers" is a question a public body has to be
     * able to answer in writing, to someone who is not going to log in and
     * look at a screen. Reading sixty rows off a page and retyping them is how
     * that answer gets given wrongly.
     *
     * Written straight to output in chunks rather than assembled in memory,
     * because a two year log is hundreds of thousands of rows.
     */
    public static function handleExportLog(): void
    {
        if (!current_user_can('hilg_manage_vault')) {
            wp_die(esc_html__('You do not have permission to export the log.', 'hilg-vault'));
        }

        check_admin_referer(self::NONCE);

        global $wpdb;

        $log     = Schema::tableAccessLog();
        $files   = Schema::tableFiles();
        $folders = Schema::tableFolders();

        $filters = self::logFilters();
        $where   = self::logWhere($filters);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="access-log-' . gmdate('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');

        // Byte order mark: without it Excel opens a UTF-8 CSV as Windows-1252
        // and every accented name arrives mangled. The people receiving this
        // file open it in Excel.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, ['When (UTC)', 'Action', 'Result', 'Item', 'Folder', 'User', 'Visitor']);

        $offset = 0;
        $chunk  = 1000;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT l.created_at, l.action, l.result, l.user_id, l.ip_hash,
                            f.name AS file_name, d.name AS folder_name
                     FROM {$log} l
                     LEFT JOIN {$files} f ON f.id = l.file_id
                     LEFT JOIN {$folders} d ON d.id = l.folder_id
                     {$where}
                     ORDER BY l.id DESC
                     LIMIT %d OFFSET %d",
                    $chunk,
                    $offset
                ),
                ARRAY_A
            );

            foreach ((array) $rows as $row) {
                $userId = (int) $row['user_id'];
                $user   = $userId > 0 ? get_userdata($userId) : null;

                fputcsv($out, [
                    (string) $row['created_at'],
                    self::actionLabel((string) $row['action']),
                    self::resultLabel((string) $row['result']),
                    (string) ($row['file_name'] ?? ''),
                    (string) ($row['folder_name'] ?? ''),
                    $user instanceof \WP_User ? $user->user_login : '',
                    // The address itself is never stored, only a keyed hash,
                    // so this identifies a visitor across entries without
                    // being personal data anyone can reverse.
                    (string) $row['ip_hash'],
                ]);
            }

            $offset += $chunk;
        } while (count((array) $rows) === $chunk);

        fclose($out);
        exit;
    }

    /**
     * @return array{action:string,result:string,days:int}
     */
    private static function logFilters(): array
    {
        // Read from either method on purpose. The screen filters with GET,
        // because a filtered view should be a link somebody can bookmark or
        // send on. The export posts, because it carries a nonce. Both reach
        // the same reader so the file matches what is on screen.
        // phpcs:disable WordPress.Security.NonceVerification -- read-only filters; the export verifies its own nonce.
        $source = $_POST !== [] ? $_POST : $_GET;

        return [
            'action' => isset($source['log_action']) ? sanitize_key(wp_unslash($source['log_action'])) : '',
            'result' => isset($source['log_result']) ? sanitize_key(wp_unslash($source['log_result'])) : '',
            'days'   => isset($source['log_days']) ? absint(wp_unslash($source['log_days'])) : 0,
        ];
        // phpcs:enable
    }

    /**
     * @param array{action:string,result:string,days:int} $filters
     */
    private static function logWhere(array $filters): string
    {
        global $wpdb;

        $clauses = [];

        // Compared against the known sets rather than escaped into the query,
        // so nothing from the request is ever interpolated.
        if (in_array($filters['action'], ['download', 'upload', 'unlock', 'delete', 'list_files'], true)) {
            $clauses[] = $wpdb->prepare('l.action = %s', $filters['action']);
        }

        if (in_array($filters['result'], ['allowed', 'denied', 'throttled', 'unavailable'], true)) {
            $clauses[] = $wpdb->prepare('l.result = %s', $filters['result']);
        }

        if ($filters['days'] > 0) {
            $clauses[] = $wpdb->prepare(
                'l.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)',
                min($filters['days'], 3650)
            );
        }

        return $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
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

        <?php self::renderStorageChart(); ?>

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

    /**
     * Storage over time, drawn as inline SVG.
     *
     * No charting library, for the same reason as everywhere else here: one
     * polyline over ninety points is not worth a dependency to keep patched
     * for eighteen months, or a script exception on a site with a strict
     * content policy. The figures sit underneath in a table, so the chart is
     * decoration and the numbers are the record.
     */
    private static function renderStorageChart(): void
    {
        $history = Housekeeping::storageHistory(90);

        if (count($history) < 2) {
            printf(
                '<p class="description">%s</p>',
                esc_html__('A storage reading is taken each day the cleanup runs. The chart appears once there are two.', 'hilg-vault')
            );

            return;
        }

        $values = array_map(static fn(array $day): int => (int) $day['live'], array_values($history));
        $peak   = max($values) ?: 1;
        $count  = count($values);

        $width  = 640;
        $height = 120;
        $step   = $count > 1 ? $width / ($count - 1) : $width;

        $points = [];

        foreach ($values as $index => $value) {
            $points[] = round($index * $step, 1) . ',' . round($height - (($value / $peak) * ($height - 10)) - 5, 1);
        }

        $first = (int) reset($values);
        $last  = (int) end($values);
        $change = $last - $first;

        printf('<h3>%s</h3>', esc_html__('Storage over time', 'hilg-vault'));

        printf(
            '<p class="description">%s</p>',
            esc_html(sprintf(
                $change >= 0
                    /* translators: 1: amount of growth, 2: first date, 3: number of days. */
                    ? __('%1$s more than on %2$s, over %3$d recorded days.', 'hilg-vault')
                    /* translators: 1: amount released, 2: first date, 3: number of days. */
                    : __('%1$s less than on %2$s, over %3$d recorded days.', 'hilg-vault'),
                size_format(abs($change), 1),
                (string) array_key_first($history),
                $count
            ))
        );

        printf(
            '<svg class="hilg-chart" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s" preserveAspectRatio="none">'
                . '<polyline fill="none" stroke="#2271b1" stroke-width="2" points="%4$s" /></svg>',
            $width,
            $height,
            esc_attr(sprintf(
                /* translators: 1: earliest size, 2: latest size, 3: number of days. */
                __('Storage went from %1$s to %2$s over %3$d recorded days.', 'hilg-vault'),
                size_format($first, 1),
                size_format($last, 1),
                $count
            )),
            esc_attr(implode(' ', $points))
        );

        echo '<details class="hilg-chart-data"><summary>'
            . esc_html__('Show the figures behind this chart', 'hilg-vault')
            . '</summary><table class="wp-list-table widefat striped"><thead><tr>'
            . '<th scope="col">' . esc_html__('Date', 'hilg-vault') . '</th>'
            . '<th scope="col">' . esc_html__('Live files', 'hilg-vault') . '</th>'
            . '<th scope="col">' . esc_html__('In the bin', 'hilg-vault') . '</th>'
            . '<th scope="col">' . esc_html__('Files', 'hilg-vault') . '</th>'
            . '</tr></thead><tbody>';

        foreach (array_reverse($history, true) as $date => $day) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html((string) $date),
                esc_html(size_format((int) $day['live'], 1)),
                esc_html(size_format((int) ($day['binned'] ?? 0), 1)),
                esc_html(number_format((int) ($day['files'] ?? 0)))
            );
        }

        echo '</tbody></table></details>';
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

        $filters = self::logFilters();
        $where   = self::logWhere($filters);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
        $page    = isset($_GET['log_page']) ? max(1, absint(wp_unslash($_GET['log_page']))) : 1;
        $perPage = 50;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$log} l {$where}");
        $pages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.created_at, l.action, l.result, l.user_id,
                        f.name AS file_name, d.name AS folder_name
                 FROM {$log} l
                 LEFT JOIN {$files} f ON f.id = l.file_id
                 LEFT JOIN {$folders} d ON d.id = l.folder_id
                 {$where}
                 ORDER BY l.id DESC
                 LIMIT %d OFFSET %d",
                $perPage,
                ($page - 1) * $perPage
            ),
            ARRAY_A
        );

        $base = menu_page_url(self::SLUG, false);

        ?>
        <h2 class="title"><?php esc_html_e('Access history', 'hilg-vault'); ?></h2>
        <p class="description">
            <?php
            printf(
                /* translators: %d: number of days the log is kept. */
                esc_html__('Downloads, unlock attempts and refusals. Refused attempts are recorded too: knowing what was tried and blocked matters as much as knowing what succeeded. Entries are kept for %d days.', 'hilg-vault'),
                Housekeeping::logRetentionDays()
            );
            ?>
        </p>

        <form method="get" class="hilg-log__filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">

            <label class="screen-reader-text" for="hilg-log-action"><?php esc_html_e('Action', 'hilg-vault'); ?></label>
            <select name="log_action" id="hilg-log-action">
                <option value=""><?php esc_html_e('Any action', 'hilg-vault'); ?></option>
                <?php foreach (['download', 'upload', 'unlock', 'delete', 'list_files'] as $action) : ?>
                    <option value="<?php echo esc_attr($action); ?>" <?php selected($filters['action'], $action); ?>>
                        <?php echo esc_html(self::actionLabel($action)); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="screen-reader-text" for="hilg-log-result"><?php esc_html_e('Result', 'hilg-vault'); ?></label>
            <select name="log_result" id="hilg-log-result">
                <option value=""><?php esc_html_e('Any result', 'hilg-vault'); ?></option>
                <?php foreach (['allowed', 'denied', 'throttled', 'unavailable'] as $result) : ?>
                    <option value="<?php echo esc_attr($result); ?>" <?php selected($filters['result'], $result); ?>>
                        <?php echo esc_html(self::resultLabel($result)); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="screen-reader-text" for="hilg-log-days"><?php esc_html_e('Period', 'hilg-vault'); ?></label>
            <select name="log_days" id="hilg-log-days">
                <option value="0"><?php esc_html_e('All time', 'hilg-vault'); ?></option>
                <option value="1" <?php selected($filters['days'], 1); ?>><?php esc_html_e('Last 24 hours', 'hilg-vault'); ?></option>
                <option value="7" <?php selected($filters['days'], 7); ?>><?php esc_html_e('Last 7 days', 'hilg-vault'); ?></option>
                <option value="30" <?php selected($filters['days'], 30); ?>><?php esc_html_e('Last 30 days', 'hilg-vault'); ?></option>
                <option value="90" <?php selected($filters['days'], 90); ?>><?php esc_html_e('Last 90 days', 'hilg-vault'); ?></option>
            </select>

            <button type="submit" class="button"><?php esc_html_e('Filter', 'hilg-vault'); ?></button>

            <?php if ($filters['action'] !== '' || $filters['result'] !== '' || $filters['days'] > 0) : ?>
                <a class="button-link" href="<?php echo esc_url($base); ?>"><?php esc_html_e('Clear', 'hilg-vault'); ?></a>
            <?php endif; ?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hilg-log__export">
            <input type="hidden" name="action" value="hilg_vault_export_log">
            <input type="hidden" name="log_action" value="<?php echo esc_attr($filters['action']); ?>">
            <input type="hidden" name="log_result" value="<?php echo esc_attr($filters['result']); ?>">
            <input type="hidden" name="log_days" value="<?php echo (int) $filters['days']; ?>">
            <?php wp_nonce_field(self::NONCE); ?>
            <button type="submit" class="button"><?php esc_html_e('Download as CSV', 'hilg-vault'); ?></button>
            <span class="description"><?php esc_html_e('Exports every entry matching the filters above, not just this page.', 'hilg-vault'); ?></span>
        </form>

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
                <tr><td colspan="5"><?php esc_html_e('Nothing matches.', 'hilg-vault'); ?></td></tr>
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

        <?php if ($pages > 1) : ?>
            <div class="hilg-admin__pagination">
                <?php
                $link = static function (int $target) use ($base, $filters): string {
                    return add_query_arg(
                        array_filter([
                            'log_action' => $filters['action'],
                            'log_result' => $filters['result'],
                            'log_days'   => $filters['days'] ?: null,
                            'log_page'   => $target,
                        ]),
                        $base
                    );
                };
                ?>

                <?php if ($page > 1) : ?>
                    <a class="button" href="<?php echo esc_url($link($page - 1)); ?>"><?php esc_html_e('Previous', 'hilg-vault'); ?></a>
                <?php endif; ?>

                <span class="hilg-admin__page-count">
                    <?php
                    printf(
                        /* translators: 1: current page, 2: total pages, 3: total entries. */
                        esc_html__('Page %1$d of %2$d, %3$s entries', 'hilg-vault'),
                        $page,
                        $pages,
                        esc_html(number_format($total))
                    );
                    ?>
                </span>

                <?php if ($page < $pages) : ?>
                    <a class="button" href="<?php echo esc_url($link($page + 1)); ?>"><?php esc_html_e('Next', 'hilg-vault'); ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
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
