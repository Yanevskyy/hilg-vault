<?php
/**
 * Renders learning content on a page.
 *
 * Two ways in, both driven by the same data: a Gutenberg block where an
 * administrator picks from an automatically populated list, and a shortcode
 * for pages built outside the editor.
 *
 * When the platform is unreachable the last known content is shown with a
 * plain note saying when it was captured. The alternative, an empty block or
 * an error, punishes the visitor for a problem on someone else's server.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Lms;

defined('ABSPATH') || exit;

final class LmsRenderer
{
    public static function register(): void
    {
        add_shortcode('hilg_lms', [self::class, 'shortcode']);
        add_action('init', [self::class, 'registerBlock']);
        add_action('wp_enqueue_scripts', [self::class, 'registerAssets']);
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerAssets(): void
    {
        $file = HILG_VAULT_DIR . 'assets/lms.css';
        $ver  = is_readable($file) ? (string) filemtime($file) : \ClarityWeb\HilgVault\VERSION;

        wp_register_style('hilg-lms', HILG_VAULT_URL . 'assets/lms.css', [], $ver);
    }

    public static function registerRoutes(): void
    {
        // Powers the picker in the editor: the list is fetched, never typed in
        // by hand, which is exactly what the brief asks for.
        register_rest_route('hilg-vault/v1', '/lms/modules', [
            'methods'             => 'GET',
            'callback'            => static function (\WP_REST_Request $request): \WP_REST_Response {
                $result = Catalogue::modules((bool) $request->get_param('refresh'));

                return new \WP_REST_Response($result);
            },
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
        ]);

        register_rest_route('hilg-vault/v1', '/lms/modules/(?P<id>[^/]+)/lessons', [
            'methods'             => 'GET',
            'callback'            => static function (\WP_REST_Request $request): \WP_REST_Response {
                $result = Catalogue::lessons((string) $request->get_param('id'), (bool) $request->get_param('refresh'));

                return new \WP_REST_Response($result);
            },
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
        ]);
    }

    public static function registerBlock(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        register_block_type('hilg-vault/lms', [
            'api_version'     => 3,
            'title'           => __('Learning Module', 'hilg-vault'),
            'category'        => 'widgets',
            'icon'            => 'welcome-learn-more',
            'description'     => __('Shows a module or a single lesson from the learning platform.', 'hilg-vault'),
            'attributes'      => [
                'moduleId'    => ['type' => 'string', 'default' => ''],
                'lessonId'    => ['type' => 'string', 'default' => ''],
                'showLessons' => ['type' => 'boolean', 'default' => true],
            ],
            'render_callback' => static fn(array $attributes): string => self::render([
                'module'       => (string) ($attributes['moduleId'] ?? ''),
                'lesson'       => (string) ($attributes['lessonId'] ?? ''),
                'show_lessons' => !empty($attributes['showLessons']),
            ]),
        ]);
    }

    /**
     * @param array<string,mixed>|string $atts
     */
    public static function shortcode(array|string $atts): string
    {
        $atts = shortcode_atts(
            ['module' => '', 'lesson' => '', 'show_lessons' => 'yes'],
            is_array($atts) ? $atts : [],
            'hilg_lms'
        );

        return self::render([
            'module'       => (string) $atts['module'],
            'lesson'       => (string) $atts['lesson'],
            'show_lessons' => $atts['show_lessons'] !== 'no',
        ]);
    }

    /**
     * @param array{module:string,lesson:string,show_lessons:bool} $args
     */
    public static function render(array $args): string
    {
        if ($args['module'] === '') {
            return '';
        }

        wp_enqueue_style('hilg-lms');

        $catalogue = Catalogue::modules();
        $module    = null;

        foreach ($catalogue['modules'] as $candidate) {
            if ((string) $candidate['id'] === $args['module']) {
                $module = $candidate;

                break;
            }
        }

        if ($module === null) {
            // The module is genuinely not in the catalogue. Editors need to
            // know; visitors do not need to see a broken block.
            if (current_user_can('edit_posts')) {
                return '<p class="hilg-lms__notice">'
                    . esc_html__('This learning module is no longer in the platform catalogue.', 'hilg-vault')
                    . '</p>';
            }

            return '';
        }

        $lessons = [];
        $stale   = $catalogue['stale'];
        $capturedAt = (string) $catalogue['captured_at'];

        if ($args['show_lessons'] || $args['lesson'] !== '') {
            $result  = Catalogue::lessons($args['module']);
            $lessons = $result['lessons'];

            if ($result['stale']) {
                $stale = true;
                $capturedAt = $capturedAt ?: (string) $result['captured_at'];
            }
        }

        if ($args['lesson'] !== '') {
            $lessons = array_values(array_filter(
                $lessons,
                static fn(array $lesson): bool => (string) $lesson['id'] === $args['lesson']
            ));
        }

        ob_start();

        echo '<section class="hilg-lms">';

        self::heading($module, $args['lesson'] !== '' && $lessons !== [] ? $lessons[0] : null);

        if ($lessons !== []) {
            self::lessonList($lessons, $args['lesson'] !== '');
        }

        if ($stale) {
            self::staleNotice($capturedAt);
        }

        echo '</section>';

        return (string) ob_get_clean();
    }

    /**
     * @param array<string,mixed>      $module
     * @param array<string,mixed>|null $lesson
     */
    private static function heading(array $module, ?array $lesson): void
    {
        $title = $lesson !== null ? (string) $lesson['title'] : (string) $module['title'];
        $url   = $lesson !== null && $lesson['url'] !== '' ? (string) $lesson['url'] : (string) $module['url'];

        echo '<header class="hilg-lms__head">';
        printf('<p class="hilg-lms__eyebrow">%s</p>', esc_html__('Learning module', 'hilg-vault'));
        printf('<h3 class="hilg-lms__title">%s</h3>', esc_html($title));

        if ($lesson === null && $module['description'] !== '') {
            printf('<p class="hilg-lms__summary">%s</p>', esc_html((string) $module['description']));
        }

        if ($lesson !== null && $lesson['summary'] !== '') {
            printf('<p class="hilg-lms__summary">%s</p>', esc_html((string) $lesson['summary']));
        }

        $meta = [];

        if ($lesson === null && (int) $module['lesson_count'] > 0) {
            $meta[] = sprintf(
                /* translators: %d: number of lessons. */
                _n('%d lesson', '%d lessons', (int) $module['lesson_count'], 'hilg-vault'),
                (int) $module['lesson_count']
            );
        }

        $duration = $lesson !== null ? (int) $lesson['duration'] : (int) $module['duration'];

        if ($duration > 0) {
            $meta[] = sprintf(
                /* translators: %d: duration in minutes. */
                __('%d minutes', 'hilg-vault'),
                $duration
            );
        }

        if ($meta !== []) {
            printf('<p class="hilg-lms__meta">%s</p>', esc_html(implode(' · ', $meta)));
        }

        if ($url !== '') {
            printf(
                '<p><a class="hilg-lms__cta" href="%s" rel="noopener">%s</a></p>',
                esc_url($url),
                esc_html__('Open in the learning platform', 'hilg-vault')
            );
        }

        echo '</header>';
    }

    /**
     * @param array<int,array<string,mixed>> $lessons
     */
    private static function lessonList(array $lessons, bool $singleLesson): void
    {
        if ($singleLesson) {
            return;
        }

        echo '<ol class="hilg-lms__lessons">';

        foreach ($lessons as $lesson) {
            echo '<li class="hilg-lms__lesson">';

            $title = esc_html((string) $lesson['title']);

            if ($lesson['url'] !== '') {
                printf('<a href="%s" rel="noopener">%s</a>', esc_url((string) $lesson['url']), $title);
            } else {
                printf('<span class="hilg-lms__lesson-title">%s</span>', $title);
            }

            if ((int) $lesson['duration'] > 0) {
                printf(
                    '<span class="hilg-lms__lesson-meta">%s</span>',
                    esc_html(sprintf(
                        /* translators: %d: duration in minutes. */
                        __('%d min', 'hilg-vault'),
                        (int) $lesson['duration']
                    ))
                );
            }

            if ($lesson['summary'] !== '') {
                printf('<p class="hilg-lms__lesson-summary">%s</p>', esc_html((string) $lesson['summary']));
            }

            echo '</li>';
        }

        echo '</ol>';
    }

    /**
     * Says plainly that the platform could not be reached and when this content
     * was captured. No invented data, no blank space pretending all is well.
     */
    private static function staleNotice(string $capturedAt): void
    {
        $when = '';

        if ($capturedAt !== '') {
            $timestamp = strtotime($capturedAt . ' UTC');

            if ($timestamp !== false) {
                $when = wp_date(get_option('date_format') . ', ' . get_option('time_format'), $timestamp);
            }
        }

        echo '<p class="hilg-lms__stale">';

        if ($when !== '') {
            printf(
                /* translators: %s: date and time the content was last retrieved. */
                esc_html__('The learning platform could not be reached. Showing the version saved on %s.', 'hilg-vault'),
                esc_html($when)
            );
        } else {
            esc_html_e('The learning platform could not be reached just now.', 'hilg-vault');
        }

        echo '</p>';
    }
}
