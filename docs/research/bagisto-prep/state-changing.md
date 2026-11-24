# Bagisto: the routes that rewrite a table other routes read

The profile of Bagisto (`poc/runtime/profiles/bagisto.php`) names, in `state_changing`, the routes of the measured route set
(`route-set-measure.txt`, 120 routes) whose request rewrites a part of a table that grows with the count of rows. The measurement
runs every request inside a transaction that is rolled back, so the rows come back, but the optimizer's statistics of the rewritten
table do not; a route measured after such a request reads the table in another state. The tool measures the listed routes after every
other route of the same database, in the order of the list (`RouteOrder`, `poc/runtime/src/Measure/RouteOrder.php`). This file is the
reading that decided the list: one line for each of the 120 routes. It is not a result of any measurement at the count the test is run at.

## The rule used

The rule is the one already used for BookStack (`profiles/bookstack.php`, decision D224 in `docs/decisions.md`): list a route when one
request writes a number of rows that grows with N, in a table that another route of the set reads. A write by primary key, a write of
the few rows of one cart, one customer address or one request, and a write the body decides, are not N-dependent and are not listed.
When a route could write many rows depending on a body, a password or a state that the tool may not reach, it is **listed** (measured
last costs nothing) and the doubt is written next to it.

## What the data layout does to the rule

The generator fills every table to N rows and links row j to row j of the parent. The profile then moves some rows (the fixups in
`bagisto.php`): every wishlist item, every order and a third of the addresses to customer 1; every order item and order payment to
order 1; every review to product 1. So a statement that works "for customer 1" or "for order 1" touches N (or N/3) rows here, where it
would touch a few in a real shop. `compare_items`, `cart_items` and the like keep one row for each customer or cart. Boolean columns
take `i % 2` unless their name hides a row (`poc/runtime/src/Data/ValueFactory.php:160-161`); `rma_statuses.default` is 0 in every row.

## Result: 5 routes, in the order they are measured

| # | Route | Tables | Why in one line |
|---|---|---|---|
| 1 | `POST /admin/sales/rma/rma-status/mass-delete` | `rma_statuses` | the delete has no condition on the id: 30,003 rows in the capture of 30,000 rows |
| 2 | `POST /api/customer/addresses` | `addresses` | with `default_address` in the body, updates every default address of customer 1 (about N/6 rows). Doubt: the form request does not list the field |
| 3 | `POST /customer/account/addresses/create` | `addresses` | same statement, same doubt |
| 4 | `POST /customer/account/orders/cancel/{id}` | `order_items`, `product_inventory_indices` | saves every item of order 1, then re-indexes the inventory of every product of the order (queue `sync`). Doubt: needs a quantity to cancel |
| 5 | `POST /customer/account/profile/destroy` | `wishlist_items`, `addresses`, `orders` | the customer delete cascades to N wishlist items and N/3 addresses and nulls N orders. Doubt: needs a matching password |

The order: the rows that delete or null rows other listed routes read go later. The customer deletion (5) removes `addresses` and
`wishlist_items` rows and nulls `orders.customer_id`, so it is last; the cancellation (4) reads and rewrites the order's items, so it
is before it; routes 2 and 3 rewrite `addresses`, which 5 deletes from, so they are before it. The delete of statuses (1) touches a table
no other listed route reads.

## What was read, and the limits

- For each of the 120 routes, the controller method that the route table names (`static.json` of the preparation capture) was read in
  full, and for each route that writes, the repository, service, model and listener it calls were followed to the statements that write.
  Handlers that only read were not followed through every callee (`Cart::getCart`, data grids, views); for the measured ones the
  statements of the capture of 30,000 rows back this: 57 routes were measured at that size and the only statements that were not
  `select` were the 8 in the table below (the mass delete above and 7 small writes of 1 or 2 rows).
