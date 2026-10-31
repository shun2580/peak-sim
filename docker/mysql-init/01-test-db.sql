-- Separate database for `php artisan test` (phpunit.xml points here).
CREATE DATABASE IF NOT EXISTS app_test;
GRANT ALL PRIVILEGES ON app_test.* TO 'app'@'%';
