<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest\Tests;

use K2gl\ComposerAttest\Policy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function K2gl\PHPUnitFluentAssertions\fact;

#[CoversClass(Policy::class)]
final class PolicyTest extends TestCase
{
    public function testDefaultsToWarn(): void
    {
        $policy = Policy::fromExtra([]);

        fact($policy->mode)->is(Policy::MODE_WARN);
        fact($policy->requireAttestation)->false();
        fact($policy->isOff())->false();
        fact($policy->isEnforcing())->false();
        fact($policy->issuer)->is('https://token.actions.githubusercontent.com');
        fact($policy->emitVsa)->false();
        fact($policy->vsaDir)->is('.attestations/vsa');
    }

    public function testReadsConfiguredValues(): void
    {
        $policy = Policy::fromExtra(['k2gl-attest' => [
            'mode' => 'enforce',
            'require-attestation' => true,
            'issuer' => 'https://gitlab.example/oidc',
            'emit-vsa' => true,
            'vsa-dir' => 'build/vsa',
        ]]);

        fact($policy->isEnforcing())->true();
        fact($policy->requireAttestation)->true();
        fact($policy->issuer)->is('https://gitlab.example/oidc');
        fact($policy->emitVsa)->true();
        fact($policy->vsaDir)->is('build/vsa');
    }

    public function testOffMode(): void
    {
        fact(Policy::fromExtra(['k2gl-attest' => ['mode' => 'off']])->isOff())->true();
    }

    public function testUnknownModeFallsBackToWarn(): void
    {
        fact(Policy::fromExtra(['k2gl-attest' => ['mode' => 'nonsense']])->mode)->is(Policy::MODE_WARN);
    }

    public function testMalformedConfigIsIgnored(): void
    {
        fact(Policy::fromExtra(['k2gl-attest' => 'not-an-array'])->mode)->is(Policy::MODE_WARN);
    }
}
