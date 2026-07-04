<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest\Internal;

/**
 * Pulls the owner/repo out of a package's dist or source URL, for GitHub-hosted
 * packages. Handles the API dist form (api.github.com/repos/owner/repo/zipball/…)
 * and the git source form (github.com/owner/repo(.git)).
 *
 * @internal
 */
final class GithubRepo
{
    /**
     * @return array{0: string, 1: string}|null [owner, repo], or null if not GitHub
     */
    public static function fromPackage(?string $distUrl, ?string $sourceUrl): ?array
    {
        foreach ([$distUrl, $sourceUrl] as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            if (preg_match('#api\.github\.com/repos/([^/]+)/([^/]+)#', $url, $m) === 1) {
                return [$m[1], self::trimGit($m[2])];
            }

            if (preg_match('#github\.com[:/]([^/]+)/([^/\s]+?)(?:\.git)?(?:/|$)#', $url, $m) === 1) {
                return [$m[1], self::trimGit($m[2])];
            }
        }

        return null;
    }

    private static function trimGit(string $repo): string
    {
        return preg_replace('/\.git$/', '', $repo) ?? $repo;
    }
}
