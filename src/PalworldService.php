<?php

namespace Soul\PalworldAdmin;

use App\Models\Server;
use Soul\PalworldAdmin\Api\PalworldClient;
use Soul\PalworldAdmin\Api\PalworldConnection;

class PalworldService
{
    /**
     * Whether the plugin's pages should be shown for this server at all,
     * based on the egg name (config: palworld-admin.egg_name_matches).
     */
    public static function isPalworldServer(?Server $server): bool
    {
        if ($server === null || $server->egg === null) {
            return false;
        }

        $egg = mb_strtolower($server->egg->name);

        foreach (config('palworld-admin.egg_name_matches', ['palworld']) as $needle) {
            if (str_contains($egg, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    public static function clientFor(Server $server): PalworldClient
    {
        return new PalworldClient(
            self::connectionFor($server),
            (int) config('palworld-admin.http_timeout', 5),
        );
    }

    public static function connectionFor(Server $server): PalworldConnection
    {
        $variables = self::variables($server);

        return new PalworldConnection(
            host: config('palworld-admin.host') ?: $server->node->fqdn,
            port: (int) ($variables[config('palworld-admin.variables.rest_api_port')] ?? 0) ?: (int) config('palworld-admin.default_port', 8212),
            adminPassword: (string) ($variables[config('palworld-admin.variables.admin_password')] ?? ''),
        );
    }

    /** @return array<string, string|null> env_variable => effective value */
    private static function variables(Server $server): array
    {
        return $server->variables
            ->mapWithKeys(fn ($variable) => [$variable->env_variable => $variable->server_value ?? $variable->default_value])
            ->all();
    }
}