- Events: the listeners of the events these routes fire were searched (`EventServiceProvider.php` of Shop, Admin, Product, FPC,
  Marketing, CatalogRule, Omnibus). The only listener that rewrites many rows is the product inventory re-index on an order
  cancellation (`Product/src/Providers/EventServiceProvider.php:58-60`, `Product/src/Listeners/Order.php:23-35`, with the queue set to
  `sync` in `.env.example:44`). The channel update listener (`FPC`) clears a cache and writes no table.
- Not followed: the mail that some routes queue (queue `sync` sends it in the request, and writes no table of the set).
- The doubts above (rows 2-5) are the places where the code can write N rows but the tool's own request may not reach the statement. They
  are listed on purpose. Listing a route that does not write only moves it to the end of its database.

Statements the capture of 30,000 rows recorded that were not `select`:

| Route | Statement | Rows |
|---|---|---|
| `POST /admin/sales/rma/rma-status/mass-delete` | `delete from rma_statuses where default = ?` | 30,003 |
| `POST /admin/marketing/search-seo/search-synonyms/create` | `insert into search_synonyms` | 1 |
| `DELETE /admin/marketing/search-seo/sitemaps/edit/{id}` | `delete from sitemaps where id = ?` | 1 |
| `DELETE /customer/logout` | `update customers set remember_token = ? where id = ?` | 1 |
| `DELETE /admin/sales/rma/rules/delete/{id}` | `delete from rma_rules where id = ?` | 1 |
| `POST /api/customer/wishlist` | `delete from wishlist_items where product_id = ? and customer_id = ?` | 2 |
| `POST /admin/sales/eu-withdrawals/{id}/resend-confirmation` | `update eu_withdrawals set ... where id = ?` | 1 |
| `POST /customer/account/rma/cancel/{id}` | `update rma set rma_status_id = ? where id = ?` | 1 |

## The 120 routes

Paths are in the Bagisto clone at tag `v2.5.2`; `file:a-b` is the line range of the controller method.

