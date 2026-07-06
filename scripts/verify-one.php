<?php

/**
 * Standalone: verify one artifact against its GitHub build-provenance attestation
 * with our own AttestationVerifier, independent of Composer. Used by the
 * cross-check script to compare our verdict with `gh attestation verify`.
 *
 * Usage: php scripts/verify-one.php <owner> <repo> <artifact-path>
 * Prints VERIFIED | FAILED | NONE and exits 0 only when VERIFIED.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use K2gl\ComposerAttest\AttestationVerifier;
use K2gl\ComposerAttest\Policy;
use K2gl\Sigstore\TrustedRoot;

[$owner, $repo, $path] = [$argv[1] ?? null, $argv[2] ?? null, $argv[3] ?? null];

if ($owner === null || $repo === null || $path === null) {
    fwrite(STDERR, "usage: verify-one.php <owner> <repo> <artifact-path>\n");
    exit(2);
}

$token = getenv('GITHUB_TOKEN') ?: '';

$fetch = static function (string $url) use ($token): ?string {
    $headers = ['Accept: application/vnd.github+json', 'User-Agent: k2gl-cross-check'];

    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $context = stream_context_create(['http' => [
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;

    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $status = (int) $m[1];
        }
    }

    return $body === false || $status >= 400 ? null : $body;
};

$result = (new AttestationVerifier($fetch, TrustedRoot::fromSigstorePublicGood(), new Policy))
    ->verify($owner, $repo, $path);

if ($result->isVerified()) {
    echo "VERIFIED\n";
    exit(0);
}

echo $result->hasAttestation() ? "FAILED\n" : "NONE\n";
exit(1);
