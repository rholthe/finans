# Finans — a self-hosted YNAB alternative

Finans is a self-hosted, single-user budgeting app with European bank
integration, built as a replacement for YNAB. Money that comes in lands in
**Ready to Assign**, and you give every krone a job by assigning it to
categories. Transactions are pulled automatically from your bank, categorized by
rules, and reconciled against your real balances.

> **Heads up — read before you self-host**
> - **The UI is in Norwegian** and the app currently supports **NOK only**.
>   Bank connectivity works across Europe (the aggregators below cover most of
>   the EEA), but the interface and currency are not localized yet.
> - It's a **single-user** app secured by **one shared password** — there is no
>   multi-user account system. Run it for yourself (or a household), behind
>   HTTPS.
> - This is a personal project shared in the hope it's useful. It comes with **no
>   warranty** (see [LICENSE](LICENSE)). You are responsible for your own
>   deployment, backups, and the bank credentials you configure.

## Features

- **Manual + automatic accounting** — cash, bank, savings, credit card and loan
  accounts; on-budget vs. tracking accounts.
- **Envelope budgeting engine** — category groups & categories, monthly
  assignment, activity/available, and a leak-free *Ready to Assign*.
- **Goals & auto-assign** — monthly funding, "have X available each month", and
  save-up-to-an-amount-by-a-date targets.
- **Bank integration** behind a provider-agnostic abstraction:
  **GoCardless Bank Account Data** and **Enable Banking** (use either or both).
- **Auto-categorization rules** — derive payee/memo and a target (a category,
  *Ready to Assign*, or a transfer) from the bank's description; most specific
  rule wins.
- **Scheduled / recurring transactions** (bills), including scheduled transfers.
- **Credit cards** as ordinary accounts that can go negative; pay them down with
  a transfer.
- **Reconciliation** against your real bank balance.
- **Split transactions** across multiple categories.
- **Reports** — spending by category, income vs. spending, category trends, net
  worth (Recharts).
- **Nightly sync** (queued job + scheduler) with an email report, plus
  consent-expiry warnings and one-click renewal.
- **Mobile quick-entry** screen for jotting down cash transactions on the go.

## Tech stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12 (PHP 8.2+) as a JSON API |
| Frontend | React 19 + TypeScript + React Router 7, built with Vite; Tailwind 4; Recharts |
| Database | SQLite locally, MySQL/MariaDB in production (migrations are DB-agnostic) |
| Auth | Single app-wide password; 1-year session cookie |
| Queue / cache | `database` driver — **no Redis required** |

Frontend and backend are the **same Laravel app on the same origin**: the React
SPA is served by a catch-all Blade route, and the API lives under `/api/*` with
session + CSRF protection.

## Requirements

- **PHP 8.2+** (developed and tested on 8.4) with the usual Laravel extensions
  (`mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`,
  `fileinfo`). `openssl` is required for Enable Banking's JWT signing.
- **Composer 2**
- **Node.js 20+** and npm (for building the frontend with Vite 7)
- A database: **SQLite** is enough locally; **MySQL/MariaDB** recommended for
  production.
