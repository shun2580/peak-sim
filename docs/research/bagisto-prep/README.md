# Bagisto: preparation for the runtime measurement

A record of how a third real application (Bagisto, a Laravel e-commerce platform) was prepared for the runtime measurement
(`poc/runtime`): the install, the static analysis, the route set of a **preparation capture**, the data profile, and the capture.
It is preparation only. No estimate, prediction or measurement was made at a size that was not seen before (the capture sizes are
100, 1,000, 10,000 and 30,000 rows, as for the other apps), and nothing here selects the routes of any test.

The application is never changed (D7): it is a clean clone, its seeder is not run, and its code runs only in the sealed stack
of `poc/runtime`.

## The application

| | |
| --- | --- |
| Source | `https://github.com/bagisto/bagisto`, tag `v2.5.2` (the latest stable release when it was taken), commit `03114b667914054d48f0dd27698db04da5e07a13` (2026-11-18) |
| Requires | `php ^8.4`, `laravel/framework ^13.0` (`composer.json`), so it runs on PHP 8.4. The application's PHP and the tool's own requirement (PHP 8.2 or later, tested on 8.2 and 8.3) are separate, as with the application that runs on PHP 8.5 |
| Obtained from | the official GitHub repository (`git clone --depth 1 --branch v2.5.2`) and composer (packagist; GitHub zipballs) only |
| Installed | `composer install --no-interaction` (development packages included, as for the other apps), then a `.env` copied from `.env.example` with four database keys set, `php artisan key:generate`, `php artisan migrate --force`: 213 migrations, all done on MySQL 8.4, 152 tables. No seeder. The clone's `git status` is clean afterwards |
| One install detail | the GitHub zipball of one package (`php-jsonpointer/php-jsonpointer` v3.0.2) answered HTTP 504 on every try. Composer's own (global, not the application's) setting `preferred-install` was set to `source` for that one package, which clones it from GitHub instead. Nothing in the application changed |
| PHP image of the measurement | `PEAK_PHP_EXTENSIONS=calendar,gd PHP_VERSION=8.4 bin/stack build-image peak-measure-php-bagisto:8.4` (the application needs `ext-calendar` and `ext-gd` beyond the default image) |

## Static analysis

`peak-sim analyze <app> --artisan-command='php artisan' --format=json`: 5 seconds, 543 routes (all of them `controller` routes), 7 skipped
(framework routes). **All 543 are `unknown`** on both axes, as are all 341 routes of BookStack and all of Snipe-IT and Laravel.io; only Firefly III and
Koel have a few determinate routes. One route has a static lower bound (`POST /install/api/create-admin-user`, `O(N)`), and 10 routes name a candidate table (`n_table`).
The gaps, by code, as the number of routes that carry at least one (a route carries several): VENDOR_CALL_NOT_MODELLED 476, VENDOR_PARENT 399,
UNRESOLVED_CALL 251, NEW_UNKNOWN_CLASS 173, VIEW_NOT_FOUND 140, LOOP_SOURCE_UNKNOWN 88, NO_BINDING 31, METHOD_NOT_FOUND 19,
LOOP_BOUND_UNKNOWN 14, RECURSION 13, DYNAMIC_METHOD_NAME 10, CACHE_VALUE_SIZE_UNKNOWN 6, DYNAMIC_CLASS_NAME 6, UNKNOWN_BUILDER_METHOD 1.
The analysis file is kept outside the repository. The analyzer and the tool were not changed.

## The route set of the preparation capture

**This is a set for the preparation capture. It is not the route selection of a test**: how the routes of a test are chosen is a
separate rule that is not written here.

The rule, applied mechanically to the analysis above and nothing else: a route whose kind is `controller`, that answers GET, and whose
URI does not start with `/admin` or `/install`. That is 98 routes (`route-set.txt`): the storefront (`/`, `/api/*`, `/search`,
`/checkout/*`, `/customer/*`), the payment-gateway callbacks, and three development-package routes (`/_debugbar/*`) that a production
install would not have. Nothing was added or dropped by hand. The 371 `/admin` routes are left for a later run.

