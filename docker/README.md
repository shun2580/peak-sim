# Dev environment (Docker)

PHP and Composer run only in Docker; nothing is installed on the host. This is for developing and verifying peak-sim, and is not part of the fixture app.

```bash
cd docker
docker compose up -d --build                 # php-dev (PHP 8.3) + mysql 8.4
docker compose exec php-dev sh -c 'cd fixtures/laravel-app && composer install && cp -n .env.example .env && php artisan key:generate && php artisan migrate:fresh --seed'
docker compose exec php-dev sh -c 'cd fixtures/laravel-app && php artisan test'
docker compose down                          # add -v to drop the database volume
```

- The repo root is mounted at `/app`. The container runs as your user (`HOST_UID` / `HOST_GID`, default 1000).
- Another PHP line: `PHP_VERSION=8.2 docker compose up -d --build` (Laravel 13 itself needs PHP 8.3+; use 8.2 to run the tool).
- MySQL: database `app` (dev) and `app_test` (phpunit), user `app` / password `secret`, host `mysql`. Not published to the host.
- Fixture data size: `SEED_CUSTOMERS`, `SEED_PRODUCTS`, `SEED_ORDERS`, `SEED_ITEMS_PER_ORDER`, e.g. `SEED_ORDERS=10000 php artisan migrate:fresh --seed`.

## Running the tool's tests (PHP 8.2 and 8.3)

The tool itself (`src/`, `tests/`) must work on PHP 8.2 and 8.3. Run the suite on both. `vendor/` at the repo root is created inside the container and is git-ignored.

```bash
cd docker
# PHP 8.2
PHP_VERSION=8.2 docker compose -p peak-sim-t up -d --build
docker compose -p peak-sim-t exec php-dev sh -c "composer install && vendor/bin/phpunit"
docker compose -p peak-sim-t down -v

# PHP 8.3
PHP_VERSION=8.3 docker compose -p peak-sim-t up -d --build
docker compose -p peak-sim-t exec php-dev sh -c "composer install && vendor/bin/phpunit"
docker compose -p peak-sim-t down -v
```

- `-p <name>` gives the stack its own containers, network and database volume, so parallel runs do not collide. Pick any name.
- `composer.lock` resolves on both PHP lines. Do not call the fixture app's `artisan` from the tool's tests on PHP 8.2: the fixture app is Laravel 13 and needs PHP 8.3+. The tool reads the pre-exported JSON in `fixtures/artisan-export/` instead.

## Exporting artisan output (once, on PHP 8.3)

`docker/export-artisan.sh` runs the fixture app's `route:list --json` and `model:show <Model> --json` and writes them to `fixtures/artisan-export/`. Run it on PHP 8.3 with its own stack, then commit the JSON.

```bash
cd docker
PHP_VERSION=8.3 docker compose -p peak-sim-export up -d --build
docker compose -p peak-sim-export exec php-dev sh -c 'cd fixtures/laravel-app && composer install && cp -n .env.example .env && php artisan key:generate && php artisan migrate:fresh --seed'
docker compose -p peak-sim-export exec php-dev sh docker/export-artisan.sh
docker compose -p peak-sim-export down -v
```

It exports seven models in one run: `Customer`, `Order`, `Item`, `Product` and `ProductStat` (a bare name in the script's `MODELS` is an `App\Models` class), and `EagerOrder` and `AppendedOrder` of the hidden group, which live outside `App\Models` and are given as full class names (`App\Hidden\Models\EagerOrder`, `App\Hidden\Models\AppendedOrder`). Each file is named after the last segment of the class (`fixtures/artisan-export/model-show/EagerOrder.json`). To export another model, add it to `MODELS`: a bare name for `App\Models`, the full class name anywhere else.

The script calls `php -d display_errors=stderr artisan ...`, keeps stdout and stderr apart, rejects anything that is not valid JSON, and replaces absolute paths with `<scrubbed>` (see `docs/research/artisan-compat.md` section 3.4).
