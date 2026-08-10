<?php
/**
 * Minimal S3 client: AWS Signature Version 4, presigned URLs, multipart uploads.
 *
 * Why not the AWS SDK. This plugin is delivered under a support contract that
 * commits to weekly updates for eighteen months. Every dependency is future
 * work and future exposure. Presigning is a deterministic algorithm, so the
 * whole surface we actually need fits in one auditable file with no vendor
 * directory. It works against AWS S3, Cloudflare R2, MinIO, Wasabi or any
 * other S3 compatible endpoint.
 *
 * The browser uploads straight to the bucket using a presigned URL. The file
 * never passes through PHP, so PHP upload limits stop being the ceiling and
 * the web server stops being the bottleneck. That is what makes a library
 * beyond one terabyte practical on an ordinary WordPress host.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Storage;

defined('ABSPATH') || exit;

final class S3Client
{
    private const ALGORITHM   = 'AWS4-HMAC-SHA256';
    private const SERVICE     = 's3';
    private const UNSIGNED    = 'UNSIGNED-PAYLOAD';
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function __construct(
        private string $endpoint,
        private string $region,
        private string $bucket,
        private string $accessKey,
        private string $secretKey,
        private bool $pathStyle = true,
    ) {
        $this->endpoint = rtrim($this->endpoint, '/');
    }

    /**
     * Builds the client from wp-config / environment values.
     */
    public static function fromEnvironment(): ?self
    {
        $endpoint = self::env('S3_ENDPOINT');
        $bucket   = self::env('S3_BUCKET');
        $key      = self::env('S3_KEY');
        $secret   = self::env('S3_SECRET');

        // No credentials means no storage. We return null and let the caller
        // surface an honest error rather than silently falling back to the
        // media library, which would quietly break the whole premise.
        if ($endpoint === null || $bucket === null || $key === null || $secret === null) {
            return null;
        }

        return new self(
            endpoint: $endpoint,
            region: self::env('S3_REGION') ?? 'us-east-1',
            bucket: $bucket,
            accessKey: $key,
            secretKey: $secret,
            pathStyle: self::env('S3_PATH_STYLE') !== 'false',
        );
    }

    private static function env(string $name): ?string
    {
        if (defined($name)) {
            $value = constant($name);

            return is_string($value) && $value !== '' ? $value : null;
        }

        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Presigned URL for a single request.
     *
     * @param string               $method  GET, PUT, POST, DELETE.
     * @param string               $key     Object key inside the bucket.
     * @param int                  $expires Lifetime in seconds.
     * @param array<string,string> $query   Extra query parameters, for example uploadId.
     * @param array<string,string> $headers Extra headers that must be signed.
     */
    public function presign(
        string $method,
        string $key,
        int $expires = 900,
        array $query = [],
        array $headers = [],
    ): string {
        $method    = strtoupper($method);
        $timestamp = time();
        $amzDate   = gmdate('Ymd\THis\Z', $timestamp);
        $shortDate = gmdate('Ymd', $timestamp);
        $scope     = "{$shortDate}/{$this->region}/" . self::SERVICE . '/aws4_request';

        $host = parse_url($this->endpoint, PHP_URL_HOST) ?: '';
        $port = parse_url($this->endpoint, PHP_URL_PORT);

        if ($port !== null) {
            $host .= ':' . $port;
        }

        $signedHeaders = ['host' => $host] + array_change_key_case($headers, CASE_LOWER);
        ksort($signedHeaders);

        $signedHeaderNames = implode(';', array_keys($signedHeaders));

        $query = array_merge($query, [
            'X-Amz-Algorithm'     => self::ALGORITHM,
            'X-Amz-Credential'    => "{$this->accessKey}/{$scope}",
            'X-Amz-Date'          => $amzDate,
            'X-Amz-Expires'       => (string) max(1, min($expires, 604800)),
            'X-Amz-SignedHeaders' => $signedHeaderNames,
        ]);

        $canonicalUri   = $this->canonicalUri($key);
        $canonicalQuery = $this->canonicalQuery($query);

        $canonicalHeaders = '';

        foreach ($signedHeaders as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim((string) $value) . "\n";
        }

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaderNames,
            self::UNSIGNED,
        ]);

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($shortDate));

        return $this->endpoint . $canonicalUri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    /**
     * Short lived URL the browser uses to PUT a whole (small) object.
     */
    public function presignUpload(string $key, int $expires = 900): string
    {
        return $this->presign('PUT', $key, $expires);
    }

    /**
     * Short lived URL for downloading. Private objects are never publicly
     * readable, so a link that leaks stops working within minutes instead of
     * staying valid forever.
     *
     * @param string|null $downloadAs Filename presented to the browser.
     */
    public function presignDownload(string $key, int $expires = 300, ?string $downloadAs = null): string
    {
        $query = [];

        if ($downloadAs !== null) {
            $query['response-content-disposition'] =
                'attachment; filename="' . str_replace('"', '', $downloadAs) . '"';
        }

        return $this->presign('GET', $key, $expires, $query);
    }

    /**
     * Starts a multipart upload and returns the upload id.
     *
     * Large files are split client side. Each part gets its own presigned URL,
     * parts upload in parallel, and an interrupted upload resumes by repeating
     * only the missing parts rather than the whole file.
     */
    public function createMultipartUpload(string $key, ?string $contentType = null): ?string
    {
        $headers = [];

        if ($contentType !== null) {
            $headers['content-type'] = $contentType;
        }

        $url = $this->presign('POST', $key, 900, ['uploads' => ''], $headers);

        $response = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => $headers,
        ]);

        $body = $this->body($response);

        if ($body === null) {
            return null;
        }

        if (preg_match('#<UploadId>(.*?)</UploadId>#s', $body, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public function presignUploadPart(string $key, string $uploadId, int $partNumber, int $expires = 3600): string
    {
        return $this->presign('PUT', $key, $expires, [
            'partNumber' => (string) $partNumber,
            'uploadId'   => $uploadId,
        ]);
    }

    /**
     * @param array<int,array{PartNumber:int,ETag:string}> $parts
     */
    public function completeMultipartUpload(string $key, string $uploadId, array $parts): bool
    {
        usort($parts, static fn(array $a, array $b): int => $a['PartNumber'] <=> $b['PartNumber']);

        $xml = '<CompleteMultipartUpload>';

        foreach ($parts as $part) {
            $etag = str_replace('"', '&quot;', $part['ETag']);
            $xml .= '<Part><PartNumber>' . (int) $part['PartNumber'] . '</PartNumber>'
                . '<ETag>' . $etag . '</ETag></Part>';
        }

        $xml .= '</CompleteMultipartUpload>';

        $url = $this->presign('POST', $key, 900, ['uploadId' => $uploadId]);

        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => ['content-type' => 'application/xml'],
            'body'    => $xml,
        ]);

        $body = $this->body($response);

        if ($body === null) {
            return false;
        }

        // This one call can fail with HTTP 200.
        //
        // Completing a multipart upload can take minutes, so S3 starts the
        // response immediately and only then discovers whether it worked,
        // reporting the outcome in the body. Trusting the status code alone
        // marks a failed assembly as available, and the file is listed but
        // unreadable. Documented AWS behaviour, and the compatible providers
        // copy it.
        if (str_contains($body, '<Error')) {
            return false;
        }

        return str_contains($body, '<CompleteMultipartUploadResult');
    }

    public function abortMultipartUpload(string $key, string $uploadId): bool
    {
        $url = $this->presign('DELETE', $key, 900, ['uploadId' => $uploadId]);

        return self::deletionSucceeded(
            wp_remote_request($url, ['method' => 'DELETE', 'timeout' => 20])
        );
    }

    public function deleteObject(string $key): bool
    {
        $url = $this->presign('DELETE', $key, 300);

        return self::deletionSucceeded(
            wp_remote_request($url, ['method' => 'DELETE', 'timeout' => 20])
        );
    }

    /**
     * Whether a delete actually removed the object.
     *
     * Reaching the server is not the same as being allowed to delete. This
     * used to report success for any response that was not a transport error,
     * so an expired key answering 403 counted as "gone". Housekeeping then
     * dropped the database row, which was the only thing pointing at the
     * object, and the bucket kept billing for a file nothing could ever find
     * again. A silent failure that costs money every month.
     *
     * 404 counts as success: the object is not there, which is the outcome
     * asked for, and retrying forever would be pointless.
     *
     * @param array<string,mixed>|\WP_Error $response
     */
    private static function deletionSucceeded(array|\WP_Error $response): bool
    {
        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);

        return ($code >= 200 && $code < 300) || $code === 404;
    }

    /**
     * Confirms the object really exists and returns its size, so the database
     * records what the bucket actually holds rather than what the browser
     * claimed it uploaded.
     *
     * @return array{size:int,etag:string}|null
     */
    public function headObject(string $key): ?array
    {
        $url = $this->presign('HEAD', $key, 300);

        $response = wp_remote_head($url, ['timeout' => 20]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        return [
            'size' => (int) wp_remote_retrieve_header($response, 'content-length'),
            'etag' => trim((string) wp_remote_retrieve_header($response, 'etag'), '"'),
        ];
    }

    /**
     * Verifies credentials and bucket reachability. Used by the settings screen
     * so an administrator sees a real answer instead of discovering the problem
     * during their first upload.
     *
     * @return array{ok:bool,message:string}
     */
    public function healthCheck(): array
    {
        $url      = $this->presign('GET', '', 60, ['list-type' => '2', 'max-keys' => '1']);
        $response = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code === 200) {
            return ['ok' => true, 'message' => __('Connected.', 'hilg-vault')];
        }

        if ($code === 403) {
            return ['ok' => false, 'message' => __('Credentials rejected by the storage provider.', 'hilg-vault')];
        }

        if ($code === 404) {
            return ['ok' => false, 'message' => __('Bucket not found at this endpoint.', 'hilg-vault')];
        }

        return [
            'ok'      => false,
            'message' => sprintf(
                /* translators: %d: HTTP status code returned by the storage provider. */
                __('Storage returned HTTP %d.', 'hilg-vault'),
                $code
            ),
        ];
    }

    /**
     * Path style puts the bucket in the URI (MinIO, most self hosted setups).
     * Virtual host style puts it in the hostname (AWS default, R2).
     */
    private function canonicalUri(string $key): string
    {
        $segments = $key === '' ? [] : explode('/', ltrim($key, '/'));

        // Relative segments are rejected rather than encoded.
        //
        // Encoding them would break the signature, because the canonical path
        // must match what is actually sent. Leaving them alone risks an
        // intermediary normalising the path, after which every signature
        // mismatches and links start failing with no obvious cause. Keys are
        // generated by this plugin and never contain these, so anything that
        // does is a bug or an attempt, and both deserve to stop here.
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new \InvalidArgumentException('Object key must not contain relative path segments.');
            }
        }

        $encoded = array_map(static fn(string $s): string => rawurlencode($s), $segments);

        $path = '/' . implode('/', $encoded);

        if ($this->pathStyle) {
            $path = '/' . rawurlencode($this->bucket) . ($path === '/' ? '/' : $path);
        }

        return $path;
    }

    /**
     * @param array<string,string> $query
     */
    private function canonicalQuery(array $query): string
    {
        ksort($query);

        $pairs = [];

        foreach ($query as $name => $value) {
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    /**
     * The SigV4 key derivation chain: secret, date, region, service, request.
     */
    private function signingKey(string $shortDate): string
    {
        $dateKey    = hash_hmac('sha256', $shortDate, 'AWS4' . $this->secretKey, true);
        $regionKey  = hash_hmac('sha256', $this->region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', self::SERVICE, $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }

    /**
     * @param array<string,mixed>|\WP_Error $response
     */
    private function body(array|\WP_Error $response): ?string
    {
        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            return null;
        }

        return (string) wp_remote_retrieve_body($response);
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }
}
