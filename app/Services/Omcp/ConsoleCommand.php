<?php

namespace Pterodactyl\Services\Omcp;

class ConsoleCommand
{
    public static function allowed(string $command): bool
    {
        if (strlen($command) < 1 || strlen($command) > 256 || preg_match('/[\x00-\x1f\x7f]/', $command)) {
            return false;
        }

        return in_array($command, ['list', 'save-all', 'save-all flush', 'whitelist list', 'time query daytime', 'time query gametime'], true)
            || (str_starts_with($command, 'say ') && trim(substr($command, 4)) !== '');
    }
}
