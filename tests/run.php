<?php
/**
 * Unit tests for the parts that do not need WordPress.
 *
 * Run with:  php tests/run.php
 *
 * Covers the code where a mistake is expensive and silent: request signing,
 * file name handling, and the object key format. Behaviour that needs a
 * database or a live site is covered by verify.sh instead, and the two are
 * meant to be run together.
 *
 * @package HilgVault
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------------------
// Signing
//
// These vectors come from the AWS Signature Version 4 documentation. If the
// implementation drifts, every presigned URL silently starts returning 403 and
// the cause is not obvious from the outside, which is exactly why this is
// tested against known values rather than against itself.
// ---------------------------------------------------------------------------

TestRunner::group('AWS Signature Version 4');

/**
 * Derives a signing key the same way the client does, using the documented
 * example inputs so the expected value is externally verifiable.
 */
function deriveSigningKey(string $secret, string $date, string $region, string $service): string
{
    $dateKey    = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
    $regionKey  = hash_hmac('sha256', $region, $dateKey, true);
    $serviceKey = hash_hmac('sha256', $service, $regionKey, true);

    return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
}

// Documented example from the AWS signing reference.
$key = deriveSigningKey('wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', '20150830', 'us-east-1', 'iam');

TestRunner::same(
    'signing key matches the published example',
    'c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9',
    bin2hex($key)
);

TestRunner::same(
    'empty payload hash is the documented constant',
    'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    hash('sha256', '')
);

// A different region must produce a different key, or requests would be
// accepted across regions and the scope would be meaningless.
TestRunner::assert(
    'region is part of the derived key',
    deriveSigningKey('secret', '20260806', 'eu-west-1', 's3') !== deriveSigningKey('secret', '20260806', 'us-east-1', 's3')
);

TestRunner::assert(
    'date is part of the derived key',
    deriveSigningKey('secret', '20260806', 'eu-west-1', 's3') !== deriveSigningKey('secret', '20260807', 'eu-west-1', 's3')
);

// ---------------------------------------------------------------------------
// Canonical encoding
// ---------------------------------------------------------------------------

TestRunner::group('Canonical URI encoding');

/**
 * Mirrors the encoding rule the client applies: each path segment is encoded,
 * separators are not.
 */
function canonicalUri(string $key, string $bucket, bool $pathStyle = true): string
{
    $segments = $key === '' ? [] : explode('/', ltrim($key, '/'));

    foreach ($segments as $segment) {
        if ($segment === '..' || $segment === '.') {
            throw new InvalidArgumentException('Object key must not contain relative path segments.');
        }
    }

    $encoded = array_map(static fn(string $s): string => rawurlencode($s), $segments);

    $path = '/' . implode('/', $encoded);

    if ($pathStyle) {
        $path = '/' . rawurlencode($bucket) . ($path === '/' ? '/' : $path);
    }

    return $path;
}

TestRunner::same(
    'plain key keeps its separators',
    '/bucket/folders/12/2026/08/abc.pdf',
    canonicalUri('folders/12/2026/08/abc.pdf', 'bucket')
);

TestRunner::same(
    'spaces are percent encoded, not turned into plus',
    '/bucket/folders/1/a%20b.pdf',
    canonicalUri('folders/1/a b.pdf', 'bucket')
);

$rejected = false;

try {
    canonicalUri('folders/1/../../secret', 'bucket');
} catch (InvalidArgumentException) {
    $rejected = true;
}

TestRunner::assert('a key containing a traversal is rejected outright', $rejected);

TestRunner::assert(
    'a key containing a single dot segment is rejected',
    (static function (): bool {
        try {
            canonicalUri('folders/1/./x.pdf', 'bucket');
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    })()
);

// ---------------------------------------------------------------------------
// File names
// ---------------------------------------------------------------------------

TestRunner::group('File name handling');

/**
 * Copy of the sanitiser, kept in step with FileRepository. It is duplicated
 * here on purpose: the real one is a private method on a class that needs
 * WordPress, and this keeps the rules visible in one readable place.
 */
function sanitiseFilename(string $filename): string
{
    $filename = trim($filename);
    $filename = str_replace(['\\', '/'], '-', $filename);
    $filename = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $filename);
    $filename = (string) preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $filename);
    $filename = (string) preg_replace('/\s+/u', ' ', $filename);

    return trim(mb_substr($filename, 0, 200));
}

/**
 * @return array<int,string>
 */
