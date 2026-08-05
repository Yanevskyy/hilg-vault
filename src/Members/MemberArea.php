<?php
/**
 * Front end area for network members.
 *
 * The brief asks for external network members who manage their own profile and
 * their own content. Two design choices follow from "external":
 *
 * 1. Everything happens on the front end. Network members are people from
 *    partner organisations, not staff. Handing them the WordPress admin means
 *    training them on an interface built for editors and widening the surface
 *    they can reach.
 *
 * 2. Ownership is enforced on every operation, never inferred from what the
 *    interface happened to render. A member who edits the id in a form still
 *    only ever touches their own records.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Members;

use ClarityWeb\HilgVault\Access\AccessPolicy;
use ClarityWeb\HilgVault\Install\Schema;

defined('ABSPATH') || exit;

final class MemberArea
{
    private const NONCE_PROFILE = 'hilg_member_profile';
    private const NONCE_CONTENT = 'hilg_member_content';

    /** Post type members contribute to. Filterable per project. */
    public static function postType(): string
    {
        return (string) apply_filters('hilg_vault_member_post_type', 'post');
    }

    public static function register(): void
    {
        add_shortcode('hilg_member_area', [self::class, 'shortcode']);
        add_action('wp_enqueue_scripts', [self::class, 'registerAssets']);
        add_action('template_redirect', [self::class, 'handleSubmissions']);

        add_action('admin_init', [self::class, 'keepMembersOutOfAdmin']);
        add_filter('show_admin_bar', [self::class, 'hideAdminBarForMembers']);
    }

    /**
     * Sends network members back to their own area if they land in wp-admin.
     *
     * They have no reason to be there: everything they can do lives on the
     * front end. This is defence in depth rather than the primary control,
     * which is that they hold no administrative capabilities in the first
     * place. AJAX is left alone so front end requests keep working.
     */
    public static function keepMembersOutOfAdmin(): void
    {
        if (wp_doing_ajax() || !is_user_logged_in()) {
            return;
        }

        $user = wp_get_current_user();

        if (user_can($user, 'edit_posts') || !user_can($user, 'hilg_vault_access')) {
            return;
        }

        wp_safe_redirect(self::memberAreaUrl());
        exit;
    }

    public static function hideAdminBarForMembers(bool $show): bool
    {
        if (!is_user_logged_in()) {
            return $show;
        }

        $user = wp_get_current_user();

        if (user_can($user, 'edit_posts') || !user_can($user, 'hilg_vault_access')) {
            return $show;
        }

        return false;
    }

    private static function memberAreaUrl(): string
    {
        $page = get_page_by_path('my-area');

        return $page instanceof \WP_Post ? (string) get_permalink($page) : home_url('/');
    }

    public static function registerAssets(): void
    {
        $file = HILG_VAULT_DIR . 'assets/members.css';
        $ver  = is_readable($file) ? (string) filemtime($file) : \ClarityWeb\HilgVault\VERSION;

        wp_register_style('hilg-members', HILG_VAULT_URL . 'assets/members.css', [], $ver);
    }

    // -----------------------------------------------------------------
    // Form handling
    // -----------------------------------------------------------------

    public static function handleSubmissions(): void
    {
        if (!is_user_logged_in() || empty($_POST['hilg_member_action'])) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['hilg_member_action']));

        match ($action) {
            'save_profile' => self::saveProfile(),
            'save_content' => self::saveContent(),
            'trash_content' => self::trashContent(),
            default        => null,
        };
    }

    private static function saveProfile(): void
    {
        check_admin_referer(self::NONCE_PROFILE);

        $user = wp_get_current_user();

        if (!user_can($user, 'hilg_manage_own_profile')) {
            self::redirect('forbidden');
        }

        $post = wp_unslash($_POST);

        $result = wp_update_user([
            'ID'           => $user->ID,
            'display_name' => sanitize_text_field((string) ($post['display_name'] ?? '')),
            'first_name'   => sanitize_text_field((string) ($post['first_name'] ?? '')),
            'last_name'    => sanitize_text_field((string) ($post['last_name'] ?? '')),
            'user_url'     => esc_url_raw((string) ($post['user_url'] ?? '')),
            'description'  => sanitize_textarea_field((string) ($post['description'] ?? '')),
        ]);

        if (is_wp_error($result)) {
            self::redirect('profile_failed');
        }

        // Organisation is stored separately: it belongs to the member's role in
        // the network, not to their WordPress account.
        update_user_meta(
            $user->ID,
            'hilg_organisation',
            sanitize_text_field((string) ($post['organisation'] ?? ''))
        );

        self::redirect('profile_saved');
    }

    private static function saveContent(): void
    {
        check_admin_referer(self::NONCE_CONTENT);

        $user = wp_get_current_user();

        if (!user_can($user, 'hilg_manage_own_content')) {
            self::redirect('forbidden');
        }

        $post    = wp_unslash($_POST);
        $postId  = isset($post['post_id']) ? absint($post['post_id']) : 0;
        $title   = sanitize_text_field((string) ($post['title'] ?? ''));
        $content = wp_kses_post((string) ($post['content'] ?? ''));

        if ($title === '') {
            self::redirect('content_needs_title');
        }

        if ($postId > 0) {
            // Ownership is re-checked here rather than trusted from the form.
            $existing = get_post($postId);

            if (!$existing instanceof \WP_Post || (int) $existing->post_author !== $user->ID) {
                self::redirect('forbidden');
            }

            wp_update_post([
                'ID'           => $postId,
                'post_title'   => $title,
                'post_content' => $content,
            ]);

            self::redirect('content_saved');
        }

        // New submissions land as pending. An external contributor publishing
        // straight to a public body's website without review is not a feature.
        $created = wp_insert_post([
            'post_type'    => self::postType(),
            'post_status'  => 'pending',
            'post_title'   => $title,
            'post_content' => $content,
            'post_author'  => $user->ID,
        ], true);

        self::redirect(is_wp_error($created) ? 'content_failed' : 'content_submitted');
    }

    private static function trashContent(): void
    {
        check_admin_referer(self::NONCE_CONTENT);

        $user   = wp_get_current_user();
        $postId = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
        $post   = $postId > 0 ? get_post($postId) : null;

        if (!$post instanceof \WP_Post || (int) $post->post_author !== $user->ID) {
            self::redirect('forbidden');
        }

        wp_trash_post($postId);
        self::redirect('content_trashed');
    }

    /**
     * Returns the member to the page they submitted from.
     *
     * wp_get_referer() is not usable here. It deliberately returns false when
     * the referer matches the current request, which is exactly the case for a
     * form that posts back to its own page, so the fallback would fire every
     * single time. The return URL is carried in the form instead and validated
     * against this site before use.
     */
    private static function redirect(string $notice): never
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify the nonce first.
        $submitted = isset($_POST['hilg_return']) ? esc_url_raw(wp_unslash($_POST['hilg_return'])) : '';

        $home = home_url('/');
        $url  = $home;

        if ($submitted !== '' && str_starts_with($submitted, home_url())) {
            $url = $submitted;
        }

        $url = remove_query_arg(['hilg_edit', 'hilg_notice'], $url);

        wp_safe_redirect(add_query_arg('hilg_notice', $notice, $url));
        exit;
    }

    /**
     * Hidden field carrying the page to come back to.
     */
    private static function returnField(): void
    {
        $current = get_permalink();

        if (!is_string($current) || $current === '') {
            $current = home_url('/');
        }

        printf('<input type="hidden" name="hilg_return" value="%s">', esc_url($current));
    }

    // -----------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed>|string $atts
     */
    public static function shortcode(array|string $atts): string
    {
        wp_enqueue_style('hilg-members');

        if (!is_user_logged_in()) {
            return sprintf(
                '<div class="hilg-member"><p class="hilg-member__notice">%s</p><p><a class="hilg-member__button" href="%s">%s</a></p></div>',
                esc_html__('Sign in to manage your profile and your content.', 'hilg-vault'),
                esc_url(wp_login_url(get_permalink() ?: home_url('/'))),
                esc_html__('Sign in', 'hilg-vault')
            );
        }

        $user = wp_get_current_user();

        if (!user_can($user, 'hilg_vault_access')) {
            return '<div class="hilg-member"><p class="hilg-member__notice">'
                . esc_html__('Your account is not part of the network area.', 'hilg-vault')
                . '</p></div>';
        }

        ob_start();

        echo '<div class="hilg-member">';

        self::notice();
        self::profileSection($user);
        self::contentSection($user);
        self::foldersSection($user);

        echo '</div>';

        return (string) ob_get_clean();
    }

    private static function notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        if (empty($_GET['hilg_notice'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $key = sanitize_key(wp_unslash($_GET['hilg_notice']));

        $messages = [
            'profile_saved'       => [__('Your profile has been updated.', 'hilg-vault'), 'success'],
            'profile_failed'      => [__('Your profile could not be saved.', 'hilg-vault'), 'error'],
            'content_submitted'   => [__('Thank you. Your submission is with the team for review.', 'hilg-vault'), 'success'],
            'content_saved'       => [__('Your changes have been saved.', 'hilg-vault'), 'success'],
            'content_trashed'     => [__('That item has been removed.', 'hilg-vault'), 'success'],
            'content_needs_title' => [__('Please add a title before submitting.', 'hilg-vault'), 'error'],
            'content_failed'      => [__('That could not be saved. Please try again.', 'hilg-vault'), 'error'],
            'forbidden'           => [__('You do not have permission to do that.', 'hilg-vault'), 'error'],
        ];

        if (!isset($messages[$key])) {
            return;
        }

        [$message, $tone] = $messages[$key];

        printf(
            '<p class="hilg-member__flash hilg-member__flash--%s" role="status">%s</p>',
            esc_attr($tone),
            esc_html($message)
        );
    }

    private static function profileSection(\WP_User $user): void
    {
        ?>
        <section class="hilg-member__section">
            <h2><?php esc_html_e('Your profile', 'hilg-vault'); ?></h2>

            <form method="post" class="hilg-member__form">
                <input type="hidden" name="hilg_member_action" value="save_profile">
                <?php wp_nonce_field(self::NONCE_PROFILE); ?>
                <?php self::returnField(); ?>

                <div class="hilg-member__grid">
                    <p>
                        <label for="hilg-first-name"><?php esc_html_e('First name', 'hilg-vault'); ?></label>
                        <input type="text" id="hilg-first-name" name="first_name"
                               value="<?php echo esc_attr($user->first_name); ?>">
                    </p>
                    <p>
                        <label for="hilg-last-name"><?php esc_html_e('Last name', 'hilg-vault'); ?></label>
                        <input type="text" id="hilg-last-name" name="last_name"
                               value="<?php echo esc_attr($user->last_name); ?>">
                    </p>
                </div>

                <p>
                    <label for="hilg-display-name"><?php esc_html_e('Name shown on the site', 'hilg-vault'); ?></label>
                    <input type="text" id="hilg-display-name" name="display_name"
                           value="<?php echo esc_attr($user->display_name); ?>">
                </p>

                <p>
                    <label for="hilg-organisation"><?php esc_html_e('Organisation', 'hilg-vault'); ?></label>
                    <input type="text" id="hilg-organisation" name="organisation"
                           value="<?php echo esc_attr((string) get_user_meta($user->ID, 'hilg_organisation', true)); ?>">
                </p>

                <p>
                    <label for="hilg-url"><?php esc_html_e('Website', 'hilg-vault'); ?></label>
                    <input type="url" id="hilg-url" name="user_url"
                           value="<?php echo esc_attr($user->user_url); ?>" placeholder="https://">
                </p>

                <p>
                    <label for="hilg-bio"><?php esc_html_e('About you', 'hilg-vault'); ?></label>
                    <textarea id="hilg-bio" name="description" rows="4"><?php
                        echo esc_textarea((string) get_user_meta($user->ID, 'description', true));
                    ?></textarea>
                </p>

                <p>
                    <button type="submit" class="hilg-member__button"><?php esc_html_e('Save profile', 'hilg-vault'); ?></button>
                </p>
            </form>
        </section>
        <?php
    }

    private static function contentSection(\WP_User $user): void
    {
        if (!user_can($user, 'hilg_manage_own_content')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
        $editing = isset($_GET['hilg_edit']) ? absint(wp_unslash($_GET['hilg_edit'])) : 0;
        $editPost = null;

        if ($editing > 0) {
            $candidate = get_post($editing);

            // Even opening the editor is checked. The form cannot be reached
            // for someone else's post by editing the URL.
            if ($candidate instanceof \WP_Post && (int) $candidate->post_author === $user->ID) {
                $editPost = $candidate;
            }
        }

        $posts = get_posts([
            'post_type'      => self::postType(),
            'author'         => $user->ID,
            'post_status'    => ['publish', 'pending', 'draft'],
            'posts_per_page' => 25,
        ]);

        ?>
        <section class="hilg-member__section">
            <h2><?php esc_html_e('Your content', 'hilg-vault'); ?></h2>

            <form method="post" class="hilg-member__form">
                <input type="hidden" name="hilg_member_action" value="save_content">
                <input type="hidden" name="post_id" value="<?php echo (int) ($editPost->ID ?? 0); ?>">
                <?php wp_nonce_field(self::NONCE_CONTENT); ?>
                <?php self::returnField(); ?>

                <p>
                    <label for="hilg-title"><?php esc_html_e('Title', 'hilg-vault'); ?></label>
                    <input type="text" id="hilg-title" name="title" required
                           value="<?php echo esc_attr($editPost->post_title ?? ''); ?>">
                </p>

                <p>
                    <label for="hilg-content"><?php esc_html_e('Content', 'hilg-vault'); ?></label>
                    <textarea id="hilg-content" name="content" rows="8"><?php
                        echo esc_textarea($editPost->post_content ?? '');
                    ?></textarea>
                </p>

                <p class="hilg-member__hint">
                    <?php esc_html_e('New submissions are reviewed by the team before they appear on the site.', 'hilg-vault'); ?>
                </p>

                <p>
                    <button type="submit" class="hilg-member__button">
                        <?php echo $editPost ? esc_html__('Save changes', 'hilg-vault') : esc_html__('Submit for review', 'hilg-vault'); ?>
                    </button>
                    <?php if ($editPost) : ?>
                        <a class="hilg-member__link" href="<?php echo esc_url(remove_query_arg(['hilg_edit', 'hilg_notice'])); ?>">
                            <?php esc_html_e('Cancel editing', 'hilg-vault'); ?>
                        </a>
                    <?php endif; ?>
                </p>
            </form>

            <?php if ($posts === []) : ?>
                <p class="hilg-member__notice"><?php esc_html_e('You have not submitted anything yet.', 'hilg-vault'); ?></p>
            <?php else : ?>
                <ul class="hilg-member__list">
                    <?php foreach ($posts as $post) : ?>
                        <li class="hilg-member__item">
                            <span class="hilg-member__item-title"><?php echo esc_html($post->post_title); ?></span>
                            <span class="hilg-member__status hilg-member__status--<?php echo esc_attr($post->post_status); ?>">
                                <?php echo esc_html(self::statusLabel($post->post_status)); ?>
                            </span>
                            <span class="hilg-member__item-actions">
                                <a href="<?php echo esc_url(add_query_arg('hilg_edit', $post->ID, remove_query_arg('hilg_notice'))); ?>">
                                    <?php esc_html_e('Edit', 'hilg-vault'); ?>
                                </a>
                                <form method="post" class="hilg-member__inline">
                                    <input type="hidden" name="hilg_member_action" value="trash_content">
                                    <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>">
                                    <?php wp_nonce_field(self::NONCE_CONTENT); ?>
                                    <?php self::returnField(); ?>
                <?php self::returnField(); ?>
                                    <button type="submit" class="hilg-member__link hilg-member__link--danger">
                                        <?php esc_html_e('Remove', 'hilg-vault'); ?>
                                    </button>
                                </form>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * Folders granted to this member through the role matrix.
     *
     * The question here is "what was given to you", not "what can you see".
     * They are different: a public folder is open to everyone, and a password
     * protected one opens for anyone who knows the password. Neither was
     * shared with this person, so listing them under "shared with you" would
     * misrepresent what the member actually holds.
     */
    private static function foldersSection(\WP_User $user): void
    {
        global $wpdb;

        $folders = Schema::tableFolders();

        $rows = $wpdb->get_results(
            "SELECT id, name FROM {$folders} WHERE deleted_at IS NULL ORDER BY path",
            ARRAY_A
        );

        $roles     = array_values($user->roles);
        $available = [];

        foreach ((array) $rows as $row) {
            $id    = (int) $row['id'];
            $grant = AccessPolicy::effectiveGrant($id, $roles);

            if ($grant !== null && $grant['can_view']) {
                $available[] = ['id' => $id, 'name' => (string) $row['name']];
            }
        }

        if ($available === []) {
            return;
        }

        ?>
        <section class="hilg-member__section">
            <h2><?php esc_html_e('Files shared with you', 'hilg-vault'); ?></h2>
            <ul class="hilg-member__folders">
                <?php foreach ($available as $folder) : ?>
                    <li>
                        <a href="<?php echo esc_url(add_query_arg('hilg_folder', $folder['id'], self::filesPageUrl())); ?>">
                            <?php echo esc_html($folder['name']); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
    }

    private static function filesPageUrl(): string
    {
        $page = get_page_by_path('resources');

        return $page instanceof \WP_Post ? (string) get_permalink($page) : home_url('/');
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'publish' => __('Published', 'hilg-vault'),
            'pending' => __('Awaiting review', 'hilg-vault'),
            'draft'   => __('Draft', 'hilg-vault'),
            default   => $status,
        };
    }
}
