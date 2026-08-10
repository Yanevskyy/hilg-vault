<?php
/**
 * Plugin bootstrap.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault;

use ClarityWeb\HilgVault\Access\AccessPolicy;
use ClarityWeb\HilgVault\Install\Schema;
use ClarityWeb\HilgVault\Storage\S3Client;

defined('ABSPATH') || exit;

final class Plugin
{
    private static ?self $instance = null;

    private ?S3Client $storage = null;

    private bool $booted = false;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('init', [$this, 'onInit']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);

        Admin\AdminPage::register();
        Admin\MaintenancePage::register();
        Maintenance\Housekeeping::register();
        Frontend\VaultRenderer::register();

        Lms\Catalogue::register();
        Lms\LmsRenderer::register();
        Lms\LmsSettingsPage::register();

        Members\MemberArea::register();
    }

    public function onInit(): void
    {
        load_plugin_textdomain('hilg-vault', false, dirname(plugin_basename(PLUGIN_FILE)) . '/languages');

        // Survives a manual plugin update where the activation hook never fires.
        Schema::maybeUpgrade();
    }

    /**
     * Storage is resolved lazily and may legitimately be absent.
     *
     * When it is absent the plugin says so plainly. It does not fall back to
     * the media library, because doing that would quietly defeat the entire
     * reason this plugin exists.
     */
    public function storage(): ?S3Client
    {
        return $this->storage ??= S3Client::fromEnvironment();
    }

    public function registerRestRoutes(): void
    {
        Rest\ManageController::register();

        $namespace = 'hilg-vault/v1';

        register_rest_route($namespace, '/folders', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restListFolders'],
            'permission_callback' => '__return_true',
            'args'                => [
                'parent' => [
                    'type'              => 'integer',
                    'required'          => false,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route($namespace, '/folders/(?P<id>\d+)/files', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restListFiles'],
            'permission_callback' => '__return_true',
        ]);

        // Breadcrumbs are served separately so client side navigation can keep
        // the trail in step with the listing. Without this the trail only ever
        // reflects the server rendered page, and after a few clicks the only
        // way back up is the browser's back button.
        register_rest_route($namespace, '/folders/(?P<id>\d+)/breadcrumbs', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restBreadcrumbs'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/files/(?P<id>\d+)/download', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restDownload'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/unlock/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'restUnlock'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restStatus'],
            'permission_callback' => static fn(): bool => current_user_can('hilg_manage_vault'),
        ]);
    }

    public function restListFolders(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $table  = Schema::tableFolders();
        $parent = $request->get_param('parent');

        $rows = $parent
            ? $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE parent_id = %d AND deleted_at IS NULL ORDER BY sort_order, name",
                    (int) $parent
                ),
                ARRAY_A
            )
            : $wpdb->get_results(
                "SELECT * FROM {$table} WHERE parent_id IS NULL AND deleted_at IS NULL ORDER BY sort_order, name",
                ARRAY_A
            );

        // Which of these folders have children, answered once for the whole
        // list. Asking per row turned a listing of twenty folders into twenty
        // extra queries, on a page that is drawn on every navigation.
        $withChildren = self::childBearing(array_map(
            static fn(array $row): int => (int) $row['id'],
            (array) $rows
        ));

        $visible = [];

        foreach ((array) $rows as $row) {
            $id = (int) $row['id'];

            if (!AccessPolicy::canViewFolder($id)) {
                // A password protected folder is still announced, otherwise a
                // visitor has no way to know a password would help. Its
                // contents stay closed until the password is entered.
                if (AccessPolicy::effectiveMode($row) === AccessPolicy::MODE_PASSWORD) {
                    $visible[] = [
                        'id'     => $id,
                        'name'   => $row['name'],
                        'locked' => true,
                        'mode'   => AccessPolicy::MODE_PASSWORD,
                    ];
                }

                continue;
            }

            $visible[] = [
                'id'           => $id,
                'name'         => $row['name'],
                'path'         => $row['path'],
                'locked'       => false,
                'mode'         => AccessPolicy::effectiveMode($row),
                // The admin tree needs to know whether to render a child list.
                'has_children' => isset($withChildren[$id]),
            ];
        }

        return new \WP_REST_Response($visible);
    }

    public function restBreadcrumbs(\WP_REST_Request $request): \WP_REST_Response
    {
        $folderId = (int) $request->get_param('id');

        if (!AccessPolicy::canViewFolder($folderId)) {
            return new \WP_REST_Response([], 200);
        }

        return new \WP_REST_Response(Model\FolderRepository::breadcrumbs($folderId));
    }

    public function restListFiles(\WP_REST_Request $request): \WP_REST_Response
    {
        $folderId = (int) $request->get_param('id');

        if (!AccessPolicy::canViewFolder($folderId)) {
            AccessPolicy::log('list_files', 'denied', $folderId);

            return new \WP_REST_Response(['error' => 'forbidden'], 403);
        }

        global $wpdb;

        $table = Schema::tableFiles();

        $page    = max(1, (int) ($request->get_param('page') ?: 1));
        $search  = (string) ($request->get_param('search') ?: '');
        $perPage = (int) ($request->get_param('per_page') ?: Model\FileRepository::PER_PAGE);

        // Ordering is chosen by the caller from a fixed set; the repository
        // falls back to name for anything it does not recognise, so an invented
        // value cannot reach the query.
        $orderBy = (string) ($request->get_param('orderby') ?: 'name');

        $rows  = Model\FileRepository::listByFolder($folderId, $search, $orderBy, $page, $perPage);
        $total = Model\FileRepository::countByFolder($folderId, $search);

        $response = new \WP_REST_Response($rows);

        // Standard pagination headers, so a client knows there is more without
        // having to guess from the row count.
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) ($perPage > 0 ? (int) ceil($total / $perPage) : 1));

        return $response;
    }

    /**
     * Mints a short lived download URL, and only after re-running the same
     * permission check that produced the listing.
     */
    public function restDownload(\WP_REST_Request $request): \WP_REST_Response
    {
        $fileId = (int) $request->get_param('id');

        if (!AccessPolicy::canDownloadFile($fileId)) {
            AccessPolicy::log('download', 'denied', null, $fileId);

            return new \WP_REST_Response(['error' => 'forbidden'], 403);
        }

        $storage = $this->storage();

        if ($storage === null) {
            return new \WP_REST_Response(
                ['error' => 'storage_unavailable', 'message' => 'Object storage is not configured.'],
                503
            );
        }

        global $wpdb;

        $table = Schema::tableFiles();

        $file = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $fileId),
            ARRAY_A
        );

        if (!is_array($file)) {
            return new \WP_REST_Response(['error' => 'not_found'], 404);
        }

        // Confirm the object is actually there before handing out a link.
        //
        // One request to storage, only on download, never on listing. Without
        // it an outage produces a link that leads to a browser error with no
        // explanation, and the visitor cannot tell whether the file was
        // deleted, they lack permission, or something is simply down.
        if ($storage->headObject((string) $file['object_key']) === null) {
            AccessPolicy::log('download', 'unavailable', (int) $file['folder_id'], $fileId);

            return new \WP_REST_Response(
                [
                    'error'   => 'file_unavailable',
                    'message' => __('This file cannot be reached right now. It has not been deleted, the storage service is not responding. Please try again shortly.', 'hilg-vault'),
                ],
                503
            );
        }

        $url = $storage->presignDownload((string) $file['object_key'], 300, (string) $file['name']);

        $wpdb->query(
            $wpdb->prepare("UPDATE {$table} SET download_count = download_count + 1 WHERE id = %d", $fileId)
        );

        AccessPolicy::log('download', 'allowed', (int) $file['folder_id'], $fileId);

        // A browser following the link directly gets a redirect to the file.
        // Only a script asking for JSON gets JSON.
        //
        // Without this, someone with JavaScript disabled clicks a document and
        // is shown a page of raw JSON. The listing degrades gracefully and then
        // the download, the one thing the whole plugin exists for, does not.
        if (!self::wantsJson($request)) {
            return new \WP_REST_Response(null, 302, ['Location' => $url]);
        }

        return new \WP_REST_Response(['url' => $url, 'expires_in' => 300]);
    }

    /**
     * Whether the caller is a script expecting JSON or a browser following a link.
     *
     * Our own script announces itself explicitly. Anything else that does not
     * ask for JSON is treated as a person clicking a link, which is the safer
     * default: a redirect is useful to both, raw JSON is useful only to code.
     */
    private static function wantsJson(\WP_REST_Request $request): bool
    {
        if ($request->get_header('x_requested_with') === 'HilgVault') {
            return true;
        }

        $accept = (string) $request->get_header('accept');

        // A browser navigating sends text/html first; fetch() defaults to */*.
        if ($accept !== '' && str_contains($accept, 'text/html')) {
            return false;
        }

        return $accept === '' || str_contains($accept, 'application/json') || str_contains($accept, '*/*');
    }

    public function restUnlock(\WP_REST_Request $request): \WP_REST_Response
    {
        $folderId = (int) $request->get_param('id');
        $password = (string) $request->get_param('password');

        if (AccessPolicy::attemptPasswordUnlock($folderId, $password)) {
            AccessPolicy::log('unlock', 'allowed', $folderId);

            return new \WP_REST_Response(['unlocked' => true]);
        }

        AccessPolicy::log('unlock', 'denied', $folderId);

        return new \WP_REST_Response(['unlocked' => false], 401);
    }

    /**
     * Health endpoint for administrators. Reports what is actually true rather
     * than assuming the configuration works.
     */
    public function restStatus(): \WP_REST_Response
    {
        $storage = $this->storage();

        if ($storage === null) {
            return new \WP_REST_Response([
                'storage'   => false,
                'message'   => 'No storage credentials found in the environment.',
                'db_version' => get_option(Schema::OPTION_DB_VERSION),
            ]);
        }

        $health = $storage->healthCheck();

        return new \WP_REST_Response([
            'storage'    => $health['ok'],
            'message'    => $health['message'],
            'endpoint'   => $storage->endpoint(),
            'bucket'     => $storage->bucket(),
            'db_version' => get_option(Schema::OPTION_DB_VERSION),
        ]);
    }

    /**
     * Which of the given folders have at least one live child.
     *
     * One grouped query for the whole list rather than an EXISTS per row. The
     * result is keyed by id so the caller can test with isset.
     *
     * @param array<int,int> $folderIds
     * @return array<int,true>
     */
    private static function childBearing(array $folderIds): array
    {
        $folderIds = array_values(array_filter(array_map('intval', $folderIds)));

        if ($folderIds === []) {
            return [];
        }

        global $wpdb;

        $table        = Install\Schema::tableFolders();
        $placeholders = implode(',', array_fill(0, count($folderIds), '%d'));

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT parent_id FROM {$table}
                 WHERE parent_id IN ({$placeholders}) AND deleted_at IS NULL",
                $folderIds
            )
        );

        $map = [];

        foreach ((array) $rows as $parentId) {
            $map[(int) $parentId] = true;
        }

        return $map;
    }
}
