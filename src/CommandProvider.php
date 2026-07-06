<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * Exposes the `composer attest` command. Registered through the plugin's
 * {@see Plugin::getCapabilities()}.
 */
final class CommandProvider implements CommandProviderCapability
{
    /** @return array<int, \Composer\Command\BaseCommand> */
    public function getCommands(): array
    {
        return [new AttestCommand];
    }
}