## The data profile (`poc/runtime/profiles/bagisto.php`)

What a person knows about the application and its schema cannot say. The tool's generator fills each table from its own schema; Bagisto
needs the rows its seeders write (one channel, one locale, one currency, the attribute definitions, the customer groups, the root
category) and finds a product's data through combinations of rows (attribute, channel, locale) that the generator cannot make. Each
declaration says why, with the file and line in Bagisto `v2.5.2`. The profile has a unit test (`tests/Unit/Profiles/BagistoProfileTest.php`, PHP 8.2 and 8.3).

- **Pinned small** (`rows`): channel, locale, currency, inventory source, attribute family, the role and admin of the login, the
  three customer groups, the 29 attribute definitions, and the channel's link tables.
- **Built by statements** (`skip` + `fixups`): `product_attribute_values` (sku, name, url key, visible individually, status, price, one row each per product),
  `product_price_indices`, `product_inventory_indices`, `product_inventories`, `product_channels`, `product_categories`. `product_flat` and `currency_exchange_rates` are left empty (one currency, no rate).
- **Given a meaning** (`fixups`): the categories become a nested set under the root, CMS pages get a url key, the customer groups get their codes.
- **Not done**: the catalogue is simple products only. Configurable, bundle, grouped, booking and downloadable products, product images, reviews, group prices, cart rules and
  catalog rules are what the generator made (rows that exist, linked to rows that exist, but not rows the application can use).

### Which tables grow

The decision is made here, before the capture, and does not depend on the static analysis (whose `n_table` is not used). **Every table of
the schema grows together to N rows, except**: the framework's own tables (never filled), the tables pinned small above, and the tables the
fixups build (their size follows the products). 123 tables grow (`n-tables.txt`), passed to `bin/capture` as `--n-tables`, so the
routes share one database per size, built as the lab database is, with the empty system (0 rows) first. The list was made from the migrated schema
by that rule alone.

## What the tool could not do for this application (the tool was not changed)

- **Login.** The tool decides that a route needs a login from the middleware names it knows (`auth`, `auth.basic`, `auth.session`, `verified`, `can`,
  and names ending in `authenticate`: `UserProvider::requirement`). Bagisto's own middleware are `Webkul\Shop\Http\Middleware\AuthenticateCustomer`
  (alias `customer`; 53 routes) and `Webkul\User\Http\Middleware\Bouncer` (the 371 `/admin` routes). They are not recognised, so these routes are
  called without a login, answer with a redirect and are recorded `unauthenticated`: not measured. The profile's `user`, `admin` and `login_as` act only on routes the tool
  already knows need a login.

## Database build time (checked before the capture)

One database of N rows per table, built by the tool (`bin/inner/build-db.php`: the application's migrations, the generator, the profile's statements), in the sealed stack,
all 152 tables, no table failed at any size. The driver stops a build after 7,200 seconds (`ProcessDatabaseBuilder::TIMEOUT`); the 900 seconds of `bin/variance-watch` is the length of a single statement it reports, not a build limit.

| N | migrations (s) | generator and profile (s) | total (s) | `products` | `product_attribute_values` |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 100 | 18.1 | 1.2 | 21.2 | 100 | 600 |
| 1,000 | 17.4 | 11.4 | 29.0 | 1,000 | 6,000 |
| 10,000 | 49.5 | 61.9 | 112.4 | 10,000 | 60,000 |
| 30,000 | 18.0 | 134.0 | 154.2 | 30,000 | 180,000 |

The generator's time grows about linearly with N (about 4.5 ms per N at 30,000); the migrations take 17 to 18 seconds on an idle machine (the 49.5 seconds at 10,000 was on a busy one). The largest build is far under the limit.
