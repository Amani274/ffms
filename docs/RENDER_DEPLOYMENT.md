# Deploying to Render

**Status: prepared but not yet built.** Docker is not installed on the development machine, so the container was syntax-checked only
(`start.sh` parses; PHP files lint). The pattern mirrors your HRM project's Render setup. Expect the first deploy to be the real test.

## What gets deployed

One Docker web service: Apache + PHP 8.2, the built React app, and — in demo mode — a MariaDB server inside the same container.
On every start it creates the database, applies migrations, and seeds the demo data if the database is empty.
The site is served at `https://<service>.onrender.com/` (redirects to `/ffms/app/`).

## Steps (you do these — they need your accounts)

1. **Create a GitHub repository** and push the project folder (`C:\xampp\htdocs\ffms`). `.gitignore` already excludes `.env`, `node_modules`, the local `app/` build and backups.
   ```bat
   cd C:\xampp\htdocs\ffms
   git init
   git add .
   git commit -m "FFMS farm management system"
   git branch -M main
   git remote add origin https://github.com/<you>/ffms.git
   git push -u origin main
   ```
2. In Render: **New → Blueprint**, connect the repository. Render reads `render.yaml` and creates the `ffms` web service (free plan).
3. Wait for the build (several minutes: Node build + Apache/MariaDB image). Health check is `/ffms/api/health`.
4. Open the service URL and sign in with the demo accounts (`owner@ffms.test` / `Farm@Demo2026`).

If you prefer not to use a Blueprint: **New → Web Service → Docker**, same repo, leave the Docker context as the repo root, set health check path `/ffms/api/health`.

## Know before sharing the link

- **Data resets.** The built-in database is ephemeral. The free plan sleeps after inactivity and a cold start takes about a minute; every start re-seeds fresh demo data.
  Changes people make are lost on restart.
- **The demo password is public** (shown on the login page). Anyone with the URL can sign in. Do not enter real data. To hide the hints, set the Docker build arg
  `VITE_SHOW_DEMO=0`, and set an env var `DEMO_PASSWORD` to something private.
- **Memory.** The free instance has 512 MB. Apache and MariaDB are tuned small (`docker/apache.conf`, `docker/mariadb.cnf`); if it restarts under load, move to a paid instance.
- **Weather** uses the live forecast when the container has internet (Render does) and falls back to an estimate otherwise.

## Persistent database (when it needs to keep data)

Render does not host MySQL on its free tier, so use a managed MySQL/MariaDB (e.g. TiDB Cloud Starter or Aiven free). Set these env vars on the service:

| Variable | Value |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | from the provider (create the database first) |
| `DB_SSL` | `1` (uses the system CA bundle; override with `DB_SSL_CA`) |
| `SEED_ON_START` | `0` — then create your admin: open a Render shell and run `ADMIN_PASSWORD=... php bin/create-admin.php "Name" you@example.com` from `/var/www/html/ffms` |

With an external `DB_HOST` the start script skips the built-in MariaDB automatically.
Alternative on a paid plan: keep demo mode and attach a persistent disk at `/var/lib/mysql`.

## Changing the build later

Rebuild happens on every push to the connected branch. To test locally with Docker installed:
`docker build -t ffms . && docker run -p 10000:10000 ffms` then open `http://localhost:10000/`.
