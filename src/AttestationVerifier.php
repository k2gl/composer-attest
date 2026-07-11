<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest;

use Closure;
use K2gl\Dsse\Envelope;
use K2gl\Sigstore\Bundle;
use K2gl\Sigstore\Exception\SigstoreException;
use K2gl\Sigstore\IdentityPolicy;
use K2gl\Sigstore\SigstoreVerifier;
use K2gl\Sigstore\TrustedRoot;

/**
 * Checks a downloaded package against GitHub's build-provenance attestations:
 * it hashes the artifact, asks GitHub for any attestation bound to that digest,
 * and verifies the Sigstore bundle with {@see SigstoreVerifier}, requiring the
 * signing identity to be a GitHub Actions workflow of the package's own repo.
 *
 * The HTTP fetch is injected (a closure returning the response body or null for
 * "not found"), so the plugin can hand it Composer's authenticated downloader
 * and tests can hand it a canned response.
 */
final class AttestationVerifier
{
    /** @param Closure(string): ?string $fetch (url) => JSON body, or null when absent (404) */
    public function __construct(
        private readonly Closure $fetch,
        private readonly TrustedRoot $trustedRoot,
        private readonly Policy $policy,
    ) {}

    public function verify(string $owner, string $repo, string $artifactPath): VerificationResult
    {
        $digest = @hash_file('sha256', $artifactPath);

        if ($digest === false) {
            return VerificationResult::failed('could not read the downloaded artifact');
        }
        $body = ($this->fetch)(sprintf('https://api.github.com/repos/%s/%s/attestations/sha256:%s', $owner, $repo, $digest));

        if ($body === null) {
            return VerificationResult::noAttestation();
        }
        $bundles = $this->bundles($body);

        if ($bundles === []) {
            return VerificationResult::noAttestation();
        }
        $identityPolicy = IdentityPolicy::sanRegex(
            sprintf('#^https://github\.com/%s/#', preg_quote($owner . '/' . $repo, '#')),
            $this->policy->issuer,
        );
        $verifier = new SigstoreVerifier;
        $lastError = 'attestation did not verify';

        // GitHub build-provenance attestations are DSSE in-toto statements: verify
        // the envelope, then confirm the artifact digest is one of its subjects.
        foreach ($bundles as $bundle) {
            try {
                $envelope = $verifier->verify($bundle, $this->trustedRoot, $identityPolicy);

                if ($this->subjectMatches($envelope, $digest)) {
                    return VerificationResult::verified($owner . '/' . $repo, $digest);
                }
                $lastError = 'verified attestation does not cover this artifact';
            } catch (SigstoreException $e) {
                $lastError = $e->getMessage();
            }
        }

        return VerificationResult::failed($lastError);
    }

    /** True if the in-toto statement lists the artifact's sha256 among its subjects. */
    private function subjectMatches(Envelope $envelope, string $digest): bool
    {
        $statement = json_decode($envelope->payload, true);
        $subjects = is_array($statement) ? ($statement['subject'] ?? null) : null;

        if (! is_array($subjects)) {
            return false;
        }

        foreach ($subjects as $subject) {
            $digests = is_array($subject) ? ($subject['digest'] ?? null) : null;
            $recorded = is_array($digests) ? ($digests['sha256'] ?? null) : null;

            if (is_string($recorded) && hash_equals($digest, strtolower($recorded))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Bundle>
     */
    private function bundles(string $body): array
    {
        $data = json_decode($body, true);
        $attestations = is_array($data) ? ($data['attestations'] ?? null) : null;

        if (! is_array($attestations)) {
            return [];
        }
        $bundles = [];

        foreach ($attestations as $attestation) {
            $bundle = is_array($attestation) ? ($attestation['bundle'] ?? null) : null;

            if (! is_array($bundle)) {
                continue;
            }

            try {
                /** @var array<string, mixed> $bundle */
                $bundles[] = Bundle::fromArray($bundle);
            } catch (SigstoreException) {
                // Skip an unparseable bundle; another attestation may still verify.
            }
        }

        return $bundles;
    }
}
