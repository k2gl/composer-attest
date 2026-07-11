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
 * - `emit-vsa` — after a package verifies, write a SLSA Verification Summary
 *   Attestation (VSA) recording the outcome.
 * - `vsa-dir` — where those VSA files are written (default `.attestations/vsa`).
 * - `vsa-sign-key` — path to a PEM private key; when set, each VSA is signed into
 *   a DSSE envelope instead of written as a bare statement.
 */
final class Policy
{
    public const MODE_OFF = 'off';
    public const MODE_WARN = 'warn';
    public const MODE_ENFORCE = 'enforce';

    private const DEFAULT_ISSUER = 'https://token.actions.githubusercontent.com';

    private const DEFAULT_VSA_DIR = '.attestations/vsa';

    /** @param self::MODE_* $mode */
    public function __construct(
        public readonly string $mode = self::MODE_WARN,
        public readonly bool $requireAttestation = false,
        public readonly string $issuer = self::DEFAULT_ISSUER,
        public readonly bool $emitVsa = false,
        public readonly string $vsaDir = self::DEFAULT_VSA_DIR,
        public readonly ?string $vsaSignKey = null,
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
            issuer: is_string($config['issuer'] ?? null) && $config['issuer'] !== '' ? $config['issuer'] : self::DEFAULT_ISSUER,
            emitVsa: (bool) ($config['emit-vsa'] ?? false),
            vsaDir: is_string($config['vsa-dir'] ?? null) && $config['vsa-dir'] !== '' ? $config['vsa-dir'] : self::DEFAULT_VSA_DIR,
            vsaSignKey: is_string($config['vsa-sign-key'] ?? null) && $config['vsa-sign-key'] !== '' ? $config['vsa-sign-key'] : null,
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
