<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\GitHubPharRelease;
use PHPUnit\Framework\TestCase;

final class GitHubPharReleaseTest extends TestCase
{
    public function test_tag_to_semver_strips_cli_v_prefix(): void
    {
        $this->assertSame('1.2.3', GitHubPharRelease::tagToSemver('cli-v1.2.3'));
    }

    public function test_tag_to_semver_strips_cli_auto_prefix(): void
    {
        $this->assertSame('1.2.3', GitHubPharRelease::tagToSemver('cli-auto-1.2.3'));
    }

    public function test_tag_to_semver_leaves_v_prefix_unchanged(): void
    {
        $this->assertSame('v1.2.3', GitHubPharRelease::tagToSemver('v1.2.3'));
    }

    public function test_tag_to_semver_leaves_plain_version_unchanged(): void
    {
        $this->assertSame('1.0.0', GitHubPharRelease::tagToSemver('1.0.0'));
    }

    public function test_tag_to_semver_case_insensitive_cli_v(): void
    {
        $this->assertSame('2.0.0', GitHubPharRelease::tagToSemver('CLI-V2.0.0'));
    }

    public function test_tag_to_semver_case_insensitive_cli_auto(): void
    {
        $this->assertSame('3.0.0', GitHubPharRelease::tagToSemver('CLI-AUTO-3.0.0'));
    }

    public function test_tag_to_semver_empty_string(): void
    {
        $this->assertSame('', GitHubPharRelease::tagToSemver(''));
    }

    public function test_tag_to_semver_only_cli_v_prefix(): void
    {
        // cli-v with no version still strips the prefix.
        $this->assertSame('', GitHubPharRelease::tagToSemver('cli-v'));
    }
}
