# palworld-admin — Pelican panel plugin

Filament plugin for Pelican (pelican.dev, a Pterodactyl fork) that adds Palworld
administration to the server (client) panel: live players (kick/ban/announce/save),
a ban list backed by `banlist.txt` + a `palworld_bans` ledger table, and a
`PalWorldSettings.ini` world-settings editor. Data comes from the official
Palworld REST API (basic auth `admin:<ADMIN_PASSWORD>`, port from the
`REST_API_PORT` egg variable) and from Wings file access.

Target panel: `1.0.0-beta35` (see `panel_version` in plugin.json — STRICT match
unless prefixed with `^`). Filament v5 (split namespaces: `Filament\Schemas\*`
for layout, `Filament\Forms\Components\*` for fields), Laravel 13, PHP 8.3+.

## Layout

- `plugin.json` — id MUST equal the folder name AND the release zip filename.
  `meta.status` is panel-managed state written back into this file; the release
  workflow strips it (shipping "enabled" would bypass the panel's Install step
  and its cache clearing).
- `src/PalworldAdminPlugin.php` — pages MUST be registered here via
  `$panel->pages([...])`; there is no auto-discovery for plugin pages.
- `src/PalworldService.php` — egg detection (name contains "palworld"),
  connection resolution (node fqdn + egg variables), offline labels, banlist.
- `src/Api/` — REST client (framework-light). `src/Support/OptionSettings.php`
  — parser for the single-line `OptionSettings=(...)` UE tuple (has a lenient
  salvage mode for corrupted files; keep round-trip tests green).
- `src/Filament/Pages/` — the three pages. Views under `resources/views`
  are namespaced `palworld-admin::` automatically by the panel.
- `database/migrations/` — run by the panel's install/update job, or
  `php artisan migrate --force` for git-based installs.

## Panel API landmarks (verified against beta35)

- Current server (tenant): `Filament::getTenant()`; permissions:
  `user()?->can(SubuserPermission::X, $server)` (enum `App\Enums\SubuserPermission`).
- Files via Wings: `(new DaemonFileRepository())->setServer($s)->getContent()/putContent()`.
- Power: `app(DaemonServerRepository::class)->setServer($s)->power('start'|'stop'|...)`;
  console command: `$server->send('cmd')`.
- Container state: `$server->retrieveStatus()` (cached 15 s; key
  `servers.{uuid}.status` — forget it for real-time polling).
- Custom-data tables: `->records(fn () => [...])` keyed arrays; actions receive
  `array $record`. After mutating inside an action call
  `$this->flushCachedTableRecords()` — records are memoized per request BEFORE
  the action runs.

## Hard-won gotchas (do not relearn these)

- Palworld writes its in-memory settings back to `PalWorldSettings.ini` on
  shutdown — editing the file is only valid while the server is fully stopped
  (hence the settings page's stop→edit→save→start flow).
- The egg's config parser tool only UPDATES ini keys that already exist
  ("Key not found" = skipped) and corrupts the tuple when a value contains
  `)` — the egg guards `SERVER_NAME`/`SERVER_DESCRIPTION` against `" ( )`.
- Ban/unban via REST rewrite `banlist.txt` asynchronously — UI uses optimistic
  filtering (`justUnbanned`) plus polling.
- Panels cache Filament components: after installing/updating the plugin
  outside the panel UI run `php artisan filament:optimize-clear`. Panels also
  run a queue worker that must be restarted after plugin changes
  (`php artisan queue:restart`) or plugin jobs fail with ModelNotFound.
- The page view must NOT bind `wire:submit` — buttons inside the form default
  to type=submit and would trigger save() on every action click.
- Table `heading` renders BEFORE the records closure in the same request —
  state used there must be computed independently (see players page indicator).

## Release process

1. Bump `version` in plugin.json (the workflow FAILS if tag ≠ version).
2. Commit, tag `vX.Y.Z`, push tag → GitHub Action builds `palworld-admin.zip`
   (meta-stripped, git-archive respecting `.gitattributes` export-ignore) and
   `update.json`, drafts the release. Publishing it activates the update badge
   on installed panels via `update_url` (`releases/latest/download/update.json`).
3. The zip filename must stay exactly `palworld-admin.zip` (panel derives the
   plugin id from it).

## Development

`DEVELOPMENT.md` covers the dockerized dev stack; `dev/setup.sh` builds it
(panel + optional wings, this checkout bind-mounted as the live plugin,
seeded dev server). `dev/mock-palworld-api.py` fakes the game's REST API on
:8212 (`admin`/`devpassword`) for UI work without a game server. PHP changes
apply on reload; after config file changes run `php artisan config:clear` in
the panel container.

Browser-automation (Playwright) testing of the panel UI is possible against
the dev stack, but the maintainer tests UI changes in their own browser -
only reach for Playwright when they ask for it or a bug genuinely can't be
diagnosed without it.
