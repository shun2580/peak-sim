# artisan compatibility research: Laravel 12

Scope: `route:list --json`, `model:show {Model} --json`, `db:table {table} --json` on Laravel 12 (T1, Laravel 12 share).
Laravel 11 and 13 are covered by separate notes; the merged comparison lives elsewhere.

## 1. Versions used

| Item | Value | How it was confirmed |
|---|---|---|
| Laravel | 12.70.2 (`laravel/framework`), skeleton `laravel/laravel` from `composer create-project laravel/laravel:^12.0` | `php artisan --version`, `composer.lock` |
| PHP range for Laravel 12 | `^8.2` (min 8.2, max 8.5) | skeleton `composer.json` `require.php` = `^8.2`; every `laravel/framework` v12.x on Packagist requires `^8.2`; the official release page lists Laravel 12 as "PHP 8.2 - 8.5" |
| PHP (min) | 8.2.35 (`php:8.2-cli`) | `php -v` (`php8.2/mysql/php-version.txt`) |
| PHP (max) | 8.5.12 (`php:8.5-cli`) | `php -v` (`php8.5/mysql/php-version.txt`) |
| Composer | 2.11.1 | `composer --version` in the dev image |
| DB (main) | MySQL 8.4.7 (`mysql:8.4.7`) | image tag, `mysqld --version` |
| DB (supplement) | SQLite (Laravel default), PHP 8.5 only | `DB_CONNECTION=sqlite` |

The PHP upper bound 8.5 is "confirmed" in the sense that `composer create-project`, `migrate:fresh` and all five
artisan commands run on 8.5.12 without error. Laravel's own CI matrix was not inspected.

Each PHP got its own skeleton project (dependencies resolved separately by Composer on that PHP).
Both resolved to the same `laravel/framework` 12.70.2.

## 2. Reproduction

Dev image per PHP version (official `php:X.Y-cli` image has neither `pdo_mysql` nor composer):

```dockerfile
FROM php:8.2-cli            # or php:8.5-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /work
```

Compose (project name `t1-12`, no published host ports; `php82`/`php85` services mount the work dir, the DB is
reachable only on the compose network):

```yaml
services:
  mysql:
    image: mysql:8.4.7
    environment: { MYSQL_ROOT_PASSWORD: root, MYSQL_DATABASE: laravel }
    healthcheck: { test: ["CMD","mysqladmin","ping","-h","127.0.0.1","-proot"], interval: 3s, timeout: 3s, retries: 40 }
  php82:   # php85 is identical with image t1-12-php85 and working_dir /work/app-php8.5
    image: t1-12-php82
    user: "<uid>:<gid>"
    working_dir: /work/app-php8.2
    volumes: [".:/work"]
    environment: { HOME: /tmp, DB_CONNECTION: mysql, DB_HOST: mysql, DB_PORT: "3306",
                   DB_DATABASE: laravel, DB_USERNAME: root, DB_PASSWORD: root }
    depends_on: { mysql: { condition: service_healthy } }
```

Steps:

```sh
docker build -f Dockerfile.php8.2 -t t1-12-php82 .
docker build -f Dockerfile.php8.5 -t t1-12-php85 .
docker run --rm -v "$PWD":/work -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/c -e HOME=/tmp t1-12-php82 \
  composer create-project laravel/laravel:^12.0 app-php8.2 --no-interaction --no-progress   # same for 8.5
# add the shared subject (below) to each app, then per PHP:
docker compose -p t1-12 run --rm -T php82 php artisan migrate:fresh --force
docker compose -p t1-12 run --rm -T php82 php artisan route:list --json            > route-list.json 2> route-list.err
docker compose -p t1-12 run --rm -T php82 php artisan model:show Order --json      > model-show-Order.json 2> model-show-Order.err
docker compose -p t1-12 run --rm -T php82 php artisan model:show Item --json       > model-show-Item.json 2> model-show-Item.err
docker compose -p t1-12 run --rm -T php82 php artisan db:table orders --json       > db-table-orders.json 2> db-table-orders.err
docker compose -p t1-12 run --rm -T php82 php artisan db:table items --json        > db-table-items.json 2> db-table-items.err
docker compose -p t1-12 run --rm -T php82 php artisan db:table customers --json    > db-table-customers.json 2> db-table-customers.err   # MySQL only
echo $?   # exit code recorded after each command (*.exit); JSON validated with python3 -m json.tool (*.jsoncheck)
docker compose -p t1-12 down -v
```

The stream split was itself checked: a container command that writes `OUT` to stdout and `ERR` to stderr lands in the
two redirect targets separately, so the `.err` files hold only what artisan wrote to stderr.
No `php.ini` was used (`php --ini` shows "Loaded Configuration File: (none)" in the official image).
`compose` banner lines are silenced with `--progress quiet` so they do not leak into `*.err`.

