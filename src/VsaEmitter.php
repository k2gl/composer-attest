<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest;

use K2gl\InToto\ResourceDescriptor;
use K2gl\InToto\Statement;
use K2gl\Slsa\VerificationResult as VsaResult;
use K2gl\Slsa\VerificationSummary;
use K2gl\Slsa\Verifier;

/**
 * Turns a passing attestation check into a SLSA Verification Summary Attestation
 * (VSA): the record that this verifier confirmed a package's GitHub build
 * provenance. Where the plugin's own check is transient — a line in the install
 * log — a VSA is a portable in-toto Statement you can store, sign, or hand to a
 * downstream policy gate.
 *
 * GitHub build-provenance attestations meet SLSA Build Level 2 (hosted build
 * platform, signed provenance in a transparency log), so that is the level the
 * summary records.
 */
final class VsaEmitter
{
    private const VERIFIER_ID = 'https://github.com/k2gl/composer-attest';

    private const BUILD_LEVEL = 'SLSA_BUILD_LEVEL_2';

    public function __construct(private readonly Policy $policy) {}

    /**
     * The VSA wrapped in an in-toto Statement over the verified artifact.
     *
     * @param string $timeVerified RFC 3339 timestamp of the verification
     */
    public function statement(string $packageName, string $version, string $artifactName, string $digest, string $timeVerified): Statement
    {
        $vsa = new VerificationSummary(
            verifier: new Verifier(id: self::VERIFIER_ID),
            timeVerified: $timeVerified,
            resourceUri: sprintf('pkg:composer/%s@%s', $packageName, $version),
            policy: new ResourceDescriptor(uri: $this->policy->issuer),
            verificationResult: VsaResult::Passed,
            verifiedLevels: [self::BUILD_LEVEL],
            slsaVersion: '1.0',
        );

        return $vsa->toStatement([
            new ResourceDescriptor(name: $artifactName, digest: ['sha256' => $digest]),
        ]);
    }

    /** The VSA Statement as pretty-printed JSON. */
    public function json(string $packageName, string $version, string $artifactName, string $digest, string $timeVerified): string
    {
        return json_encode(
            $this->statement($packageName, $version, $artifactName, $digest, $timeVerified)->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Write the VSA under the configured directory. Returns the file path, or
     * null when emission is disabled or the file could not be written.
     */
    public function write(string $packageName, string $version, string $artifactName, string $digest, string $timeVerified): ?string
    {
        if (! $this->policy->emitVsa) {
            return null;
        }
        $dir = $this->policy->vsaDir;

        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            return null;
        }
        $slug = str_replace('/', '-', $packageName . '-' . $version);
        $path = rtrim($dir, '/') . '/' . $slug . '.vsa.json';
        $json = $this->json($packageName, $version, $artifactName, $digest, $timeVerified);

        return @file_put_contents($path, $json . "\n") !== false ? $path : null;
    }
}