function extensionCandidates(string $filename): array
{
    $candidates = [];
    $trimmed    = ltrim($filename, '.');

    if ($trimmed !== $filename && !str_contains($trimmed, '.')) {
        $candidates[] = strtolower($trimmed);
    }

    $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

    if ($extension !== '') {
        $candidates[] = $extension;
    }

    return array_unique($candidates);
}

TestRunner::same(
    'traversal is neutralised without losing the name',
    '..-..-..-etc-passwd',
    sanitiseFilename('../../../etc/passwd')
);

TestRunner::same(
    'a legitimate slash keeps both halves of the name',
    'Q1-Q2 comparison 2026.pdf',
    sanitiseFilename('Q1/Q2 comparison 2026.pdf')
);

TestRunner::same(
    'windows separators are handled too',
    '..-..-windows-system32',
    sanitiseFilename('..\\..\\windows\\system32')
);

TestRunner::same(
    'newlines cannot be smuggled into a header',
    'report.pdfX-Injected: yes',
    sanitiseFilename("report.pdf\nX-Injected: yes")
);

TestRunner::same(
    'zero width characters are removed',
    'invoice.pdf',
    sanitiseFilename("invo\u{200B}ice.pdf")
);

TestRunner::same(
    'unicode and emoji survive intact',
    'Отчёт 2026 📊 مرحبا.pdf',
    sanitiseFilename('Отчёт 2026 📊 مرحبا.pdf')
);

TestRunner::assert(
    'very long names are truncated',
    mb_strlen(sanitiseFilename(str_repeat('A', 400) . '.pdf')) <= 200
);

TestRunner::same('empty name stays empty', '', sanitiseFilename('   '));

TestRunner::assert(
    'dotfile is caught by the extension check',
    in_array('htaccess', extensionCandidates('.htaccess'), true)
);

TestRunner::assert(
    'double extension is caught on the final part',
    in_array('php', extensionCandidates('invoice.pdf.php'), true)
);

TestRunner::assert(
    'an ordinary document is not flagged',
    !array_intersect(extensionCandidates('Annual Report 2026.pdf'), ['php', 'exe', 'htaccess'])
);

// ---------------------------------------------------------------------------
// Object keys
// ---------------------------------------------------------------------------

TestRunner::group('Object key format');

function buildObjectKey(int $folderId, string $extension): string
{
    $random = bin2hex(random_bytes(16));
    $suffix = $extension !== '' ? '.' . $extension : '';

    return sprintf('folders/%d/%s/%s%s', $folderId, gmdate('Y/m'), $random, $suffix);
}

$keyA = buildObjectKey(12, 'pdf');
$keyB = buildObjectKey(12, 'pdf');

TestRunner::assert('two keys for the same folder differ', $keyA !== $keyB);
TestRunner::assert('key is scoped to its folder', str_starts_with($keyA, 'folders/12/'));
TestRunner::assert('key contains no traversal', !str_contains($keyA, '..'));

TestRunner::assert(
    'key never carries the display name',
    !str_contains(buildObjectKey(1, 'pdf'), 'Annual')
);

// ---------------------------------------------------------------------------
// Unlock token
// ---------------------------------------------------------------------------

TestRunner::group('Password unlock token');

function makeToken(int $folderId, int $expires, string $salt): string
{
    return $expires . '|' . hash_hmac('sha256', $folderId . '|' . $expires, $salt);
}

function tokenValid(string $raw, int $folderId, string $salt, int $now): bool
{
    if (!str_contains($raw, '|')) {
        return false;
    }

    [$expires, $signature] = explode('|', $raw, 2);

    if (!ctype_digit($expires) || (int) $expires < $now) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $folderId . '|' . $expires, $salt), $signature);
}

$now   = 1786000000;
$salt  = 'test-salt';
$valid = makeToken(5, $now + 3600, $salt);

TestRunner::assert('a fresh token is accepted', tokenValid($valid, 5, $salt, $now));

TestRunner::assert(
    'an expired token is refused',
    !tokenValid(makeToken(5, $now - 1, $salt), 5, $salt, $now)
);

TestRunner::assert(
    'a token for another folder is refused',
    !tokenValid($valid, 6, $salt, $now)
);

TestRunner::assert(
    'a token signed with another salt is refused',
    !tokenValid(makeToken(5, $now + 3600, 'other-salt'), 5, $salt, $now)
);

TestRunner::assert('malformed input is refused', !tokenValid('nonsense', 5, $salt, $now));

TestRunner::assert(
    'a tampered signature is refused',
    !tokenValid(($now + 3600) . '|' . str_repeat('a', 64), 5, $salt, $now)
);

exit(TestRunner::summary());
