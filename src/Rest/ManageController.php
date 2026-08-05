<?php
/**
 * Management endpoints: folders, the role matrix, and the upload handshake.
 *
 * The upload handshake is the heart of the plugin:
 *
 *   1. the browser registers the file and receives presigned URLs,
 *   2. the browser sends the bytes straight to the bucket,
 *   3. the browser reports completion and the server verifies with the bucket.
 *
 * PHP never touches the payload. That is what removes the upload size ceiling
 * and keeps a library beyond a terabyte practical on ordinary hosting.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Rest;

use ClarityWeb\HilgVault\Access\AccessPolicy;
use ClarityWeb\HilgVault\Install\Schema;
use ClarityWeb\HilgVault\Model\FileRepository;
use ClarityWeb\HilgVault\Model\FolderRepository;
use ClarityWeb\HilgVault\Plugin;

defined('ABSPATH') || exit;

final class ManageController
{
    private const NAMESPACE = 'hilg-vault/v1';

    /** Files above this size are uploaded in parts. */
    private const MULTIPART_THRESHOLD = 32 * 1024 * 1024;

    /** S3 requires at least 5 MiB per part, except the last one. */
    private const PART_SIZE = 16 * 1024 * 1024;

    public static function register(): void
    {
        $manage = static fn(): bool => current_user_can('hilg_manage_vault');

        register_rest_route(self::NAMESPACE, '/manage/folders', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'createFolder'],
            'permission_callback' => $manage,
        ]);

        register_rest_route(self::NAMESPACE, '/manage/folders/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [self::class, 'updateFolder'],
                'permission_callback' => $manage,
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [self::class, 'deleteFolder'],
                'permission_callback' => $manage,
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/manage/folders/(?P<id>\d+)/move', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'moveFolder'],
            'permission_callback' => $manage,
        ]);

        register_rest_route(self::NAMESPACE, '/manage/folders/(?P<id>\d+)/roles', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'getRoles'],
                'permission_callback' => $manage,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'setRoles'],
                'permission_callback' => $manage,
            ],
        ]);

        // Uploading is not an administrator-only action: a network member may
        // hold upload rights on a specific folder through the role matrix.
        register_rest_route(self::NAMESPACE, '/manage/folders/(?P<id>\d+)/uploads', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'beginUpload'],
            'permission_callback' => static fn(\WP_REST_Request $r): bool =>
                AccessPolicy::canUploadToFolder((int) $r->get_param('id')),
        ]);

        register_rest_route(self::NAMESPACE, '/manage/uploads/(?P<file>\d+)/parts', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'signParts'],
            'permission_callback' => [self::class, 'canActOnUpload'],
        ]);

        register_rest_route(self::NAMESPACE, '/manage/uploads/(?P<file>\d+)/complete', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'completeUpload'],
            'permission_callback' => [self::class, 'canActOnUpload'],
        ]);

        register_rest_route(self::NAMESPACE, '/manage/files/(?P<file>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [self::class, 'renameFile'],
                'permission_callback' => [self::class, 'canActOnUpload'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [self::class, 'deleteFile'],
                'permission_callback' => [self::class, 'canActOnUpload'],
            ],
        ]);
    }

    /**
     * A pending upload belongs to whoever registered it, and to anyone who can
     * manage the vault. Checked on every part, not only when the upload starts.
     */
    public static function canActOnUpload(\WP_REST_Request $request): bool
    {
        if (current_user_can('hilg_manage_vault')) {
            return true;
        }

        $file = FileRepository::find((int) $request->get_param('file'));

        if ($file === null) {
            return false;
        }

        $userId = get_current_user_id();

        return $userId > 0 && (int) $file['uploaded_by'] === $userId;
    }

    // -----------------------------------------------------------------
    // Folders
    // -----------------------------------------------------------------

    public static function createFolder(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = FolderRepository::create([
            'name'        => (string) $request->get_param('name'),
            'parent_id'   => $request->get_param('parent_id') ? (int) $request->get_param('parent_id') : null,
            'access_mode' => (string) ($request->get_param('access_mode') ?: AccessPolicy::MODE_PRIVATE),
            'password'    => $request->get_param('password'),
            'description' => $request->get_param('description'),
        ]);

        return self::respond($result, static fn(int $id): array => ['id' => $id]);
    }

    public static function updateFolder(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = FolderRepository::update((int) $request->get_param('id'), [
            'name'        => $request->get_param('name'),
            'access_mode' => $request->get_param('access_mode'),
            'password'    => $request->get_param('password'),
            'description' => $request->get_param('description'),
        ]);

        return self::respond($result, static fn(): array => ['updated' => true]);
    }

    public static function deleteFolder(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = FolderRepository::delete((int) $request->get_param('id'));

        return self::respond($result, static fn(): array => ['deleted' => true]);
    }

    public static function moveFolder(\WP_REST_Request $request): \WP_REST_Response
    {
        $parent = $request->get_param('parent_id');

        $result = FolderRepository::move(
            (int) $request->get_param('id'),
            $parent === null || $parent === '' ? null : (int) $parent
        );

        return self::respond($result, static fn(): array => ['moved' => true]);
    }

    // -----------------------------------------------------------------
    // Role to folder matrix
    // -----------------------------------------------------------------

    public static function getRoles(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $folderId = (int) $request->get_param('id');
        $table    = Schema::tableFolderRoles();

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT role_slug, can_view, can_upload, can_manage FROM {$table} WHERE folder_id = %d", $folderId),
            ARRAY_A
        );

        $assigned = [];

        foreach ((array) $rows as $row) {
            $assigned[(string) $row['role_slug']] = [
                'can_view'   => (bool) $row['can_view'],
                'can_upload' => (bool) $row['can_upload'],
                'can_manage' => (bool) $row['can_manage'],
            ];
        }

        $available = [];

        foreach (wp_roles()->roles as $slug => $role) {
            $available[] = ['slug' => $slug, 'name' => translate_user_role($role['name'])];
        }

        // An inherited grant is shown explicitly, so an administrator can see
        // why a role already has access without a row on this folder.
        $inherited = [];

        foreach ($available as $role) {
            if (isset($assigned[$role['slug']])) {
                continue;
            }

            $grant = AccessPolicy::effectiveGrant($folderId, [$role['slug']]);

            if ($grant !== null) {
                $inherited[$role['slug']] = $grant;
            }
        }

        return new \WP_REST_Response([
            'roles'     => $available,
            'assigned'  => $assigned,
            'inherited' => $inherited,
        ]);
    }

    public static function setRoles(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $folderId = (int) $request->get_param('id');
        $grants   = $request->get_param('grants');

        if (!is_array($grants)) {
            return new \WP_REST_Response(['error' => 'grants must be an object'], 400);
        }

        $table      = Schema::tableFolderRoles();
        $validRoles = array_keys(wp_roles()->roles);

        $wpdb->delete($table, ['folder_id' => $folderId], ['%d']);

        foreach ($grants as $roleSlug => $flags) {
            $roleSlug = (string) $roleSlug;

            if (!in_array($roleSlug, $validRoles, true) || !is_array($flags)) {
                continue;
            }

            $canView   = !empty($flags['can_view']);
            $canUpload = !empty($flags['can_upload']);
            $canManage = !empty($flags['can_manage']);

            if (!$canView && !$canUpload && !$canManage) {
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'folder_id'  => $folderId,
                    'role_slug'  => $roleSlug,
                    // Upload or manage without view would be incoherent, so
                    // view is implied rather than left to the caller.
                    'can_view'   => ($canView || $canUpload || $canManage) ? 1 : 0,
                    'can_upload' => $canUpload ? 1 : 0,
                    'can_manage' => $canManage ? 1 : 0,
                ],
                ['%d', '%s', '%d', '%d', '%d']
            );
        }

        AccessPolicy::flushCache();

        return new \WP_REST_Response(['saved' => true]);
    }

    // -----------------------------------------------------------------
    // Upload handshake
    // -----------------------------------------------------------------

    public static function beginUpload(\WP_REST_Request $request): \WP_REST_Response
    {
        $storage = Plugin::instance()->storage();

        if ($storage === null) {
            return self::storageUnavailable();
        }

        $folderId = (int) $request->get_param('id');
        $size     = (int) $request->get_param('size');

        $registered = FileRepository::registerPending(
            $folderId,
            (string) $request->get_param('name'),
            $size,
            $request->get_param('type') ? (string) $request->get_param('type') : null
        );

        if ($registered instanceof \WP_Error) {
            return new \WP_REST_Response(
                ['error' => $registered->get_error_code(), 'message' => $registered->get_error_message()],
                400
            );
        }

        $fileId    = $registered['id'];
        $objectKey = $registered['object_key'];

        if ($size < self::MULTIPART_THRESHOLD) {
            return new \WP_REST_Response([
                'file_id'    => $fileId,
                'mode'       => 'single',
                'upload_url' => $storage->presignUpload($objectKey, 3600),
                'expires_in' => 3600,
            ]);
        }

        $uploadId = $storage->createMultipartUpload(
            $objectKey,
            $request->get_param('type') ? (string) $request->get_param('type') : null
        );

        if ($uploadId === null) {
            FileRepository::markFailed($fileId);

            return new \WP_REST_Response(
                ['error' => 'multipart_failed', 'message' => 'Storage refused to start a multipart upload.'],
                502
            );
        }

        FileRepository::attachUploadId($fileId, $uploadId);

        return new \WP_REST_Response([
            'file_id'     => $fileId,
            'mode'        => 'multipart',
            'upload_id'   => $uploadId,
            'part_size'   => self::PART_SIZE,
            'part_count'  => (int) ceil($size / self::PART_SIZE),
        ]);
    }

    /**
     * Signs a batch of part URLs. Signing on demand rather than all at once
     * means a resumed upload asks only for the parts it still needs.
     */
    public static function signParts(\WP_REST_Request $request): \WP_REST_Response
    {
        $storage = Plugin::instance()->storage();

        if ($storage === null) {
            return self::storageUnavailable();
        }

        $file = FileRepository::find((int) $request->get_param('file'));

        if ($file === null || empty($file['upload_id'])) {
            return new \WP_REST_Response(['error' => 'unknown_upload'], 404);
        }

        $parts = $request->get_param('parts');

        if (!is_array($parts) || $parts === []) {
            return new \WP_REST_Response(['error' => 'parts required'], 400);
        }

        $urls = [];

        foreach (array_slice($parts, 0, 100) as $partNumber) {
            $partNumber = (int) $partNumber;

            if ($partNumber < 1 || $partNumber > 10000) {
                continue;
            }

            $urls[$partNumber] = $storage->presignUploadPart(
                (string) $file['object_key'],
                (string) $file['upload_id'],
                $partNumber,
                3600
            );
        }

        return new \WP_REST_Response(['urls' => $urls, 'expires_in' => 3600]);
    }

    /**
     * Finishes the upload and verifies it against the bucket.
     *
     * The size written to the database is the one the bucket reports, never the
     * one the browser claimed. A truncated upload is recorded as failed instead
     * of appearing in the listing as a working file.
     */
    public static function completeUpload(\WP_REST_Request $request): \WP_REST_Response
    {
        $storage = Plugin::instance()->storage();

        if ($storage === null) {
            return self::storageUnavailable();
        }

        $fileId = (int) $request->get_param('file');
        $file   = FileRepository::find($fileId);

        if ($file === null) {
            return new \WP_REST_Response(['error' => 'unknown_upload'], 404);
        }

        $objectKey = (string) $file['object_key'];

        if (!empty($file['upload_id'])) {
            $parts = $request->get_param('parts');

            if (!is_array($parts) || $parts === []) {
                return new \WP_REST_Response(['error' => 'parts required'], 400);
            }

            $normalised = [];

            foreach ($parts as $part) {
                if (!isset($part['PartNumber'], $part['ETag'])) {
                    continue;
                }

                $normalised[] = [
                    'PartNumber' => (int) $part['PartNumber'],
                    'ETag'       => (string) $part['ETag'],
                ];
            }

            if (!$storage->completeMultipartUpload($objectKey, (string) $file['upload_id'], $normalised)) {
                FileRepository::markFailed($fileId);

                return new \WP_REST_Response(['error' => 'complete_failed'], 502);
            }
        }

        $head = $storage->headObject($objectKey);

        if ($head === null) {
            FileRepository::markFailed($fileId);

            return new \WP_REST_Response(
                ['error' => 'not_in_storage', 'message' => 'The object was not found in storage after upload.'],
                502
            );
        }

        FileRepository::markAvailable($fileId, $head['size'], $head['etag']);
        AccessPolicy::log('upload', 'allowed', (int) $file['folder_id'], $fileId);

        return new \WP_REST_Response([
            'file_id' => $fileId,
            'name'    => $file['name'],
            'size'    => $head['size'],
            'status'  => FileRepository::STATUS_AVAILABLE,
        ]);
    }

    public static function renameFile(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = FileRepository::rename(
            (int) $request->get_param('file'),
            (string) $request->get_param('name')
        );

        return self::respond($result, static fn(): array => ['renamed' => true]);
    }

    public static function deleteFile(\WP_REST_Request $request): \WP_REST_Response
    {
        $fileId = (int) $request->get_param('file');
        $file   = FileRepository::find($fileId);

        if ($file === null) {
            return new \WP_REST_Response(['error' => 'not_found'], 404);
        }

        FileRepository::softDelete($fileId);
        AccessPolicy::log('delete', 'allowed', (int) $file['folder_id'], $fileId);

        return new \WP_REST_Response(['deleted' => true]);
    }

    // -----------------------------------------------------------------

    private static function storageUnavailable(): \WP_REST_Response
    {
        return new \WP_REST_Response(
            [
                'error'   => 'storage_unavailable',
                'message' => 'Object storage is not configured. Nothing was saved.',
            ],
            503
        );
    }

    /**
     * @param callable $success Receives the raw result when it is not a WP_Error.
     */
    private static function respond(mixed $result, callable $success): \WP_REST_Response
    {
        if ($result instanceof \WP_Error) {
            return new \WP_REST_Response(
                ['error' => $result->get_error_code(), 'message' => $result->get_error_message()],
                400
            );
        }

        return new \WP_REST_Response($success($result));
    }
}
