<?php

namespace Soul\PalworldAdmin;

use App\Enums\ContainerStatus;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
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

    /**
     * Why the game is expectedly unreachable right now - or null if it should
     * be reachable (container running / state unknown), in which case a
     * connection failure is a real error worth showing.
     */
    public static function offlineLabel(Server $server): ?string
    {
        try {
            $status = $server->retrieveStatus();
        } catch (\Exception) {
            return null;
        }

        return match ($status) {
            ContainerStatus::Offline, ContainerStatus::Exited, ContainerStatus::Dead, ContainerStatus::Paused => 'Server not running',
            ContainerStatus::Starting, ContainerStatus::Created, ContainerStatus::Restarting => 'Server is starting',
            ContainerStatus::Stopping, ContainerStatus::Removing => 'Server is stopping',
            default => null,
        };
    }

    /**
     * Human-readable hint when the configured REST API port has no matching
     * allocation on the server - the most common cause of "unreachable",
     * especially with multiple Palworld servers on one node.
     */
    public static function allocationMismatchHint(Server $server): ?string
    {
        $port = self::connectionFor($server)->port;
        $ports = $server->allocations->pluck('port');

        if ($ports->contains($port)) {
            return null;
        }

        return sprintf(
            'The REST API port %d (from the %s variable) is not allocated to this server (allocated ports: %s). '
            . 'Add a matching allocation in the Network tab or correct the variable, then restart the server.',
            $port,
            config('palworld-admin.variables.rest_api_port'),
            $ports->isEmpty() ? 'none' : $ports->join(', '),
        );
    }

    /**
     * Read the game's local ban list (banlist.txt) via Wings.
     * Missing file = no bans yet.
     *
     * @return array<string, string> userId => playerUid
     */
    public static function readBanList(Server $server): array
    {
        try {
            $content = (new DaemonFileRepository())
                ->setServer($server)
                ->getContent(config('palworld-admin.ban_list_path'), config('panel.files.max_edit_size'));
        } catch (\Illuminate\Contracts\Filesystem\FileNotFoundException) {
            return [];
        }

        $bans = [];
        foreach (preg_split('/\R/', $content) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$userId, $playerUid] = array_pad(explode(',', $line, 2), 2, '');
            $bans[$userId] = $playerUid;
        }

        return $bans;
    }

    /** @return array<string, string|null> env_variable => effective value */
    private static function variables(Server $server): array
    {
        return $server->variables
            ->mapWithKeys(fn ($variable) => [$variable->env_variable => $variable->server_value ?? $variable->default_value])
            ->all();
    }
}
