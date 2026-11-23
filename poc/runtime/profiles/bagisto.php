<?php

declare(strict_types=1);

use PeakSim\Runtime\Guard\DbGuard;

// Knowledge about this app (Bagisto, a Laravel e-commerce platform) that its schema and its routes cannot give. A run
// uses it only when asked for (`--profile=bagisto`), and a result reached with it is reported as such, apart from one
// reached without.
//
// This is knowledge, not a rule of judgement: nothing here decides whether a count is flat or grows. The app is never
// changed and its seeder is never run; the rows its seeders write (one channel, one locale, one currency, the attribute
// definitions, the customer groups, the root category) are written here by hand, after the generator has filled the
// database, with the place in the seeders (packages/Webkul/Installer/src/Database/Seeders) they were read from.
//
// What the file holds
//   rows      the reference tables the app keeps small however large the shop is (one channel, one locale, ...), so a
//             plan that grows every table does not make a thousand channels.
//   values    columns whose generated word the app reads as a code or a name (`locales.code`, `channels.theme`, ...).
//   skip      the tables that are built by the statements below, because the app finds a product's data by a
//             combination of rows (attribute, channel, locale) that the generator cannot make.
//   fixups    single statements, run in this order after the generator, that give the generated rows the meaning the
//             app looks for. Each says why, and where in the app that is.
//
// Not done here, and why it matters when reading a result: the catalogue is simple products only; configurable,
// bundle, grouped, booking and downloadable products, product images, reviews, customer-group prices, cart rules and
// catalog rules are whatever the generator made (words in the columns, links that point at rows that exist but do not
// mean anything). The routes that read them see rows, but not rows the app can use.
$profile = [
    'rows' => [
        'channels' => 1,
        'channel_translations' => 1,
        'channel_locales' => 1,
        'channel_currencies' => 1,
        'channel_inventory_sources' => 1,
        'locales' => 1,
        'currencies' => 1,
        'inventory_sources' => 1,
        'customer_groups' => 3,
        'attribute_families' => 1,
        'attributes' => 29,
        'roles' => 1,
        'admins' => 1,
    ],

    'values' => [
        'channels' => ['code' => 'default', 'timezone' => 'UTC', 'theme' => 'default', 'hostname' => null, 'logo' => null, 'favicon' => null, 'home_seo' => '{}', 'allowed_ips' => null, 'is_maintenance_on' => 0],
        'channel_translations' => ['locale' => 'en', 'name' => 'Default', 'home_seo' => '{}'],
        'locales' => ['code' => 'en', 'name' => 'English', 'direction' => 'ltr', 'logo_path' => null],
        'currencies' => ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal' => 2, 'currency_position' => null],
        'inventory_sources' => ['code' => 'default', 'name' => 'Default', 'status' => 1],
        'attribute_families' => ['code' => 'default', 'name' => 'Default', 'status' => 0, 'is_user_defined' => 0],
        'roles' => ['name' => 'Administrator', 'description' => 'All permissions', 'permission_type' => 'all', 'permissions' => null],
        'admins' => ['name' => 'Measure Admin', 'email' => 'admin@example.test', 'status' => 1, 'api_token' => null, 'two_factor_enabled' => 0],
        'customers' => ['status' => 1, 'is_verified' => 1, 'is_suspended' => 0, 'api_token' => null],
        'products' => ['type' => 'simple', 'parent_id' => null],
    ],

    // Route argument => where its value comes from. The tool's rules cannot fill these: a bare `{id}` names no table
    // (the generated rows are numbered from 1, so row 1 exists in every table), and a page is found by its url key.
    'arguments' => [
        'id' => ['value' => 1, 'why' => 'a bare {id} names no table; the generated rows are numbered from 1, so row 1 exists in every table (products, orders, addresses, return requests)'],
        'review_id' => ['value' => 1, 'why' => 'a review is looked up by its number inside the product (packages/Webkul/Shop/src/Http/Controllers/API/ReviewController.php:109)'],
        'slug' => ['value' => 'page-1', 'why' => 'a CMS page is found by its url key in the current locale (packages/Webkul/Shop/src/Http/Controllers/PageController.php:30-33); the fixup `cms_page_translations_are_english_with_a_url_key` makes the key of page n `page-n`'],
    ],

    // Tables built by the fixups below instead of by the generator, and `currency_exchange_rates`: the shop has one
    // currency, so there is no rate to keep (the table's target currency is unique).
    'skip' => ['product_attribute_values', 'product_flat', 'product_price_indices', 'product_inventory_indices', 'product_inventories', 'product_categories', 'product_channels', 'currency_exchange_rates'],

    'fixups' => [
        [
            'name' => 'customer_groups_are_the_three_the_app_knows',
            'why' => 'the app finds the visitor\'s group by the code `guest` and a logged-in customer\'s by their group row (packages/Webkul/Customer/src/Repositories/CustomerRepository.php:40); the seeder writes guest, general and wholesale (packages/Webkul/Installer/src/Database/Seeders/Customer/CustomerGroupTableSeeder.php:28-38). The code is unique, so the three rows cannot share a constant',
            'sql' => "UPDATE customer_groups SET code = CASE id WHEN 1 THEN 'guest' WHEN 2 THEN 'general' ELSE 'wholesale' END, name = CASE id WHEN 1 THEN 'Guest' WHEN 2 THEN 'General' ELSE 'Wholesale' END, is_user_defined = 0",
        ],
        [
            'name' => 'attributes_are_the_defined_ones',
            'why' => 'the app looks an attribute up by its code (name, url_key, status, visible_individually, price, ...; packages/Webkul/Attribute/src/Repositories/AttributeRepository.php:173) and reads a product\'s value from the column its type names. The migration makes row 1 (`manage_stock`); the generated rows 2 to 30 take, in the seeder\'s order, the other 29 definitions of packages/Webkul/Installer/src/Database/Seeders/Attribute/AttributeTableSeeder.php:33 onward (type, whether the value is kept per locale or per channel, whether it is a filter)',
            'sql' => "UPDATE attributes SET code = ELT(id - 1, 'sku', 'name', 'url_key', 'tax_category_id', 'new', 'featured', 'visible_individually', 'status', 'short_description', 'description', 'price', 'cost', 'special_price', 'special_price_from', 'special_price_to', 'meta_title', 'meta_keywords', 'meta_description', 'length', 'width', 'height', 'weight', 'color', 'size', 'brand', 'guest_checkout', 'product_number', 'allow_rma', 'rma_rule_id'), type = ELT(id - 1, 'text', 'text', 'text', 'select', 'boolean', 'boolean', 'boolean', 'boolean', 'textarea', 'textarea', 'price', 'price', 'price', 'date', 'date', 'textarea', 'textarea', 'textarea', 'text', 'text', 'text', 'text', 'select', 'select', 'select', 'boolean', 'text', 'boolean', 'select'), admin_name = code, swatch_type = NULL, validation = NULL, regex = NULL, default_value = NULL, is_required = 0, is_unique = 0, is_filterable = IF(code IN ('price', 'color', 'size', 'brand'), 1, 0), is_configurable = 0, is_user_defined = 0, is_visible_on_front = 0, is_comparable = 0, value_per_locale = IF(code IN ('name', 'url_key', 'short_description', 'description', 'meta_title', 'meta_keywords', 'meta_description'), 1, 0), value_per_channel = IF(code IN ('status', 'price', 'special_price', 'cost'), 1, 0), enable_wysiwyg = 0 WHERE id BETWEEN 2 AND 30",
        ],
        [
            'name' => 'categories_are_a_tree_under_the_root',
            'why' => 'a category tree is a nested set: the root holds every other row between its left and right numbers, and a child takes two numbers of its own (kalnoy/nestedset, packages/Webkul/Category/src/Models/Category.php:18; the app reads the tree by those numbers, packages/Webkul/Category/src/Repositories/CategoryRepository.php:220). Row 1 is the root the channel points at (packages/Webkul/Installer/src/Database/Seeders/Category/CategoryTableSeeder.php:43, Seeders/Core/ChannelTableSeeder.php:37 `root_category_id` 1). The generator wrote the left and right numbers as 0 and the parent as the row itself',
            'sql' => "UPDATE categories SET parent_id = IF(id = 1, NULL, 1), _lft = IF(id = 1, 1, 2 * (id - 1)), _rgt = IF(id = 1, 2 * (SELECT COUNT(*) FROM (SELECT 1 FROM categories) c), 2 * (id - 1) + 1), status = 1, position = id, display_mode = 'products_and_description'",
        ],
        [
            'name' => 'category_translations_are_english_with_a_slug',
            'why' => 'a category is shown by its translation for the locale code and found by its slug (packages/Webkul/Category/src/Models/CategoryTranslation.php:11); the generated locale is a word and the slug a made-up string',
            'sql' => "UPDATE category_translations SET locale = 'en', locale_id = 1, name = CONCAT('Category ', category_id), slug = CONCAT('category-', category_id), url_path = CONCAT('category-', category_id)",
        ],
        [
            'name' => 'channel_points_at_the_root_and_the_only_locale_and_currency',
            'why' => 'a channel names its root category, default locale and base currency (packages/Webkul/Installer/src/Database/Seeders/Core/ChannelTableSeeder.php:30-40); the shop resolves the current channel from the hostname and falls back to the first channel (packages/Webkul/Core/src/Core.php:139-159)',
            'sql' => 'UPDATE channels SET root_category_id = 1, default_locale_id = 1, base_currency_id = 1',
        ],
        [
            'name' => 'the_admin_role_holds_every_permission',
            'why' => 'an admin may open a page when the role\'s permission type is `all` or the permission is in its list (packages/Webkul/User/src/Bouncer.php:17); the admin the measurement logs in as is row 1, with role 1',
            'sql' => "UPDATE admins SET role_id = 1, status = 1, name = 'Measure Admin', email = 'admin@example.test'",
        ],
        [
            'name' => 'cms_page_translations_are_english_with_a_url_key',
            'why' => 'a page is found by the url key of its translation (packages/Webkul/Shop/src/Http/Controllers/PageController.php:30-33, packages/Webkul/CMS/src/Repositories/PageRepository.php:104); the generated key is a made-up string and the locale a word',
            'sql' => "UPDATE cms_page_translations SET locale = 'en', url_key = CONCAT('page-', cms_page_id), page_title = CONCAT('Page ', cms_page_id)",
        ],
        [
            'name' => 'products_are_in_the_default_family',
            'why' => 'a product\'s attributes are the ones of its family; the generated family numbers point at the one family row (packages/Webkul/Installer/src/Database/Seeders/Attribute/AttributeFamilyTableSeeder.php:25)',
            'sql' => "UPDATE products SET type = 'simple', parent_id = NULL, attribute_family_id = 1",
        ],
        [
            'name' => 'product_values_sku',
            'why' => 'a product\'s value for an attribute is one row of product_attribute_values, found by (channel, locale, product, attribute); `unique_id` is those four joined with | (packages/Webkul/Product/src/Repositories/ProductAttributeValueRepository.php:172, packages/Webkul/Product/src/Type/AbstractType.php:1053). The sku is kept for neither channel nor locale',
            'sql' => "INSERT INTO product_attribute_values (channel, locale, text_value, product_id, attribute_id, unique_id) SELECT NULL, NULL, p.sku, p.id, a.id, CONCAT_WS('|', p.id, a.id) FROM products p JOIN attributes a ON a.code = 'sku'",
        ],
        [
            'name' => 'product_values_name',
            'why' => 'the name is kept per locale (value_per_locale 1 in the seeder); the listing filters on it with a LIKE when a search term is given (packages/Webkul/Product/src/Repositories/ProductRepository.php:551)',
            'sql' => "INSERT INTO product_attribute_values (channel, locale, text_value, product_id, attribute_id, unique_id) SELECT NULL, 'en', CONCAT('Product ', p.id), p.id, a.id, CONCAT_WS('|', 'en', p.id, a.id) FROM products p JOIN attributes a ON a.code = 'name'",
        ],
        [
            'name' => 'product_values_url_key',
            'why' => 'the listing keeps only the products that have a url key (packages/Webkul/Product/src/Repositories/ProductRepository.php:551 onward: `whereNotNull(text_value)`); kept per locale',
            'sql' => "INSERT INTO product_attribute_values (channel, locale, text_value, product_id, attribute_id, unique_id) SELECT NULL, 'en', CONCAT('product-', p.id), p.id, a.id, CONCAT_WS('|', 'en', p.id, a.id) FROM products p JOIN attributes a ON a.code = 'url_key'",
        ],
        [
            'name' => 'product_values_visible_individually',
            'why' => 'the listing keeps only the products visible on their own (packages/Webkul/Product/src/Repositories/ProductRepository.php:551 onward: the attribute\'s column equals 1)',
            'sql' => "INSERT INTO product_attribute_values (channel, locale, boolean_value, product_id, attribute_id, unique_id) SELECT NULL, NULL, 1, p.id, a.id, CONCAT_WS('|', p.id, a.id) FROM products p JOIN attributes a ON a.code = 'visible_individually'",
        ],
        [
            'name' => 'product_values_status',
            'why' => 'the listing keeps only enabled products (packages/Webkul/Product/src/Repositories/ProductRepository.php:551 onward); the status is kept per channel (value_per_channel 1 in packages/Webkul/Installer/src/Database/Seeders/Attribute/AttributeTableSeeder.php), under the channel\'s code',
            'sql' => "INSERT INTO product_attribute_values (channel, locale, boolean_value, product_id, attribute_id, unique_id) SELECT 'default', NULL, 1, p.id, a.id, CONCAT_WS('|', 'default', p.id, a.id) FROM products p JOIN attributes a ON a.code = 'status'",
        ],
        [
            'name' => 'product_values_price',
            'why' => 'the price is a float value kept per channel; the card shows it (packages/Webkul/Shop/src/Http/Resources/ProductCardResource.php:9, packages/Webkul/Product/src/Type/AbstractType.php:1053)',
            'sql' => "INSERT INTO product_attribute_values (channel, locale, float_value, product_id, attribute_id, unique_id) SELECT 'default', NULL, 10 + MOD(p.id, 90), p.id, a.id, CONCAT_WS('|', 'default', p.id, a.id) FROM products p JOIN attributes a ON a.code = 'price'",
        ],
        [
            'name' => 'product_price_indices_one_per_group',
            'why' => 'the listing joins the price index of the visitor\'s group (packages/Webkul/Product/src/Repositories/ProductRepository.php:257 onward) and sorts by its minimum price; one row for every product and customer group, in the one channel',
            'sql' => 'INSERT INTO product_price_indices (product_id, customer_group_id, channel_id, min_price, regular_min_price, max_price, regular_max_price, created_at, updated_at) SELECT p.id, g.id, 1, 10 + MOD(p.id, 90), 10 + MOD(p.id, 90), 10 + MOD(p.id, 90), 10 + MOD(p.id, 90), NOW(), NOW() FROM products p CROSS JOIN customer_groups g',
        ],
        [
            'name' => 'product_inventory_indices_one_per_product',
            'why' => 'the card reads the stock of the product in the current channel from this index (packages/Webkul/Product/src/Models/Product.php:122); one row per product and channel',
            'sql' => 'INSERT INTO product_inventory_indices (qty, product_id, channel_id, created_at, updated_at) SELECT 100, p.id, 1, NOW(), NOW() FROM products p',
        ],
        [
            'name' => 'product_inventories_one_per_product',
            'why' => 'the stock of a product at the one inventory source (packages/Webkul/Product/src/Models/ProductInventory.php:13); the vendor is 0 as the app writes it for its own products',
            'sql' => 'INSERT INTO product_inventories (qty, product_id, vendor_id, inventory_source_id) SELECT 100, p.id, 0, 1 FROM products p',
        ],
        [
            'name' => 'products_are_in_the_channel',
            'why' => 'a product belongs to a channel through this link (packages/Webkul/Product/src/Repositories/ProductRepository.php:480 when a channel is given; packages/Webkul/Product/src/Models/Product.php:290)',
            'sql' => 'INSERT INTO product_channels (product_id, channel_id) SELECT p.id, 1 FROM products p',
        ],
        [
            'name' => 'products_are_in_a_category',
            'why' => 'a category page lists the products linked to it (packages/Webkul/Product/src/Repositories/ProductRepository.php:469); each product is put in one category other than the root, round among them',
            'sql' => 'INSERT INTO product_categories (product_id, category_id) SELECT p.id, 2 + MOD(p.id, GREATEST((SELECT COUNT(*) FROM categories) - 1, 1)) FROM products p WHERE (SELECT COUNT(*) FROM categories) > 1',
        ],
    ],
];

$profile['after_generation'] =
    /** @return array{fixups: array<string, int>} */
    static function (PDO $pdo, string $database) use ($profile): array {
        DbGuard::assertDbName($database);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $current = $pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($current !== $database) {
                throw new RuntimeException(sprintf('refused: the connection is on %s, not on the measurement database %s', is_string($current) ? $current : 'no database', $database));
            }
        }
        $summary = ['fixups' => []];
        foreach ($profile['fixups'] as $fix) {
            $summary['fixups'][$fix['name']] = (int) $pdo->exec($fix['sql']);
        }

        return $summary;
    };

return $profile;
