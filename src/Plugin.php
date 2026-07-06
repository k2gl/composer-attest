<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PostFileDownloadEvent;
use K2gl\ComposerAttest\Exception\AttestationException;
use K2gl\ComposerAttest\Internal\GithubRepo;
use K2gl\Sigstore\TrustedRoot;
use Closure;
use Throwable;

/**
 * Verifies GitHub build-provenance attestations for packages as Composer
 * downloads them. On each package dist download it hashes the artifact, looks up
 * the attestation GitHub published for that digest, and verifies the Sigstore
 * bundle — reporting the result, or failing the install under `enforce` mode.
 *
 * Configure via `extra.k2gl-attest` (see {@see Policy}). Packages not hosted on
 * GitHub, or with no attestation, are skipped unless `require-attestation` is set.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private IOInterface $io;

    private Composer $composer;

    private Policy $policy;

    private ?TrustedRoot $trustedRoot = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->policy = Policy::fromExtra($composer->getPackage()->getExtra());
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    /** @return array<class-string, class-string> */
    public function getCapabilities(): array
    {
        return [CommandProviderCapability::class => CommandProvider::class];
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [PluginEvents::POST_FILE_DOWNLOAD => 'onPostFileDownload'];
    }

    public function onPostFileDownload(PostFileDownloadEvent $event): void
    {
        if ($this->policy->isOff() || $event->getType() !== 'package') {
            return;
        }
        $package = $event->getContext();
        $file = $event->getFileName();

        if (! $package instanceof PackageInterface || ! is_string($file)) {
            return;
        }
        $repo = GithubRepo::fromPackage($package->getDistUrl(), $package->getSourceUrl());

        if ($repo === null) {
            return; // not a GitHub-hosted package; nothing to check against
        }
        $result = (new AttestationVerifier($this->fetcher(), $this->trustedRoot(), $this->policy))
            ->verify($repo[0], $repo[1], $file);

        $this->report($package->getName(), $result);
    }

    private function report(string $package, VerificationResult $result): void
    {
        if ($result->isVerified()) {
            $this->io->write(sprintf('  <info>✓ attestation verified</info> for %s (%s)', $package, $result->message));

            return;
        }

        if (! $result->hasAttestation()) {
            if ($this->policy->requireAttestation) {
                $this->fail(sprintf('%s has no build-provenance attestation', $package));
            }
            $this->io->write(sprintf('  <comment>· no attestation</comment> for %s', $package), true, IOInterface::VERBOSE);

            return;
        }
        $this->fail(sprintf('attestation for %s did not verify: %s', $package, $result->message));
    }

    private function fail(string $message): void
    {
        if ($this->policy->isEnforcing()) {
            throw new AttestationException($message);
        }
        $this->io->writeError(sprintf('  <warning>! %s</warning>', $message));
    }

    /** @return Closure(string): ?string */
    private function fetcher(): Closure
    {
        $downloader = $this->composer->getLoop()->getHttpDownloader();

        return static function (string $url) use ($downloader): ?string {
            try {
                return $downloader->get($url, ['http' => ['header' => ['Accept: application/vnd.github+json']]])->getBody();
            } catch (Throwable) {
                return null; // 404 (no attestation) or a transport error — treated as "absent"
            }
        };
    }

    private function trustedRoot(): TrustedRoot
    {
        return $this->trustedRoot ??= TrustedRoot::fromSigstorePublicGood();
    }
}
