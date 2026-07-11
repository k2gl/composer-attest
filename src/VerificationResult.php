<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest;

/**
 * The outcome of checking one package: a build-provenance attestation was found
 * and verified, none was published, or one was found but did not verify.
 */
final class VerificationResult
{
    private const VERIFIED = 'verified';
    private const NONE = 'none';
    private const FAILED = 'failed';

    private function __construct(
        private readonly string $status,
        public readonly string $message,
        public readonly ?string $digest = null,
    ) {}

    public static function verified(string $identity, string $digest): self
    {
        return new self(self::VERIFIED, $identity, $digest);
    }

    public static function noAttestation(): self
    {
        return new self(self::NONE, 'no build-provenance attestation published');
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, $message);
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    public function hasAttestation(): bool
    {
        return $this->status !== self::NONE;
    }

    public function isFailure(): bool
    {
        return $this->status === self::FAILED;
    }
}
