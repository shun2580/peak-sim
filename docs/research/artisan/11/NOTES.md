# artisan output research: Laravel 11

Scope: `route:list --json`, `model:show {Model} --json`, `db:table {table} --json` on a fresh Laravel 11 project.
Task: T1 (one Laravel version per researcher; the cross-version summary is written later in `docs/research/artisan-compat.md`).

## 1. Versions used

| Item | Version | How it was fixed |
|---|---|---|
| Laravel skeleton | `laravel/laravel` v11.6.1 | `composer create-project laravel/laravel:^11` (latest 11.x skeleton) |
| Laravel framework | **11.57.1** | `php artisan --version` (latest 11.x on Packagist at the time) |
| PHP (min) | **8.2.35** | `php:8.2-cli` official image |
| PHP (max, official) | **8.4.27** | `php:8.4-cli` official image |
| PHP (bonus, not official) | 8.5.12 | `php:8.5-cli` official image; see section 3.3 |
| Composer | 2.11.1 | `composer:2` image copied into the dev image |
| MySQL | 8.4.7 | `mysql:8.4.7` image (the only MySQL 8 image available locally) |
| SQLite | bundled with the PHP image (`pdo_sqlite`) | PHP 8.4 only (supplement) |

PHP range, and how it was checked:

- Skeleton `composer.json`: `"php": "^8.2"`; framework 11.57.1 `require.php`: `^8.2` (read from the Packagist API and from the installed `composer.json`).
- Official support table (Laravel docs, `11.x` branch, `releases.md`): Laravel 11 = **PHP 8.2 - 8.4**.
- So min = 8.2 and max = 8.4. `^8.2` also admits 8.5, but 8.5 is not in the official table, so it is tested only as a bonus.
- The Laravel docs list security fixes for 11 until 2026-03-12 (already past on the research date, 2026-10-31).

PHP configuration: `php --ini` shows "Loaded Configuration File: (none)" in every image, i.e. the official image defaults, no php.ini.

## 2. Reproduction (no absolute paths or host information)

Work area (git-ignored, not committed): a directory holding `Dockerfile`, `compose.yaml`, and one sub-directory per PHP version.

`Dockerfile`:

```dockerfile
ARG PHP_VERSION
FROM php:${PHP_VERSION}-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libsqlite3-dev && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo_mysql pdo_sqlite zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
```

`compose.yaml`: services `db` (`mysql:8.4.7`, `MYSQL_DATABASE=laravel`, a throw-away root password, healthcheck, **no published ports**) and `app` (built from the Dockerfile with `PHP_VERSION`, runs as the invoking uid:gid, bind-mounts `./php<X.Y>` at `/work`, `command: sleep infinity`).

Commands, per PHP version `X.Y` (8.2, 8.4, and 8.5 for the bonus):

```sh
PHP_VERSION=X.Y UID_GID="$(id -u):$(id -g)" docker compose -p t1-11-phpXY up -d --build
docker compose -p t1-11-phpXY exec app sh -c 'cd /work && COMPOSER_NO_SECURITY_BLOCKING=1 composer create-project laravel/laravel:^11 proj --no-interaction --no-progress'
# add models/migrations (section 2.1), point .env at MySQL:
#   DB_CONNECTION=mysql DB_HOST=db DB_PORT=3306 DB_DATABASE=laravel DB_USERNAME=root DB_PASSWORD=<throw-away>
docker compose -p t1-11-phpXY exec app sh -c 'cd /work/proj && php artisan migrate --force'
# capture (stdout and stderr to separate files, exit code recorded):
docker compose -p t1-11-phpXY exec app sh -c 'cd /work/proj && \
  php artisan route:list --json            > route-list.stdout       2> route-list.stderr; echo $? ; \
  php artisan model:show Order --json      > model-show-Order.stdout 2> model-show-Order.stderr; echo $? ; \
  php artisan model:show Item --json       > model-show-Item.stdout  2> model-show-Item.stderr; echo $? ; \
  php artisan db:table orders --json       > db-table-orders.stdout  2> db-table-orders.stderr; echo $? ; \
  php artisan db:table items --json        > db-table-items.stdout   2> db-table-items.stderr; echo $?'
python3 -m json.tool <file>.stdout        # syntax check, on the host
docker compose -p t1-11-phpXY down -v     # clean up
```

`COMPOSER_NO_SECURITY_BLOCKING=1` is required, see section 3.1.

