<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest;

use K2gl\ComposerAttest\Exception\AttestationException;
use K2gl\Dsse\EcdsaP256Signer;
use K2gl\Dsse\EcdsaP384Signer;
use K2gl\Dsse\EcdsaP521Signer;
use K2gl\Dsse\Envelope;
use K2gl\Dsse\RsaSigner;
use K2gl\Dsse\Signer;
use K2gl\InToto\Statement;

/**
 * Signs a VSA statement into a DSSE envelope with a local private key, so the
 * emitted attestation is verifiable rather than a bare record. The signing
 * algorithm is read from the key itself — RSA or ECDSA P-256/384/521.
 *
 * Keyless (Fulcio/OIDC) signing is out of scope here: a Composer install has no
 * interactive OIDC flow, so a local key is the sensible path.
 */
final class VsaSigner
{
    private function __construct(private readonly Signer $signer) {}

    /** Load a signer from a PEM private key, choosing the algorithm from the key. */
    public static function fromPrivateKeyPem(string $pem): self
    {
        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new AttestationException('could not load the VSA signing key');
        }
        $details = openssl_pkey_get_details($key);
        $type = is_array($details) ? ($details['type'] ?? null) : null;
        $bits = is_array($details) ? ($details['bits'] ?? 0) : 0;

        return new self(match (true) {
            $type === OPENSSL_KEYTYPE_RSA => RsaSigner::fromPem($pem),
            $type === OPENSSL_KEYTYPE_EC && $bits === 256 => EcdsaP256Signer::fromPem($pem),
            $type === OPENSSL_KEYTYPE_EC && $bits === 384 => EcdsaP384Signer::fromPem($pem),
            $type === OPENSSL_KEYTYPE_EC && $bits === 521 => EcdsaP521Signer::fromPem($pem),
            default => throw new AttestationException('unsupported VSA signing key: use RSA or ECDSA P-256/384/521'),
        });
    }

    /** Sign the statement, returning the DSSE envelope carrying it. */
    public function sign(Statement $statement): Envelope
    {
        return $statement->sign($this->signer);
    }
}
