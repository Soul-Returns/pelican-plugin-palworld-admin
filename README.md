# Palworld Admin — Pelican Panel Plugin

Adds Palworld administration pages to the [Pelican](https://pelican.dev) server panel
(client area), powered by the official
[Palworld REST API](https://docs.palworldgame.com/category/rest-api/):

- **Palworld Players** — live player table (name, account, Steam ID, level, ping,
  coordinates, building count) with kick/ban actions, unban, in-game announcements,
  world save, and live server metrics (FPS, player count, in-game day, uptime).
  Auto-refreshes every 15 seconds.
- **Palworld World Settings** — edit the `OptionSettings` values in
  `PalWorldSettings.ini` directly from the panel (via Wings), with a
  restart-to-apply action. Keys managed by panel startup variables are flagged.

Pages only appear on servers whose egg name contains `palworld` and respect Pelican's
subuser permissions (`control.console` for player actions, `file.*` for settings,
`control.restart` for the restart button).

## Requirements

- Pelican panel `1.0.0-beta35` or newer (Filament v5 plugin API)
- A Palworld egg whose variables include:
  - `ADMIN_PASSWORD` — the server's AdminPassword (used as REST API credential)
  - `REST_API_ENABLED` = `True` and `REST_API_PORT` (default `8212`) — applied to
    `PalWorldSettings.ini` by the
    [Palworld Config Parser Tool](https://github.com/pelican-eggs/Palworld-Config-Parser-Tool)
    on every server start
- An allocation for the REST API port (TCP) assigned to the server, reachable
  **from the panel host only** (see Security below)

## Installation

```sh
cd /var/www/pelican/plugins        # your panel's plugins directory
git clone https://github.com/Soul-Returns/pelican-plugin-palworld-admin palworld-admin
cd /var/www/pelican
php artisan p:plugin:install palworld-admin
php artisan filament:optimize-clear
```

The folder name **must** be `palworld-admin` (it has to match the plugin id).

## Configuration

Defaults work out of the box. To override, see `config/palworld-admin.php`:
host resolution (defaults to the server's node FQDN), default REST port, the egg
variable names, egg-name matching, and the HTTP timeout — each overridable via
`PALWORLD_ADMIN_*` environment variables in the panel's `.env`.

## Security

The Palworld REST API has no rate limiting and only Basic auth — **never expose it to
the public internet**. Firewall the REST port on the game node so that only the panel
host can reach it, e.g. with ufw:

```sh
ufw deny 8212/tcp
ufw allow from <panel-ip> to any port 8212 proto tcp
```

The plugin talks to the API server-side (panel → node); players' browsers never
connect to it directly.

## Known limitations

- No teleport / item-give: the official REST API doesn't support them. They would
  require a UE4SS-based server mod (e.g. PalDefender, Windows servers only).
- The players endpoint only lists online players, so bans of offline players need
  their user id (header → Unban works the same way).
