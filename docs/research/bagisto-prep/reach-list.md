# Routes the data is to be made to reach (Bagisto)

Fixed **before** the profile is changed for them, and not changed after the capture is taken again. The rule: a route that returns rows
of a table, so that what it returns grows with the number of rows. Left out: payment-gateway return routes, forms (login, register,
contact), pages that show an empty state (cart, compare, checkout steps), routes that answer with a redirect or a file, and pages whose rows
come only from an ajax datagrid call (the tool sends no ajax header, so the page is a shell). Each entry says where in Bagisto `v2.5.2` the rows are read.
The list is made from reading the code of the routes the preparation capture reported as "data may not have arrived", plus the customer-area routes that read
rows of the logged-in customer. The admin routes are decided later, after the admin sample is fixed.

| Route | Rows it returns | Where |
| --- | --- | --- |
| `GET /search` | the suggestions and search terms for the `query` given (a term has to be sent) | `packages/Webkul/Shop/src/Http/Controllers/SearchController.php:29` |
| `GET /page/{slug}` | the CMS page found by its url key | `packages/Webkul/Shop/src/Http/Controllers/PageController.php:27` |
| `GET /api/products/{id}/related` | the products related to a product | `packages/Webkul/Shop/src/Http/Controllers/API/ProductController.php:120` |
| `GET /api/products/{id}/up-sell` | the up-sell products of a product | `packages/Webkul/Shop/src/Http/Controllers/API/ProductController.php:136` |
| `GET /api/product/{id}/reviews` | the reviews of a product | `packages/Webkul/Shop/src/Http/Controllers/API/ReviewController.php:38` |
| `GET /api/categories/attributes/{attribute_id}/options` | the options of a filterable attribute | `packages/Webkul/Shop/src/Http/Controllers/API/CategoryController.php:94` |
| `GET /api/categories/max-price/{id?}` | the highest price among the products of a category | `packages/Webkul/Shop/src/Http/Controllers/API/CategoryController.php:124` |
| `GET /api/customer/addresses` | the addresses of the logged-in customer | `packages/Webkul/Shop/src/Http/Controllers/API/AddressController.php:47` |
| `GET /api/customer/wishlist` | the wishlist of the logged-in customer | `packages/Webkul/Shop/src/Http/Controllers/API/WishlistController.php:28` |
| `GET /customer/account/orders/view/{id}` | one order of the logged-in customer with its items | `packages/Webkul/Shop/src/Http/Controllers/Customer/Account/OrderController.php:48` |

`GET /customer/account/orders`, `/customer/account/addresses` and `/customer/account/wishlist` are not in the list: their rows are read by an ajax call or by the `/api/customer/*` routes above.
