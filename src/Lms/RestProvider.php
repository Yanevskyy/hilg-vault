<?php
/**
 * Configurable REST provider.
 *
 * Written against the shape most learning platforms expose rather than any one
 * product: a list endpoint and a per module lessons endpoint returning JSON.
 * The parts that differ between platforms are configuration, not code:
 *
 *   - base URL and the two paths,
 *   - authentication (bearer token, API key header, or none),
 *   - the field map, because one platform calls it "title" and the next calls
 *     it "name" or "fullname".
 *
 * When the client confirms which platform they use, this class is usually
 * enough on its own. If that platform is genuinely unusual, a new class
 * implementing LmsProvider slots in beside it without touching anything else.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Lms;

defined('ABSPATH') || exit;

final class RestProvider implements LmsProvider
{
    /**
     * @param array<string,mixed> $settings
     */
    public function __construct(private array $settings)
    {
    }

    public function label(): string
    {
        $host = wp_parse_url((string) $this->setting('base_url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : __('Learning platform', 'hilg-vault');
    }

    public function modules(): array
    {
        $payload = $this->request((string) $this->setting('modules_path', '/api/v1/modules'));
        $rows    = $this->extractList($payload, (string) $this->setting('modules_root', ''));

        $modules = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = $this->field($row, 'id', 'id');

            if ($id === '') {
                continue;
            }

            $modules[] = [
                'id'           => $id,
                'title'        => $this->field($row, 'title', 'title'),
                'description'  => $this->field($row, 'description', 'description'),
                'lesson_count' => (int) $this->field($row, 'lesson_count', 'lesson_count'),
                'duration'     => (int) $this->field($row, 'duration', 'duration_minutes'),
                'url'          => $this->buildUrl($this->field($row, 'url', 'url'), $id),
                'updated_at'   => $this->field($row, 'updated_at', 'updated_at'),
            ];
        }

        return $modules;
    }

    public function lessons(string $moduleId): array
    {
        $path = str_replace(
            '{module}',
            rawurlencode($moduleId),
            (string) $this->setting('lessons_path', '/api/v1/modules/{module}/lessons')
        );

        $payload = $this->request($path);
        $rows    = $this->extractList($payload, (string) $this->setting('lessons_root', 'lessons'));

        $lessons = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = $this->field($row, 'lesson_id', 'id');

            if ($id === '') {
                continue;
            }

            $lessons[] = [
                'id'       => $id,
                'title'    => $this->field($row, 'lesson_title', 'title'),
                'summary'  => $this->field($row, 'lesson_summary', 'summary'),
                'duration' => (int) $this->field($row, 'lesson_duration', 'duration_minutes'),
                'url'      => $this->buildUrl($this->field($row, 'lesson_url', 'url'), $moduleId, $id),
            ];
        }

        return $lessons;
    }

    public function testConnection(): array
    {
        try {
            $modules = $this->modules();
        } catch (LmsUnavailable $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        }

        if ($modules === []) {
            // An empty catalogue is a valid answer, not a failure. Saying so
            // plainly stops an administrator hunting for a fault that is not
            // there.
            return [
                'ok'      => true,
                'message' => __('Connected. The platform returned no modules.', 'hilg-vault'),
            ];
        }

        return [
            'ok'      => true,
            'message' => sprintf(
                /* translators: %d: number of modules returned by the platform. */
                _n('Connected. %d module found.', 'Connected. %d modules found.', count($modules), 'hilg-vault'),
                count($modules)
            ),
        ];
    }

    // -----------------------------------------------------------------

    /**
     * @return array<mixed>
     *
     * @throws LmsUnavailable
     */
    private function request(string $path): array
    {
        $base = rtrim((string) $this->setting('base_url'), '/');

        if ($base === '') {
            throw new LmsUnavailable(__('No learning platform URL is configured.', 'hilg-vault'));
        }

        $response = wp_remote_get($base . '/' . ltrim($path, '/'), [
            'timeout' => (int) $this->setting('timeout', 12),
            'headers' => $this->headers(),
        ]);

        if (is_wp_error($response)) {
            throw new LmsUnavailable($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code === 401 || $code === 403) {
            throw new LmsUnavailable(__('The learning platform rejected our credentials.', 'hilg-vault'));
        }

        if ($code < 200 || $code >= 300) {
            throw new LmsUnavailable(
                sprintf(
                    /* translators: %d: HTTP status code. */
                    __('The learning platform returned HTTP %d.', 'hilg-vault'),
                    $code
                )
            );
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($decoded)) {
            throw new LmsUnavailable(__('The learning platform returned a response we could not read.', 'hilg-vault'));
        }

        return $decoded;
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        $headers = ['Accept' => 'application/json'];
        $token   = (string) $this->setting('token', '');

        if ($token === '') {
            return $headers;
        }

        // Only the two schemes the settings screen offers. A third was listed
        // here and never implemented, so choosing it would have sent the
        // request unauthenticated and reported the platform as rejecting our
        // credentials, which is a hard thing to debug from the outside.
        return match ((string) $this->setting('auth', 'bearer')) {
            'header' => $headers + [(string) $this->setting('token_header', 'X-API-Key') => $token],
            'none'   => $headers,
            default  => $headers + ['Authorization' => 'Bearer ' . $token],
        };
    }

    /**
     * Some platforms return a bare array, others wrap it in an envelope such
     * as {"data": [...]}. The wrapper key is configurable and dot separated.
     *
     * @param array<mixed> $payload
     * @return array<mixed>
     */
    private function extractList(array $payload, string $root): array
    {
        if ($root === '') {
            return array_is_list($payload) ? $payload : [];
        }

        $current = $payload;

        foreach (explode('.', $root) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return [];
            }

            $current = $current[$segment];
        }

        return is_array($current) ? $current : [];
    }

    /**
     * Reads a value using the configured field map, falling back to the
     * conventional name when nothing is configured.
     *
     * @param array<string,mixed> $row
     */
    private function field(array $row, string $mapKey, string $default): string
    {
        $map  = $this->setting('field_map', []);
        $name = is_array($map) && !empty($map[$mapKey]) ? (string) $map[$mapKey] : $default;

        $value = $row[$name] ?? '';

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Builds the link a visitor follows into the platform. If the API supplies
     * one we use it; otherwise we assemble it from a configured pattern.
     */
    private function buildUrl(string $supplied, string $moduleId, string $lessonId = ''): string
    {
        if ($supplied !== '') {
            return $supplied;
        }

        $pattern = (string) $this->setting(
            $lessonId === '' ? 'module_url_pattern' : 'lesson_url_pattern',
            ''
        );

        if ($pattern === '') {
            return '';
        }

        return str_replace(['{module}', '{lesson}'], [$moduleId, $lessonId], $pattern);
    }

    private function setting(string $key, mixed $default = ''): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
