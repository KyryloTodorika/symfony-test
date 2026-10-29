# Symfony Server Test

A small Symfony web app for testing server configurations in DDEV or a virtual machine. The frontend shows live PHP, extension, MariaDB, cache, filesystem and logger checks, request information, and an interactive session test.

## Reference versions and settings

Based on the local `websedit-monitor` project. `composer.lock` retains **all of its exact dependency versions** (including development tools); `symfony.lock` retains its recipe versions. Root project metadata and application autoloading are adapted, and the Apache pack constraint is bounded to `^1.0` while retaining the reference's locked v1.0.1. No monitor business logic, database contents, private keys or deployment credentials are included.

| Component | Configuration |
| --- | --- |
| Symfony | 7.4.*; exact patches pinned in composer.lock |
| PHP | 8.2 |
| Web server | Apache / PHP-FPM |
| Document root | public/ |
| Database | MariaDB 10.11, pdo_mysql, utf8mb4 |
| Composer in DDEV | 2.8.12 (reference 2.8 series) |
| Frontend | Twig, AssetMapper, Stimulus 3.2.2, Turbo 8.0.23 |
| Tests | PHPUnit 11.5 constraint; exact version pinned |

Framework sessions, Doctrine, security, CSRF, Messenger, mailer, logger, development profiler and production cache settings follow the reference project. Doctrine maps the new `App\Entity` namespace. Database settings use the same `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` variables. The DDEV project name is `symfony-test`, so it can run alongside `monitor`. Composer is pinned to 2.8.12 because the reference setting `composer_version: "2.8"` makes this DDEV installation attempt an unsupported `composer self-update --2.8` command and leaves the image default installed.

## Run with DDEV

```bash
ddev start
ddev composer install
ddev exec php bin/console importmap:install
ddev launch
```

Open **https://symfony-test.ddev.site**. Defaults connect to DDEV's `db` service. No schema or migrations are required: the database check executes only `SELECT 1` and `SELECT VERSION()`.

The reference project declares Composer auto-scripts without a post-install hook, so the importmap installation is intentionally explicit. There is no Node build step.

## Run on a VM

Install PHP 8.2 with the extensions required by Composer (the dashboard also checks curl, intl, mbstring, pdo_mysql, XML and zip), Composer 2.8, Apache with PHP-FPM and rewrite enabled, and MariaDB 10.11.

```bash
cp .env.local.example .env.local
# Edit .env.local for this VM; generate APP_SECRET with:
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
composer install --no-dev --optimize-autoloader
php bin/console importmap:install
php bin/console asset-map:compile
php bin/console cache:clear --env=prod
```

Set Apache's document root to this project's `public/` directory. Enable `AllowOverride All` for that directory so the included `.htaccess` routes requests to `index.php`; configure the PHP 8.2 FPM handler and TLS for your VM. Give the PHP-FPM user write access to `var/cache/` and `var/log/`. Create the database and a dedicated database user, then set their credentials in `.env.local`. Real environment variables override dotenv values.

Keep this diagnostic app on a private test VM or behind your server's access controls: it intentionally displays runtime and request metadata. When testing behind a reverse proxy, configure Symfony's trusted proxies for your actual proxy addresses if HTTPS or host detection needs it. Mail delivery is disabled by default (`null://null`); Messenger retains the reference Doctrine transport configuration but is not used by the diagnostics.

## What to test

- `/`: Twig rendering, AssetMapper CSS/JS, request routing and live service checks. The footer confirms when Stimulus connects.
- **Run checks**: fetches fresh results without reloading the page.
- **Test session**: CSRF-protected POST, session write, cookie persistence and HTTP 303 redirect. Reload to confirm the counter persists.
- `/health`: JSON with HTTP **200** when all checks pass, or **503** when a check fails. It does not start a session. Responses disable HTTP caching.
- Stop or misconfigure the database: the dashboard stays available and reports the failed connection without exposing database exceptions or credentials.
- The logger check confirms that an info message was accepted; inspect `var/log/dev.log` in development. Production follows the reference Monolog configuration: JSON to stderr, with info messages buffered until an error occurs.

Each refresh writes and removes small temporary files/cache entries. Checks do not send mail or make external requests. The functional test suite mocks the database; validate the actual connection through the live dashboard or `/health`.

## Validation

```bash
ddev composer validate --strict
ddev exec php bin/console lint:container
ddev exec php bin/console lint:yaml config
ddev exec php bin/console lint:twig templates
ddev exec php bin/phpunit
ddev exec curl -fsS http://localhost/health
```
