<?php
/**
 * Administration screen.
 *
 * The markup here is a shell. Everything below the toolbar is populated over
 * the REST API, which keeps one implementation of the permission rules instead
 * of a second, subtly different one written for the admin screen.
 *
 * Deliberately built on native WordPress admin classes rather than a bundled
 * design system. Editors already know how this looks and behaves, it inherits
 * the admin colour scheme including high contrast modes, and there is no
 * framework to keep patched for eighteen months of weekly updates.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Admin;

use ClarityWeb\HilgVault\Access\AccessPolicy;
use ClarityWeb\HilgVault\Plugin;

defined('ABSPATH') || exit;

final class AdminPage
{
    public const SLUG = 'hilg-vault';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    /**
     * The admin screen requires hilg_manage_vault, not hilg_vault_access.
     *
     * The two are deliberately different. hilg_vault_access means "may reach
     * shared material through the site" and is held by external network
     * members. hilg_manage_vault means "may administer the library" and belongs
     * to staff. Guarding this screen with the first would put partner
     * organisations inside wp-admin, which is an interface built for editors
     * and a surface external contributors have no reason to touch.
     */
    public static function addMenu(): void
    {
        add_menu_page(
            __('File Vault', 'hilg-vault'),
            __('File Vault', 'hilg-vault'),
            'hilg_manage_vault',
            self::SLUG,
            [self::class, 'render'],
            'dashicons-portfolio',
            26
        );
    }

    public static function enqueue(string $hookSuffix): void
    {
        if ($hookSuffix !== 'toplevel_page_' . self::SLUG) {
            return;
        }

        $dir = HILG_VAULT_DIR;
        $url = HILG_VAULT_URL;

        $version = static function (string $path) use ($dir): string {
            $file = $dir . $path;
            $time = is_readable($file) ? filemtime($file) : false;

            return $time === false ? \ClarityWeb\HilgVault\VERSION : (string) $time;
        };

        wp_enqueue_style('hilg-vault-admin', $url . 'assets/admin.css', [], $version('assets/admin.css'));
        wp_enqueue_script('hilg-vault-admin', $url . 'assets/admin.js', [], $version('assets/admin.js'), true);

        wp_localize_script('hilg-vault-admin', 'hilgVaultAdmin', [
            'root'     => esc_url_raw(rest_url('hilg-vault/v1')),
            'nonce'    => wp_create_nonce('wp_rest'),
            'canManage' => current_user_can('hilg_manage_vault'),
            'modes'    => AccessPolicy::modes(),
            'roles'    => self::roleList(),
            'maxBytes' => \ClarityWeb\HilgVault\Model\FileRepository::maxUploadBytes(),
            'i18n'     => self::strings(),
        ]);
    }

    /**
     * @return array<int,array{slug:string,name:string}>
     */
    private static function roleList(): array
    {
        $roles = [];

        foreach (wp_roles()->roles as $slug => $role) {
            $roles[] = ['slug' => (string) $slug, 'name' => translate_user_role($role['name'])];
        }

        return $roles;
    }

    /**
     * @return array<string,string>
     */
    private static function strings(): array
    {
        return [
            'allFiles'      => __('All files', 'hilg-vault'),
            'newFolder'     => __('New folder', 'hilg-vault'),
            'folderName'    => __('Folder name', 'hilg-vault'),
            'rename'        => __('Rename', 'hilg-vault'),
            'move'          => __('Move', 'hilg-vault'),
            'moved'         => __('Moved', 'hilg-vault'),
            'moveFile'      => __('Move file', 'hilg-vault'),
            'moveFolder'    => __('Move folder', 'hilg-vault'),
            'topLevel'      => __('Top level', 'hilg-vault'),
            'cannotNest'    => __('A folder cannot be moved into itself or into one of its own sub folders.', 'hilg-vault'),
            'previous'      => __('Previous', 'hilg-vault'),
            'next'          => __('Next', 'hilg-vault'),
            /* translators: 1: current page, 2: total pages, 3: total files. */
            'pageOf'        => __('Page %1$s of %2$s, %3$s files', 'hilg-vault'),
            /* translators: %s: number of files. */
            'fileCount'     => __('%s files', 'hilg-vault'),
            'noDestination' => __('There is no other folder to move it to yet.', 'hilg-vault'),
            'delete'        => __('Delete', 'hilg-vault'),
            'settings'      => __('Access settings', 'hilg-vault'),
            'upload'        => __('Upload files', 'hilg-vault'),
            'dropHere'      => __('Drop files here, or', 'hilg-vault'),
            'browse'        => __('choose files', 'hilg-vault'),
            'empty'         => __('This folder is empty.', 'hilg-vault'),
            'loading'       => __('Loading', 'hilg-vault'),
            'uploading'     => __('Uploading', 'hilg-vault'),
            'uploaded'      => __('Uploaded', 'hilg-vault'),
            'failed'        => __('Failed', 'hilg-vault'),
            'confirmDelete' => __('Delete this permanently from the listing? Files can be restored by an administrator.', 'hilg-vault'),
            'password'      => __('Shared password', 'hilg-vault'),
            'passwordKeep'  => __('Leave blank to keep the current password', 'hilg-vault'),
            'save'          => __('Save', 'hilg-vault'),
            'cancel'        => __('Cancel', 'hilg-vault'),
            'canView'       => __('View', 'hilg-vault'),
            'canUpload'     => __('Upload', 'hilg-vault'),
            'canManage'     => __('Manage', 'hilg-vault'),
            'inherited'     => __('inherited', 'hilg-vault'),
            'roleMatrix'    => __('Which roles can see this folder', 'hilg-vault'),
            'shortcodeHint' => __('Shortcode for this folder', 'hilg-vault'),
            'copied'        => __('Copied', 'hilg-vault'),
            'noStorage'     => __('Object storage is not configured, so nothing can be uploaded.', 'hilg-vault'),
        ];
    }

    public static function render(): void
    {
        if (!current_user_can('hilg_manage_vault')) {
            wp_die(esc_html__('You do not have permission to open the file vault.', 'hilg-vault'));
        }

        $storage = Plugin::instance()->storage();
        $health  = $storage?->healthCheck();

        ?>
        <div class="wrap hilg-admin">
            <h1 class="wp-heading-inline"><?php esc_html_e('File Vault', 'hilg-vault'); ?></h1>

            <?php if ($storage === null) : ?>
                <div class="notice notice-error">
                    <p>
                        <?php esc_html_e('Object storage is not configured. Set S3_ENDPOINT, S3_BUCKET, S3_KEY and S3_SECRET in the environment.', 'hilg-vault'); ?>
                    </p>
                </div>
            <?php elseif ($health !== null && !$health['ok']) : ?>
                <div class="notice notice-error">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: message returned by the storage provider. */
                            esc_html__('Storage is unreachable: %s', 'hilg-vault'),
                            esc_html($health['message'])
                        );
                        ?>
                    </p>
                </div>
            <?php else : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: bucket name, 2: storage endpoint. */
                            esc_html__('Storage connected: %1$s at %2$s', 'hilg-vault'),
                            esc_html($storage->bucket()),
                            esc_html($storage->endpoint())
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="hilg-admin__layout">
                <aside class="hilg-admin__sidebar">
                    <div class="hilg-admin__sidebar-head">
                        <h2><?php esc_html_e('Folders', 'hilg-vault'); ?></h2>
                        <?php if (current_user_can('hilg_manage_vault')) : ?>
                            <button type="button" class="button button-small" id="hilg-new-folder">
                                <?php esc_html_e('New folder', 'hilg-vault'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                    <nav class="hilg-admin__tree" id="hilg-tree"
                         aria-label="<?php esc_attr_e('Folder tree', 'hilg-vault'); ?>"></nav>
                </aside>

                <section class="hilg-admin__main">
                    <header class="hilg-admin__toolbar">
                        <h2 class="hilg-admin__current" id="hilg-current-folder">
                            <?php esc_html_e('All files', 'hilg-vault'); ?>
                        </h2>
                        <div class="hilg-admin__actions" id="hilg-actions"></div>
                    </header>

                    <p class="hilg-admin__status" role="status" aria-live="polite" id="hilg-status"></p>

                    <div class="hilg-admin__dropzone" id="hilg-dropzone" hidden>
                        <p>
                            <?php esc_html_e('Drop files here, or', 'hilg-vault'); ?>
                            <label class="hilg-admin__browse">
                                <input type="file" multiple id="hilg-file-input" class="screen-reader-text">
                                <span class="button button-secondary"><?php esc_html_e('choose files', 'hilg-vault'); ?></span>
                            </label>
                        </p>
                        <p class="description">
                            <?php esc_html_e('Files go straight to object storage. They are not added to the media library.', 'hilg-vault'); ?>
                        </p>
                    </div>

                    <ul class="hilg-admin__queue" id="hilg-queue" aria-label="<?php esc_attr_e('Uploads in progress', 'hilg-vault'); ?>"></ul>

                    <table class="wp-list-table widefat fixed striped hilg-admin__files">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Name', 'hilg-vault'); ?></th>
                                <th scope="col" class="hilg-admin__col-folder" id="hilg-col-folder" hidden>
                                    <?php esc_html_e('Folder', 'hilg-vault'); ?>
                                </th>
                                <th scope="col" class="hilg-admin__col-size"><?php esc_html_e('Size', 'hilg-vault'); ?></th>
                                <th scope="col" class="hilg-admin__col-date"><?php esc_html_e('Added', 'hilg-vault'); ?></th>
                                <th scope="col" class="hilg-admin__col-actions">
                                    <span class="screen-reader-text"><?php esc_html_e('Actions', 'hilg-vault'); ?></span>
                                </th>
                            </tr>
                        </thead>
                        <tbody id="hilg-file-rows"></tbody>
                    </table>

                    <div class="hilg-admin__pagination" id="hilg-pagination"></div>
                </section>
            </div>

            <?php self::renderFolderDialog(); ?>
        </div>
        <?php
    }

    /**
     * Native dialog element: it brings focus trapping, Escape to close and the
     * top layer without a modal library that would need maintaining.
     */
    private static function renderFolderDialog(): void
    {
        ?>
        <dialog class="hilg-admin__dialog" id="hilg-folder-dialog"
                aria-labelledby="hilg-dialog-title">
            <form method="dialog" id="hilg-folder-form">
                <h2 id="hilg-dialog-title"><?php esc_html_e('Folder settings', 'hilg-vault'); ?></h2>

                <p class="hilg-admin__field">
                    <label for="hilg-dialog-name"><?php esc_html_e('Folder name', 'hilg-vault'); ?></label>
                    <input type="text" id="hilg-dialog-name" class="regular-text" required>
                </p>

                <fieldset class="hilg-admin__field">
                    <legend><?php esc_html_e('Who can open this folder', 'hilg-vault'); ?></legend>
                    <?php foreach (AccessPolicy::modes() as $value => $label) : ?>
                        <label class="hilg-admin__radio">
                            <input type="radio" name="hilg_access_mode" value="<?php echo esc_attr($value); ?>">
                            <span><?php echo esc_html($label); ?></span>
                        </label>
                    <?php endforeach; ?>
                    <label class="hilg-admin__radio">
                        <input type="radio" name="hilg_access_mode" value="inherit">
                        <span><?php esc_html_e('Inherit from the parent folder', 'hilg-vault'); ?></span>
                    </label>
                </fieldset>

                <p class="hilg-admin__field" id="hilg-password-field" hidden>
                    <label for="hilg-dialog-password"><?php esc_html_e('Shared password', 'hilg-vault'); ?></label>
                    <input type="text" id="hilg-dialog-password" class="regular-text" autocomplete="off">
                    <span class="description"><?php esc_html_e('Leave blank to keep the current password.', 'hilg-vault'); ?></span>
                </p>

                <fieldset class="hilg-admin__field" id="hilg-roles-field">
                    <legend><?php esc_html_e('Which roles can see this folder', 'hilg-vault'); ?></legend>
                    <p class="description">
                        <?php esc_html_e('Applies to folders that are open to signed in users. Sub folders inherit these unless they set their own.', 'hilg-vault'); ?>
                    </p>
                    <div class="hilg-admin__matrix" id="hilg-role-matrix"></div>
                </fieldset>

                <p class="hilg-admin__dialog-actions">
                    <button type="button" class="button button-primary" id="hilg-dialog-save">
                        <?php esc_html_e('Save', 'hilg-vault'); ?>
                    </button>
                    <button type="button" class="button" id="hilg-dialog-cancel">
                        <?php esc_html_e('Cancel', 'hilg-vault'); ?>
                    </button>
                </p>
            </form>
        </dialog>

        <?php
        /*
         * A second dialog rather than a prompt(): the destination is a choice
         * from a known set, and a native select gives keyboard selection, type
         * ahead and a screen reader announcement of how many options there are.
         * A prompt asking an editor to type the number of a folder does none of
         * that, and gets worse the larger the library becomes.
         */
        ?>
        <dialog class="hilg-admin__dialog" id="hilg-move-dialog" aria-labelledby="hilg-move-title">
            <form method="dialog" id="hilg-move-form">
                <h2 id="hilg-move-title"><?php esc_html_e('Move file', 'hilg-vault'); ?></h2>

                <p class="hilg-admin__moving" id="hilg-move-file"></p>

                <div class="hilg-admin__field">
                    <label for="hilg-move-target"><?php esc_html_e('Destination folder', 'hilg-vault'); ?></label>
                    <select id="hilg-move-target" class="regular-text"></select>
                </div>

                <p class="hilg-admin__dialog-actions">
                    <button type="button" class="button button-primary" id="hilg-move-save">
                        <?php esc_html_e('Move', 'hilg-vault'); ?>
                    </button>
                    <button type="button" class="button" id="hilg-move-cancel">
                        <?php esc_html_e('Cancel', 'hilg-vault'); ?>
                    </button>
                </p>
            </form>
        </dialog>
        <?php
    }
}