- For automatic bank sync in production: a **persistent queue worker** and the
  **Laravel scheduler**. The bundled Docker setup runs both as their own
  containers — no cron or Supervisor on the host. See
  [Production deployment](#production-deployment).
- A free account with at least one bank aggregator —
  **[GoCardless Bank Account Data](https://gocardless.com/bank-account-data/)**
  or **[Enable Banking](https://enablebanking.com/)**. See
  [docs/bank-setup.md](docs/bank-setup.md).

## Quick start (local)

```bash
git clone https://github.com/rholthe/finans.git
cd finans

composer install
npm install

cp .env.example .env
php artisan key:generate
```

For the simplest local setup, use SQLite. Edit `.env`:

```dotenv
DB_CONNECTION=sqlite
# remove or comment out DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD
```

```bash
touch database/database.sqlite
php artisan migrate

# Set the single app password (you'll log in with this)
php artisan app:set-password "choose-a-strong-password"

# Run server + Vite + queue worker + log tailer together
composer dev
```

Open the URL printed by `php artisan serve` (default `http://localhost:8000`)
and log in with the password you set.

> **Why `composer dev` and not just `php artisan serve`?** Bank sync runs as a
> **queued job**. `composer dev` starts a queue worker alongside the server, so
> sync jobs actually run. With a bare `serve` they would sit in the queue.

Run the test suite with:

```bash
php artisan test
```

## Configuration

Everything is `.env`-driven; see `.env.example` for the full list. The
essentials:

| Variable | Purpose |
|---|---|
| `APP_PASSWORD_HASH` | Set via `php artisan app:set-password "…"` — the single login password |
| `APP_DISPLAY_TIMEZONE` | Timezone for *display* only (e.g. emails). Data is stored in UTC. Default `Europe/Oslo` |
| `DB_*` | SQLite locally, MySQL/MariaDB in production |
| `MAIL_*` | SMTP for sync reports & expiry warnings. `MAIL_MAILER=log` writes mail to the log instead of sending |
| `BANK_SYNC_REPORT_EMAIL` | Fallback recipient for sync/expiry emails (the in-app *Settings* value wins) |
| `GOCARDLESS_*` / `ENABLEBANKING_*` | Bank aggregator credentials — see [docs/bank-setup.md](docs/bank-setup.md) |

You can run the app with **no bank provider configured** and enter everything
manually; the bank integration is optional.

## Bank integration

Finans talks to banks through a provider-agnostic interface, so you can use
either or both of:

- **GoCardless Bank Account Data** (formerly Nordigen) — free tier, broad EEA
  coverage. Good if you already have an account.
- **Enable Banking** — free tier for personal, non-commercial use. Uses a
  self-signed RSA JWT per request.

Each connection records its own provider, so multiple providers can be active at
once. **Full step-by-step setup for both is in
[docs/bank-setup.md](docs/bank-setup.md).**

Once a provider is configured, go to the **Bank** page in the app, connect a
bank, complete the consent flow, and link each bank account to a budget account.

## Production deployment

The repo ships a **Docker deployment** — a multi-stage [`Dockerfile`](Dockerfile)
(Node build → Composer build → `php:8.4-fpm` runtime with nginx + supervisor) and a
[`docker-compose.yml`](docker-compose.yml) that runs **three containers from the same
image**:

| Container | Command | Role |
| --- | --- | --- |
| `finans-web` | supervisord → nginx + php-fpm | serves the app on port 80 |
| `finans-worker` | `queue:work --tries=3 --max-time=3600 --sleep=3` | queue worker (the bank sync job) |
| `finans-scheduler` | `schedule:work` | Laravel scheduler — **replaces the cron entry** |

This covers both background pieces needed for automatic sync and scheduled postings —
**no Redis needed** (the `database` queue driver is used), and **no cron or Supervisor
on the host**: `schedule:work` runs the scheduler in the foreground, and
`restart: unless-stopped` combined with `--max-time=3600` lets Docker recycle the
worker every hour.

The scheduler runs the nightly bank sync (05:00), posts due scheduled transactions
(00:05), and checks for expiring bank consents (06:00) — see `routes/console.php`.

**What you need to provide around the stack:**

- **A reverse proxy** terminating TLS in front of `finans-web` (Caddy, Traefik, nginx…).
  The app trusts forwarded headers via `trustProxies(at: '*')` in `bootstrap/app.php`, so
  make sure your proxy sets `X-Forwarded-Proto https` — otherwise generated asset URLs
  come out as `http://` behind https.
- **A MySQL/MariaDB instance** reachable from the containers. The bundled compose file
  expects one in an external `app-backend` network rather than defining its own database
  service, so point `DB_HOST` at it.
- **The external networks** referenced at the bottom of `docker-compose.yml`. They're
  declared `external: true` and are site-specific — rename or replace them to match your
  own setup (e.g. drop the mail network if you send mail through an ordinary SMTP relay).
- **Persistence:** only `./storage-app` is bind-mounted (it holds `storage/app`, including
  the Enable Banking PEM key). Everything else in the container is disposable — the
  database lives in your MySQL/MariaDB instance.

### Redeploying

[`deploy.sh`](deploy.sh) is run on the server from the project root: `git pull --ff-only`
→ `docker compose build` → `docker compose up -d` → `migrate --force` →
`config:cache`/`route:cache`/`view:cache` inside `finans-web` → `docker compose restart`.
No maintenance mode is needed since the new image is built before the swap, and neither
`composer install` nor `npm ci` runs on the host — both happen in the image build.

> **Important production gotcha:** `.env` is in `.dockerignore` and is **never copied into
> the image**. Config reaches the app purely as environment variables injected from
> `env_file: .env` **when the container is created**. So after editing `.env` you must
> *recreate* the containers, not just restart them:
> ```bash
> docker compose up -d          # add --force-recreate if compose says "up-to-date"
> ```
> `docker compose restart` reuses the existing container and keeps serving the old values.
> Only `finans-web` gets a cached config; the worker and scheduler read the environment
> directly, so they need no separate `queue:restart` after a config change.

Run artisan in production inside the container:
`docker exec finans-web php artisan <command>`.

**Enable Banking requires public `/privacy` and `/terms` pages** for app
approval. These are served as standalone Blade pages (outside the login/SPA) at
`/privacy` and `/terms` — make sure they're reachable on your domain.

## Security notes

- **Serve over HTTPS only.** The whole app is protected by one password and a
  long-lived session cookie.
- **Never commit secrets.** `.env` is git-ignored; keep your bank credentials,
  `APP_KEY`, and Enable Banking private key out of version control.
- The Enable Banking private key can be provided as PEM content in `.env` or as a
  file path — a file path outside the web root is preferable in production.
- Keep your host, PHP, and dependencies patched. You are handling read-only
  access to your own bank data; treat the server accordingly.

## Troubleshooting

- **A frontend change isn't showing up** — run `npm run build` (prod) or
  `npm run dev` / `composer dev` (local). Vite assets must be rebuilt.
- **`Unable to locate file in Vite manifest`** — same fix: build the frontend.
- **Bank sync jobs never run** — you don't have a queue worker running. Use
  `composer dev` locally, or check that the `finans-worker` container is up in
  production (`docker compose ps`).
- **`PSU_HEADER_NOT_PROVIDED` / `ASPSP_ERROR` from Enable Banking** — some banks
  require the `psu-ip-address` header for unattended sync. Set
  `ENABLEBANKING_PSU_IP` to your server's outbound public IP, then recreate the
  containers (`docker compose up -d`). Remember to update it if you migrate to a
  new server — the outbound IP changes with it. See
  [docs/bank-setup.md](docs/bank-setup.md).
- **Changed `.env` in production but nothing happened** — the containers still hold
  the environment they were created with. Recreate them with `docker compose up -d`
  (see the gotcha above); a bare `docker compose restart` is not enough.

## Contributing

This is primarily a personal project, but issues and pull requests are welcome.
If you send a PR, please run `php artisan test` and `vendor/bin/pint` first.

## License

[MIT](LICENSE) © 2026 Ragnar Holthe.

Built on [Laravel](https://laravel.com) (MIT). Not affiliated with YNAB,
GoCardless, or Enable Banking.
