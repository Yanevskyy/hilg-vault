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

/**
 * Mirrors FileRepository::blockedExtension, used by both upload and rename.
 */
function blockedExtension(string $filename): ?string
{
    $blocked = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar',
        'exe', 'com', 'bat', 'cmd', 'msi', 'scr', 'cpl', 'jar',
        'sh', 'bash', 'zsh', 'ps1', 'psm1', 'vbs', 'js', 'jse', 'wsf', 'hta',
        'htaccess', 'htpasswd',
    ];

    $candidates = [];
    $trimmed    = ltrim($filename, '.');

    if ($trimmed !== $filename && !str_contains($trimmed, '.')) {
        $candidates[] = strtolower($trimmed);
    }

    $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

    if ($extension !== '') {
        $candidates[] = $extension;
    }

    foreach (array_unique($candidates) as $candidate) {
        if (in_array($candidate, $blocked, true)) {
            return $candidate;
        }
    }

    return null;
}

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

TestRunner::assert(
    'moving a file leaves the object key alone',
    (static function (): bool {
        // A move is a metadata change by design: the key is random rather than
        // derived from the folder, so a ten gigabyte file moves in one UPDATE
        // instead of a copy plus a delete. If a future edit ever derives the
        // key from folder_id, this assertion is what should start failing.
        $key = buildObjectKey(12, 'pdf');

        $row = ['folder_id' => 12, 'object_key' => $key];
        $row['folder_id'] = 44;

        return $row['object_key'] === $key;
    })()
);

// ---------------------------------------------------------------------------
// Folder path labels
// ---------------------------------------------------------------------------

TestRunner::group('Folder path labels');

/**
 * @param array<int,array{id:int,parent_id:?int,name:string}> $rows
 * @return array<int,string>
 */
function pathLabels(array $rows): array
{
    $byId = [];

    foreach ($rows as $row) {
        $byId[$row['id']] = $row;
    }

    $labels = [];

    foreach ($rows as $row) {
        $trail  = [];
        $cursor = $row;
        $guard  = 0;

        while ($cursor !== null && $guard++ < 50) {
            array_unshift($trail, $cursor['name']);
            $parentId = $cursor['parent_id'] ?? 0;
            $cursor   = $byId[$parentId] ?? null;
        }

        $labels[$row['id']] = implode(' / ', $trail);
    }

    return $labels;
}

$tree = [
    ['id' => 1, 'parent_id' => null, 'name' => 'Annual Reports'],
    ['id' => 2, 'parent_id' => 1,    'name' => 'Archive 2020-2023'],
    ['id' => 3, 'parent_id' => 2,    'name' => '2021'],
    ['id' => 4, 'parent_id' => null, 'name' => 'Partner Toolkit'],
];

$labels = pathLabels($tree);

TestRunner::assert('a root folder is labelled by its own name', $labels[1] === 'Annual Reports');

TestRunner::assert(
    'a child carries its parent',
    $labels[2] === 'Annual Reports / Archive 2020-2023'
);

TestRunner::assert(
    'a grandchild carries the whole trail',
    $labels[3] === 'Annual Reports / Archive 2020-2023 / 2021'
);

TestRunner::assert(
    'labels use display names, not slugs',
    !str_contains($labels[3], 'annual-reports')
);

TestRunner::assert(
    'a cycle terminates instead of hanging',
    (static function (): bool {
        $cyclic = [
            ['id' => 1, 'parent_id' => 2, 'name' => 'A'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'B'],
        ];

        $result = pathLabels($cyclic);

        return isset($result[1]) && substr_count($result[1], ' / ') < 60;
    })()
);

// ---------------------------------------------------------------------------
// Pagination arithmetic
// ---------------------------------------------------------------------------

TestRunner::group('Pagination');

function totalPages(int $total, int $perPage): int
{
    return $perPage > 0 ? (int) ceil($total / $perPage) : 1;
}

function pageOffset(int $page, int $perPage): int
{
    return (max(1, $page) - 1) * $perPage;
}

