<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest;

use Closure;
use Composer\Command\BaseCommand;
use Composer\Package\PackageInterface;
use Composer\Util\HttpDownloader;
use K2gl\ComposerAttest\Internal\GithubRepo;
use K2gl\Sigstore\TrustedRoot;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `composer attest` — verify build-provenance attestations for the packages that
 * are already installed, on demand.
 *
 * The install-time plugin only sees packages Composer downloads during a given
 * run; this command re-fetches each installed GitHub-hosted package's dist and
 * verifies its attestation, so you can audit a whole `vendor/` at once. Honours
 * the same `extra.k2gl-attest` policy; exits non-zero on a failure under
 * `enforce`.
 */
final class AttestCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('attest')
            ->setDescription('Verify build-provenance attestations for installed packages');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();
        $io = $this->getIO();
        $policy = Policy::fromExtra($composer->getPackage()->getExtra());

        if ($policy->isOff()) {
            $io->write('<comment>k2gl-attest is off (extra.k2gl-attest.mode); nothing to do.</comment>');

            return 0;
        }
        $downloader = $composer->getLoop()->getHttpDownloader();
        $verifier = new AttestationVerifier($this->fetcher($downloader), TrustedRoot::fromSigstorePublicGood(), $policy);

        $packages = $composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
        $verified = 0;
        $absent = 0;
        $failed = 0;

        foreach ($packages as $package) {
            $repo = GithubRepo::fromPackage($package->getDistUrl(), $package->getSourceUrl());
            $dist = $package->getDistUrl();

            if ($repo === null || ! is_string($dist)) {
                continue; // not a GitHub-hosted dist; nothing to check against
            }
            $result = $this->check($downloader, $verifier, $repo, $dist);

            if ($result->isVerified()) {
                $io->write(sprintf('  <info>✓</info> %s <comment>(%s)</comment>', $package->getName(), $result->message));
                $verified++;
                $this->emitVsa($policy, $package, $result);
            } elseif (! $result->hasAttestation()) {
                if ($policy->requireAttestation) {
                    $io->writeError(sprintf('  <error>✗</error> %s — no build-provenance attestation', $package->getName()));
                    $failed++;
                } else {
                    $io->write(sprintf('  <comment>·</comment> %s — no attestation', $package->getName()));
                    $absent++;
                }
            } else {
                $io->writeError(sprintf('  <error>✗</error> %s — %s', $package->getName(), $result->message));
                $failed++;
            }
        }
        $io->write(sprintf('<info>%d verified</info>, %d without attestation, <error>%d failed</error>.', $verified, $absent, $failed));

        return $failed > 0 && $policy->isEnforcing() ? 1 : 0;
    }

    private function emitVsa(Policy $policy, PackageInterface $package, VerificationResult $result): void
    {
        if ($result->digest === null) {
            return;
        }
        $path = (new VsaEmitter($policy))->write(
            packageName: $package->getName(),
            version: $package->getPrettyVersion(),
            artifactName: sprintf('%s-%s.zip', basename($package->getName()), $package->getPrettyVersion()),
            digest: $result->digest,
            timeVerified: gmdate('Y-m-d\TH:i:s\Z'),
        );

        if ($path !== null) {
            $this->getIO()->write(sprintf('    <info>→ VSA</info> %s', $path));
        }
    }

    /**
     * @param array{0: string, 1: string} $repo
     */
    private function check(HttpDownloader $downloader, AttestationVerifier $verifier, array $repo, string $dist): VerificationResult
    {
        $tmp = tempnam(sys_get_temp_dir(), 'k2gl-attest');

        if ($tmp === false) {
            return VerificationResult::failed('could not create a temporary file');
        }

        try {
            $downloader->copy($dist, $tmp);

            return $verifier->verify($repo[0], $repo[1], $tmp);
        } catch (Throwable $e) {
            return VerificationResult::failed($e->getMessage());
        } finally {
            @unlink($tmp);
        }
    }

    /** @return Closure(string): ?string */
    private function fetcher(HttpDownloader $downloader): Closure
    {
        return static function (string $url) use ($downloader): ?string {
            try {
                return $downloader->get($url, ['http' => ['header' => ['Accept: application/vnd.github+json']]])->getBody();
            } catch (Throwable) {
                return null; // 404 (no attestation) or a transport error — treated as "absent"
            }
        };
    }
}
