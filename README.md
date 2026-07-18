# apigopro/slim-database

Lazy PDO database connection management for **Slim Framework 4**, requiring **PHP 8.5**.

Supports **PostgreSQL**, **MySQL/MariaDB**, and **Oracle** through one consistent API. Built around
a dual-credential pattern: separate, distinctly-typed connections for authentication data vs. the
rest of the application, so least-privilege database roles are enforced both by Postgres/MySQL/
Oracle GRANTs *and* by PHP's type system (you can't accidentally inject the wrong connection into
the wrong place without it being visible in the constructor signature).

## Install

Published on Packagist

```bash
composer update apigopro/slim-database
```

## Requirements

- PHP 8.5
- The `pdo` extension (always required)
- **One** driver-specific PDO extension, matching whichever database you actually use:
  - `pdo_pgsql` for PostgreSQL
  - `pdo_mysql` for MySQL/MariaDB
  - `pdo_oci` for Oracle (see below — this one needs manual setup, not a package manager)
- [`vlucas/phpdotenv`](https://github.com/vlucas/phpdotenv) if you're loading config from a `.env`
  file rather than real process environment variables
- A PSR-11 DI container (this README uses [PHP-DI](https://php-di.org/)) — not strictly required by
  this package's code, but the intended usage pattern relies on one for wiring things together

### PostgreSQL

```bash
sudo apt install php8.3-pgsql   # adjust for your installed PHP version
```

### MySQL / MariaDB

```bash
sudo apt install php8.3-mysql
```

**Named timezones require a one-time setup step.** MySQL doesn't ship with timezone data loaded by
default — connecting with `DB_TIMEZONE=Europe/Sarajevo` (or any named zone) fails with
`Unknown or incorrect time zone` until you load it once per server:

```bash
mysql_tzinfo_to_sql /usr/share/zoneinfo | sudo mysql -u root mysql
```

This is a MySQL server-level gotcha, not something this package can work around — it affects any
application connecting with a named timezone, not just this one.

### Oracle

This is the one that isn't a simple package-manager install — Oracle requires a **licensed manual
download**, no `apt`/`pecl` one-liner can fetch it.

1. **Create a free Oracle account** and download **Instant Client Basic** and **Instant Client SDK**
   for your platform from Oracle's site (search "Oracle Instant Client downloads" — the exact URL
   path changes over Oracle's site restructures often enough that it's not worth hardcoding here).

2. **Extract and register the shared libraries:**
   ```bash
   sudo mkdir -p /opt/oracle
   sudo unzip instantclient-basic-linux.x64-*.zip -d /opt/oracle
   sudo unzip instantclient-sdk-linux.x64-*.zip -d /opt/oracle

   echo /opt/oracle/instantclient_21_1 | sudo tee /etc/ld.so.conf.d/oracle-instantclient.conf
   sudo ldconfig
   ```
   (adjust the `instantclient_21_1` directory name to whatever version you actually downloaded)

3. **Install build tools and the PECL extensions:**
   ```bash
   sudo apt install php8.3-dev php-pear build-essential libaio1
   ```

   `pdo_oci` has, at different points in PHP's history, shipped bundled and also existed as a
   separate PECL package — check what's actually available for your PHP version before assuming
   either way:
   ```bash
   pecl list-all | grep -i oci
   ```

   Typical PECL install, pointing at your Instant Client directory:
   ```bash
   sudo pecl install oci8
   # when prompted for the Instant Client location:
   # instantclient,/opt/oracle/instantclient_21_1
   ```
   For non-interactive installs:
   ```bash
   echo "instantclient,/opt/oracle/instantclient_21_1" | sudo pecl install oci8
   ```

4. **Enable the extension(s)** in `php.ini` (or a file under `conf.d/`):
   ```ini
   extension=oci8.so
   extension=pdo_oci.so
   ```
   Then restart PHP-FPM:
   ```bash
   sudo systemctl restart php8.3-fpm
   ```

5. **Verify:**
   ```bash
   php -m | grep -i oci
   ```

I wasn't able to test the Oracle path against a real server while building this package — Oracle's
download requires accepting their license through a browser session, which isn't something
available in an automated environment. Everything else in this README (PostgreSQL, MySQL, the DSN
templating, the connection classes themselves) was verified against real running servers. If you
hit something that doesn't match reality during Oracle setup, it's worth double-checking against
Oracle's current official docs for your specific Instant Client version.

## How the DSN template works

`DatabaseCredentials->dns` is a `printf`-style template. `AbstractDatabaseConnection` builds the
final DSN with:

```php
sprintf($credentials->dns, $credentials->host, $credentials->port, $credentials->name)
```

So whatever you put in `DB_DNS` must have exactly three placeholders, in that order: host, port,
name. Examples per driver are in `.env.example`. If your DSN ever needs a literal `%` character for
some other reason, escape it as `%%`.

## `.env` setup

Copy `.env.example` to `.env` and fill in real values — **pick exactly one driver block** (Postgres,
MySQL, or Oracle) and leave the others commented out. `DB_DNS` and `DB_ENCODING` are only meant to
be set once; if more than one block is active, later values silently overwrite earlier ones in the
same `.env` file (dotenv doesn't merge repeated keys).

Load it early in your bootstrap, before building your container:

```php
use Dotenv\Dotenv;

if (file_exists('/etc/myapp/.env')) {
    Dotenv::createImmutable('/etc/myapp')->load();
}
```

## The two-connection pattern

`DatabaseConfiguration` reads two separate sets of credentials from your env — `DB_AUTH_USER`/
`DB_AUTH_PASSWORD` and `DB_APP_USER`/`DB_APP_PASSWORD` — sharing the same host/port/database name.
The intended setup is two actual database roles with different grants:

```sql
-- PostgreSQL example
CREATE ROLE auth_user WITH LOGIN PASSWORD '...';
GRANT SELECT ON auth_credentials TO auth_user;

CREATE ROLE app_user WITH LOGIN PASSWORD '...';
GRANT SELECT, INSERT, UPDATE, DELETE ON users TO app_user;
-- deliberately no grants on auth_credentials for app_user
```

`AuthDatabaseConnection` and `AppDatabaseConnection` are distinct PHP classes (both extending
`AbstractDatabaseConnection`), not just two instances of the same class — so a constructor
type-hint like `AuthDatabaseConnection $db` is self-documenting, and using the wrong one is a
visible mistake in code, on top of the database itself enforcing the real boundary via GRANTs.

## Wiring into a DI container (PHP-DI example)

```php
use DI\Container;
use SlimDatabase\DatabaseConfiguration;
use SlimDatabase\AuthDatabaseConnection;
use SlimDatabase\AppDatabaseConnection;
use SlimDatabase\Middleware\CloseDatabaseConnectionsMiddleware;

$container = new Container();

$container->set(DatabaseConfiguration::class, function () {
    return new DatabaseConfiguration();
});

$container->set(AuthDatabaseConnection::class, function (Container $c) {
    return new AuthDatabaseConnection($c->get(DatabaseConfiguration::class)->authDb);
});

$container->set(AppDatabaseConnection::class, function (Container $c) {
    return new AppDatabaseConnection($c->get(DatabaseConfiguration::class)->appDb);
});
```

## Wiring the closing middleware

Add it **first**, before any other middleware, so it's the outermost layer — Slim's middleware
stack is nested, and the first middleware added is the *last* to finish on the way out, which is
what guarantees the connections close only after the route handler and every other middleware have
fully completed:

```php
$app->add($container->get(CloseDatabaseConnectionsMiddleware::class));
$app->add(new JwtAuthMiddleware([/* ... */]));
// ... routes
```

## Usage in an action class

```php
use SlimDatabase\AppDatabaseConnection;

final class ListUsersAction
{
    public function __construct(private readonly AppDatabaseConnection $db) {}

    public function __invoke($request, $response, array $args)
    {
        $stmt = $this->db->get()->query('SELECT id, name FROM users');
        // ...
    }
}
```

```php
use SlimDatabase\AuthDatabaseConnection;

final class LoginAction
{
    public function __construct(private readonly AuthDatabaseConnection $db) {}

    // Only this class (and anything else that legitimately needs it)
    // should ever type-hint AuthDatabaseConnection.
}
```

## `DatabaseCredentials` reference

| Property      | Meaning                                                                 |
|---------------|--------------------------------------------------------------------------|
| `dns`         | printf-style DSN template — see "How the DSN template works" above.      |
| `host`        | Database host.                                                           |
| `port`        | Database port.                                                           |
| `name`        | Database/service name.                                                   |
| `user`        | Connection username.                                                     |
| `password`    | Connection password.                                                     |
| `timezone`    | Session timezone. Applied via `SET TIME ZONE` (Postgres), `SET time_zone` (MySQL), or `ALTER SESSION SET TIME_ZONE` (Oracle). |
| `encoding`    | Client character encoding. Applied via `SET client_encoding` (Postgres) or `SET NAMES` (MySQL). For Oracle, this is instead baked directly into the DSN's `;charset=` parameter (see `.env.example`) rather than set via a session command. |
| `persistent`  | Whether to use `PDO::ATTR_PERSISTENT`. See "Design notes" below before enabling this on a busy server. |
| `collation`   | MySQL-only: session collation, applied via `SET collation_connection`. Unused by Postgres/Oracle. |
| `autocommit`  | MySQL/Oracle-only: applied via `PDO::ATTR_AUTOCOMMIT`. Unused by Postgres (which doesn't expose this as a settable session attribute the same way). |

## Design notes

- **`PDO::ATTR_ORACLE_NULLS` is applied unconditionally, for every driver.** Despite the name, this
  isn't Oracle-specific — it converts empty strings to `NULL` on fetch, for whichever driver is
  active. If your Postgres/MySQL tables rely on distinguishing an empty string from `NULL`, this
  default may not be what you want; it's currently not configurable per-connection.
- **Connections are lazy.** Nothing connects to the database until `->get()` is actually called, so
  injecting these classes broadly (even into code paths that never touch the DB) costs nothing.
- **`close()` has no effect on connections referenced elsewhere.** If `->get()`'s return value gets
  stashed in another variable/property, that reference keeps the connection alive regardless of
  calling `close()` on the wrapper — PDO has no real `close()` method, only "destroy every
  reference and let PHP's refcounting close it."
- **Persistent connections (`DB_PERSISTENT=true`)** get pooled/reused by PHP across requests on the
  same worker rather than truly closing — useful under load, but every long-lived worker holding a
  persistent connection open adds up against your database's connection limit. Left off by default.

## Testing

```bash
composer install
composer test
```

The included tests cover connection lifecycle (`get()`/`close()`/`isConnected()`), the
`DatabaseConfiguration` env-var parsing, and the closing middleware's success/exception paths
against a real PostgreSQL instance. MySQL's session-configuration SQL was independently verified
against a live MySQL 8.0 server during development (including the named-timezone table dependency
noted above). Oracle's SQL/DSN construction was checked for correctness but not run against a live
Oracle instance — see "Oracle setup" above for why.

## License

MIT.