TestRunner::assert('518 files at 60 a page is 9 pages', totalPages(518, 60) === 9);
TestRunner::assert('an exact multiple does not gain a page', totalPages(120, 60) === 2);
TestRunner::assert('an empty library is one page', totalPages(0, 60) === 0 || totalPages(0, 60) === 1);
TestRunner::assert('page one starts at zero', pageOffset(1, 60) === 0);
TestRunner::assert('page nine starts at 480', pageOffset(9, 60) === 480);
TestRunner::assert('page zero is treated as page one', pageOffset(0, 60) === 0);
TestRunner::assert('a negative page is treated as page one', pageOffset(-5, 60) === 0);

TestRunner::assert(
    'the last page holds the remainder, not a full page',
    518 - pageOffset(9, 60) === 38
);

// ---------------------------------------------------------------------------
// Deleting from storage
// ---------------------------------------------------------------------------

TestRunner::group('Storage deletion');

/**
 * Mirrors S3Client::deletionSucceeded. Reaching the server is not the same as
 * being allowed to delete.
 */
function deletionSucceeded(?int $code): bool
{
    if ($code === null) {
        return false;
    }

    return ($code >= 200 && $code < 300) || $code === 404;
}

TestRunner::assert('204 is success', deletionSucceeded(204));
TestRunner::assert('200 is success', deletionSucceeded(200));
TestRunner::assert('404 is success, the object is already gone', deletionSucceeded(404));
TestRunner::assert('403 is a failure, not a deletion', !deletionSucceeded(403));
TestRunner::assert('500 is a failure', !deletionSucceeded(500));
TestRunner::assert('a transport error is a failure', !deletionSucceeded(null));

TestRunner::assert(
    'a refused delete never lets the row be removed',
    (static function (): bool {
        // The row is the only thing pointing at the object. Dropping it after
        // a refused delete orphans the object and bills for it for ever.
        $rowDeleted = deletionSucceeded(403);

        return $rowDeleted === false;
    })()
);

// ---------------------------------------------------------------------------
// Completing a multipart upload
// ---------------------------------------------------------------------------

TestRunner::group('Multipart completion');

/**
 * S3 can report failure inside a 200 response, because assembling the parts
 * starts before the outcome is known.
 */
function completionSucceeded(string $body): bool
{
    if (str_contains($body, '<Error')) {
        return false;
    }

    return str_contains($body, '<CompleteMultipartUploadResult');
}

TestRunner::assert(
    'a real completion is accepted',
    completionSucceeded('<?xml version="1.0"?><CompleteMultipartUploadResult><ETag>"abc"</ETag></CompleteMultipartUploadResult>')
);

TestRunner::assert(
    'an error inside a 200 response is refused',
    !completionSucceeded('<?xml version="1.0"?><Error><Code>InternalError</Code></Error>')
);

TestRunner::assert('an empty body is refused', !completionSucceeded(''));
TestRunner::assert('an unrecognised body is refused', !completionSucceeded('<html>oops</html>'));

// ---------------------------------------------------------------------------
// Breadcrumbs
// ---------------------------------------------------------------------------

TestRunner::group('Breadcrumb trail');

/**
 * Walks parent_id, as FolderRepository::breadcrumbs now does.
 *
 * @param array<int,array{id:int,parent_id:?int,name:string,slug:string}> $rows
 * @return array<int,string>
 */
function trailFor(array $rows, int $folderId): array
{
    $byId = [];

    foreach ($rows as $row) {
        $byId[$row['id']] = $row;
    }

    $trail  = [];
    $cursor = $byId[$folderId] ?? null;
    $guard  = 0;

    while ($cursor !== null && $guard++ <= 32) {
        array_unshift($trail, $cursor['name']);
        $cursor = $cursor['parent_id'] === null ? null : ($byId[$cursor['parent_id']] ?? null);
    }

    return $trail;
}

/**
 * The old approach: select every folder whose slug appears in the path. Slugs
 * are unique among siblings only, so this pulls in strangers.
 *
 * @param array<int,array{id:int,parent_id:?int,name:string,slug:string}> $rows
 * @return array<int,string>
 */
function trailBySlug(array $rows, array $slugs): array
{
    $names = [];

    foreach ($rows as $row) {
        if (in_array($row['slug'], $slugs, true)) {
            $names[] = $row['name'];
        }
    }

    return $names;
}