SQLite supplement (PHP 8.4): copy the finished project, set `DB_CONNECTION=sqlite`, delete the other `DB_*` lines (Laravel then uses `database/database.sqlite`, which `create-project` already created), run `php artisan migrate --force`, capture again.

Extra route pass (`mysql/route-list-extra.*`): the clean install only has 3 closure routes, so a second capture was made after appending these to `routes/web.php`: a controller action with `throttle:60,1` and a name, `Route::resource` (7 actions), an invokable controller, a `prefix('admin')` group with `['web','auth']`, a `domain('{tenant}.example.test')` group, `Route::match(['post','put'])`, and a route with `where()` and an optional parameter.

### 2.1 Subject (identical for every PHP)

| Table | Columns | Foreign keys |
|---|---|---|
| customers | id, name, email (unique) | |
| products | id, name, price (decimal 10,2) | |
| orders | id, customer_id, status, total (decimal 10,2) | customer_id -> customers |
| items | id, order_id, product_id, quantity (unsigned int), price (decimal 10,2) | order_id -> orders, product_id -> products |

Models (no timestamps): `Customer` hasMany `Order`; `Order` belongsTo `Customer`, hasMany `Item`; `Item` belongsTo `Order`, belongsTo `Product`; `Product` hasMany `Item`. Created with four migrations (`foreignId(...)->constrained(...)`).

## 3. Results

### 3.1 Unexpected: Composer refuses to install any Laravel 11 release

With Composer 2.11.1 a plain `composer create-project laravel/laravel:^11` fails:

> Root composer.json requires laravel/framework ^11.31, found laravel/framework[v11.31.0, ..., v11.57.1] but these were not loaded, because they are affected by security advisories (...)

Every 11.31 - 11.57.1 release is blocked by Composer's security-advisory policy. The install only works with `COMPOSER_NO_SECURITY_BLOCKING=1` (or `policy.advisories.block=false`). This affects anyone who sets up a Laravel 11 test application with a current Composer; it does not affect the artisan output itself. (Observed, not analysed further: which advisories, and whether a fixed 11.x exists.)

### 3.2 Result table (MySQL 8.4.7; stdout and stderr captured separately)

"Works" = exit code 0, stderr empty, stdout is valid JSON (`python3 -m json.tool`).

| Command | PHP 8.2.35 | PHP 8.4.27 | PHP 8.5.12 (bonus) |
|---|---|---|---|
| `route:list --json` | works | works | **JSON broken** (deprecation text on stdout) |
| `model:show Order --json` | works | works | **JSON broken** |
| `model:show Item --json` | works | works | **JSON broken** |
| `db:table orders --json` | works | works | **JSON broken** |
| `db:table items --json` | works | works | **JSON broken** |
| `route:list --json` (extra routes) | works | works | not run |

Between PHP 8.2 and 8.4 the outputs are **byte-identical** (`diff -r` on `mysql/` is empty, including the extra-route capture). No difference in the command set, JSON keys or value formats between PHP versions was found for Laravel 11.57.1 on the official range.

All exit codes were 0 for the clean captures (`exitcodes.txt` in each directory). stderr was empty (0 bytes) in every capture.

### 3.3 PHP 8.5 (not in the official table for Laravel 11): stdout is polluted

A stock, unmodified Laravel 11.57.1 project on PHP 8.5.12 prints four `Deprecated: Constant PDO::MYSQL_ATTR_SSL_CA is deprecated since 8.5 ...` blocks (from `config/database.php`, lines 61 and 81, in both the framework copy and the project copy) **to stdout, before the JSON**. stderr stays empty, exit code stays 0, and the first byte of stdout is a newline instead of `[` / `{`. All 5 captures fail JSON parsing (`php8.5/mysql/*.stdout`; the JSON starts at line 9). The message comes from the skeleton's `config/database.php`, which Laravel loads before its own error handler is registered (see 3.4).

Mitigations tested on 8.5 (all give valid JSON): `php -d display_errors=stderr artisan ...` (messages move to stderr, 646 bytes), `php -d display_errors=0 artisan ...`, `php -d error_reporting="E_ALL & ~E_DEPRECATED" artisan ...`. Outputs: `php8.5/mitigation/`.

### 3.4 Does a warning or deprecation on stdout break the JSON? (induced experiments, PHP 8.2 and 8.4)

A clean install never triggered this on 8.2/8.4, so errors were induced in a copy of the project.

