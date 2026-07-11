<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest\Tests;

use K2gl\ComposerAttest\Exception\AttestationException;
use K2gl\ComposerAttest\Policy;
use K2gl\ComposerAttest\VsaEmitter;
use K2gl\ComposerAttest\VsaSigner;
use K2gl\Dsse\PublicKey;
use K2gl\InToto\Statement;
use K2gl\Slsa\VerificationSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function K2gl\PHPUnitFluentAssertions\fact;

#[CoversClass(VsaSigner::class)]
final class VsaSignerTest extends TestCase
{
    private const DIGEST = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testSignsAVsaStatementIntoAVerifiableEnvelope(): void
    {
        [$privatePem, $publicPem] = $this->ecKeyPair('prime256v1');

        $envelope = VsaSigner::fromPrivateKeyPem($privatePem)->sign($this->statement());

        fact($envelope->payloadType)->is(Statement::PAYLOAD_TYPE);
        fact($envelope->signatures)->count(1);

        // The signature verifies under the matching public key, and the payload is the VSA.
        $payload = $envelope->verify(PublicKey::fromPem($publicPem));
        $vsa = VerificationSummary::fromStatement(Statement::fromJson($payload));

        fact($vsa->verifiedLevels)->is(['SLSA_BUILD_LEVEL_2']);
        fact($vsa->resourceUri)->is('pkg:composer/k2gl/dsse@1.3.0');
    }

    public function testPicksTheAlgorithmFromTheKey(): void
    {
        [$privatePem] = $this->ecKeyPair('secp384r1');

        $envelope = VsaSigner::fromPrivateKeyPem($privatePem)->sign($this->statement());

        fact($envelope->signatures)->count(1);
    }

    public function testRejectsAnUnloadableKey(): void
    {
        fact(static fn () => VsaSigner::fromPrivateKeyPem('not a key'))->throws(AttestationException::class);
    }

    private function statement(): Statement
    {
        return (new VsaEmitter(new Policy(emitVsa: true)))
            ->statement('k2gl/dsse', '1.3.0', 'dsse.zip', self::DIGEST, '2026-07-11T12:00:00Z');
    }

    /** @return array{0: string, 1: string} private + public PEM */
    private function ecKeyPair(string $curve): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);

        if ($key === false) {
            self::fail('could not generate an EC key for the test');
        }
        openssl_pkey_export($key, $privatePem);

        return [(string) $privatePem, (string) openssl_pkey_get_details($key)['key']];
    }
}
