<?php
/**
 * Learning platform settings.
 *
 * Everything a different platform needs is on this one screen: the base URL,
 * the two paths, how to authenticate, and the field map. Connecting a new
 * platform is a task for an administrator with the platform's API docs open,
 * not a development job.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Lms;

use ClarityWeb\HilgVault\Admin\AdminPage;

defined('ABSPATH') || exit;

final class LmsSettingsPage
{
    private const SLUG  = 'hilg-vault-lms';
    private const NONCE = 'hilg_vault_lms_settings';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_hilg_vault_lms_save', [self::class, 'handleSave']);
        add_action('admin_post_hilg_vault_lms_refresh', [self::class, 'handleRefresh']);
    }

    public static function addMenu(): void
    {
        add_submenu_page(
            AdminPage::SLUG,
            __('Learning Platform', 'hilg-vault'),
            __('Learning Platform', 'hilg-vault'),
            'manage_options',
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function handleSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to change these settings.', 'hilg-vault'));
        }

        check_admin_referer(self::NONCE);

        $post = wp_unslash($_POST);

        $settings = [
            'base_url'      => esc_url_raw((string) ($post['base_url'] ?? '')),
            'modules_path'  => sanitize_text_field((string) ($post['modules_path'] ?? '')),
            'lessons_path'  => sanitize_text_field((string) ($post['lessons_path'] ?? '')),
            'modules_root'  => sanitize_text_field((string) ($post['modules_root'] ?? '')),
            'lessons_root'  => sanitize_text_field((string) ($post['lessons_root'] ?? '')),
            'auth'          => in_array($post['auth'] ?? '', ['none', 'bearer', 'header'], true)
                ? (string) $post['auth']
                : 'none',
            'token_header'  => sanitize_text_field((string) ($post['token_header'] ?? 'X-API-Key')),
            'timeout'       => max(3, min(60, (int) ($post['timeout'] ?? 12))),
            'module_url_pattern' => esc_url_raw((string) ($post['module_url_pattern'] ?? '')),
            'lesson_url_pattern' => esc_url_raw((string) ($post['lesson_url_pattern'] ?? '')),
            'field_map'     => [],
        ];

        foreach (['id', 'title', 'description', 'lesson_count', 'duration', 'url', 'updated_at',
                  'lesson_id', 'lesson_title', 'lesson_summary', 'lesson_duration', 'lesson_url'] as $key) {
            $value = sanitize_text_field((string) ($post['map_' . $key] ?? ''));

            if ($value !== '') {
                $settings['field_map'][$key] = $value;
            }
        }

        // An empty token field means "leave the stored one alone", so a token
        // is never wiped just because someone saved another field.
        $existing = Catalogue::settings();
        $token    = (string) ($post['token'] ?? '');

        $settings['token'] = $token !== '' ? $token : (string) ($existing['token'] ?? '');

        update_option(Catalogue::OPTION_SETTINGS, $settings, false);
        Catalogue::flush();

        wp_safe_redirect(add_query_arg('updated', '1', menu_page_url(self::SLUG, false)));
        exit;
    }

    public static function handleRefresh(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do that.', 'hilg-vault'));
        }

        check_admin_referer(self::NONCE . '_refresh');

        Catalogue::flush();
        Catalogue::modules(true);

        wp_safe_redirect(add_query_arg('refreshed', '1', menu_page_url(self::SLUG, false)));
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to open this page.', 'hilg-vault'));
        }

        $settings = Catalogue::settings();
        $map      = is_array($settings['field_map'] ?? null) ? $settings['field_map'] : [];
        $result   = Catalogue::modules();

        ?>
        <div class="wrap hilg-lms-settings">
            <h1><?php esc_html_e('Learning Platform', 'hilg-vault'); ?></h1>

            <?php if (isset($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Settings saved.', 'hilg-vault'); ?></p>
                </div>
            <?php endif; ?>

            <?php self::renderStatus($result); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="hilg_vault_lms_save">
                <?php wp_nonce_field(self::NONCE); ?>

                <h2 class="title"><?php esc_html_e('Connection', 'hilg-vault'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    self::textRow('base_url', __('Base URL', 'hilg-vault'), $settings['base_url'] ?? '', 'https://learning.example.ie');
                    self::textRow('modules_path', __('Modules path', 'hilg-vault'), $settings['modules_path'] ?? '/api/v1/modules', '/api/v1/modules');
                    self::textRow('lessons_path', __('Lessons path', 'hilg-vault'), $settings['lessons_path'] ?? '/api/v1/modules/{module}/lessons', '/api/v1/modules/{module}/lessons');
                    ?>
                    <tr>
                        <th scope="row"><label for="hilg-auth"><?php esc_html_e('Authentication', 'hilg-vault'); ?></label></th>
                        <td>
                            <select name="auth" id="hilg-auth">
                                <?php
                                $current = (string) ($settings['auth'] ?? 'none');

                                foreach ([
                                    'none'   => __('None', 'hilg-vault'),
                                    'bearer' => __('Bearer token', 'hilg-vault'),
                                    'header' => __('API key header', 'hilg-vault'),
                                ] as $value => $label) {
                                    printf(
                                        '<option value="%s"%s>%s</option>',
                                        esc_attr($value),
                                        selected($current, $value, false),
                                        esc_html($label)
                                    );
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <?php
                    self::textRow('token_header', __('API key header name', 'hilg-vault'), $settings['token_header'] ?? 'X-API-Key', 'X-API-Key');
                    ?>
                    <tr>
                        <th scope="row"><label for="hilg-token"><?php esc_html_e('Token', 'hilg-vault'); ?></label></th>
                        <td>
                            <input type="password" name="token" id="hilg-token" class="regular-text" autocomplete="off"
                                   placeholder="<?php echo empty($settings['token'])
                                       ? esc_attr__('not set', 'hilg-vault')
                                       : esc_attr__('stored, leave blank to keep', 'hilg-vault'); ?>">
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Response shape', 'hilg-vault'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Only needed when the platform wraps its results or names fields differently. Leave blank to use the common names.', 'hilg-vault'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <?php
                    self::textRow('modules_root', __('Modules wrapper key', 'hilg-vault'), $settings['modules_root'] ?? '', 'data');
                    self::textRow('lessons_root', __('Lessons wrapper key', 'hilg-vault'), $settings['lessons_root'] ?? 'lessons', 'lessons');
                    self::textRow('map_id', __('Module id field', 'hilg-vault'), $map['id'] ?? '', 'id');
                    self::textRow('map_title', __('Module title field', 'hilg-vault'), $map['title'] ?? '', 'title');
                    self::textRow('map_description', __('Module description field', 'hilg-vault'), $map['description'] ?? '', 'description');
                    self::textRow('map_lesson_title', __('Lesson title field', 'hilg-vault'), $map['lesson_title'] ?? '', 'title');
                    self::textRow('module_url_pattern', __('Module link pattern', 'hilg-vault'), $settings['module_url_pattern'] ?? '', 'https://learning.example.ie/course/{module}');
                    self::textRow('lesson_url_pattern', __('Lesson link pattern', 'hilg-vault'), $settings['lesson_url_pattern'] ?? '', 'https://learning.example.ie/course/{module}/lesson/{lesson}');
                    ?>
                </table>

                <?php submit_button(__('Save settings', 'hilg-vault')); ?>
            </form>

            <?php self::renderCatalogue($result); ?>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function renderStatus(array $result): void
    {
        if (!$result['configured']) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('No learning platform is connected yet. Add the base URL below.', 'hilg-vault')
                . '</p></div>';

            return;
        }

        if ($result['stale']) {
            echo '<div class="notice notice-error"><p>';
            printf(
                /* translators: %s: error reported by the platform. */
                esc_html__('The platform could not be reached: %s', 'hilg-vault'),
                esc_html((string) $result['error'])
            );

            if ($result['modules'] !== []) {
                echo ' ' . esc_html__('Pages are showing the last saved copy.', 'hilg-vault');
            }

            echo '</p></div>';

            return;
        }

        echo '<div class="notice notice-success"><p>';
        printf(
            /* translators: %d: number of modules. */
            esc_html(_n('Connected. %d module available.', 'Connected. %d modules available.', count($result['modules']), 'hilg-vault')),
            count($result['modules'])
        );
        echo '</p></div>';
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function renderCatalogue(array $result): void
    {
        if (!$result['configured']) {
            return;
        }

        ?>
        <h2 class="title"><?php esc_html_e('Catalogue', 'hilg-vault'); ?></h2>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
            <input type="hidden" name="action" value="hilg_vault_lms_refresh">
            <?php wp_nonce_field(self::NONCE . '_refresh'); ?>
            <button type="submit" class="button"><?php esc_html_e('Refresh now', 'hilg-vault'); ?></button>
            <?php if ($result['captured_at'] !== '') : ?>
                <span class="description" style="margin-left:8px">
                    <?php
                    $timestamp = strtotime((string) $result['captured_at'] . ' UTC');

                    printf(
                        /* translators: %s: date and time of the last successful sync. */
                        esc_html__('Last retrieved %s', 'hilg-vault'),
                        esc_html($timestamp ? wp_date(get_option('date_format') . ', ' . get_option('time_format'), $timestamp) : '')
                    );
                    ?>
                </span>
            <?php endif; ?>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Module', 'hilg-vault'); ?></th>
                    <th scope="col" style="width:110px"><?php esc_html_e('Lessons', 'hilg-vault'); ?></th>
                    <th scope="col" style="width:300px"><?php esc_html_e('Shortcode', 'hilg-vault'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($result['modules'] === []) : ?>
                <tr><td colspan="3"><?php esc_html_e('The platform returned no modules.', 'hilg-vault'); ?></td></tr>
            <?php else : ?>
                <?php foreach ($result['modules'] as $module) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html((string) $module['title']); ?></strong>
                            <?php if ($module['description'] !== '') : ?>
                                <p class="description"><?php echo esc_html((string) $module['description']); ?></p>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html((string) $module['lesson_count']); ?></td>
                        <td><code>[hilg_lms module="<?php echo esc_attr((string) $module['id']); ?>"]</code></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function textRow(string $name, string $label, string $value, string $placeholder): void
    {
        printf(
            '<tr><th scope="row"><label for="hilg-%1$s">%2$s</label></th>
             <td><input type="text" name="%1$s" id="hilg-%1$s" class="regular-text" value="%3$s" placeholder="%4$s"></td></tr>',
            esc_attr($name),
            esc_html($label),
            esc_attr($value),
            esc_attr($placeholder)
        );
    }
}
