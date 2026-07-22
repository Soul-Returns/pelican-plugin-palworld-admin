# Development setup

Everything runs in Docker. The setup script builds a disposable Pelican dev
stack with **this checkout bind-mounted as the live plugin** — edit code here,
reload the browser.

## Quick start

```sh
./dev/setup.sh
```

That gives you:

- Pelican panel at **http://localhost:8888** (change with `PANEL_PORT=8890 ./dev/setup.sh`)
  - login: `admin` / `palworld-dev`
- this repo mounted at `/var/www/html/plugins/palworld-admin`, plugin installed,
  migrations run
- a seeded node (`host.docker.internal`) and a server **"Palworld Dev"** using a
  placeholder Palworld egg (allocations `8211` game / `8212` REST API,
  `ADMIN_PASSWORD=devpassword`) so all plugin pages are reachable
- the stack lives in `dev/stack/` (gitignored): `docker compose` from there for
  logs/up/down

To develop against your real egg instead of the placeholder:

```sh
./dev/setup.sh --egg /path/to/egg-palworld.yaml
```

## Faking the game (no 5 GB install needed)

```sh
python3 dev/mock-palworld-api.py
```

Serves the official REST API endpoints on `:8212` with fixture players
(`admin`/`devpassword`); kick/ban actually mutate its state so the UI feedback
loop feels real. The players page works fully against it. Stop it before
running a real server — both want port 8212.

## Running a real Palworld server (optional, in-game testing)

```sh
./dev/setup.sh --with-wings     # needs sudo: /etc/pelican, /var/lib/pelican, ...
```

Adds a Wings container wired to the panel (`remote: http://panel`, browser
websocket origin allowed). Then press **Reinstall** on the dev server in the
panel (with a real egg imported via `--egg`) — that downloads ~5 GB of server
files — and start it. Game reachable at `localhost:8211` (UDP).

Only one wings per host: it owns `/etc/pelican` + `/var/lib/pelican`, so don't
mix multiple dev stacks with `--with-wings`.

## Cheat sheet

```sh
cd dev/stack
docker compose exec panel php artisan config:clear            # after config/ changes
docker compose exec panel php artisan filament:optimize-clear # after adding pages
docker compose exec panel php artisan migrate --force         # after adding migrations
docker compose exec panel php artisan tinker                  # poke at panel APIs
docker compose logs -f wings                                  # watch installs/boots
docker compose down                                           # stop (add -v to wipe data)
```

## Gotchas

- The panel container runs as uid 82 and occasionally writes `plugin.json`
  (install status). The setup script makes it writable; if git shows a
  `meta.status` change in plugin.json, that's the panel — don't commit it as
  part of unrelated work (the release zip strips `meta` anyway).
- Blade view changes may need `php artisan view:clear`.
- If plugin pages vanish after edits: `filament:optimize-clear` (cached
  components), then reload.
- The settings page only unlocks editing while the game server is fully
  stopped — that's by design (Palworld rewrites its ini on shutdown), not a bug.
