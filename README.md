# Calmfox Watch for Sylius

**English** · [Polski](README.pl.md)

A package that monitors the internals of a Sylius store. It exposes a single
secret health endpoint, polled by the Calmfox Watch monitoring, and implements
the same contract as the WordPress plugin and the Magento and Neos packages:
the same response shape, the same signature, the same pairing flow.

The model is "pull": the package sends nothing on its own apart from
registration, pairing and disconnecting. Everything else is an answer to a
question asked by the monitoring.

## About Calmfox Watch

[Calmfox Watch](https://watch.calmfox.net) is a website monitoring service. It
checks availability, security, software updates, content and performance from
the outside and rolls the results into a single site health score from 0 to
100, with an explanation of every point lost and what to do about it. It also
watches domain and TLS certificate expiry, DNS changes and broken links, and
sends an alert once a problem is confirmed.

On its own, Calmfox Watch sees the store the way a visitor does. This
package adds the view from inside Sylius: the state of the services behind the
store, configuration hygiene and a history of package changes. No admin
passwords are shared with the service.

- Calmfox Watch: <https://watch.calmfox.net>
- Packagist: <https://packagist.org/packages/calmfox/watch-sylius>

## Requirements

| Component | Range |
| --- | --- |
| PHP | 8.1 and up |
| Symfony | 6.4 or 7.x |
| Sylius | 1.12 and up, including 2.x |

The Sylius range is declared as `"sylius/sylius": "^1.12 || ^2.0"`, the same
way other packages in this ecosystem do it: `sylius/sylius` is the metapackage
of the whole platform, and it decides what is available. Two remarks:

- Sylius 2.x requires PHP 8.2, so on PHP 8.1 Composer resolves the dependency
  to the 1.13 branch. That is not a configuration error, just the consequence
  of both ranges applying at once.
- The places where the 1.x and 2.x APIs differ (the admin menu, the name of
  the admin layout) are written defensively: when the structure is unknown,
  the package does not add a menu item instead of bringing the Sylius admin
  down. The screen address and the CLI commands keep working the same way.

## Installation

The package is available in the public Composer package index (Packagist) as
`calmfox/watch-sylius`.

### 1. Installing the package

```bash
composer require calmfox/watch-sylius
```

Updating: `composer update calmfox/watch-sylius`.

Where a store cannot use Packagist, the package is installed from the
`calmfox-watch-sylius.zip` archive provided by the Calmfox Watch panel
(Integrations, the "Download for Sylius" button). The archive contains a single
directory: `calmfox-watch/`. Even then it is best to go through Composer: it
rebuilds the autoloader and takes care of the package's dependencies exactly as
it would for a package downloaded from Packagist.

```bash
mkdir -p packages && unzip calmfox-watch-sylius.zip -d packages
composer config repositories.calmfox-watch '{"type":"path","url":"./packages/calmfox-watch","options":{"symlink":false}}'
composer require calmfox/watch-sylius:@dev
```

Three places where it is easy to trip up:

- **`"symlink": false`** tells Composer to copy the files. Without it the
  directory in `vendor` is only a symlink to `packages` and disappears
  together with it.
- **The unpacked directory stays in the project** (and in the repository, if
  you deploy from git). Composer reads it on every `composer install`, so
  deleting it will break the next deployment.
- **The `@dev` next to the package name is required.** The archive's
  `composer.json` deliberately has no `version` field (Composer derives the
  version from a repository tag, and the archive has no tag), so a `path`
  repository reports it as `dev-main`.

Updating from the archive: unpack the newer one into the same place and run
`composer update calmfox/watch-sylius`.

Deployments with no Composer on the server can unpack the archive into the
project directory and add the namespace to the application's `composer.json`:

```json
"autoload": {
    "psr-4": {
        "Calmfox\\WatchBundle\\": "packages/calmfox-watch/src/"
    }
}
```

The autoloader then has to be rebuilt wherever Composer is available
(`composer dump-autoload`) and shipped together with `vendor`. The package's
routes, templates and translations live in `src/Resources`, that is, in the
directory of the bundle class, so the `@CalmfoxWatchBundle/...` references in
the following steps work the same way with either route.

### 2. Registering the bundle

`config/bundles.php` (Symfony Flex will not add this for you: the entry comes
from a Flex recipe, and recipes are published only for packages from the
public index):

```php
return [
    // ...
    Calmfox\WatchBundle\CalmfoxWatchBundle::class => ['all' => true],
];
```

### 3. Routes

`config/routes/calmfox_watch.yaml`:

```yaml
calmfox_watch:
    resource: '@CalmfoxWatchBundle/Resources/config/routes.yaml'
```

This creates two things: the public health endpoint `/calmfox-watch/health`
and a screen in the Sylius admin under `/admin/calmfox-watch`.

### 4. Access to the health endpoint

The health endpoint MUST be public. The monitoring polls us, not the other
way round, and it has no session to identify itself with. Authorisation is
the secret in the `key` parameter, compared with `hash_equals`.

In `config/packages/security.yaml`, under `access_control`, **before** the
Sylius rules:

```yaml
security:
    access_control:
        - { path: "^/calmfox-watch/health", roles: PUBLIC_ACCESS }
        # ... the existing Sylius rules ...
```

On Sylius 1.12 with Symfony 5.4 the role is called
`IS_AUTHENTICATED_ANONYMOUSLY`.

Also check that a firewall or web server rules do not block this path. The
screen in the Sylius admin and the `calmfox:watch:status` command run a
loopback self-check and will say plainly if the endpoint cannot be reached
from the server itself.

### 5. Connecting to the Calmfox Watch panel

The shortest way: the store's Sylius admin, the **Calmfox Watch** item in the
main menu, the "Connect through watch.calmfox.net" button. It takes you to
the Calmfox Watch panel (sign in or create an account, pick an organisation)
and then returns to the store with the installation key and connects the
store without you retyping anything. The store does not have to exist in the
Calmfox Watch panel beforehand.

The return is protected by a one-time token (valid for a quarter of an hour),
and the Calmfox Watch panel only accepts a return address on the domain of
the store being connected, leading to the package's screen. A store whose
Sylius admin runs on a separate domain will not get this route: the button
does not show up then, and pairing with the key remains.

The two other ways (both available from the same screen or from the command
line):

```bash
# a new account on the Free plan
bin/console calmfox:watch:register owner@shop.example

# or attach to an existing site in the Calmfox Watch panel (key from the Integrations screen)
bin/console calmfox:watch:pair fxp_live_0123456789abcdef
```

In the CLI the router does not know the store address, so either set
`framework.router.default_uri` or provide `calmfox_watch.site_url`. Both
commands print the health endpoint they report to the Calmfox Watch panel, so
you can see straight away whether it is correct.

## Configuration

Everything has sensible defaults. `config/packages/calmfox_watch.yaml` is
needed only when something deviates from the standard:

```yaml
calmfox_watch:
    # API address. Environment variable: CALMFOX_WATCH_API_URL.
    api_url: 'https://watch.calmfox.net'

    # State directory. Environment variable: CALMFOX_WATCH_STATE_DIR.
    state_dir: '%kernel.project_dir%/var/calmfox-watch'

    # Store address, used in the CLI and by the HTTPS check.
    site_url: 'https://shop.example'

    # Sylius admin prefix, if you changed sylius_admin_path.
    admin_path: 'admin'

    # Store health tile on the Sylius admin dashboard. false leaves the dashboard untouched.
    dashboard_widget: true

    # Sylius admin layout. The name differs between Sylius 1.x and 2.x.
    admin_layout: '@SyliusAdmin/layout.html.twig'

    # Disk quota of the hosting account in GB. You can set the same thing from
    # the screen in the Sylius admin, and then the value from the screen wins.
    disk_quota_gb: 20

    media_dir: '%kernel.project_dir%/public/media'
    mailer_dsn: '%env(MAILER_DSN)%'
    elasticsearch_url: null          # empty = the store does not use it, the check is not created
    messenger_table: 'messenger_messages'
    failure_transports: ['failed']
    heartbeat_interval: 600          # how often, in seconds, cron calls calmfox:watch:heartbeat
    platform_package: 'sylius/sylius'
    composer_binary: 'composer'
```

`api_url` and `state_dir` are read from environment variables already when
the container is built, so clear the cache after changing them
(`bin/console cache:clear`).

### Layout, menu and the dashboard tile

Sylius 1.x and 2.x have a different base template for the admin. Check what
it is called in your version (`bin/console debug:twig`, or the
`vendor/sylius/sylius/src/Sylius/Bundle/AdminBundle/Resources/views`
directory) and set `calmfox_watch.admin_layout`.

**Main menu.** The "Calmfox Watch" item is attached through the
`sylius.menu.admin.main` event to the MAIN MENU, right below the dashboard,
and not under "Configuration": an outage should be visible from every screen
of the store. If your version builds the menu differently, the item will not
appear, and the screen still works under `/admin/calmfox-watch`.

**Dashboard tile.** The Sylius admin dashboard gets a health summary: section
status, check counts and at most three of the most urgent problems (failures
before warnings), with a link to the full screen. We hook it in ourselves,
with no digging in the store's configuration: in Sylius 1.x through the
`sylius.admin.dashboard.content` template event, in 2.x through the
`sylius_admin.dashboard.index.content` hook. The tile is rendered by a
sub-request to the package's action, with the same permission and the same
cache as the screen, and an error inside it is never allowed to stop the
dashboard. It is switched off with a single setting:
`calmfox_watch.dashboard_widget: false`.

## State, the secret and release-directory deployments

The state (the health endpoint secret, the pairing nonce, the version
history, the heartbeat marker, the counted updates) lives in a **file**, not
in the database: `var/calmfox-watch/state.json`, mode 600, atomic writes.

The reason is in the contract: when the database is down, the health endpoint
has to answer `db: fail` with a 503 code, not go silent. That is the one
moment when monitoring really earns its keep.

> **A note on "new directory for every release" deployments** (Deployer,
> Capistrano, a `current` symlink): the state directory MUST be shared between
> releases. Otherwise every deployment creates a new secret, the health
> endpoint remembered by the Calmfox Watch panel stops working and the
> monitoring reports a silent store. The version change history then also
> starts from scratch.
>
> Add `var/calmfox-watch` to the shared directories, or set
> `CALMFOX_WATCH_STATE_DIR=/var/www/shop/shared/calmfox-watch`.

Replacing the secret (the "Replace the key" button) works with a 15-minute
window: the new secret applies immediately, the previous one is honoured for
another quarter of an hour, so a failed switch-over in the Calmfox Watch panel
does not break the monitoring.

## Scheduled tasks

A Sylius store has commands that MUST run regularly, and the package will not
run them for you. What it does watch is whether cron is alive at all:

```cron
# cron state for Calmfox Watch (cheap, it just writes a timestamp)
*/10 * * * * cd /var/www/shop && php bin/console calmfox:watch:heartbeat -q

# pending package updates (once a day is enough, needs network access)
15 3 * * *   cd /var/www/shop && php bin/console calmfox:watch:updates -q

# the store's actual tasks
*/5 * * * *  cd /var/www/shop && php bin/console sylius:remove-expired-carts -q
*/5 * * * *  cd /var/www/shop && php bin/console sylius:cancel-unpaid-orders -q
0 4 * * *    cd /var/www/shop && php bin/console sylius:remove-expired-payments -q
```

Command names and their availability depend on the Sylius version. Check the
list with `bin/console list sylius`.

The queue consumer is a separate matter: it should run as a system service
(`messenger:consume async -vv` under supervisor or systemd), not from cron.
The `messenger` check watches precisely whether it is running.

## Commands

| Command | What it is for |
| --- | --- |
| `calmfox:watch:status` | Connection state, health endpoint, both check sections |
| `calmfox:watch:register <email>` | Creates a Free account and connects the store |
| `calmfox:watch:pair [token]` | Connects to an existing site in the Calmfox Watch panel |
| `calmfox:watch:disconnect` | Ends the internals monitoring and tells the Calmfox Watch panel about it |
| `calmfox:watch:updates` | Counts pending updates, for cron |
| `calmfox:watch:heartbeat` | Marker for the scheduled tasks check, for cron |
| `calmfox:watch:health [--section=security]` | Prints the payload locally |

## What we check

### The `health` section (the monitoring asks every minute)

| Identifier | What it checks |
| --- | --- |
| `db` | A probe query through Doctrine, timed. No database means `fail`, not an exception. |
| `disk` | Write permission to `var/` and the media directory, installation size, usage against the account quota you provided. |
| `smtp` | Connection to the mail server (TCP, 220 greeting, EHLO). This tests the CONNECTION, not delivery. |
| `messenger` | Backlog in the doctrine queues: age of the oldest message and the number of failed ones. |
| `app_cache` | Writing and reading a probe key in the application cache pool. |
| `checkout` | Whether a purchase is possible in every enabled channel: payment method, shipping method, zone. |
| `scheduled_tasks` | Freshness of the heartbeat marker, that is, whether cron runs at all. |
| `elasticsearch` | Cluster state. Optional: without a configured address the check is not created at all. |

### The `security` section (the monitoring asks once a day)

`admin_count`, `admin_login`, `app_env`, `debug_display`, `https`,
`php_version`, `config_perms`, `dir_perms`, `app_secret`, `dev_packages`,
`pending_updates`.

This is basic hygiene, not an audit. We do not scan for malicious code, we do
not compute checksums of the platform files and we do not make backups.

### What we deliberately do not do

- We do not send a list of packages with versions. `site.updates` is numbers
  only, because an inventory of "what, and in which version" is a ready-made
  map of holes for an attacker. Names travel only where they are the essence
  of the feature: in the version change history and as the set of ENABLED
  bundles (`signals.activePlugins`, without versions). That second exception
  is deliberate and has a price: whoever obtains the secret health endpoint
  will see the list of bundles. Without names, an event about a removed
  extension would read "something changed", and you cannot react to that.
- We do not pretend there are automatic updates. Sylius has none, so the
  `signals.autoUpdates` field is not sent at all, instead of carrying a value
  that would mean "checked".
- We do not send logins. What travels instead is the number of admin accounts
  and a one-way fingerprint of their set, salted with the installation
  secret. The Calmfox Watch panel detects a CHANGE in the set, not
  identities.
- We do not look into sales data. The `checkout` check looks only at the
  channel configuration, never at orders or revenue.
- We do not guess numbers we do not know. Until somebody has run
  `calmfox:watch:updates`, the `updates` field is not sent at all. Zero would
  mean "checked, nothing to update", and that would be untrue.

## Custom checks

Services only the store owner knows about (a queue broker, a warehouse
integration, a price synchronisation daemon) are attached with a class of
your own:

```php
namespace App\Monitoring;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;

final class WarehouseCheck implements HealthCheckInterface
{
    public function run(): ?CheckResult
    {
        $start = microtime(true);
        $socket = @fsockopen('127.0.0.1', 5672, $errno, $error, 2);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if (!\is_resource($socket)) {
            return CheckResult::fail('warehouse', 'Warehouse integration', 'The service is not accepting connections.', $ms);
        }
        fclose($socket);

        return CheckResult::ok('warehouse', 'Warehouse integration', null, $ms);
    }
}
```

With `autoconfigure` enabled, that is all. Without it, add the tag:

```yaml
services:
    App\Monitoring\WarehouseCheck:
        tags: [{ name: calmfox_watch.health_check, priority: 0 }]
```

Two rules, both from the contract:

1. Return `null` when the check does not apply to this installation. We do
   not send "ok" about something that is not there.
2. Keep a short, hard timeout. The health endpoint answers every minute and
   must not slow the store down.

An identifier from outside the parameter catalogue reaches the Calmfox Watch
panel with the label from the payload and the note "service attached by a
custom extension".

## Version change history

Sylius has no update hook: deployment is done by Composer, usually from a
completely different machine. That is why the package compares snapshots of
`vendor/composer/installed.php` (plus the PHP version) every time the
`security` section is built, which is at most every ten minutes, and on
`calmfox:watch:updates`.

Consequences worth knowing:

- The `at` timestamp is the **time the change was detected**, not the time of
  deployment. They usually differ by minutes; for a store with no traffic it
  can be more. For the sentence "the outage started after package X was
  updated" that is enough; for accounting for deployments to the second it
  is not.
- The history starts when the package is installed. Earlier changes cannot be
  reconstructed, because there is nothing to reconstruct them from.
- A change of `sylius/sylius` or PHP is recorded as `core`, other packages as
  `plugin`. The buffer holds 200 entries.
- With release-directory deployments the history requires a shared state
  directory (see above). Without it every deployment starts it over.

## Privacy

What goes to Calmfox: the store domain, the e-mail address you provided (only
when creating an account) and the diagnostic data described above: check
statuses with descriptions, platform and PHP versions, counts of pending
updates, the number and fingerprint of admin accounts, and the package
version change history. No store content, orders, customers, logins or
passwords.

## Tests

The package core (`src/Core`) is free of Symfony and Sylius: these are plain
PHP classes. Thanks to that, the contract with the Calmfox Watch panel can be
tested without a container and without a database. The suite runs standalone:

```bash
composer install
vendor/bin/phpunit
```

The only exception is the test of signature compatibility with the
verification on the Calmfox Watch server side. It reaches for the server
code, which is not public, so without it the test skips itself; the remaining
tests verify the signature with the package's own code. If you have access to
the server code, point to its directory with the `CALMFOX_HUB_DIR` variable:

```bash
CALMFOX_HUB_DIR=/path/to/calmfox-watch-server vendor/bin/phpunit
```

Among other things, the tests guard: the `ok`/`warn`/`fail` aggregation,
rejection of a wrong key, validity of the previous secret within the rotation
window and its expiry, signature compatibility with the verification on the
Calmfox Watch side, omission of the `updates` field when there is no data,
comparison of version snapshots and parsing of `MAILER_DSN`.

Sample responses of both sections are in `docs/sample-health.json` and
`docs/sample-security.json`. They are produced by the same code that answers
the monitoring, and a test makes sure they do not drift apart. To regenerate
them after a deliberate change of the contract:

```bash
CALMFOX_WRITE_SAMPLES=1 vendor/bin/phpunit --filter SamplePayloads
```

## Limits of protection

We sign the response with the installation key (HMAC-SHA256 over the nonce,
the generation time and the exact bytes of the body). That cuts off the cheap
attacks: a substituted static file, a response served from a cache, a replay
from before the store was taken over. Whoever has full control of the server
also has the secret and can sign a lie. The signature does not replace
recovering the server, and that is how we talk about it.

## License

MIT, see [LICENSE](LICENSE).
