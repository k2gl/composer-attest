<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest;

/**
 * How the plugin behaves, read from the root package's
 * `extra.k2gl-attest` block:
 *
 * ```json
 * "extra": {
 *   "k2gl-attest": {
 *     "mode": "warn",
 *     "require-attestation": false,
 *     "issuer": "https://token.actions.githubusercontent.com"
 *   }
 * }
 * ```
 *
 * - `mode` — `warn` (log and continue), `enforce` (fail the install on a bad or,
 *   with `require-attestation`, a missing attestation), or `off`.
 * - `require-attestation` — treat a package with no attestation as a failure.
 * - `issuer` — the OIDC issuer the signing certificate must carry.
 */
final class Policy
{
    public const MODE_OFF = 'off';
    public const MODE_WARN = 'warn';
    public const MODE_ENFORCE = 'enforce';

    /** @param self::MODE_* $mode */
    public function __construct(
        public readonly string $mode = self::MODE_WARN,
        public readonly bool $requireAttestation = false,
        public readonly string $issuer = 'https://token.actions.githubusercontent.com',
    ) {}

    /** @param array<string, mixed> $extra the root package's `extra` array */
    public static function fromExtra(array $extra): self
    {
        $config = $extra['k2gl-attest'] ?? null;

        if (! is_array($config)) {
            return new self;
        }
        $mode = $config['mode'] ?? self::MODE_WARN;

        return new self(
            mode: in_array($mode, [self::MODE_OFF, self::MODE_WARN, self::MODE_ENFORCE], true) ? $mode : self::MODE_WARN,
            requireAttestation: (bool) ($config['require-attestation'] ?? false),
            issuer: is_string($config['issuer'] ?? null) && $config['issuer'] !== '' ? $config['issuer'] : 'https://token.actions.githubusercontent.com',
        );
    }

    public function isOff(): bool
    {
        return $this->mode === self::MODE_OFF;
    }

    public function isEnforcing(): bool
    {
        return $this->mode === self::MODE_ENFORCE;
    }
}