SQLite supplement (PHP 8.5 only): same commands with `-e DB_CONNECTION=sqlite -e DB_DATABASE=<path to an empty database.sqlite in the app>`.

Shared subject (identical in both apps, created by migrations; models have `$timestamps = false`, `$guarded = []`):

- `customers(id, name, email unique)`, `products(id, name, price)`
- `orders(id, customer_id -> customers, status, total)`, `items(id, order_id -> orders, product_id -> products, quantity, price)`
- Models: `Customer` hasMany `Order`; `Order` belongsTo `Customer`, hasMany `Item`; `Item` belongsTo `Order`, belongsTo `Product`; `Product` hasMany `Item`
- Routes appended to `routes/web.php` so `route:list` has controller routes and middleware:
  `GET /orders` (named, `throttle:60,1`), `GET /orders/{order}` (named), `Route::resource('items', OrderController::class)->only(['index','store'])`

Scrubbing: the raw outputs contain no absolute host paths, user names or host names (`path` values are project-relative,
the container-side path never appears), so no replacement was made and no `<scrubbed>` marker exists.
This was checked by grep over the saved files. Command timings in `migrate.out` are the only run-specific values.

## 3. Results

Directory layout: `php8.2/mysql/`, `php8.5/mysql/`, `php8.5/sqlite/`, `php8.5/warning-probe/`.
Per command: `<name>.json` (stdout), `<name>.err` (stderr), `<name>.exit` (exit code), `<name>.jsoncheck`
(`valid` when `json.tool` accepts the file).

| Command | PHP 8.2.35 / MySQL | PHP 8.5.12 / MySQL | PHP 8.5.12 / SQLite |
|---|---|---|---|
| `route:list --json` | works, exit 0, valid JSON, stderr empty | works, identical bytes to 8.2 | works, identical bytes to MySQL |
| `model:show Order --json` | works | works, identical bytes to 8.2 | differs (types only, see below) |
| `model:show Item --json` | works | works, identical bytes to 8.2 | differs (types only) |
| `db:table orders --json` | works | works, identical bytes to 8.2 | differs (many fields) |
| `db:table items --json` | works | works, identical bytes to 8.2 | differs (many fields) |

Within Laravel 12.70.2 there is no difference at all between PHP 8.2 and PHP 8.5 for any of the five outputs
(`cmp` on every file: identical). No deprecation or warning reached stdout or stderr on PHP 8.5.

### 3.1 JSON shapes (Laravel 12.70.2, MySQL)

- `route:list --json`: top-level array. Each element: `domain` (null), `method` (string, `"GET|HEAD"` joined with `|`),
  `uri` (no leading slash except `"/"`), `name` (string or null), `action` (`"Closure"` or `"Class@method"`),
  `middleware` (array of strings, e.g. `["web","throttle:60,1"]`), `path` (`"routes/web.php:5"` for closures,
  **null for controller routes**). Vendor-registered routes (`storage/{path}`, `up`) are present in the list.
- `model:show X --json`: object with `class`, `database` (connection name), `table`, `policy`, `attributes[]`
  (`name`, `type`, `increments`, `nullable`, `default`, `unique`, `fillable`, `hidden`, `appended`, `cast`),
  `relations[]` (`name`, `type` as short class name such as `BelongsTo`/`HasMany`, `related` as FQCN), `events[]`, `observers[]`.
  Our models declare return types on their relation methods; relations without a return type were not tried.
- `db:table X --json`: object with `table` (`schema`, `name`, `schema_qualified_name`, `columns` as a count, `size`, `comment`,
  `collation`, `engine`), `columns[]` (`column`, `attributes`, `default`, `type`), `indexes[]` (`name`, `columns[]`, `attributes`),
  `foreign_keys[]` (`name`, `columns[]`, `foreign_schema`, `foreign_table`, `foreign_columns[]`, `on_update`, `on_delete`).

### 3.2 Surprises worth handling in the tool

1. **`attributes` is a JSON array in some places and a JSON object in others.** It is produced by filtering a list while keeping keys,
   so when an earlier element is removed the result serializes as an object with numeric string keys:
   `"attributes": {"0": "bigint", "2": "autoincrement"}` for `items.id` versus `"attributes": ["bigint"]` for `items.order_id`.
   The same happens for the primary index (`{"0": "btree", "3": "primary"}`). A parser must accept both and should read the values only.
2. **There is no `unique` or `primary` boolean on `indexes[]` in `db:table`.** The kind is carried only in `attributes` as a string
   (`"primary"` or `"unique"`; `db-table-customers.json` shows `customers_email_unique` as `{"0":"btree","2":"unique"}` and the primary as
   `{"0":"btree","3":"primary"}`). The numeric keys are not stable positions, so read the values, never the keys.
   The primary index is named `"primary"`.
3. **MySQL lists the foreign-key backing indexes** (`items_order_id_foreign`, `items_product_id_foreign`) as `btree` indexes
   in addition to the primary key. This matters for index-based judgements; SQLite does not (see 3.3).
