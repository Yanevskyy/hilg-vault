<?php
/**
 * Unit tests for the storage client.
 *
 * These exercise the real class rather than a copy of its logic, which matters:
 * a test that reimplements the code it checks will happily agree with itself
 * after the original changes.
 *
 * Only a few WordPress helpers are needed, and they are declared in the unit
 * bootstrap, so no database and no WordPress install are involved.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Tests\Unit;

use ClarityWeb\HilgVault\Storage\S3Client;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class S3ClientTest extends TestCase
{
    private function client(bool $pathStyle = true): S3Client
    {
        return new S3Client(
            endpoint: 'https://storage.example.ie',
            region: 'eu-west-1',
            bucket: 'hilg-vault',
            accessKey: 'AKIAEXAMPLEKEY',
            secretKey: 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            pathStyle: $pathStyle,
        );
    }

    public function testPresignedUrlCarriesEveryRequiredParameter(): void
    {
        $url = $this->client()->presignDownload('folders/1/2026/08/abc.pdf', 300);

        foreach ([
            'X-Amz-Algorithm=AWS4-HMAC-SHA256',
            'X-Amz-Credential=',
            'X-Amz-Date=',
            'X-Amz-Expires=300',
            'X-Amz-SignedHeaders=host',
            'X-Amz-Signature=',
        ] as $parameter) {
            $this->assertStringContainsString($parameter, $url, "Missing {$parameter}");
        }
    }

    public function testSignatureIsSixtyFourHexCharacters(): void
    {
        $url = $this->client()->presignUpload('folders/1/2026/08/abc.pdf');

        preg_match('/X-Amz-Signature=([a-f0-9]+)/', $url, $matches);

        $this->assertSame(64, strlen($matches[1] ?? ''));
    }

    public function testADifferentKeyProducesADifferentSignature(): void
    {
        $client = $this->client();

        $first  = $client->presignDownload('folders/1/a.pdf', 300);
        $second = $client->presignDownload('folders/1/b.pdf', 300);

        $this->assertNotSame(
            $this->signatureOf($first),
            $this->signatureOf($second),
            'The signature must cover the object key, or a link could be edited to fetch another file.'
        );
    }

    public function testADifferentMethodProducesADifferentSignature(): void
    {
        $client = $this->client();

        $this->assertNotSame(
            $this->signatureOf($client->presign('GET', 'folders/1/a.pdf', 300)),
            $this->signatureOf($client->presign('PUT', 'folders/1/a.pdf', 300)),
            'A download link must not double as an upload link.'
        );
    }

    public function testExpiryIsClampedToTheProtocolMaximum(): void
    {
        $url = $this->client()->presignDownload('folders/1/a.pdf', 999999999);

        $this->assertStringContainsString('X-Amz-Expires=604800', $url);
    }

    public function testExpiryIsNeverZeroOrNegative(): void
    {
        $url = $this->client()->presignDownload('folders/1/a.pdf', -50);

        $this->assertStringContainsString('X-Amz-Expires=1', $url);
    }

    public function testSpacesInAKeyAreEncodedForTheSignature(): void
    {
        $url = $this->client()->presignDownload('folders/1/annual report.pdf', 300);

        $this->assertStringContainsString('annual%20report.pdf', $url);
        $this->assertStringNotContainsString('annual+report.pdf', $url);
    }

    public function testRelativeSegmentsInAKeyAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client()->presignDownload('folders/1/../../etc/passwd', 300);
    }

    public function testSingleDotSegmentsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client()->presignDownload('folders/1/./a.pdf', 300);
    }

    public function testPathStylePutsTheBucketInThePath(): void
    {
        $url = $this->client(pathStyle: true)->presignDownload('folders/1/a.pdf', 300);

        $this->assertStringContainsString('/hilg-vault/folders/1/a.pdf', $url);
    }

    public function testVirtualHostStyleLeavesTheBucketOutOfThePath(): void
    {
        $url = $this->client(pathStyle: false)->presignDownload('folders/1/a.pdf', 300);

        $this->assertStringNotContainsString('/hilg-vault/folders', $url);
        $this->assertStringContainsString('/folders/1/a.pdf', $url);
    }

    public function testDownloadNameIsPassedAsContentDisposition(): void
    {
        $url = $this->client()->presignDownload('folders/1/x.pdf', 300, 'Annual Report 2026.pdf');

        $this->assertStringContainsString('response-content-disposition', $url);
        $this->assertStringContainsString('Annual', $url);
    }

    public function testAQuoteInTheDownloadNameCannotBreakOutOfTheHeader(): void
    {
        $url = $this->client()->presignDownload('folders/1/x.pdf', 300, 'evil".pdf');

        // The quote is stripped before signing, so the header it lands in
        // cannot be terminated early by a crafted file name.
        $this->assertStringNotContainsString('%22.pdf', $url);
    }

    public function testTheClientIsAbsentWhenCredentialsAreMissing(): void
    {
        $this->assertNull(S3Client::fromEnvironment());
    }

    private function signatureOf(string $url): string
    {
        preg_match('/X-Amz-Signature=([a-f0-9]+)/', $url, $matches);

        return $matches[1] ?? '';
    }
}
