# composer-attest

A Composer plugin that verifies **GitHub build-provenance attestations** for the
packages you install. As Composer downloads each package, the plugin hashes the
artifact, asks GitHub for any attestation bound to that digest, and verifies the
Sigstore bundle — requiring the signing identity to be a GitHub Actions workflow
of the package's own repository.

It builds on [`k2gl/sigstore-verify`](https://github.com/k2gl/sigstore-verify) for
the cryptographic verification (certificate chain, transparency-log inclusion,
DSSE envelope, identity), so a passing check means the artifact really was built
by the repository's own CI and recorded in the public transparency log.

> **Status: proof of concept.** The verification path is real and tested end to
> end (see [Caveat](#caveat-what-gets-attested) for what this does and does not
> cover today).

## Install

```bash
composer require k2gl/composer-attest
```

Composer will ask to trust the plugin the first time (it runs during install).

## Configure

All configuration lives under `extra.k2gl-attest` in your root `composer.json`:

```json
{
  "extra": {
    "k2gl-attest": {
      "mode": "warn",
      "require-attestation": false,
      "issuer": "https://token.actions.githubusercontent.com"
    }
  }
}
```

- **`mode`**
  - `warn` (default) — verify and print the result; a bad attestation is a
    warning, not a stop.
  - `enforce` — fail the install if an attestation is present but does not verify
    (and, with `require-attestation`, if one is missing).
  - `off` — do nothing.
- **`require-attestation`** — treat a package that publishes *no* attestation as a
  failure (respecting `mode`). Off by default, since most packages don't publish
  one yet.
- **`issuer`** — the OIDC issuer the signing certificate must carry. Defaults to
  GitHub Actions.
- **`emit-vsa`** — after a package verifies, write a SLSA Verification Summary
  Attestation recording the outcome (see [below](#emit-a-verification-summary-vsa)).
  Off by default.
- **`vsa-dir`** — where those VSA files are written. Defaults to `.attestations/vsa`.

## What you'll see

```
  ✓ attestation verified for k2gl/sigstore-verify (k2gl/sigstore-verify)
  · no attestation for some/other-package
```

Under `enforce`, a package whose attestation fails verification aborts the install
with a non-zero exit code.

## Verify on demand

The plugin only sees packages Composer downloads during a given install. To audit
everything already in `vendor/` at once, run:

```bash
composer attest
```

It re-fetches each installed GitHub-hosted package's dist, verifies its
attestation, and prints a summary — honouring the same `extra.k2gl-attest` policy,
and exiting non-zero on a failure under `enforce`.

## Emit a Verification Summary (VSA)

The check itself is transient — a line in the install log that scrolls past. Turn
on `emit-vsa` to also record each passing verification as a **SLSA Verification
Summary Attestation** (`https://slsa.dev/verification_summary/v1`): a portable
in-toto Statement you can store next to `vendor/`, hand to a downstream policy
gate, or sign later.

```json
{
  "extra": {
    "k2gl-attest": { "mode": "warn", "emit-vsa": true }
  }
}
```

For each verified package the plugin writes `<vsa-dir>/<vendor>-<name>-<version>.vsa.json`
— a Statement over the artifact digest carrying the verifier, the policy issuer it
required, the outcome (`PASSED`), and the SLSA level GitHub build provenance meets
(`SLSA_BUILD_LEVEL_2`). `composer attest` emits them for the whole `vendor/` in one
pass. Built on [`k2gl/slsa-provenance`](https://github.com/k2gl/slsa-provenance);
the files are unsigned records — signing is a separate step.

## How it works

The plugin subscribes to Composer's `POST_FILE_DOWNLOAD` event. For each package
dist it:

1. computes the artifact's SHA-256 digest;
2. requests `GET /repos/{owner}/{repo}/attestations/sha256:{digest}` (through
   Composer's authenticated HTTP client);
3. parses each returned Sigstore bundle and verifies it with `sigstore-verify`,
   requiring a GitHub Actions identity of `{owner}/{repo}`;
4. confirms the artifact's digest is one of the in-toto statement's subjects.

## Cross-checked against GitHub's own tooling

Two independent implementations agreeing is stronger evidence than either one's
own tests. The [cross-check workflow](.github/workflows/cross-check.yml) verifies a
real attested package with **both** this verifier (pure PHP) and GitHub's official
`gh attestation verify` (sigstore-go), and requires them to agree — verified on the
clean zipball, rejected on a tampered one. Run it yourself:

```bash
GITHUB_TOKEN=$(gh auth token) bash scripts/cross-check.sh
```

## Caveat: what gets attested

Composer installs a package's dist as a **GitHub zipball**
(`api.github.com/repos/{owner}/{repo}/zipball/{ref}`). For the plugin to verify a
package at install time, the repository must publish a build-provenance
attestation **for that zipball's digest**.

Most repositories today attest their *release tarball* (a `git archive` output) or
other build outputs — a different artifact than the zipball Composer fetches — so
the plugin will report "no attestation" for them. This is a property of the
current ecosystem, not the plugin: it is exactly why the zipball digest is
reproducible yet unattested. As registries and publishers begin attesting the
artifacts Composer actually installs, the plugin verifies them with no changes.

The verification logic itself is proven: it verifies a real published attestation
end to end (the k2gl release tarballs, whose digests *are* attested, verify
against the live GitHub attestations API).

## Requirements

- PHP 8.1+
- Composer 2 (`composer-plugin-api ^2.0`)

## License

MIT
