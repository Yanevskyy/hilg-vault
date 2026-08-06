<?php
/**
 * Front end rendering: shortcode, Gutenberg block, and the folder browser.
 *
 * Built as progressive enhancement. The server renders a complete, usable
 * listing as plain HTML links. JavaScript then intercepts navigation to keep
 * it on one page. With scripting unavailable, on a screen reader, or in an old
 * browser, the files are still reachable, which is what EN 301 549 and the
 * Disability Act 2005 actually require.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Frontend;

use ClarityWeb\HilgVault\Access\AccessPolicy;
use ClarityWeb\HilgVault\Model\FileRepository;
use ClarityWeb\HilgVault\Model\FolderRepository;

defined('ABSPATH') || exit;

final class VaultRenderer
{
    /** Set once a page renders anything that is not public. */
    private static bool $renderedRestricted = false;

    public static function register(): void
    {
        add_shortcode('hilg_vault', [self::class, 'shortcode']);
        add_action('init', [self::class, 'registerBlock']);
        add_action('wp_enqueue_scripts', [self::class, 'registerAssets']);

        // Must run before any output. Headers cannot be set once the body has
        // started, which is why this cannot wait until rendering decides what
        // it is showing.
        add_action('template_redirect', [self::class, 'protectFromCaching'], 5);

        // The password form posts to its own page. Without this handler it only
        // works when JavaScript intercepts it, which would make the claim of
        // progressive enhancement half true: the listing degrades gracefully,
        // but a locked folder could never be opened without scripting.
        add_action('template_redirect', [self::class, 'handlePasswordPost'], 4);
    }

    /**
     * Handles the plain form submission from the password gate.
     *
     * Runs before output so the unlock cookie can actually be set. Setting it
     * later produces a PHP warning and, more importantly, no cookie.
     */
    public static function handlePasswordPost(): void
    {
        if (!isset($_POST['hilg_vault_password'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a public
        // gate with no state change beyond this visitor's own session; the
        // password itself is the credential, and attempts are rate limited.
        $password = (string) wp_unslash($_POST['hilg_vault_password']);

        $folderId = isset($_POST['hilg_vault_folder'])
            ? absint(wp_unslash($_POST['hilg_vault_folder']))
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : (isset($_GET['hilg_folder']) ? absint(wp_unslash($_GET['hilg_folder'])) : 0);

        if ($folderId === 0) {
            return;
        }

        $base = get_permalink();
        $base = is_string($base) && $base !== '' ? $base : home_url('/');

        if (AccessPolicy::attemptPasswordUnlock($folderId, $password)) {
            AccessPolicy::log('unlock', 'allowed', $folderId);

            // Redirect after POST so a refresh does not resubmit the password.
            wp_safe_redirect(add_query_arg('hilg_folder', $folderId, $base));
            exit;
        }

        AccessPolicy::log('unlock', 'denied', $folderId);

        wp_safe_redirect(add_query_arg(
            ['hilg_folder' => $folderId, 'hilg_unlock' => 'failed'],
            $base
        ));
        exit;
    }

    /**
     * Decides, before the page renders, whether this response may be cached.
     *
     * The check has to happen up front: by the time the folder listing is
     * drawn the headers are already on their way, and a late attempt fails
     * silently. So the page content is inspected for our block or shortcode
     * and the folders it references are resolved from the request.
     */
    private static function requestIsRestricted(): bool
    {
        if (is_user_logged_in()) {
            return true;
        }

        // An unlock cookie means this visitor sees something others do not.
        foreach (array_keys($_COOKIE) as $cookie) {
            if (str_starts_with((string) $cookie, 'hilg_vault_pw_')) {
                return true;
            }
        }

        $post = get_post();

        if (!$post instanceof \WP_Post) {
            return false;
        }

        $content = (string) $post->post_content;

        $hasVault = has_shortcode($content, 'hilg_vault')
            || has_block('hilg-vault/folder', $post);

        if (!$hasVault) {
            return false;
        }

        // Which folder is on screen: the one in the request, or the one the
        // page was configured with.
        $folderIds = [];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
        if (isset($_GET['hilg_folder'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $folderIds[] = absint(wp_unslash($_GET['hilg_folder']));
        }

        if (preg_match_all('/folder(?:Id)?[="\s:]+(\d+)/i', $content, $matches) === 1 || !empty($matches[1])) {
            foreach ($matches[1] as $id) {
                $folderIds[] = (int) $id;
            }
        }

        foreach (array_unique(array_filter($folderIds)) as $folderId) {
            $folder = AccessPolicy::folder($folderId);

            if ($folder !== null && AccessPolicy::effectiveMode($folder) !== AccessPolicy::MODE_PUBLIC) {
                return true;
            }
        }

        // The root listing includes every top level folder, so if any of them
        // is restricted the rendered page differs per visitor.
        if ($folderIds === []) {
            foreach (FolderRepository::children(null) as $child) {
                if (AccessPolicy::effectiveMode($child) !== AccessPolicy::MODE_PUBLIC) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Keeps restricted listings out of page caches.
     *
     * The site sits behind LiteSpeed and Cloudflare, and both cache anything
     * the origin does not explicitly refuse. A page showing a private folder,
     * or a folder unlocked by password, would be stored and then served to the
     * next visitor complete with someone else's files. The permission checks
     * would work perfectly; nothing would ask them, because the answer would
     * come from the cache.
     *
     * Marking the response is the only defence that does not depend on the
     * cache being configured correctly by someone else.
     */
    public static function protectFromCaching(): void
    {
        if (is_admin() || !self::requestIsRestricted()) {
            return;
        }

        if (headers_sent()) {
            return;
        }

        self::$renderedRestricted = true;

        // WordPress core headers cover browsers and well behaved proxies.
        nocache_headers();

        // LiteSpeed and Cloudflare each read their own signal.
        header('Cache-Control: private, no-store, no-cache, max-age=0', true);
        header('X-LiteSpeed-Cache-Control: no-cache, esi=off', true);
        header('CDN-Cache-Control: no-store', true);

        // Anything that does cache must at least vary on the unlock cookie.
        header('Vary: Cookie', false);

        // Tells LiteSpeed's own plugin, when present, to skip this response.
        if (!defined('LSCACHE_NO_CACHE')) {
            define('LSCACHE_NO_CACHE', true);
        }

        do_action('litespeed_control_set_nocache', 'HILG Vault restricted content');
    }

    /**
     * Called by the renderer whenever it outputs something not public.
     */
    private static function markRestricted(): void
    {
        self::$renderedRestricted = true;
    }

    /**
     * Asset version follows the file's modification time, not the plugin
     * version.
     *
     * This site sits behind LiteSpeed Cache and Cloudflare, and the support
     * contract commits to weekly updates. With a version string that only
     * changes on a release, a CSS or JS fix keeps serving stale to anyone with
     * a warm cache, and "we fixed it" turns into "nothing changed for me".
     * Tying it to the file itself makes invalidation automatic.
     */
    private static function assetVersion(string $relativePath): string
    {
        $file = HILG_VAULT_DIR . $relativePath;
        $time = is_readable($file) ? filemtime($file) : false;

        return $time === false
            ? \ClarityWeb\HilgVault\VERSION
            : \ClarityWeb\HilgVault\VERSION . '.' . $time;
    }

    public static function registerAssets(): void
    {
        wp_register_style(
            'hilg-vault',
            HILG_VAULT_URL . 'assets/vault.css',
            [],
            self::assetVersion('assets/vault.css')
        );

        wp_register_script(
            'hilg-vault',
            HILG_VAULT_URL . 'assets/vault.js',
            [],
            self::assetVersion('assets/vault.js'),
            true
        );

        wp_localize_script('hilg-vault', 'hilgVault', [
            'root'  => esc_url_raw(rest_url('hilg-vault/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'i18n'  => [
                'loading'    => __('Loading', 'hilg-vault'),
                'empty'      => __('This folder is empty.', 'hilg-vault'),
                'locked'     => __('This folder is password protected.', 'hilg-vault'),
                'wrongPass'  => __('That password did not work.', 'hilg-vault'),
                'failed'     => __('Could not load the folder. Please try again.', 'hilg-vault'),
                'downloading' => __('Preparing download', 'hilg-vault'),
            ],
        ]);
    }

    /**
     * Registers the editor block. An administrator picks a folder and a layout,
     * which is what the brief asks for: folders displayed on selected pages.
     */
    public static function registerBlock(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        register_block_type('hilg-vault/folder', [
            'api_version'     => 3,
            'title'           => __('File Vault Folder', 'hilg-vault'),
            'category'        => 'widgets',
            'icon'            => 'portfolio',
            'description'     => __('Displays a vault folder on this page.', 'hilg-vault'),
            'attributes'      => [
                'folderId' => ['type' => 'number', 'default' => 0],
                'layout'   => ['type' => 'string', 'default' => 'grid'],
                'showSearch' => ['type' => 'boolean', 'default' => true],
                'title'    => ['type' => 'string', 'default' => ''],
            ],
            'render_callback' => static fn(array $attributes): string => self::render([
                'folder'      => (int) ($attributes['folderId'] ?? 0),
                'layout'      => (string) ($attributes['layout'] ?? 'grid'),
                'show_search' => !empty($attributes['showSearch']),
                'title'       => (string) ($attributes['title'] ?? ''),
            ]),
        ]);
    }

    /**
     * @param array<string,mixed>|string $atts
     */
    public static function shortcode(array|string $atts): string
    {
        $atts = shortcode_atts(
            ['folder' => 0, 'layout' => 'grid', 'show_search' => 'yes', 'title' => ''],
            is_array($atts) ? $atts : [],
            'hilg_vault'
        );

        return self::render([
            'folder'      => (int) $atts['folder'],
            'layout'      => (string) $atts['layout'],
            'show_search' => $atts['show_search'] !== 'no',
            'title'       => (string) $atts['title'],
        ]);
    }

    /**
     * @param array{folder:int,layout:string,show_search:bool,title:string} $args
     */
    public static function render(array $args): string
    {
        wp_enqueue_style('hilg-vault');
        wp_enqueue_script('hilg-vault');

        $folderId = $args['folder'];
        $layout   = in_array($args['layout'], ['grid', 'list'], true) ? $args['layout'] : 'grid';

        // Navigation without JavaScript: each folder link carries ?hilg_folder,
        // and the server renders that folder directly. This is what keeps the
        // library usable when scripting is unavailable.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
        if (isset($_GET['hilg_folder'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $requested = absint(wp_unslash($_GET['hilg_folder']));

            // Only follow the parameter when it resolves to a real folder, so
            // a guessed id cannot push the component into a broken state.
            if ($requested === 0 || AccessPolicy::folder($requested) !== null) {
                $folderId = $requested;
            }
        }

        // A folder id of zero means the root of the tree.
        $folder = $folderId > 0 ? AccessPolicy::folder($folderId) : null;

        if ($folderId > 0 && $folder === null) {
            return self::notice(__('This folder is no longer available.', 'hilg-vault'));
        }

        $instanceId = wp_unique_id('hilg-vault-');

        ob_start();

        printf(
            '<div class="hilg-vault hilg-vault--%1$s" id="%2$s" data-folder="%3$d" data-layout="%1$s">',
            esc_attr($layout),
            esc_attr($instanceId),
            (int) $folderId
        );

        if ($args['title'] !== '') {
            printf('<h2 class="hilg-vault__title">%s</h2>', esc_html($args['title']));
        }

        // Anything that is not a public listing must not end up in a page
        // cache, so the response is marked before any of it is rendered.
        if ($folder !== null && AccessPolicy::effectiveMode($folder) !== AccessPolicy::MODE_PUBLIC) {
            self::markRestricted();
        }

        if (is_user_logged_in()) {
            self::markRestricted();
        }

        // Password gate takes over the whole component when it applies.
        if ($folder !== null && !AccessPolicy::canViewFolder($folderId)) {
            // The trail stays, otherwise someone arriving on a closed folder
            // from a link has no way back to the material they can open, and
            // the browser's back button becomes the only exit.
            echo self::backToRoot(); // phpcs:ignore WordPress.Security.EscapeOutput

            if (AccessPolicy::effectiveMode($folder) === AccessPolicy::MODE_PASSWORD) {
                echo self::passwordForm($folderId); // phpcs:ignore WordPress.Security.EscapeOutput
            } else {
                echo self::notice(self::privateMessage()); // phpcs:ignore WordPress.Security.EscapeOutput
            }

            echo '</div>';

            return (string) ob_get_clean();
        }

        echo self::breadcrumbs($folderId); // phpcs:ignore WordPress.Security.EscapeOutput

        if ($args['show_search']) {
            echo self::searchField($instanceId); // phpcs:ignore WordPress.Security.EscapeOutput
        }

        // Live region: screen readers hear what changed after navigating,
        // which is the part single page interfaces usually forget.
        printf(
            '<p class="hilg-vault__status" role="status" aria-live="polite" id="%s-status"></p>',
            esc_attr($instanceId)
        );

        echo self::listing($folderId, $layout); // phpcs:ignore WordPress.Security.EscapeOutput

        echo '</div>';

        return (string) ob_get_clean();
    }

    private static function listing(int $folderId, string $layout): string
    {
        $folders = FolderRepository::children($folderId ?: null);
        $files   = $folderId > 0 ? FileRepository::listByFolder($folderId) : [];

        $visibleFolders = [];

        foreach ($folders as $child) {
            $childId = (int) $child['id'];

            // A listing that includes a locked or private folder is tailored to
            // this visitor and must not be cached for the next one.
            if (AccessPolicy::effectiveMode($child) !== AccessPolicy::MODE_PUBLIC) {
                self::markRestricted();
            }

            if (AccessPolicy::canViewFolder($childId)) {
                $visibleFolders[] = ['row' => $child, 'locked' => false];

                continue;
            }

            // A locked folder is still listed, otherwise a visitor has no way
            // to know a password would open it. Its contents stay closed.
            if (AccessPolicy::effectiveMode($child) === AccessPolicy::MODE_PASSWORD) {
                $visibleFolders[] = ['row' => $child, 'locked' => true];
            }
        }

        if ($visibleFolders === [] && $files === []) {
            return self::notice(__('This folder is empty.', 'hilg-vault'));
        }

        $out = '<ul class="hilg-vault__items hilg-vault__items--' . esc_attr($layout) . '" role="list">';

        foreach ($visibleFolders as $entry) {
            $out .= self::folderItem($entry['row'], $entry['locked']);
        }

        foreach ($files as $file) {
            $out .= self::fileItem($file);
        }

        return $out . '</ul>';
    }

    /**
     * @param array<string,mixed> $folder
     */
    private static function folderItem(array $folder, bool $locked): string
    {
        $id   = (int) $folder['id'];
        $name = (string) $folder['name'];
        $url  = add_query_arg('hilg_folder', $id, get_permalink() ?: home_url('/'));

        // No aria-label here, on purpose.
        //
        // WCAG 2.5.3 requires the accessible name to contain all the visible
        // text of the control, and this row shows two pieces: the folder name
        // and, when locked, "Password required". An aria-label would have to
        // repeat both and stay in step with the markup forever. Letting the
        // name come from the text itself makes them identical by definition,
        // so the whole class of mismatch cannot occur. The icon is hidden from
        // assistive technology and the list context already conveys structure.
        return sprintf(
            '<li class="hilg-vault__item hilg-vault__item--folder%1$s">
                <a class="hilg-vault__link" href="%2$s" data-folder="%3$d">
                    <span class="hilg-vault__icon" aria-hidden="true">%4$s</span>
                    <span class="hilg-vault__name">%5$s</span>
                    %6$s
                </a>
            </li>',
            $locked ? ' is-locked' : '',
            esc_url($url),
            $id,
            $locked ? self::iconLock() : self::iconFolder(),
            esc_html($name),
            $locked
                ? '<span class="hilg-vault__meta">' . esc_html__('Password required', 'hilg-vault') . '</span>'
                : ''
        );
    }

    /**
     * @param array<string,mixed> $file
     */
    private static function fileItem(array $file): string
    {
        $id        = (int) $file['id'];
        $name      = (string) $file['name'];
        $extension = strtoupper((string) ($file['extension'] ?? ''));
        $size      = size_format((int) $file['size_bytes'], 1);

        // Same reasoning as folders: the accessible name is the visible text,
        // which here reads as "HILG Annual Report 2025.pdf PDF 3.1 MB".
        return sprintf(
            '<li class="hilg-vault__item hilg-vault__item--file">
                <a class="hilg-vault__link" href="%1$s" data-file="%2$d" rel="nofollow">
                    <span class="hilg-vault__icon" aria-hidden="true">%3$s</span>
                    <span class="hilg-vault__name">%4$s</span>
                    <span class="hilg-vault__meta">%5$s</span>
                </a>
            </li>',
            esc_url(rest_url('hilg-vault/v1/files/' . $id . '/download')),
            $id,
            self::iconFile($extension),
            esc_html($name),
            esc_html(trim($extension . ' ' . $size))
        );
    }

    private static function breadcrumbs(int $folderId): string
    {
        $base  = get_permalink() ?: home_url('/');
        $label = esc_attr__('Folder path', 'hilg-vault');

        // The container is always present, even at the root, so client side
        // navigation has somewhere to write the trail. Hiding an empty trail
        // is a style concern; removing the element breaks the enhancement.
        if ($folderId === 0) {
            return '<nav class="hilg-vault__breadcrumbs" aria-label="' . $label . '" hidden><ol></ol></nav>';
        }

        $trail = FolderRepository::breadcrumbs($folderId);

        if ($trail === []) {
            return '<nav class="hilg-vault__breadcrumbs" aria-label="' . $label . '" hidden><ol></ol></nav>';
        }

        $out = '<nav class="hilg-vault__breadcrumbs" aria-label="' . $label . '"><ol>';
        $out .= sprintf(
            '<li><a href="%s" data-folder="0">%s</a></li>',
            esc_url($base),
            esc_html__('All files', 'hilg-vault')
        );

        $last = count($trail) - 1;

        foreach ($trail as $index => $crumb) {
            if ($index === $last) {
                $out .= sprintf(
                    '<li><span aria-current="page">%s</span></li>',
                    esc_html($crumb['name'])
                );

                continue;
            }

            $out .= sprintf(
                '<li><a href="%s" data-folder="%d">%s</a></li>',
                esc_url(add_query_arg('hilg_folder', $crumb['id'], $base)),
                $crumb['id'],
                esc_html($crumb['name'])
            );
        }

        return $out . '</ol></nav>';
    }

    private static function searchField(string $instanceId): string
    {
        return sprintf(
            '<div class="hilg-vault__search">
                <label class="screen-reader-text" for="%1$s-search">%2$s</label>
                <input type="search" id="%1$s-search" class="hilg-vault__search-input"
                       placeholder="%3$s" autocomplete="off">
            </div>',
            esc_attr($instanceId),
            esc_html__('Search files in this folder', 'hilg-vault'),
            esc_attr__('Search files', 'hilg-vault')
        );
    }

    /**
     * Route back to the top of the library from a folder the visitor cannot
     * open. Rendered as the same breadcrumb component so the page does not
     * change shape depending on whether access was granted.
     */
    private static function backToRoot(): string
    {
        return sprintf(
            '<nav class="hilg-vault__breadcrumbs" aria-label="%1$s"><ol><li><a href="%2$s" data-folder="0">%3$s</a></li></ol></nav>',
            esc_attr__('Folder path', 'hilg-vault'),
            esc_url(get_permalink() ?: home_url('/')),
            esc_html__('All files', 'hilg-vault')
        );
    }

    private static function passwordForm(int $folderId): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $failed = isset($_GET['hilg_unlock']) && $_GET['hilg_unlock'] === 'failed';

        return sprintf(
            '<form class="hilg-vault__password" data-folder="%1$d" method="post">
                <p class="hilg-vault__password-intro">%2$s</p>
                <input type="hidden" name="hilg_vault_folder" value="%1$d">
                <label for="hilg-pw-%1$d">%3$s</label>
                <input type="password" id="hilg-pw-%1$d" name="hilg_vault_password"
                       autocomplete="off" required>
                <button type="submit" class="hilg-vault__button">%4$s</button>
                <p class="hilg-vault__password-error" role="alert"%5$s>%6$s</p>
            </form>',
            $folderId,
            esc_html__('This folder is password protected.', 'hilg-vault'),
            esc_html__('Password', 'hilg-vault'),
            esc_html__('Open folder', 'hilg-vault'),
            $failed ? '' : ' hidden',
            $failed ? esc_html__('That password did not work.', 'hilg-vault') : ''
        );
    }

    private static function privateMessage(): string
    {
        return is_user_logged_in()
            ? __('Your account does not have access to this folder.', 'hilg-vault')
            : __('Please sign in to view this folder.', 'hilg-vault');
    }

    private static function notice(string $message): string
    {
        return '<p class="hilg-vault__notice">' . esc_html($message) . '</p>';
    }

    // -----------------------------------------------------------------
    // Inline SVG icons. Not emoji: emoji render differently per platform
    // and screen readers announce them as words in the middle of a label.
    // -----------------------------------------------------------------

    private static function iconFolder(): string
    {
        return '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
    }

    private static function iconLock(): string
    {
        return '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>';
    }

    private static function iconFile(string $extension): string
    {
        $fold = '<path d="M14 3v5h5"/>';
        $base = '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/>';

        return '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
            . $base . $fold . '</svg>';
    }
}
