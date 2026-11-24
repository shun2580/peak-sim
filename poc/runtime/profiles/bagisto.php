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
        'attribute_id' => ['value' => 24, 'why' => 'the options route reads the options of the attribute it is given (packages/Webkul/Shop/src/Http/Controllers/API/CategoryController.php:94-104) and answers a fixed list for a boolean one; row 24 is `color`, a select attribute (the fixup `attributes_are_the_defined_ones` puts it there), whose options the generator spreads round the attributes'],
        'slug' => ['value' => 'page-1', 'why' => 'a CMS page is found by its url key in the current locale (packages/Webkul/Shop/src/Http/Controllers/PageController.php:30-33); the fixup `cms_page_translations_are_english_with_a_url_key` makes the key of page n `page-n`'],
    ],

    // The login of the back office is the one administrator row above (`admins` = 1, row 1). The storefront customer is the
    // first generated customer: the profile names no `user`, so the tool logs in as the first row of the customers table.
    'admin' => ['id' => 1],

    // Middleware the app wrote for itself whose names the tool does not know as a login gate. A route that carries one
    // needs a login: a storefront route that carries the customer gate is entered as the customer on the `customer` guard
    // (packages/Webkul/Shop/src/Http/Middleware/AuthenticateCustomer.php), a back-office route that carries the user gate as the
    // administrator on the `admin` guard (packages/Webkul/User/src/Http/Middleware/Bouncer.php). Without them these routes
    // were called without a login and were left unmeasured as `unauthenticated`.
    'auth_gates' => [
        'storefront_customer' => ['middleware' => ['AuthenticateCustomer'], 'guard' => 'customer', 'as' => 'user'],
        'back_office_user' => ['middleware' => ['Bouncer'], 'guard' => 'admin', 'as' => 'admin'],
    ],

    // A GET route whose handler reads rows only when it is given a search term.
    'request_query' => [
        'search_term' => ['routes' => ['uri_prefix' => ['search']], 'query' => ['query' => 'Product'], 'why' => 'the search page looks the term up in the search terms and asks for suggestions only when a `query` is given (packages/Webkul/Shop/src/Http/Controllers/SearchController.php:29-50)'],
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
            'name' => 'reviews_are_approved_reviews_of_the_first_product',
            'why' => 'the reviews route lists the approved reviews of one product, paginated (packages/Webkul/Shop/src/Http/Controllers/API/ReviewController.php:38-44, status `approved` at :23, Product/src/Models/Product.php:156); the generator gives each review its own product and a made-up status, so the first product would have none. All reviews are put on product 1, as the generator puts every chapter of a book in the first book in the BookStack profile',
            'sql' => "UPDATE product_reviews SET product_id = 1, status = 'approved'",
        ],
        [
            'name' => 'related_products_are_of_the_first_product',
            'why' => 'the related-products route reads the products linked to the one it is given (packages/Webkul/Shop/src/Http/Controllers/API/ProductController.php:120-129, Product/src/Models/Product.php:266); the generator links each product to itself, so product 1 would have one. The first product becomes the parent of every link (the child numbers are all different)',
            'sql' => 'UPDATE product_relations SET parent_id = 1',
        ],
        [
            'name' => 'up_sells_are_of_the_first_product',
            'why' => 'the up-sell route reads the products linked to the one it is given (packages/Webkul/Shop/src/Http/Controllers/API/ProductController.php:136-145, Product/src/Models/Product.php:274); same as the related products',
            'sql' => 'UPDATE product_up_sells SET parent_id = 1',
        ],
        [
            'name' => 'customer_addresses_orders_and_wishlist_are_of_the_first_customer',
            'why' => 'the logged-in customer is the first generated customer (the tool logs in as the first row of the customers table when the profile names no user); the address list reads `customer->addresses` (packages/Webkul/Customer/src/Models/Customer.php:179, rows of `addresses` with address_type `customer` and that customer_id: CustomerAddress.php:19,35), the wishlist reads the customer\'s items of the current channel (packages/Webkul/Shop/src/Http/Controllers/API/WishlistController.php:28-37, table `wishlist_items`: Wishlist.php:23), and the order page finds one order by (customer_id, id) (packages/Webkul/Shop/src/Http/Controllers/Customer/Account/OrderController.php:48-53). The generator gives each row its own customer and made-up words; all are put on customer 1 and the channel, as the reviews are put on the first product above',
            'sql' => 'UPDATE wishlist_items SET customer_id = 1, channel_id = 1',
        ],
        [
            'name' => 'orders_are_of_the_first_customer',
            'why' => 'see `customer_addresses_orders_and_wishlist_are_of_the_first_customer`: the order page answers 404 unless the order of that number belongs to the logged-in customer (OrderController.php:48-53)',
            'sql' => 'UPDATE orders SET customer_id = 1',
        ],
        [
            'name' => 'order_items_and_payment_are_of_the_first_order',
            'why' => 'the order page reads the top-level items of the order (Sales/src/Models/Order.php:169-173, parent_id null) and its payment (:250-253, shown at the method line of the view); the generator links each item to its own order and to a made-up parent. All items and payments are put on order 1 and the items made top-level',
            'sql' => 'UPDATE order_items SET order_id = 1, parent_id = NULL',
        ],
        [
            'name' => 'order_payment_is_of_the_first_order',
            'why' => 'the order page shows the payment method of the order it reads (packages/Webkul/Sales/src/Models/Order.php:250-253, `hasOne` of order_payment); the generator links each payment to its own order, so order 1 would have the one made for it. All are put on order 1, as the items are in the fixup above',
            'sql' => 'UPDATE order_payment SET order_id = 1',
        ],
        [
            'name' => 'addresses_are_the_first_customers_and_the_first_orders',
            'why' => 'one table (`addresses`) holds the customer addresses (address_type `customer`, Customer/src/Models/CustomerAddress.php:19), and the billing and shipping addresses of an order (`order_billing`, `order_shipping`, Sales/src/Models/OrderAddress.php:28,33) that the order page reads through the order (Order.php:242-245,258-280). The generated rows are shared out by number in three: customer addresses of customer 1, billing and shipping addresses of order 1; the cart and the other order links are cleared',
            'sql' => "UPDATE addresses SET cart_id = NULL, customer_id = IF(id % 3 = 0, 1, NULL), order_id = IF(id % 3 = 0, NULL, 1), address_type = CASE id % 3 WHEN 0 THEN 'customer' WHEN 1 THEN 'order_billing' ELSE 'order_shipping' END",
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

// Routes whose request rewrites, in one call, a part of a table that grows with the count of rows, and that other routes of the route
// set read. Each request is rolled back, so the rows return and the optimizer's statistics of the table do not; a route measured after one
// of these reads the table in another state. They are measured after every other route of their database, in this order. The criterion is
// the one of `profiles/bookstack.php`: a write whose row count grows with N (the data above puts every wishlist item, order, order item and
// a third of the addresses on customer 1 and order 1, and every return-request status on one `default` value). The 120 routes were read
// one by one, with the file and line of each, in docs/research/bagisto-prep/state-changing.md. The list was set from the code and the
// data layout (and, for the one delete the capture of 30,000 rows recorded, from that record), before any measurement at the count the
// test is run at. A route that could write many rows depending on a
// body or a state the tool may not reach is listed (measured last costs nothing) and says so.
//   Order of the list: a route that deletes or nulls rows other listed routes read goes after them. The cascade of the customer deletion
//   removes `addresses` and `wishlist_items` rows and nulls `orders.customer_id`, so it is last; the order cancellation reads and
//   rewrites the order's items, so it goes before it.
$profile['state_changing'] = [
    [
        'route' => 'POST /admin/sales/rma/rma-status/mass-delete',
        'tables' => ['rma_statuses'],
        'why' => 'deletes with `where default = 0` and no condition on the id (packages/Webkul/Admin/src/Http/Controllers/Sales/RMA/StatusController.php:146); the capture of 30,000 rows recorded `delete from rma_statuses where default = ?` with 30,003 rows deleted. The return-request list of the storefront joins `rma_statuses` (packages/Webkul/Shop/src/DataGrids/RMA/RMADataGrid.php:33)',
    ],
    [
        'route' => 'POST /api/customer/addresses',
        'tables' => ['addresses'],
        'why' => 'when the body carries `default_address` it updates every default address of the customer (packages/Webkul/Shop/src/Http/Controllers/API/AddressController.php:81-85); the data puts a third of `addresses` on customer 1 and half of them have the flag (poc/runtime/src/Data/ValueFactory.php:160-161). The form request does not list `default_address` (packages/Webkul/Shop/src/Http/Requests/Customer/AddressRequest.php:31-50), so the tool probably does not send it; listed because it is not certain and measuring it last costs nothing',
    ],
    [
        'route' => 'POST /customer/account/addresses/create',
        'tables' => ['addresses'],
        'why' => 'the same statement as the API route above, on the same condition (packages/Webkul/Shop/src/Http/Controllers/Customer/Account/AddressController.php:70-74); listed on the same doubt',
    ],
    [
        'route' => 'POST /customer/account/orders/cancel/{id}',
        'tables' => ['order_items', 'product_inventory_indices'],
        'why' => 'cancels the order: for every item of the order it saves the item (packages/Webkul/Sales/src/Repositories/OrderRepository.php:153-185), and order 1 holds every generated item; after the cancel the product listener re-indexes the inventory of every product of the order (packages/Webkul/Product/src/Listeners/Order.php:23-35, queue `sync`: .env.example:44), which the product listings read. It writes only if an item has a quantity to cancel and the order is not closed or fraud (Sales/src/Models/Order.php:375-390), which the generated words may not satisfy; listed on that doubt',
    ],
    [
        'route' => 'POST /customer/account/profile/destroy',
        'tables' => ['wishlist_items', 'addresses', 'orders'],
        'why' => 'deletes the customer (packages/Webkul/Shop/src/Http/Controllers/Customer/CustomerController.php:167); the foreign keys cascade to `wishlist_items` (Customer/src/Database/Migrations/2023_05_26_213105_create_wishlist_items_table.php:26) and `addresses` (Core/src/Database/Migrations/2020_04_16_185147_add_table_addresses.php:39), and set `orders.customer_id` to null (Sales/src/Database/Migrations/2018_09_27_113154_create_orders_table.php:76); the data puts every wishlist item and order and a third of the addresses on customer 1. It deletes only when the password matches (CustomerController.php:160), which the tool cannot supply; listed because its effect would be the largest of the set, and it is last',
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
