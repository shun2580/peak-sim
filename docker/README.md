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