| # | Route | Method read | What a request writes | Rows | Decision |
|---|---|---|---|---|---|
| 1 | `GET /` | `packages/Webkul/Shop/src/Http/Controllers/HomeController.php:39-51` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 2 | `GET /api/categories` | `packages/Webkul/Shop/src/Http/Controllers/API/CategoryController.php:35-59` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 3 | `GET /api/categories/attributes` | `packages/Webkul/Shop/src/Http/Controllers/API/CategoryController.php:74-89` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 4 | `GET /api/categories/attributes/{attribute_id}/options` | `packages/Webkul/Shop/src/Http/Controllers/API/CategoryController.php:94-119` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 5 | `GET /api/categories/tree` | `packages/Webkul/Shop/src/Http/Controllers/API/CategoryController.php:64-69` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 6 | `DELETE /api/checkout/cart` | `packages/Webkul/Shop/src/Http/Controllers/API/CartController.php:105-119` | removes one cart item by id, then recomputes the totals of the one cart | a few rows of `cart_items`/`cart` | not listed |
| 7 | `PUT /api/checkout/cart` | `packages/Webkul/Shop/src/Http/Controllers/API/CartController.php:176-197` | updates the quantities of the items of the one cart | rows of one cart (the generator gives each cart its own item) | not listed |
| 8 | `POST /api/checkout/cart/coupon` | `packages/Webkul/Shop/src/Http/Controllers/API/CartController.php:251-297` | sets the coupon code on the one cart; reads one coupon by code | 1 `cart` row | not listed |
| 9 | `POST /api/checkout/cart/move-to-wishlist` | `packages/Webkul/Shop/src/Http/Controllers/API/CartController.php:146-171` | one wishlist insert and one cart item delete for each id in the body | rows = ids in the body | not listed |
| 10 | `POST /api/checkout/onepage/addresses` | `packages/Webkul/Shop/src/Http/Controllers/API/OnepageController.php:42-79` | saves the billing and shipping address of the one cart, recomputes totals | 2 `addresses` rows (cart_id), 1 `cart` row | not listed |
| 11 | `POST /api/checkout/onepage/orders` | `packages/Webkul/Shop/src/Http/Controllers/API/OnepageController.php:141-208` | creates the order of the one cart (the cart holds the items of its own generated row) | 1 order and the items of one cart | not listed |
| 12 | `POST /api/checkout/onepage/payment-methods` | `packages/Webkul/Shop/src/Http/Controllers/API/OnepageController.php:112-136` | saves the payment method on the one cart | 1-2 rows | not listed |
| 13 | `POST /api/checkout/onepage/shipping-methods` | `packages/Webkul/Shop/src/Http/Controllers/API/OnepageController.php:86-105` | saves the shipping method on the one cart | 1-2 rows | not listed |
| 14 | `DELETE /api/compare-items` | `packages/Webkul/Shop/src/Http/Controllers/API/CompareController.php:91-133` | deletes the compare item of (customer, product) | 1 row | not listed |
| 15 | `POST /api/compare-items` | `packages/Webkul/Shop/src/Http/Controllers/API/CompareController.php:53-86` | inserts one compare item | 1 row | not listed |
| 16 | `DELETE /api/compare-items/all` | `packages/Webkul/Shop/src/Http/Controllers/API/CompareController.php:138-161` | deletes the compare items of the customer; the generator links each compare item to its own customer, so customer 1 has about one | about 1 row (assumes one row per customer; the data layout above does not move `compare_items`) | not listed |
| 17 | `GET /api/core/countries` | `packages/Webkul/Shop/src/Http/Controllers/API/CoreController.php:14-23` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 18 | `GET /api/customer/addresses` | `packages/Webkul/Shop/src/Http/Controllers/API/AddressController.php:47-52` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 19 | `POST /api/customer/addresses` | `packages/Webkul/Shop/src/Http/Controllers/API/AddressController.php:57-95` | inserts one address; with `default_address` in the body, first updates every default address of the customer | 1 row; with the flag about N/6 rows of `addresses` | listed (measured last) (doubt) |
| 20 | `GET /api/customer/addresses/allowed-countries` | `packages/Webkul/Shop/src/Http/Controllers/API/AddressController.php:27-34` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 21 | `GET /api/customer/addresses/allowed-states` | `packages/Webkul/Shop/src/Http/Controllers/API/AddressController.php:39-42` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 22 | `GET /api/customer/wishlist` | `packages/Webkul/Shop/src/Http/Controllers/API/WishlistController.php:28-40` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 23 | `POST /api/customer/wishlist` | `packages/Webkul/Shop/src/Http/Controllers/API/WishlistController.php:45-87` | toggles one wishlist item: an insert, or a delete of the item of (product, customer) | 1-2 rows (the capture of 30,000 rows recorded a delete of 2) | not listed |
| 24 | `DELETE /api/customer/wishlist/{id}` | `packages/Webkul/Shop/src/Http/Controllers/API/WishlistController.php:153-181` | deletes one wishlist item by id and customer | 1 row | not listed |
| 25 | `GET /api/product/{id}/reviews` | `packages/Webkul/Shop/src/Http/Controllers/API/ReviewController.php:38-55` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 26 | `GET /api/product/{id}/reviews/{review_id}/translate` | `packages/Webkul/Shop/src/Http/Controllers/API/ReviewController.php:109-137` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 27 | `GET /api/products` | `packages/Webkul/Shop/src/Http/Controllers/API/ProductController.php:30-70` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 28 | `GET /api/products/{id}/related` | `packages/Webkul/Shop/src/Http/Controllers/API/ProductController.php:120-129` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 29 | `GET /api/products/{id}/up-sell` | `packages/Webkul/Shop/src/Http/Controllers/API/ProductController.php:136-145` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 30 | `GET /appearance-preview` | `packages/Webkul/Shop/src/Http/Controllers/HomeController.php:59-100` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 31 | `GET /booking-slots/{id}` | `packages/Webkul/Shop/src/Http/Controllers/BookingProductController.php:42-49` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 32 | `GET /cache/{template}/{filename}` | `packages/Webkul/ImageCache/src/Http/Controllers/ImageCacheController.php:76-92` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 33 | `GET /customer/account` | `packages/Webkul/Shop/src/Http/Controllers/Customer/CustomerController.php:203-206` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 34 | `GET /customer/account/addresses/create` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/AddressController.php:36-39` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 35 | `POST /customer/account/addresses/create` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/AddressController.php:46-83` | the same as the API route: insert, and with `default_address` an update of every default address of the customer | 1 row; with the flag about N/6 rows of `addresses` | listed (measured last) (doubt) |
| 36 | `DELETE /customer/account/addresses/delete/{id}` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/AddressController.php:179-199` | deletes one address by id and customer | 1 row | not listed |
| 37 | `PATCH /customer/account/addresses/edit/{id}` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/AddressController.php:153-172` | clears the first default address and sets one | at most 2 rows (`first()` and `find($id)`) | not listed |
| 38 | `PUT /customer/account/addresses/edit/{id}` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/AddressController.php:109-145` | updates one address | 1 row | not listed |
| 39 | `GET /customer/account/downloadable-products` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/DownloadableProductController.php:30-37` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 40 | `GET /customer/account/gdpr` | `packages/Webkul/Shop/src/Http/Controllers/Customer/GDPRController.php:30-37` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 41 | `POST /customer/account/gdpr` | `packages/Webkul/Shop/src/Http/Controllers/Customer/GDPRController.php:42-74` | inserts one request | 1 row | not listed |
| 42 | `GET /customer/account/gdpr/pdf-view` | `packages/Webkul/Shop/src/Http/Controllers/Customer/GDPRController.php:79-110` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 43 | `POST /customer/account/gdpr/revoke/{id}` | `packages/Webkul/Shop/src/Http/Controllers/Customer/GDPRController.php:156-186` | updates one request by id and customer | 1 row | not listed |
| 44 | `POST /customer/account/orders/cancel/{id}` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/OrderController.php:124-142` | for every item of the order: saves the item, returns the quantity to the inventory; then re-indexes the inventory of every product of the order (queue `sync`) | order 1 holds every generated item: about N rows of `order_items`, and up to N rows of `product_inventory_indices` | listed (measured last) (doubt: needs a quantity to cancel) |
| 45 | `GET /customer/account/orders/view/{id}` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/OrderController.php:48-58` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 46 | `GET /customer/account/orders/{orderId}/withdraw` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/EUWithdrawalController.php:30-45` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 47 | `POST /customer/account/orders/{orderId}/withdraw` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/EUWithdrawalController.php:52-65` | inserts one withdrawal (the confirmation listener sends mail only) | 1 row | not listed |
| 48 | `POST /customer/account/profile/destroy` | `packages/Webkul/Shop/src/Http/Controllers/Customer/CustomerController.php:151-182` | deletes the customer after a password check; the foreign keys cascade or null | about N `wishlist_items`, N/3 `addresses`, N `orders` (customer 1 owns them) | listed (measured last) (doubt: needs the password) |
| 49 | `GET /customer/account/profile/edit` | `packages/Webkul/Shop/src/Http/Controllers/Customer/CustomerController.php:47-52` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 50 | `POST /customer/account/profile/edit` | `packages/Webkul/Shop/src/Http/Controllers/Customer/CustomerController.php:59-144` | updates the customer | 1 row | not listed |
| 51 | `GET /customer/account/reviews` | `packages/Webkul/Shop/src/Http/Controllers/Customer/CustomerController.php:189-196` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 52 | `GET /customer/account/rma` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/RMAController.php:77-84` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 53 | `POST /customer/account/rma/cancel/{id}` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/RMAController.php:370-401` | updates one return request by id (the capture recorded one update) | 1 row | not listed |
| 54 | `GET /customer/account/rma/get-messages` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/RMAController.php:434-452` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 55 | `GET /customer/account/rma/get-order-items/{orderId}` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/RMAController.php:251-273` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 56 | `GET /customer/account/rma/images/{id}` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/RMAController.php:406-415` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 57 | `POST /customer/account/rma/reopen/{id}` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/RMAController.php:327-365` | updates one return request and inserts one message | 2 rows | not listed |
| 58 | `POST /customer/account/rma/store` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/RMAController.php:130-246` | inserts one return request, its items and images from the body | rows = items in the body | not listed |
| 59 | `GET /customer/account/wishlist` | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/WishlistController.php:15-22` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 60 | `GET /customer/account/your-cookie-consent-preferences` | `packages/Webkul/Shop/src/Http/Controllers/Customer/GDPRController.php:148-151` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 61 | `GET /customer/datagrid/look-up` | `packages/Webkul/Shop/src/Http/Controllers/DataGridController.php:12-41` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 62 | `DELETE /customer/logout` | `packages/Webkul/Shop/src/Http/Controllers/Customer/SessionController.php:118-131` | updates `remember_token` of the customer (the capture recorded one update) | 1 row | not listed |
| 63 | `GET /customer/resend/verification/{email}` | `packages/Webkul/Shop/src/Http/Controllers/Customer/RegistrationController.php:145-181` | updates the token of the customer found by email | 1 row | not listed |
| 64 | `GET /customer/social-login/{provider}` | `packages/Webkul/SocialLogin/src/Http/Controllers/LoginController.php:41-52` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 65 | `GET /customer/social-login/{provider}/callback` | `packages/Webkul/SocialLogin/src/Http/Controllers/LoginController.php:60-93` | finds or creates one customer and social account | 1-2 rows | not listed |
| 66 | `GET /customer/verify-account/{token}` | `packages/Webkul/Shop/src/Http/Controllers/Customer/RegistrationController.php:115-137` | updates the customer found by token | 1 row | not listed |
| 67 | `GET /downloadable/download-sample/{type}/{id}` | `packages/Webkul/Shop/src/Http/Controllers/ProductController.php:58-115` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 68 | `POST /paypal/standard/ipn` | `packages/Webkul/Paypal/src/Http/Controllers/StandardController.php:97-108` | posts back to the payment provider first and stops when it is not verified; no write before that | 0 rows | not listed |
| 69 | `GET /payu/redirect` | `packages/Webkul/PayU/src/Http/Controllers/PayUController.php:43-80` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 70 | `GET /phonepe/redirect` | `packages/Webkul/PhonePe/src/Http/Controllers/PhonePeController.php:39-79` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 71 | `GET /razorpay/payment/redirect` | `packages/Webkul/Razorpay/src/Http/Controllers/RazorpayController.php:43-86` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 72 | `GET /search` | `packages/Webkul/Shop/src/Http/Controllers/SearchController.php:29-69` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 73 | `POST /search/upload` | `packages/Webkul/Shop/src/Http/Controllers/SearchController.php:75-106` | stores one uploaded image on the disk | no table | not listed |
| 74 | `GET /sitemap.xml` | `packages/Webkul/Shop/src/Http/Controllers/SitemapController.php:26-53` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 75 | `GET /stripe/redirect` | `packages/Webkul/Stripe/src/Http/Controllers/StripeController.php:44-73` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 76 | `GET /subscription/{token}` | `packages/Webkul/Shop/src/Http/Controllers/SubscriptionController.php:58-65` | deletes the subscriber with the token | at most 1 row (`token` is unique per generated row) | not listed |
| 77 | `GET /webmcp/product` | `packages/Webkul/Shop/src/Http/Controllers/WebMcpController.php:26-35` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 78 | `GET /withdraw` | `packages/Webkul/Shop/src/Http/Controllers/EUWithdrawalController.php:34-42` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 79 | `POST /withdraw/lookup` | `packages/Webkul/Shop/src/Http/Controllers/EUWithdrawalController.php:50-91` | no write; queues a mail | 0 rows | not listed |
| 80 | `POST /withdraw/{orderId}/store` | `packages/Webkul/Shop/src/Http/Controllers/EUWithdrawalController.php:127-144` | inserts one withdrawal | 1 row | not listed |
| 81 | `POST /admin/appearance/themes/{code}/sections` | `packages/Webkul/Admin/src/Http/Controllers/Appearance/SectionController.php:87-120` | inserts one theme section | 1 row | not listed |
| 82 | `GET /admin/catalog/products/edit/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Catalog/ProductController.php:140-145` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 83 | `GET /admin/catalog/products/search` | `packages/Webkul/Admin/src/Http/Controllers/Catalog/ProductController.php:327-359` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 84 | `POST /admin/cms/create` | `packages/Webkul/Admin/src/Http/Controllers/CMS/PageController.php:53-83` | inserts one page, its translation and channel link | 3 rows | not listed |
| 85 | `GET /admin/configuration/{slug?}/{slug2?}` | `packages/Webkul/Admin/src/Http/Controllers/ConfigurationController.php:23-35` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 86 | `POST /admin/customers/addresses/default/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Customers/AddressController.php:129-148` | clears one default address of the customer and sets one | at most 2 rows (`findOneWhere`, then a model update) | not listed |
| 87 | `GET /admin/customers/gdpr/edit/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Customers/GDPRController.php:42-49` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 88 | `DELETE /admin/customers/groups/delete/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Customers/CustomerGroupController.php:93-125` | answers 400 before any write: the fixup makes every group a default one (`is_user_defined` 0), and a group with customers is refused | 0 rows | not listed |
| 89 | `GET /admin/customers/login-as-customer/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Customers/CustomerController.php:208-217` | logs in on the session only | 0 rows | not listed |
| 90 | `GET /admin/customers/search` | `packages/Webkul/Admin/src/Http/Controllers/Customers/CustomerController.php:275-289` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 91 | `GET /admin/customers/view/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Customers/CustomerController.php:248-268` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 92 | `GET /admin/customers/{id}/addresses` | `packages/Webkul/Admin/src/Http/Controllers/Customers/AddressController.php:30-35` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 93 | `GET /admin/marketing/communications/campaigns` | `packages/Webkul/Admin/src/Http/Controllers/Marketing/Communications/CampaignController.php:31-38` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 94 | `GET /admin/marketing/communications/campaigns/edit/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Marketing/Communications/CampaignController.php:85-92` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 95 | `POST /admin/marketing/communications/events/create` | `packages/Webkul/Admin/src/Http/Controllers/Marketing/Communications/EventController.php:40-61` | inserts one event | 1 row | not listed |
| 96 | `GET /admin/marketing/promotions/cart-rules/edit/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Marketing/Promotions/CartRuleController.php:110-115` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 97 | `POST /admin/marketing/search-seo/search-synonyms/create` | `packages/Webkul/Admin/src/Http/Controllers/Marketing/SearchSEO/SearchSynonymController.php:40-59` | inserts one synonym (the capture recorded one insert) | 1 row | not listed |
| 98 | `DELETE /admin/marketing/search-seo/sitemaps/edit/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Marketing/SearchSEO/SitemapController.php:112-133` | deletes one sitemap by id and its files (the capture recorded one delete) | 1 row | not listed |
| 99 | `GET /admin/reporting/sales/view` | `packages/Webkul/Admin/src/Http/Controllers/Reporting/SaleController.php:45-56` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 100 | `POST /admin/sales/cart/{id}/shipping-methods` | `packages/Webkul/Admin/src/Http/Controllers/Sales/CartController.php:204-227` | saves the shipping method on one cart | 1-2 rows | not listed |
| 101 | `POST /admin/sales/eu-withdrawals/{id}/resend-confirmation` | `packages/Webkul/Admin/src/Http/Controllers/Sales/EUWithdrawalController.php:120-154` | updates one withdrawal (the capture recorded one update) | 1 row | not listed |
| 102 | `POST /admin/sales/invoices/create/{order_id}` | `packages/Webkul/Admin/src/Http/Controllers/Sales/InvoiceController.php:71-117` | inserts one invoice and the invoice items the body lists; reads all items of the order to total it, writes only those listed | rows = items in the body | not listed |
| 103 | `GET /admin/sales/invoices/print/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Sales/InvoiceController.php:159-169` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 104 | `GET /admin/sales/orders/view/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Sales/OrderController.php:125-130` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 105 | `POST /admin/sales/rma/custom-fields/update/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Sales/RMA/CustomFieldController.php:103-149` | updates one custom field and its options | a few rows | not listed |
| 106 | `GET /admin/sales/rma/reasons/edit/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Sales/RMA/ReasonController.php:72-79` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 107 | `POST /admin/sales/rma/reasons/mass-update` | `packages/Webkul/Admin/src/Http/Controllers/Sales/RMA/ReasonController.php:146-163` | updates one reason for each id in the body | rows = ids in the body | not listed |
| 108 | `PUT /admin/sales/rma/reasons/update/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Sales/RMA/ReasonController.php:84-119` | updates one reason and the resolutions of that reason | a few rows (each reason has about one resolution row) | not listed |
| 109 | `POST /admin/sales/rma/rma-status/mass-delete` | `packages/Webkul/Admin/src/Http/Controllers/Sales/RMA/StatusController.php:138-159` | `where default = 0` then `delete($id)`: the id is not in the statement; the capture of 30,000 rows recorded `delete from rma_statuses where default = ?` with 30,003 rows | about N rows of `rma_statuses` (every generated row has `default` 0) | listed (measured last) |
| 110 | `DELETE /admin/sales/rma/rules/delete/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Sales/RMA/RulesController.php:104-121` | deletes one rule by id (the capture recorded one delete) | 1 row | not listed |
| 111 | `GET /admin/sales/shipments/view/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Sales/ShipmentController.php:165-170` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 112 | `PUT /admin/settings/channels/edit/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Settings/ChannelController.php:123-182` | updates the channel and syncs its locale, currency and inventory-source links | rows of tables pinned to 1 row; the listener on the update only clears the page cache | not listed |
| 113 | `GET /admin/settings/currencies` | `packages/Webkul/Admin/src/Http/Controllers/Settings/CurrencyController.php:29-38` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 114 | `PUT /admin/settings/currencies/edit` | `packages/Webkul/Admin/src/Http/Controllers/Settings/CurrencyController.php:83-108` | updates one currency | 1 row | not listed |
| 115 | `POST /admin/settings/exchange-rates/create` | `packages/Webkul/Admin/src/Http/Controllers/Settings/ExchangeRateController.php:46-67` | inserts one exchange rate | 1 row | not listed |
| 116 | `GET /admin/settings/exchange-rates/edit/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Settings/ExchangeRateController.php:72-88` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 117 | `GET /admin/settings/inventory-sources/edit/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Settings/InventorySourceController.php:87-92` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 118 | `DELETE /admin/settings/locales/edit/{id}` | `packages/Webkul/Admin/src/Http/Controllers/Settings/LocaleController.php:107-128` | answers 400 before any write: the shop has one locale | 0 rows | not listed |
| 119 | `GET /admin/settings/roles` | `packages/Webkul/Admin/src/Http/Controllers/Settings/RoleController.php:32-39` | reads only: no insert, update or delete in the handler (the calls it makes were not all followed; see the limits above) | 0 rows | not listed |
| 120 | `PUT /admin/settings/users/edit` | `packages/Webkul/Admin/src/Http/Controllers/Settings/UserController.php:108-157` | updates one admin | 1 row | not listed |