| Where the error is raised | What happens | Files |
|---|---|---|
| **Early**: PHP code run before Laravel registers its error handler (a file `require`d at the top of `bootstrap/app.php`; same stage as config loading) containing `strlen(null)`, an undefined variable, and (8.4 only) an implicit nullable parameter `function f(string $a = null)` | Message text (`Deprecated: ...`, `Warning: ...`) is printed to **stdout**, ahead of the JSON. stderr empty, exit 0, **JSON broken** for all 5 commands. On 8.4 one more line appears (the implicit-nullable deprecation is new in 8.4) | `php8.2/induced-early/`, `php8.4/induced-early/` |
| **Late**: code run after boot (a `__construct` in the `Order` model called by `model:show`) with `strlen(null)` and an undefined variable | The deprecation (`strlen(null)`) was swallowed by Laravel (nothing on stdout/stderr). The warning (undefined variable) became an `ErrorException`: exit code **1**, the formatted exception text is on **stdout**, stderr empty, no JSON | `php8.2/induced-late/`, `php8.4/induced-late/` |
| Early case with `php -d display_errors=stderr artisan route:list --json` | stdout is valid JSON, the messages are on stderr | `php8.2/mitigation/`, `php8.4/mitigation/` |

Conclusions: (1) stdout and stderr cannot be assumed clean: PHP's default (`display_errors` on, no php.ini) writes early diagnostics to stdout. (2) Exception text of failed artisan commands is also written to stdout (not stderr) when run non-interactively, so the exit code must be checked before parsing.

### 3.5 Commands that need a live DB connection (PHP 8.4, `DB_HOST` pointed to an unreachable host)

