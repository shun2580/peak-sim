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

## What the tool could not do for this application

- **Login (the gap of the first capture; closed afterwards, see "The capture taken again").** In the first capture the tool decides that a route needs a login from the middleware names it knows (`auth`, `auth.basic`, `auth.session`, `verified`, `can`,
  and names ending in `authenticate`: `UserProvider::requirement`). Bagisto's own middleware are `Webkul\Shop\Http\Middleware\AuthenticateCustomer`
  (alias `customer`; 53 routes) and `Webkul\User\Http\Middleware\Bouncer` (the 371 `/admin` routes). They are not recognised, so these routes are
  called without a login, answer with a redirect and are recorded `unauthenticated`: not measured. The profile's `user`, `admin` and `login_as` act only on routes the tool
  already knew need a login. Afterwards the tool was given a profile key `auth_gates` that names such a middleware as a login gate
  (`1b94918`, with `78a3e05`; a fix in `84b0472` so that the guard of a route's own `auth` wins over a gate), and `poc/runtime/profiles/bagisto.php` names the two gates (`4fdf4d0`).
  The first capture below was taken before that and keeps its numbers as they were.

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

## The first capture (100 / 1,000 / 10,000 / 30,000 rows; 98 routes, before the login gate and the data were filled)

The numbers in this section are those of the first capture and of nothing later. `bin/capture --profile=bagisto --scales=100,1000,10000,30000 --request-timeout=300 --analyze-after-build=on`, with the 98 routes of `route-set.txt` and the 123 tables of
`n-tables.txt` as `--n-tables`, in its own sealed stack (internal network, preflight passed), PHP 8.4. Run time 17 minutes. The record (`capture.json`, `tasks.json`,
`routes.jsonl`, `report.md`, `summary.json`) is kept outside the repository; sha256 of `capture.json` begins `e79216acff6c`, of `tasks.json` `86d79fe171f6`.
The database volume of the run is kept.

| | routes |
| --- | ---: |
| In the set | 98 |
| Tried | 83 |
| Measured (`measured_O1`) | 29 |
| `no_info` (the route did not read the scaled tables, or answered an empty page) | 17 |
| Not measured | 52 |
| Captured into `capture.json` | 46 routes, 129 statement types |

Why 52 were not measured: `unauthenticated` 29 (the login gap above), `unfilled_param` 12 (a token, uuid, provider, e-mail or template that the application makes itself),
`not_in_runtime` 3 (`/_debugbar/*`, which the booted application does not have), `exception` 3 (a cart or a booking product is needed), `forbidden` 3, `client_error` 1, `not_found` 1.
The report lists 36 routes with `data may not have arrived`; most are payment-gateway redirects, forms and empty-state pages.

Database builds in the capture (seconds, migrations plus generator and profile): 0 rows 48.4, 100 rows 19.4, 1,000 rows 46.4, 10,000 rows 93.5, 30,000 rows 142.6. Measuring the routes at 30,000 rows took 409 seconds.
At 100 to 30,000 rows no table failed. **At the empty point (0 rows) 22 tables failed**: the one channel points at root category 1, which does not exist when every table has 0 rows (`channels_root_category_id` foreign key),
and the tables that depend on the channel (carts, wishlists, search terms, theme sections and others) follow. The empty system is therefore not a usable point for this application as the profile stands; the four sizes are unaffected.
Nothing was changed after seeing this.

## The routes of the capture and the measurement (fixed before the capture is taken again)

This replaces the route set of the preparation capture above for the capture that is taken again after the profile was filled
(`reach-list.md`, `poc/runtime/profiles/bagisto.php`). It is decided by a written rule, before any result of that capture exists, and is not
changed after one. `select-routes.py` applies the rule to `routes-all.txt` (the 543 routes of the static analysis, `sha256` of `analysis.json` begins
`d04395480720`; HEAD dropped) and writes the files below. Running it again gives the same files, and shuffling the input does not change them.
An earlier draft of this list was never committed and was replaced by this rule before the capture was taken again.

**One correction to this rule, made before the capture and before any result of it.** The first committed version of the rule left out all 26 payment-gateway routes.
That was a misreading of the rule it was meant to apply, which left out the *return* routes of a payment (the page a gateway sends the buyer back to), not every
route of a gateway. Before the capture was taken, and before any result of it existed, the rule was changed to leave out only the 13 return routes (the last path segment is `success`, `cancel`, `failure`, `fail` or `callback`), and the list was drawn again with the same seed and the same
procedure. The earlier commit is kept as it was; this one replaces it. The 13 routes that came back into the population are the 6 gateway `redirect` routes, the 2 PayPal `create-order` and `capture-order` calls, and the 5 `webhook` and `ipn` routes.

**Rule.**

1. Left out before the draw, and only these: routes of development packages, `/install`, the payment-gateway return routes (the page a gateway sends the buyer back to), the forms of the login, register and contact
   kind, and the pages that show an empty state (the kinds `reach-list.md` leaves out of the data reach). Every route left out is in `excluded.tsv` with its reason. A route is never left out
   for another reason, and none is left out because of what a capture showed.
2. Two groups: the admin area (the URI is `/admin` or starts with `/admin/`) and the storefront and customer side (all others). The storefront and customer side gets 80 slots and the admin area 40, 120 in all.
   The storefront side is heavier because the load of a busy season comes mostly from the buyers.
3. A group with no more routes than its slots is taken whole, and its unused slots are not given to the other group (neither group was that small).
4. Otherwise the slots of a group are shared among the HTTP methods in proportion to the number of routes each method has in the group, by largest remainder: each method gets
   `floor(slots x n_method / n_group)`; the slots still free go one each to the methods with the largest fractional part (ties: more routes first, then the method name in alphabetical order).
5. Within a method, the routes are ordered by `sha256("20261124:<METHODS>:<URI>")` and the first *k* are taken. The seed is 20261124.

A route here is one entry of the analysis (a URI with the method it answers): 543 entries. The 7 framework routes the analysis skips (`/up`, `/storage/{path}`, `/sanctum/csrf-cookie` and
four others) are not in the 543 and are not touched by the rule.

**Left out before the draw: 48 of 543.**

| Reason | Routes |
| --- | ---: |
| Payment-gateway return routes (`payglocal` 2, `paypal` 2, `payu` 3, `phonepe` 2, `razorpay` 2, `stripe` 2: the last path segment is `success`, `cancel`, `failure`, `fail` or `callback`) | 13 |
| Forms of the login, register and contact kind (customer and admin: login, register, contact, forgot or reset password) | 17 |
| Pages or calls that show the visitor's own cart, compare list or checkout step (GET only) | 8 |
| Development package (`/_debugbar/*`) | 5 |
| Installer (`/install`, `/install/api/*`) | 5 |

Three readings are the preparer's and are written here so that they can be checked. The "forms" are named by URI in `select-routes.py` (`FORM_URIS`); verification, social-login and logout routes are not counted as forms, and the
admin login, forgot-password and reset-password routes are counted like their customer twins. The "empty state" kind covers four API calls that read the same cart and compare state as the pages (GET only); the calls that change the cart are not left out.
Why each of the four is in the kind, one line each (Bagisto `v2.5.2`):

- `GET /api/checkout/cart`: answers `data: null` when the visitor has no cart (`packages/Webkul/Shop/src/Http/Controllers/API/CartController.php:33`), and a visitor of the capture has none.
- `GET /api/checkout/cart/cross-sell`: answers an empty `data` list when the visitor has no cart (`CartController.php:319`); the products it would list come from the items of that cart.
- `GET /api/checkout/onepage/summary`: renders the visitor's own cart (`packages/Webkul/Shop/src/Http/Controllers/API/OnepageController.php:34`), so with no cart it has no rows.
- `GET /api/compare-items`: a visitor who is not logged in gets only the products whose ids are sent in `product_ids`, and none are sent (`packages/Webkul/Shop/src/Http/Controllers/API/CompareController.php:29`). The same route reads the compare list of a logged-in customer (`CompareController.php:34`), so this reading holds for the route as it is called, without a login. The other kinds `reach-list.md` mentions (routes that answer with a redirect or a file, pages whose rows come only from an ajax datagrid call) are **not** applied: they stay in the list.

**Groups and slots.**

| Group | Routes after the leaving out | Slots | Drawn |
| --- | ---: | ---: | ---: |
| Storefront and customer side | 121 | 80 | 80 |
| Admin area | 374 | 40 | 40 |

| Group | Method | Routes | Drawn |
| --- | --- | ---: | ---: |
| Storefront and customer | GET | 73 | 48 |
| | POST | 35 | 23 |
| | DELETE | 9 | 6 |
| | PUT | 3 | 2 |
| | PATCH | 1 | 1 |
| Admin | GET | 183 | 20 |
| | POST | 111 | 12 |
| | PUT | 38 | 4 |
| | DELETE | 42 | 4 |

Files: `routes-all.txt` (input), `select-routes.py` (procedure), `excluded.tsv` (left out, with reasons), `allocation.tsv` (the shares), `drawn.tsv` (the drawn routes with group and method),
`route-set-measure.txt` (the drawn routes, `METHODS URI`, the format of `route-set.txt`). Nothing here is a capture, an estimate or a prediction.

## The capture taken again (cap3: 100 / 1,000 / 10,000 / 30,000 rows; 120 routes)

Taken after the data profile was filled so that the routes read rows (`4fdf4d0`), with the login gates named, and on the 120 routes of `route-set-measure.txt` (`b6e7042`: 39 storefront, 41 customer side, 40 admin area;
the customer side is the routes whose URI has `/customer`, the storefront the others that are not `/admin`; this split is only for counting here). It was started after the list was committed and before any result of it existed.

| | |
| --- | --- |
| Tool version | peak-sim `dec492b`. From `b6e7042` to it only `poc/runtime/src/Estimate/`, a marked-routes script, their tests and docs changed; the path of the capture was not touched |
| Command | `bin/capture --profile=bagisto --scales=100,1000,10000,30000 --request-timeout=300 --analyze-after-build=on --keep-stack`, with the 120 routes and the 123 tables of `n-tables.txt` as `--n-tables`, in a new sealed stack and a new database volume (the volumes of earlier runs were not touched) |
| Environment | PHP 8.4.27, Laravel 13.40.0 |
| Sizes | 100, 1,000, 10,000 and 30,000 rows only, plus the empty point (0 rows) the tool always takes as a baseline. No other size was built or measured |
| Run time | 6,214 seconds, exit 0 |
| Record | `capture.json`, `tasks.json`, `routes.jsonl`, `report.md`, `summary.json`, kept outside the repository as for the first capture (not copied here); sha256 of `capture.json` begins `928b311eb47d`, of `tasks.json` `c996986b57df`. The database volume is kept |

The 120 routes in the record are exactly the 120 of `route-set-measure.txt`; the tool's own log says it inspected 120 routes, 7 of which are not controllers (which 7 was not looked into).

"Data seems to have arrived" below means: the verdict is `measured_O1` or `measured_ON` and the tool's note *data may not have arrived* is not on the route. The note is the tool's (it is added when the rows a route returns are few and
flat over the sizes); it was not added or removed by hand, and it does not change a verdict. Of the 45 measured routes, all 20 that carry no note read at least 1.5 times as many rows at 30,000 as at 100 (`rows.examined`).

| | Storefront | Customer side | Admin area | Total |
| --- | ---: | ---: | ---: | ---: |
| In the set | 39 | 41 | 40 | 120 |
| Measured: `measured_O1` | 14 | 17 | 13 | 44 |
| Measured: `measured_ON` | 0 | 0 | 1 | 1 |
| **of the measured, data seems to have arrived** (no note) | 9 | 5 | 6 | **20** |
| of the measured, with the note *data may not have arrived* | 5 | 12 | 8 | 25 |
| `no_info` (every route in it carries the note) | 5 | 1 | 7 | 13 |
| Not measured | 20 | 23 | 19 | 62 |

So of 45 measured routes, 20 show that the data reached them; 25 measured routes and 13 `no_info` routes carry the note (38 routes; `summary.json` counts 37 because it counts URIs and one URI is there with two methods).
The one `O(N)` verdict is in the admin area: `GET /admin/marketing/promotions/cart-rules/edit/{id}`, from the points at 100, 1,000 and 10,000 rows (it ran 206, 1,572 and 15,226 statements per request (the values the verdict is fitted on), and returned 1,334, 36,475 and 2,718,246 rows; at 10,000 rows
the answer was about 989 MB and the peak memory about 4.3 GB). At 30,000 rows it answered 500, and why was not found.
This count cannot be set beside the first capture's (29 measured, 17 `no_info`, 98 GET routes of the storefront): the route set and the data are different, and no better or worse is claimed.

Why 62 were not measured:

| Reason | Storefront | Customer side | Admin area | Total |
| --- | ---: | ---: | ---: | ---: |
| `validation_422` (a required input is missing) | 3 | 6 | 6 | 15 |
| `not_found` (404) | 2 | 8 | 4 (+1) | 14 (+1) |
| `exception` (500) | 1 (+2) | 3 | 5 | 9 (+2) |
| `unfilled_param` | 3 | 4 (+2) | 1 | 8 (+2) |
| `forbidden` | 5 | 0 | 0 | 5 |
| `unauthenticated` | 3 | 0 | 0 | 3 |
| `client_error` | 0 | 0 | 2 | 2 |
| `external_call_blocked` | 1 | 0 | 0 | 1 |
| Total | 20 | 23 | 19 | 62 |

A number in brackets is a route that was not measured because only two points could be taken (`insufficient_points`), not for the reason of its row: the related-products and up-sell calls (a 500 at 0 rows),
the wishlist call and one more customer call (at 0 rows there is no customer to act as), and the admin product edit page (404 at 0 rows).

The login gates work: of the 41 customer-side and 40 admin routes, none is recorded `unauthenticated` (the first capture recorded 29 customer-area routes that way); the 3 `unauthenticated` here are storefront routes.

**What is not known.**

- The cause of the 9 `exception` routes (and of the 500 of the one `O(N)` route at 30,000 rows) is not known: the application writes its log to stderr, the tool does not keep an exception's text, and there is no log file in the container.
  Among them are the customer's order page and the order withdraw route. A fix for the order page that was tried (the product type of the order's items) did not change the answer and was not kept.
