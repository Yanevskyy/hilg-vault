<?php
/**
 * Cached view of the learning catalogue.
 *
 * Two layers, on purpose:
 *
 *   1. a short lived cache, so an editor opening the block picker does not
 *      trigger a request to the platform every time,
 *   2. a last known good snapshot that never expires.
 *
 * When the platform is unreachable, the snapshot is served and clearly marked
 * as stale, with the time it was captured. That is the honest answer: it does
 * not invent content, and it does not show an empty page implying the client's
 * courses have disappeared. A public body publishing training material cannot
 * have its course list vanish because someone else's API had a bad afternoon.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Lms;

defined('ABSPATH') || exit;

final class Catalogue
{
    public const OPTION_SETTINGS = 'hilg_vault_lms_settings';
    public const OPTION_SNAPSHOT = 'hilg_vault_lms_snapshot';

    private const CACHE_KEY = 'hilg_vault_lms_modules';

    /**
     * Longer than the refresh interval, on purpose.
     *
     * It used to be fifteen minutes against an hourly cron, which meant the
     * cache was empty for three quarters of every hour and the visitor who
     * arrived first paid for the round trip: up to twelve seconds of page load
     * because somebody else's API was slow. The scheduled job should be what
     * refreshes this, and the cache should still be warm when it does. Two
     * hours leaves an hour of margin for a cron that runs late.
     */
    private const CACHE_TTL = 2 * HOUR_IN_SECONDS;

    /** Hook used to refresh in the background rather than during a page load. */
    private const REFRESH_HOOK = 'hilg_vault_lms_refresh_async';

    public const CRON_HOOK = 'hilg_vault_lms_sync';

    public static function register(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'sync']);
        add_action(self::REFRESH_HOOK, [self::class, 'sync']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
    }

    /**
     * Asks for a refresh soon, without doing it now.
     *
     * Serving a slightly old catalogue immediately is better for the visitor
     * than a fresh one they waited twelve seconds for, and the difference
     * matters most exactly when the platform is slow.
     */
    private static function scheduleRefresh(): void
    {
        if (!wp_next_scheduled(self::REFRESH_HOOK)) {
            wp_schedule_single_event(time() + 30, self::REFRESH_HOOK);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION_SETTINGS, []);

        return is_array($stored) ? $stored : [];
    }

    public static function provider(): ?LmsProvider
    {
        $settings = self::settings();

        if (empty($settings['base_url'])) {
            return null;
        }

        // The provider class is filterable so a platform specific
        // implementation can replace the generic one without a code change
        // here.
        $provider = apply_filters('hilg_vault_lms_provider', null, $settings);

        return $provider instanceof LmsProvider ? $provider : new RestProvider($settings);
    }

    /**
     * Modules, with an honest description of where they came from.
     *
     * @param bool $force Bypass the short cache, for the refresh button.
     *
     * @return array{
     *   modules:array<int,array<string,mixed>>,
     *   stale:bool,
     *   configured:bool,
     *   captured_at:string,
     *   error:string
     * }
     */
    public static function modules(bool $force = false): array
    {
        $provider = self::provider();

        if ($provider === null) {
            return self::result([], false, false, '', '');
        }

        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);

            if (is_array($cached)) {
                return self::result($cached['modules'], false, true, $cached['captured_at'], '');
            }

            // Nothing cached, but a snapshot exists. Serve it and refresh in
            // the background: a page render is the wrong place to wait on
            // somebody else's API, and the snapshot is what the visitor would
            // have seen a moment ago anyway.
            $snapshot = self::snapshot();

            if (!empty($snapshot['modules']) && !wp_doing_cron() && !is_admin()) {
                self::scheduleRefresh();

                return self::result(
                    $snapshot['modules'],
                    false,
                    true,
                    (string) ($snapshot['captured_at'] ?? ''),
                    ''
                );
            }
        }

        try {
            $modules = $provider->modules();
        } catch (LmsUnavailable $error) {
            $snapshot = self::snapshot();

            // No snapshot and no connection means we genuinely know nothing.
            // Saying so is the only truthful option.
            return self::result(
                $snapshot['modules'] ?? [],
                true,
                true,
                $snapshot['captured_at'] ?? '',
                $error->getMessage()
            );
        }

        $capturedAt = current_time('mysql', true);

        set_transient(self::CACHE_KEY, ['modules' => $modules, 'captured_at' => $capturedAt], self::CACHE_TTL);
        self::storeSnapshot($modules, $capturedAt);

        return self::result($modules, false, true, $capturedAt, '');
    }

    /**
     * Lessons for one module, with the same stale handling.
     *
     * @return array{lessons:array<int,array<string,mixed>>,stale:bool,error:string,captured_at:string}
     */
    public static function lessons(string $moduleId, bool $force = false): array
    {
        $provider = self::provider();

        if ($provider === null) {
            return ['lessons' => [], 'stale' => false, 'error' => '', 'captured_at' => ''];
        }

        $key = self::CACHE_KEY . '_lessons_' . md5($moduleId);

        if (!$force) {
            $cached = get_transient($key);

            if (is_array($cached)) {
                return [
                    'lessons'     => $cached['lessons'],
                    'stale'       => false,
                    'error'       => '',
                    'captured_at' => $cached['captured_at'],
                ];
            }

            $snapshot = self::snapshot();
            $stored   = $snapshot['lessons'][$moduleId] ?? null;

            if (is_array($stored) && $stored['items'] !== [] && !wp_doing_cron() && !is_admin()) {
                self::scheduleRefresh();

                return [
                    'lessons'     => $stored['items'],
                    'stale'       => false,
                    'error'       => '',
                    'captured_at' => (string) $stored['captured_at'],
                ];
            }
        }

        try {
            $lessons = $provider->lessons($moduleId);
        } catch (LmsUnavailable $error) {
            $snapshot = self::snapshot();
            $stored   = $snapshot['lessons'][$moduleId] ?? null;

            return [
                'lessons'     => is_array($stored) ? $stored['items'] : [],
                'stale'       => true,
                'error'       => $error->getMessage(),
                'captured_at' => is_array($stored) ? (string) $stored['captured_at'] : '',
            ];
        }

        $capturedAt = current_time('mysql', true);

        set_transient($key, ['lessons' => $lessons, 'captured_at' => $capturedAt], self::CACHE_TTL);
        self::storeLessonSnapshot($moduleId, $lessons, $capturedAt);

        return ['lessons' => $lessons, 'stale' => false, 'error' => '', 'captured_at' => $capturedAt];
    }

    /**
     * Scheduled refresh. Keeps the snapshot current so an outage during
     * business hours is served from something recent.
     */
    public static function sync(): void
    {
        $result = self::modules(true);

        if ($result['stale'] || $result['modules'] === []) {
            return;
        }

        // Refresh lessons only for modules already placed on a page. Walking
        // the whole catalogue every hour would be pointless traffic against
        // someone else's platform.
        foreach (self::modulesInUse() as $moduleId) {
            self::lessons($moduleId, true);
        }
    }

    /**
     * @return array<int,string>
     */
    private static function modulesInUse(): array
    {
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_hilg_lms_module' AND meta_value <> ''"
        );

        return array_map('strval', (array) $ids);
    }

    // -----------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private static function snapshot(): array
    {
        $stored = get_option(self::OPTION_SNAPSHOT, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @param array<int,array<string,mixed>> $modules
     */
    private static function storeSnapshot(array $modules, string $capturedAt): void
    {
        $snapshot = self::snapshot();

        $snapshot['modules']     = $modules;
        $snapshot['captured_at'] = $capturedAt;

        update_option(self::OPTION_SNAPSHOT, $snapshot, false);
    }

    /**
     * @param array<int,array<string,mixed>> $lessons
     */
    private static function storeLessonSnapshot(string $moduleId, array $lessons, string $capturedAt): void
    {
        $snapshot = self::snapshot();

        $snapshot['lessons'] ??= [];
        $snapshot['lessons'][$moduleId] = ['items' => $lessons, 'captured_at' => $capturedAt];

        update_option(self::OPTION_SNAPSHOT, $snapshot, false);
    }

    /**
     * @param array<int,array<string,mixed>> $modules
     * @return array<string,mixed>
     */
    private static function result(
        array $modules,
        bool $stale,
        bool $configured,
        string $capturedAt,
        string $error,
    ): array {
        return [
            'modules'     => $modules,
            'stale'       => $stale,
            'configured'  => $configured,
            'captured_at' => $capturedAt,
            'error'       => $error,
        ];
    }

    public static function flush(): void
    {
        delete_transient(self::CACHE_KEY);

        global $wpdb;

        // Lesson caches are keyed per module, so they are cleared by prefix.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_' . self::CACHE_KEY . '_lessons_') . '%'
            )
        );
    }
}
