<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest\Tests;

use K2gl\ComposerAttest\Policy;
use K2gl\ComposerAttest\VsaEmitter;
use K2gl\InToto\Statement;
use K2gl\InToto\StatementVersion;
use K2gl\Slsa\VerificationResult as VsaResult;
use K2gl\Slsa\VerificationSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function K2gl\PHPUnitFluentAssertions\fact;

#[CoversClass(VsaEmitter::class)]
final class VsaEmitterTest extends TestCase
{
    private const DIGEST = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testBuildsAVsaStatementForTheVerifiedPackage(): void
    {
        $emitter = new VsaEmitter(new Policy(emitVsa: true));

        $statement = $emitter->statement('k2gl/dsse', '1.3.0', 'dsse.zip', self::DIGEST, '2026-07-11T12:00:00Z');

        fact($statement->version)->is(StatementVersion::V1);
        fact($statement->predicateType)->is(VerificationSummary::PREDICATE_TYPE);
        fact($statement->subject[0]->name)->is('dsse.zip');
        fact($statement->subject[0]->digest)->is(['sha256' => self::DIGEST]);

        $vsa = VerificationSummary::fromStatement($statement);

        fact($vsa->verificationResult)->is(VsaResult::Passed);
        fact($vsa->verifiedLevels)->is(['SLSA_BUILD_LEVEL_2']);
        fact($vsa->resourceUri)->is('pkg:composer/k2gl/dsse@1.3.0');
        fact($vsa->verifier->id)->is('https://github.com/k2gl/composer-attest');
        fact($vsa->slsaVersion)->is('1.0');
    }

    public function testPolicyIssuerBecomesTheVerifiedPolicy(): void
    {
        $emitter = new VsaEmitter(new Policy(issuer: 'https://token.actions.githubusercontent.com', emitVsa: true));

        $vsa = VerificationSummary::fromStatement(
            $emitter->statement('k2gl/dsse', '1.3.0', 'dsse.zip', self::DIGEST, '2026-07-11T12:00:00Z'),
        );

        fact($vsa->policy->uri)->is('https://token.actions.githubusercontent.com');
    }

    public function testJsonRoundTripsThroughAStatement(): void
    {
        $emitter = new VsaEmitter(new Policy(emitVsa: true));

        $json = $emitter->json('k2gl/dsse', '1.3.0', 'dsse.zip', self::DIGEST, '2026-07-11T12:00:00Z');

        $statement = Statement::fromJson($json);

        fact($statement->predicateType)->is(VerificationSummary::PREDICATE_TYPE);
        fact(VerificationSummary::fromStatement($statement)->resourceUri)->is('pkg:composer/k2gl/dsse@1.3.0');
    }

    public function testWriteReturnsNullWhenDisabled(): void
    {
        $emitter = new VsaEmitter(new Policy(emitVsa: false));

        fact($emitter->write('k2gl/dsse', '1.3.0', 'dsse.zip', self::DIGEST, '2026-07-11T12:00:00Z'))->null();
    }

    public function testWriteCreatesTheFile(): void
    {
        $dir = sys_get_temp_dir() . '/vsa-' . uniqid();
        $emitter = new VsaEmitter(new Policy(emitVsa: true, vsaDir: $dir));

        $path = $emitter->write('k2gl/dsse', '1.3.0', 'dsse.zip', self::DIGEST, '2026-07-11T12:00:00Z');

        fact($path)->is($dir . '/k2gl-dsse-1.3.0.vsa.json');
        fact(is_file((string) $path))->true();

        $decoded = json_decode((string) file_get_contents((string) $path), true);
        fact($decoded['predicateType'])->is(VerificationSummary::PREDICATE_TYPE);

        @unlink((string) $path);
        @rmdir($dir);
    }
}
