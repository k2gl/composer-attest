<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest\Tests;

use K2gl\ComposerAttest\AttestationVerifier;
use K2gl\ComposerAttest\Policy;
use K2gl\ComposerAttest\VerificationResult;
use K2gl\Sigstore\TrustedRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Closure;

use function K2gl\PHPUnitFluentAssertions\fact;

#[CoversClass(AttestationVerifier::class)]
#[CoversClass(VerificationResult::class)]
final class AttestationVerifierTest extends TestCase
{
    private string $artifact;

    protected function setUp(): void
    {
        $this->artifact = tempnam(sys_get_temp_dir(), 'attest');
        file_put_contents($this->artifact, 'some package bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->artifact);
    }

    public function testNoAttestationWhenGithubReturns404(): void
    {
        $result = $this->verifier(fn (): ?string => null)->verify('k2gl', 'dsse', $this->artifact);

        fact($result->hasAttestation())->false();
        fact($result->isFailure())->false();
    }

    public function testNoAttestationWhenListIsEmpty(): void
    {
        $result = $this->verifier(fn (): string => '{"attestations": []}')->verify('k2gl', 'dsse', $this->artifact);

        fact($result->hasAttestation())->false();
    }

    public function testMalformedBundlesAreSkipped(): void
    {
        $result = $this->verifier(fn (): string => '{"attestations": [{"bundle": {"nonsense": true}}]}')
            ->verify('k2gl', 'dsse', $this->artifact);

        fact($result->hasAttestation())->false();
    }

    public function testUnreadableArtifactFails(): void
    {
        $result = $this->verifier(fn (): ?string => null)->verify('k2gl', 'dsse', '/no/such/file');

        fact($result->isFailure())->true();
    }

    public function testRealBundleVerifiesButDoesNotCoverAnUnrelatedArtifact(): void
    {
        // A genuine GitHub provenance bundle for sigstore/sigstore-js. The Sigstore
        // verification (identity + transparency) passes, but our throwaway artifact
        // is not among its subjects — so the result is a covered-artifact failure,
        // exercising the full verify path without needing the original artifact.
        $body = json_encode(['attestations' => [['bundle' => json_decode($this->fixture('bundle-provenance.json'), true)]]]);

        $result = $this->verifier(fn (): string => (string) $body)->verify('sigstore', 'sigstore-js', $this->artifact);

        fact($result->hasAttestation())->true();
        fact($result->isVerified())->false();
        fact($result->isFailure())->true();
    }

    public function testWrongRepoIdentityIsRejected(): void
    {
        $body = json_encode(['attestations' => [['bundle' => json_decode($this->fixture('bundle-provenance.json'), true)]]]);

        // Same bundle, but claimed for a repo whose identity the cert does not carry.
        $result = $this->verifier(fn (): string => (string) $body)->verify('attacker', 'sigstore-js', $this->artifact);

        fact($result->isVerified())->false();
        fact($result->isFailure())->true();
    }

    private function verifier(callable $fetch): AttestationVerifier
    {
        return new AttestationVerifier(
            Closure::fromCallable($fetch),
            TrustedRoot::fromJson($this->fixture('trusted-root-public-good.json')),
            new Policy,
        );
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }
}