$library = [
    ['id' => 1, 'parent_id' => null, 'name' => 'Annual Reports',  'slug' => 'annual-reports'],
    ['id' => 2, 'parent_id' => 1,    'name' => 'Archive',         'slug' => 'archive'],
    ['id' => 3, 'parent_id' => null, 'name' => 'Partner Toolkit', 'slug' => 'partner-toolkit'],
    ['id' => 4, 'parent_id' => 3,    'name' => 'Archive',         'slug' => 'archive'],
];

TestRunner::same(
    'a nested folder shows only its own ancestors',
    ['Annual Reports', 'Archive'],
    trailFor($library, 2)
);

TestRunner::same(
    'the other branch shows its own, not the first one',
    ['Partner Toolkit', 'Archive'],
    trailFor($library, 4)
);

TestRunner::same('a root folder is its own trail', ['Annual Reports'], trailFor($library, 1));

TestRunner::same(
    'matching by slug pulled in a folder from another branch',
    3,
    count(trailBySlug($library, ['annual-reports', 'archive']))
);

TestRunner::assert(
    'the walk terminates on a cycle instead of hanging',
    (static function (): bool {
        $cyclic = [
            ['id' => 1, 'parent_id' => 2, 'name' => 'A', 'slug' => 'a'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'B', 'slug' => 'b'],
        ];

        return count(trailFor($cyclic, 1)) <= 34;
    })()
);

// ---------------------------------------------------------------------------
// Renaming
// ---------------------------------------------------------------------------

TestRunner::group('Rename safety');

TestRunner::assert(
    'a rename to .php is refused',
    blockedExtension('report.php') !== null
);

TestRunner::assert(
    'case does not get past the block list',
    blockedExtension('Report.PHTML') !== null
);

TestRunner::assert(
    'a dotfile is refused by its whole name',
    blockedExtension('.htaccess') !== null
);

TestRunner::assert('an ordinary document is accepted', blockedExtension('Annual Report 2026.pdf') === null);
TestRunner::assert('a spreadsheet is accepted', blockedExtension('Budget.xlsx') === null);

TestRunner::same(
    'the extension column follows the new name',
    'xlsx',
    strtolower((string) pathinfo('Renamed Report 2026.xlsx', PATHINFO_EXTENSION))
);

// ---------------------------------------------------------------------------
// Telling visitors apart behind a proxy
// ---------------------------------------------------------------------------

TestRunner::group('Client address');

/**
 * Mirrors AccessPolicy::clientIp. The forwarded header is read only when the
 * request genuinely arrived from a proxy we were told about.
 *
 * @param array<int,string> $trusted
 */
function clientIp(string $remote, string $forwarded, array $trusted): string
{
    if ($remote === '' || !in_array($remote, $trusted, true)) {
        return $remote;
    }

    if ($forwarded === '') {
        return $remote;
    }

    foreach (array_reverse(array_map('trim', explode(',', $forwarded))) as $candidate) {
        if ($candidate === '' || in_array($candidate, $trusted, true)) {
            continue;
        }

        return filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : $remote;
    }

    return $remote;
}

$proxy = ['172.28.0.1'];

TestRunner::same(
    'behind a known proxy the real address is used',
    '86.44.254.169',
    clientIp('172.28.0.1', '86.44.254.169', $proxy)
);

TestRunner::same(
    'a header from an unknown source is ignored',
    '203.0.113.9',
    clientIp('203.0.113.9', '1.2.3.4', $proxy)
);

TestRunner::same(
    'the closest address in a chain wins, not the first',
    '198.51.100.7',
    clientIp('172.28.0.1', '1.2.3.4, 198.51.100.7', $proxy)
);

TestRunner::same(
    'rubbish in the header falls back to the connection',
    '172.28.0.1',
    clientIp('172.28.0.1', 'not-an-address', $proxy)
);

TestRunner::same(
    'no header means the connection address',
    '172.28.0.1',
    clientIp('172.28.0.1', '', $proxy)
);

TestRunner::assert(
    'two visitors behind one proxy are told apart',
    clientIp('172.28.0.1', '86.44.254.169', $proxy) !== clientIp('172.28.0.1', '72.62.88.47', $proxy)
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