| Command | Needs DB? | Result without DB |
|---|---|---|
| `route:list --json` | no | exit 0, valid JSON |
| `model:show Order --json` | **yes** (reads the table's columns) | exit 1, `QueryException` text on stdout, not JSON |
| `db:table orders --json` | **yes** | exit 1, `QueryException` text on stdout, not JSON |

Outputs: `php8.4/no-db/`. A tool reading `model:show` / `db:table` therefore needs a reachable local DB with the schema migrated (consistent with D5), or pre-exported JSON files (the "pre-export mode" in the spec).

### 3.6 JSON shape (identical on PHP 8.2 / 8.4 with MySQL)

- `route:list --json`: top-level **array**; each element has the keys `domain`, `method`, `uri`, `name`, `action`, `middleware`. `method` is a single string with `|` between verbs (`"GET|HEAD"`, `"POST|PUT"`). `uri` has no leading slash except `/` itself. `action` is `"Closure"`, `"App\\...\\Controller@method"`, or the bare class name for an invokable controller. `domain` is `null` or the pattern (`{tenant}.example.test`). `middleware` is a list of names/aliases (not expanded classes); the `web` group is listed as `web`. Route model/resource routes appear as one element per verb group (`PUT|PATCH` together). Rows are sorted by `uri`, not in definition order. The stock project already contains `storage/{path}` (`storage.local`) and `up`.
- `model:show X --json`: top-level object with keys `class`, `database`, `table`, `policy`, `attributes`, `relations`, `events`, `observers`. `attributes[]` keys: `name`, `type`, `increments`, `nullable`, `default`, `unique`, `fillable`, `hidden`, `appended`, `cast`. `relations[]` keys: `name`, `type` (short class name, e.g. `BelongsTo`, `HasMany`), `related` (FQCN). Relations found: Order -> `customer` BelongsTo `App\Models\Customer`, `items` HasMany `App\Models\Item`; Item -> `order` BelongsTo `App\Models\Order`, `product` BelongsTo `App\Models\Product`. The foreign key column is **not** in `relations[]`. `database` is the connection driver name.
- `db:table X --json`: top-level object with keys `table`, `columns`, `indexes`, `foreign_keys`. `table`: `schema`, `name`, `columns` (a count), `size`, `comment`, `collation`, `engine`. `columns[]`: `column`, `attributes`, `default`, `type`. `indexes[]`: `name`, `columns`, `attributes`. `foreign_keys[]`: `name`, `columns`, `foreign_schema`, `foreign_table`, `foreign_columns`, `on_update`, `on_delete`. **Quirk:** `attributes` is sometimes a JSON array (`["bigint"]`) and sometimes a JSON object with numeric-string keys (`{"0":"bigint","2":"autoincrement"}`, `{"0":"btree","3":"primary"}`), because PHP encodes arrays with gaps as objects. A parser must accept both.
- MySQL: foreign-key columns get an index named `<table>_<column>_foreign` (`orders_customer_id_foreign`), shown with `["btree"]`; the primary key is an index named `primary`.

### 3.7 SQLite vs MySQL (PHP 8.4, Laravel 11.57.1)

Commands, exit codes (all 0) and JSON key sets are the same; stderr empty. Differences (full diff reproduced from the files in `php8.4/mysql/` and `php8.4/sqlite/`):

| Where | MySQL 8.4.7 | SQLite |
|---|---|---|
| `route:list` | | identical |
| `model:show` `database` | `"mysql"` | `"sqlite"` |
| `model:show` / `db:table` column `type` | `bigint unsigned`, `varchar(255)`, `decimal(10,2)`, `int unsigned` | `integer`, `varchar`, `numeric`, `integer` (no length, precision or signedness) |
| `db:table` `columns[].attributes` | e.g. `{"0":"bigint","2":"autoincrement"}`, `["decimal"]`, `{"0":"varchar","4":"utf8mb4_unicode_ci"}` (collation included) | e.g. `{"0":"integer","2":"autoincrement"}`, `["numeric"]`, `["varchar"]` (no collation) |
| `db:table` `table` | `size` 32768 / 49152, `comment` `""`, `collation` `utf8mb4_unicode_ci`, `engine` `InnoDB` | `size` 4096, `comment` / `collation` / `engine` all `null` |
| `db:table` `indexes` | the primary key (`primary`, attrs `{"0":"btree","3":"primary"}`) **plus one index per foreign-key column** (`orders_customer_id_foreign`, ...) | only `primary` with attrs `{"3":"primary"}`; **no index for the foreign-key columns** (SQLite does not create one) |
| `db:table` `foreign_keys[].name` | `orders_customer_id_foreign` etc. | `null` |
| `db:table` `foreign_keys[].foreign_schema` | `"laravel"` (the database name) | `null` |
| `db:table` `foreign_keys` order (items) | order_id, then product_id (definition order) | product_id, then order_id (reversed) |

Consequence for the tool: whether a foreign-key column is indexed must be read from `indexes` and cannot be assumed from the FK on SQLite; type strings need a normaliser; do not rely on FK names or ordering.

## 4. Not verified / open

- Only the latest 11.x (11.57.1) was tested. Older 11.x patch releases (e.g. 11.0 - 11.30) were not compared.
- PHP 8.3 was not tested (between the tested min and max; the 8.2 and 8.4 outputs were identical).
- Windows PHP / non-Docker PHP, Alpine variants, and non-default `php.ini` (production ini with `display_errors=Off`) were not tested. The "stdout pollution" result applies to the official image defaults.
- Why every Laravel 11 release is advisory-blocked by Composer (which advisories) was not looked into.
- SQLite was run only on PHP 8.4. MariaDB, PostgreSQL and MySQL versions other than 8.4.7 were not tested.
- `model:show` was run only for two models with relations; models with `$with`, casts, accessors, observers, policies or soft deletes were not covered.
- `db:table` was not run for views or for tables with composite or unique indexes.
- The `size` values in `db:table` depend on the DB state and are not stable.

## 5. Scrubbing of the saved outputs

Files under `php*/` are the real outputs with these replacements only: the container-side project path (the prefix before `config/database.php`, `app/early.php` etc. in PHP messages) was replaced by `<scrubbed>`. Where it occurs: `php8.5/mysql/*.stdout`, `php8.5/mitigation/*.stderr`, `php8.2|8.4/induced-early/*.stdout`, `php8.2|8.4/induced-late/*.stdout`, `php8.2|8.4/mitigation/*.stderr`. Everything else is unmodified. A `grep` over the directory for `/home/`, the user name, `/work/`, `/mnt/`, `localhost`, `secret` and `password` returns 0 lines. The DB host name `db`, database name `laravel` and the example domain `{tenant}.example.test` are generic and kept.

## 6. Layout

```
php8.2/  mysql/ induced-early/ induced-late/ mitigation/
php8.4/  mysql/ sqlite/ induced-early/ induced-late/ mitigation/ no-db/
php8.5/  mysql/ mitigation/        (bonus, outside the official range)
```

Each capture directory has `<command>.stdout`, `<command>.stderr`, and `exitcodes.txt` where it applies. `php*/mysql/route-list-extra.*` is the extra-route capture.
