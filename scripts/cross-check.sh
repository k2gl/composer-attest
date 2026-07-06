#!/usr/bin/env bash
#
# Differential check: verify one real attested package with BOTH our verifier and
# GitHub's official `gh attestation verify`, and require them to agree — verified
# on the clean zipball, rejected on a tampered one. Independent implementations
# (pure-PHP vs sigstore-go) agreeing is strong evidence our path is correct.
#
# Run it yourself:  GITHUB_TOKEN=$(gh auth token) bash scripts/cross-check.sh
set -euo pipefail

OWNER=k2gl
REPO=dsse
: "${GITHUB_TOKEN:?set GITHUB_TOKEN (e.g. GITHUB_TOKEN=\$(gh auth token))}"

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# The exact commit Composer would install — Packagist pins it as the dist reference.
ref=$(curl -sfS "https://repo.packagist.org/p2/$OWNER/$REPO.json" \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["packages"]["'"$OWNER/$REPO"'"][0]["dist"]["reference"] ?? "";')
[ -n "$ref" ] || { echo "could not resolve $OWNER/$REPO dist reference"; exit 1; }
echo "→ $OWNER/$REPO dist reference: $ref"

curl -sfSL -H "Authorization: Bearer $GITHUB_TOKEN" -H "Accept: application/vnd.github+json" \
  "https://api.github.com/repos/$OWNER/$REPO/zipball/$ref" -o "$work/pkg.zip"
echo "→ downloaded zipball ($(wc -c < "$work/pkg.zip") bytes)"

verdict_gh() { gh attestation verify "$1" --repo "$OWNER/$REPO" >/dev/null 2>&1 && echo VERIFIED || echo REJECTED; }

# --- clean artifact: both must verify ---
ours=$(php scripts/verify-one.php "$OWNER" "$REPO" "$work/pkg.zip" || true)
theirs=$(verdict_gh "$work/pkg.zip")
echo "clean:    ours=$ours  gh=$theirs"
[ "$ours" = VERIFIED ] && [ "$theirs" = VERIFIED ] || { echo "✗ mismatch on clean artifact"; exit 1; }

# --- tampered artifact: both must reject ---
cp "$work/pkg.zip" "$work/tampered.zip"
printf 'tamper' >> "$work/tampered.zip"
ours_t=$(php scripts/verify-one.php "$OWNER" "$REPO" "$work/tampered.zip" || true)
theirs_t=$(verdict_gh "$work/tampered.zip")
echo "tampered: ours=$ours_t  gh=$theirs_t"
[ "$ours_t" != VERIFIED ] && [ "$theirs_t" != VERIFIED ] || { echo "✗ tamper not rejected by both"; exit 1; }

echo "✓ cross-check passed — our verifier and gh attestation verify agree"
