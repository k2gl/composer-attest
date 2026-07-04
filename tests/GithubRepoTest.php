<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest\Tests;

use K2gl\ComposerAttest\Internal\GithubRepo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function K2gl\PHPUnitFluentAssertions\fact;

#[CoversClass(GithubRepo::class)]
final class GithubRepoTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, ?array{0:string,1:string}}> */
    public static function cases(): iterable
    {
        yield 'api dist zipball' => ['https://api.github.com/repos/k2gl/dsse/zipball/abc123', null, ['k2gl', 'dsse']];
        yield 'git source with .git' => [null, 'https://github.com/k2gl/sigstore-verify.git', ['k2gl', 'sigstore-verify']];
        yield 'ssh source' => [null, 'git@github.com:k2gl/enum.git', ['k2gl', 'enum']];
        yield 'dist preferred over source' => ['https://api.github.com/repos/owner/repo/zipball/x', 'https://github.com/other/thing.git', ['owner', 'repo']];
        yield 'gitlab is not github' => ['https://gitlab.com/foo/bar/-/archive/x.zip', 'https://gitlab.com/foo/bar.git', null];
        yield 'packagist mirror dist falls back to source' => ['https://repo.packagist.org/dist/x.zip', 'https://github.com/k2gl/tuf.git', ['k2gl', 'tuf']];
        yield 'nothing' => [null, null, null];
    }

    #[DataProvider('cases')]
    public function testExtractsOwnerAndRepo(?string $dist, ?string $source, ?array $expected): void
    {
        fact(GithubRepo::fromPackage($dist, $source))->is($expected);
    }
}