4. **`table.columns` is a count (int), not the column list.** `table.size` is a byte size that varies with engine state
   (32768 / 49152 for the empty `orders` / `items` on MySQL).
5. **Database connection.** `model:show` and `db:table` were run with the MySQL container reachable (and with SQLite for the supplement);
   behaviour with no reachable DB was not tested here, so whether they require a live connection is unconfirmed.
6. **Output cleanliness.** Plain stdout holds only JSON. `*.err` is empty for all five commands on both PHP versions
   (the official image has no `php.ini`).
   Probes under `php8.5/warning-probe/` (an extra line appended to `routes/web.php`, then `route:list --json`):

   | Injected into the app | Exit | JSON on stdout |
   |---|---|---|
   | `trigger_error(..., E_USER_DEPRECATED)`, default ini | 0 | valid (the deprecation is swallowed by Laravel, nothing printed) |
   | same, with `-d display_errors=1 -d error_reporting=-1` | 0 | valid |
   | `trigger_error(..., E_USER_WARNING)` | 1 | **broken**: stdout holds the rendered `ErrorException` (ANSI-coloured), stderr holds a lone newline |
   | `trigger_error(..., E_USER_NOTICE)` | 1 | **broken**, same shape as the warning case |
   | `echo "stray output";` | 0 | **broken**: the text is prepended to the JSON (`stray output[{"domain":...`) |

   Consequences: (a) deprecations alone did not break the JSON in this setup; (b) a PHP warning/notice raised while the routes load
   becomes an `ErrorException`, **and its text goes to stdout, not stderr**, with exit code 1, so the exit code must be checked and
   stdout must not be trusted when it is non-zero; (c) any output written during bootstrap (an `echo` in a route file or service
   provider) is prepended to stdout with exit code 0, so validity must be checked by parsing, not by exit code alone.
   These probes are synthetic; real-world noise sources other than the ones injected were not enumerated.

### 3.3 SQLite vs MySQL (Laravel 12.70.2, PHP 8.5.12)

`route:list` is identical. Differences elsewhere:

| Field | MySQL | SQLite |
|---|---|---|
| `model:show` `database` | `"mysql"` | `"sqlite"` |
| `model:show` attribute `type` | `bigint unsigned`, `varchar(255)`, `int unsigned` | `integer`, `varchar`, `integer` (no length, no `unsigned`) |
| `db:table` `table.schema` / `schema_qualified_name` | `laravel` / `laravel.orders` | `main` / `main.orders` |
| `db:table` `table.size` | 32768 (orders), 49152 (items) | 4096 |
| `db:table` `table.comment` / `collation` / `engine` | `""` / `utf8mb4_unicode_ci` / `InnoDB` | `null` / `null` / `null` |
| `columns[].type` | `bigint unsigned`, `varchar(255)`, `int unsigned` | `integer`, `varchar`, `integer` |
| `columns[].attributes` | base type, plus the collation for string columns (`orders.status`: `{"0":"varchar","4":"utf8mb4_unicode_ci"}`, an object) | only the base type (`["varchar"]`) |
| `indexes[]` | primary **plus one `btree` index per foreign key** (`orders_customer_id_foreign`, `items_order_id_foreign`, `items_product_id_foreign`) | **primary only**, no foreign-key backing index |
| `foreign_keys[].name` | `orders_customer_id_foreign` etc. | **`null`** |
| `foreign_keys[]` order (`items`) | `order_id`, then `product_id` | `product_id`, then `order_id` (reversed) |
| `foreign_keys[].foreign_schema` | `laravel` | `main` |

The SQLite run confirms why D20 excludes SQLite for index judgements: it reports no index on the foreign-key columns, so an
index check built on SQLite output would flag every foreign key as unindexed.

## 4. Not confirmed

- Laravel 12 with PHP versions between 8.2 and 8.5 (8.3, 8.4) was not run; only the min and max were.
- The upper PHP bound 8.5 comes from the official support table plus a clean run on 8.5.12; Laravel's CI matrix and PHP 8.6 were not checked.
- Composite indexes and non-unique plain indexes in `db:table`: not captured (only the single-column unique index on `customers.email` was, via an extra `db:table customers --json` on MySQL for both PHPs; it was not run on SQLite).
- Behaviour of the three commands when the database is unreachable, and `db:table` for views.
- `route:list --json` for routes with `domain`, route groups with prefixes, or `Route::fallback`: only the routes listed in section 2 were exercised.
- Other model features (casts, accessors, observers, polymorphic relations, relations declared without a return type) were not covered by `model:show`.
- SQLite was run on PHP 8.5 only; SQLite on PHP 8.2 was not run.
- Only one patch version of Laravel 12 (12.70.2, latest at the time of the run) was tested; earlier 12.x patches were not.
- The sandbox warning probes are synthetic.
