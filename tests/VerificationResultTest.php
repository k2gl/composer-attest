<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest\Tests;

use K2gl\ComposerAttest\VerificationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function K2gl\PHPUnitFluentAssertions\fact;

#[CoversClass(VerificationResult::class)]
final class VerificationResultTest extends TestCase
{
    public function testVerified(): void
    {
        $result = VerificationResult::verified('k2gl/dsse', 'abc123');

        fact($result->isVerified())->true();
        fact($result->hasAttestation())->true();
        fact($result->isFailure())->false();
        fact($result->message)->is('k2gl/dsse');
        fact($result->digest)->is('abc123');
    }

    public function testNoAttestation(): void
    {
        $result = VerificationResult::noAttestation();

        fact($result->isVerified())->false();
        fact($result->hasAttestation())->false();
        fact($result->isFailure())->false();
    }

    public function testFailed(): void
    {
        $result = VerificationResult::failed('bad signature');

        fact($result->isVerified())->false();
        fact($result->hasAttestation())->true();
        fact($result->isFailure())->true();
        fact($result->message)->is('bad signature');
    }
}