- Which 7 routes the tool counts as not being controllers is not known.
- That a route shows the data reached it says the rows it read grew with the size; it does not say the route is measured well at a size that was not seen.

Raw output is not in the repository (as for the first capture); the numbers above are counted from `routes.jsonl`.

## How much the static analysis did here

The static analysis was not changed to fit this application (decided before the capture was taken again). The number that shows its effect is the count of routes in which it found at least one place that reads the database (`events_found` not empty):

| Application | Routes with such a place | Routes in the analysis |
| --- | ---: | ---: |
| Bagisto | 15 | 543 |
| BookStack | 264 | 341 |

Both counts were read again from the two `analysis.json` files kept outside the repository (`d04395480720` for Bagisto; `be65281cba06` for BookStack, taken with an earlier analyzer output schema, 0.1 against 0.2, on 2026-11-01).
Where the routes of Bagisto stop the analysis, by gap code, is the list under "Static analysis" above. Three gaps are left as work **after version 1.0**, not fixed for this application: the repository layer
(the model is reached through a vendor base class and a contract that the analysis cannot follow), the view names of packages (`shop::home.index` is not looked up), and the vendor helper functions and response types that are not modelled.
Fitting the analysis to Bagisto after seeing it would weaken the condition that the application was not seen before.

## Left open

- The gap named in the first capture (the login of the customer side and the admin area) is closed by the login gates (`1b94918`, `84b0472`, `4fdf4d0`); the routes that were left for a later run are in the capture taken again.
- The empty point (0 rows) is still not usable for this application as the profile stands (22 tables fail there, as above). Nothing was changed about it.
- The 62 routes not measured and the 9 `exception` routes (their cause is not known), as above.
- The sizes and the pass line of a Bagisto test are not decided here: they are for the maintainer's next instruction. No test size was built, estimated or predicted, and no estimate, EXPLAIN at a target size or prediction was made.
