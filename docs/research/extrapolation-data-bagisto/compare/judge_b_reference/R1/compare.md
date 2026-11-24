# Comparison at N = 240,000

Rule B decides; rule A is shown beside it. Lowest standard: queries +-5%, rows examined +-20%. Goal: queries +-2%, rows examined +-10%. Peak memory +-30% (reported only).

## bagisto

**Rule B (decides): pass**

- queries: 54 of 56 inside +-5% (enough), needs 80%
- rows_examined: 52 of 56 inside +-20% (enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 56 | 54 | 96.43% | 54 | 96.43% | 1 | 6 |
| all | rows_examined | 56 | 52 | 92.86% | 51 | 91.07% | 1 | 6 |
| all | peak_memory | 22 | 22 | 100.00% | 22 | 100.00% | 1 | 40 |
| unmarked | queries | 22 | 21 | 95.45% | 21 | 95.45% | 0 | 0 |
| unmarked | rows_examined | 22 | 22 | 100.00% | 22 | 100.00% | 0 | 0 |
| unmarked | peak_memory | 4 | 4 | 100.00% | 4 | 100.00% | 0 | 18 |
| marked | queries | 34 | 33 | 97.06% | 33 | 97.06% | 1 | 6 |
| marked | rows_examined | 34 | 30 | 88.24% | 29 | 85.29% | 1 | 6 |
| marked | peak_memory | 18 | 18 | 100.00% | 18 | 100.00% | 1 | 22 |

**Rule A (shown, does not decide): pass**

- queries: 54 of 56 inside +-5% (enough), needs 80%
- rows_examined: 52 of 56 inside +-20% (enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 56 | 54 | 96.43% | 54 | 96.43% | 1 | 6 |
| all | rows_examined | 56 | 52 | 92.86% | 51 | 91.07% | 1 | 6 |
| all | peak_memory | 22 | 22 | 100.00% | 22 | 100.00% | 1 | 40 |
| unmarked | queries | 22 | 21 | 95.45% | 21 | 95.45% | 0 | 0 |
| unmarked | rows_examined | 22 | 22 | 100.00% | 22 | 100.00% | 0 | 0 |
| unmarked | peak_memory | 4 | 4 | 100.00% | 4 | 100.00% | 0 | 18 |
| marked | queries | 34 | 33 | 97.06% | 33 | 97.06% | 1 | 6 |
| marked | rows_examined | 34 | 30 | 88.24% | 29 | 85.29% | 1 | 6 |
| marked | peak_memory | 18 | 18 | 100.00% | 18 | 100.00% | 1 | 22 |

## both apps

**Rule B (decides): pass**

- queries: 54 of 56 inside +-5% (enough), needs 80%
- rows_examined: 52 of 56 inside +-20% (enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 56 | 54 | 96.43% | 54 | 96.43% | 1 | 6 |
| all | rows_examined | 56 | 52 | 92.86% | 51 | 91.07% | 1 | 6 |
| all | peak_memory | 22 | 22 | 100.00% | 22 | 100.00% | 1 | 40 |
| unmarked | queries | 22 | 21 | 95.45% | 21 | 95.45% | 0 | 0 |
| unmarked | rows_examined | 22 | 22 | 100.00% | 22 | 100.00% | 0 | 0 |
| unmarked | peak_memory | 4 | 4 | 100.00% | 4 | 100.00% | 0 | 18 |
| marked | queries | 34 | 33 | 97.06% | 33 | 97.06% | 1 | 6 |
| marked | rows_examined | 34 | 30 | 88.24% | 29 | 85.29% | 1 | 6 |
| marked | peak_memory | 18 | 18 | 100.00% | 18 | 100.00% | 1 | 22 |

**Rule A (shown, does not decide): pass**

- queries: 54 of 56 inside +-5% (enough), needs 80%
- rows_examined: 52 of 56 inside +-20% (enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 56 | 54 | 96.43% | 54 | 96.43% | 1 | 6 |
| all | rows_examined | 56 | 52 | 92.86% | 51 | 91.07% | 1 | 6 |
| all | peak_memory | 22 | 22 | 100.00% | 22 | 100.00% | 1 | 40 |
| unmarked | queries | 22 | 21 | 95.45% | 21 | 95.45% | 0 | 0 |
| unmarked | rows_examined | 22 | 22 | 100.00% | 22 | 100.00% | 0 | 0 |
| unmarked | peak_memory | 4 | 4 | 100.00% | 4 | 100.00% | 0 | 18 |
| marked | queries | 34 | 33 | 97.06% | 33 | 97.06% | 1 | 6 |
| marked | rows_examined | 34 | 30 | 88.24% | 29 | 85.29% | 1 | 6 |
| marked | peak_memory | 18 | 18 | 100.00% | 18 | 100.00% | 1 | 22 |

## bagisto: largest errors (rule B, queries and rows examined)

| # | Route | Quantity | Marked | Predicted | Measured | Relative error | Lowest | Goal |
| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- |
| 1 | `GET /api/categories/tree` | queries | yes | 515.0 | 4 | 12776.22% | no | no |
| 2 | `GET /api/categories/tree` | rows_examined | yes | 15,760,554.8 | 480,011 | 3183.37% | no | no |
| 3 | `GET /customer/account/rma/get-order-items/{orderId}` | rows_examined | yes | 37 | 9 | 311.11% | no | no |
| 4 | `POST /customer/account/orders/cancel/{id}` | rows_examined | yes | 2,051,731.3 | 960,006 | 113.72% | no | no |
| 5 | `POST /admin/sales/invoices/create/{order_id}` | rows_examined | yes | 3,251,774.3 | 2,160,028 | 50.54% | no | no |
| 6 | `GET /api/categories/attributes/{attribute_id}/options` | queries | no | 31.0 | 23 | 34.64% | no | no |
| 7 | `GET /api/categories` | rows_examined | yes | 960,224 | 1,200,029 | 19.98% | yes | no |
| 8 | `GET /api/products` | rows_examined | yes | 14,290,251.0 | 14,361,602 | 0.50% | yes | yes |
| 9 | `GET /api/product/{id}/reviews` | rows_examined | yes | 480,104 | 480,041 | 0.01% | yes | yes |
| 10 | `GET /api/customer/addresses` | rows_examined | yes | 240,028 | 240,007 | 0.01% | yes | yes |

## bagisto: marked routes, the point and the upper side against the cold values (rows examined)

| Route | Point (H1) | Upper side (H2) | Cold min | Cold max | Cold values (count of 4) | Point vs cold range | Upper vs cold range | Range enclosed by point and upper |
| --- | ---: | ---: | ---: | ---: | --- | --- | --- | --- |
| `DELETE /admin/marketing/search-seo/sitemaps/edit/{id}` | 1,200,050 | 1,200,050 | 1,200,029 | 1,200,029 | 1,200,029, 1,200,029, 1,200,029, 1,200,029 (4/4) | above | above | no |
| `DELETE /admin/sales/rma/rules/delete/{id}` | 1,200,049 | 1,200,049 | 1,200,028 | 1,200,028 | 1,200,028, 1,200,028, 1,200,028, 1,200,028 (4/4) | above | above | no |
| `DELETE /api/customer/wishlist/{id}` | n/a | 0 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `GET /admin/catalog/products/edit/{id}` | n/a | 0 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `GET /admin/catalog/products/search` | 1,200,047 | 1,200,047 | 1,200,026 | 1,200,026 | 1,200,026, 1,200,026, 1,200,026, 1,200,026 (4/4) | above | above | no |
| `GET /admin/configuration/{slug?}/{slug2?}` | 1,920,056 | 1,920,056 | 1,920,035 | 1,920,035 | 1,920,035, 1,920,035, 1,920,035, 1,920,035 (4/4) | above | above | no |
| `GET /admin/customers/gdpr/edit/{id}` | 1,200,048 | 1,200,048 | 1,200,027 | 1,200,027 | 1,200,027, 1,200,027, 1,200,027, 1,200,027 (4/4) | above | above | no |
| `GET /admin/customers/login-as-customer/{id}` | 1,200,048 | 1,200,048 | 1,200,027 | 1,200,027 | 1,200,027, 1,200,027, 1,200,027, 1,200,027 (4/4) | above | above | no |
| `GET /admin/customers/search` | 1,680,060 | 1,680,060 | 1,680,039 | 1,680,039 | 1,680,039, 1,680,039, 1,680,039, 1,680,039 (4/4) | above | above | no |
| `GET /admin/customers/view/{id}` | 4,560,149 | 7,104,398.7 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `GET /admin/customers/{id}/addresses` | 1,200,048 | 1,200,048 | 1,200,027 | 1,200,027 | 1,200,027, 1,200,027, 1,200,027, 1,200,027 (4/4) | above | above | no |
| `GET /admin/marketing/communications/campaigns` | 1,920,056 | 1,920,056 | 1,920,035 | 1,920,035 | 1,920,035, 1,920,035, 1,920,035, 1,920,035 (4/4) | above | above | no |
| `GET /admin/marketing/communications/campaigns/edit/{id}` | 2,400,080 | 2,400,080 | 2,400,059 | 2,400,059 | 2,400,059, 2,400,059, 2,400,059, 2,400,059 (4/4) | above | above | no |
| `GET /admin/marketing/promotions/cart-rules/edit/{id}` | n/a | 1,514,206,866.6 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `GET /admin/sales/rma/reasons/edit/{id}` | 1,200,050 | 1,200,050 | 1,200,029 | 1,200,029 | 1,200,029, 1,200,029, 1,200,029, 1,200,029 (4/4) | above | above | no |
| `GET /admin/settings/currencies` | 1,920,056 | 1,920,056 | 1,920,035 | 1,920,035 | 1,920,035, 1,920,035, 1,920,035, 1,920,035 (4/4) | above | above | no |
| `GET /admin/settings/inventory-sources/edit/{id}` | 2,400,063 | 2,400,063 | 2,400,042 | 2,400,042 | 2,400,042, 2,400,042, 2,400,042, 2,400,042 (4/4) | above | above | no |
| `GET /admin/settings/roles` | 1,920,056 | 1,920,056 | 1,920,035 | 1,920,035 | 1,920,035, 1,920,035, 1,920,035, 1,920,035 (4/4) | above | above | no |
| `GET /api/categories` | 960,224 | 2,910,803.5 | 960,049 | 1,200,029 | 1,200,029, 960,049, 960,049, 960,049 (4/4) | inside | above | no |
| `GET /api/categories/tree` | 15,760,554.8 | 15,760,554.8 | 480,011 | 480,011 | 480,011, 480,011, 480,011, 480,011 (4/4) | above | above | no |
| `GET /api/customer/addresses` | 240,028 | 2,104,344.6 | 240,007 | 240,007 | 240,007, 240,007, 240,007, 240,007 (4/4) | above | above | no |
| `GET /api/customer/wishlist` | n/a | 0 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `GET /api/product/{id}/reviews` | 480,104 | 598,120.7 | 480,041 | 480,041 | 480,041, 480,041, 480,041, 480,041 (4/4) | above | above | no |
| `GET /api/products` | 14,290,251.0 | 2,375,618,917.5 | 14,361,602 | 14,361,602 | 14,361,602, 14,361,602, 14,361,602, 14,361,602 (4/4) | below | above | yes |
| `GET /api/products/{id}/related` | n/a | 0 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `GET /api/products/{id}/up-sell` | n/a | 0 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `GET /customer/account` | 4,800,089 | 4,913,619.1 | 4,800,082 | 4,800,082 | 4,800,082, 4,800,082, 4,800,082, 4,800,082 (4/4) | above | above | no |
| `GET /customer/account/addresses/create` | 6,480,110 | 6,593,640.1 | 6,480,103 | 6,480,103 | 6,480,103, 6,480,103, 6,480,103, 6,480,103 (4/4) | above | above | no |
| `GET /customer/account/downloadable-products` | 4,800,089 | 4,913,619.1 | 4,800,082 | 4,800,082 | 4,800,082, 4,800,082, 4,800,082, 4,800,082 (4/4) | above | above | no |
| `GET /customer/account/profile/edit` | 4,800,090 | 4,913,620.1 | 4,800,083 | 4,800,083 | 4,800,083, 4,800,083, 4,800,083, 4,800,083 (4/4) | above | above | no |
| `GET /customer/account/reviews` | 5,280,108 | 5,393,638.1 | 5,280,101 | 5,280,101 | 5,280,101, 5,280,101, 5,280,101, 5,280,101 (4/4) | above | above | no |
| `GET /customer/account/rma` | 4,560,086 | 4,673,616.1 | 4,560,079 | 4,560,079 | 4,560,079, 4,560,079, 4,560,079, 4,560,079 (4/4) | above | above | no |
| `GET /customer/account/rma/get-order-items/{orderId}` | 37 | 37 | 9 | 9 | 9, 9, 9, 9 (4/4) | above | above | no |
| `GET /customer/account/wishlist` | 4,800,089 | 4,913,619.1 | 4,800,082 | 4,800,082 | 4,800,082, 4,800,082, 4,800,082, 4,800,082 (4/4) | above | above | no |
| `GET /sitemap.xml` | 480,020 | 729,964.0 | 480,006 | 480,006 | 480,006, 480,006, 480,006, 480,006 (4/4) | above | above | no |
| `POST /admin/marketing/search-seo/search-synonyms/create` | 1,200,047 | 1,200,047 | 1,200,026 | 1,200,026 | 1,200,026, 1,200,026, 1,200,026, 1,200,026 (4/4) | above | above | no |
| `POST /admin/sales/eu-withdrawals/{id}/resend-confirmation` | 1,200,049 | 1,200,049 | 1,200,028 | 1,200,028 | 1,200,028, 1,200,028, 1,200,028, 1,200,028 (4/4) | above | above | no |
| `POST /admin/sales/invoices/create/{order_id}` | 3,251,774.3 | 15,767,677.4 | 2,160,028 | 2,160,028 | 2,160,028, 2,160,028, 2,160,028, 2,160,028 (4/4) | above | above | no |
| `POST /admin/sales/rma/reasons/mass-update` | 1,200,048 | 1,200,048 | 1,200,027 | 1,200,027 | 1,200,027, 1,200,027, 1,200,027, 1,200,027 (4/4) | above | above | no |
| `POST /admin/sales/rma/rma-status/mass-delete` | 1,440,050 | 1,440,050 | 1,440,029 | 1,440,029 | 1,440,029, 1,440,029, 1,440,029, 1,440,029 (4/4) | above | above | no |
| `POST /customer/account/orders/cancel/{id}` | 2,051,731.3 | 14,567,634.4 | 960,006 | 960,006 | 960,006, 960,006, 960,006, 960,006 (4/4) | above | above | no |


### bagisto: every try of the marked routes (which were used)

| Route | Kind | Run | State | Result | Rows examined |
| --- | --- | --- | --- | --- | ---: |
| `DELETE /admin/marketing/search-seo/sitemaps/edit/{id}` | measure | measurement run 1 | used | ok | 1,200,029 |
| `DELETE /admin/marketing/search-seo/sitemaps/edit/{id}` | variance | variance run 1 | used | ok | 1,200,029 |
| `DELETE /admin/marketing/search-seo/sitemaps/edit/{id}` | variance | variance run 1 | used | ok | 1,200,029 |
| `DELETE /admin/marketing/search-seo/sitemaps/edit/{id}` | variance | variance run 1 | used | ok | 1,200,029 |
| `DELETE /admin/sales/rma/rules/delete/{id}` | measure | measurement run 1 | used | ok | 1,200,028 |
| `DELETE /admin/sales/rma/rules/delete/{id}` | variance | variance run 1 | used | ok | 1,200,028 |
| `DELETE /admin/sales/rma/rules/delete/{id}` | variance | variance run 1 | used | ok | 1,200,028 |
| `DELETE /admin/sales/rma/rules/delete/{id}` | variance | variance run 1 | used | ok | 1,200,028 |
| `DELETE /api/customer/wishlist/{id}` | measure | measurement run 1 | used | 500 | n/a |
| `DELETE /api/customer/wishlist/{id}` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `DELETE /api/customer/wishlist/{id}` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `DELETE /api/customer/wishlist/{id}` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /admin/catalog/products/edit/{id}` | measure | measurement run 1 | used | 500 | n/a |
| `GET /admin/catalog/products/edit/{id}` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /admin/catalog/products/edit/{id}` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /admin/catalog/products/edit/{id}` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /admin/catalog/products/search` | measure | measurement run 1 | used | ok | 1,200,026 |
| `GET /admin/catalog/products/search` | variance | variance run 1 | used | ok | 1,200,026 |
| `GET /admin/catalog/products/search` | variance | variance run 1 | used | ok | 1,200,026 |
| `GET /admin/catalog/products/search` | variance | variance run 1 | used | ok | 1,200,026 |
| `GET /admin/configuration/{slug?}/{slug2?}` | measure | measurement run 1 | used | ok | 1,920,035 |
| `GET /admin/configuration/{slug?}/{slug2?}` | variance | variance run 1 | used | ok | 1,920,035 |
| `GET /admin/configuration/{slug?}/{slug2?}` | variance | variance run 1 | used | ok | 1,920,035 |
| `GET /admin/configuration/{slug?}/{slug2?}` | variance | variance run 1 | used | ok | 1,920,035 |
| `GET /admin/customers/gdpr/edit/{id}` | measure | measurement run 1 | used | ok | 1,200,027 |
| `GET /admin/customers/gdpr/edit/{id}` | variance | variance run 1 | used | ok | 1,200,027 |
| `GET /admin/customers/gdpr/edit/{id}` | variance | variance run 1 | used | ok | 1,200,027 |
| `GET /admin/customers/gdpr/edit/{id}` | variance | variance run 1 | used | ok | 1,200,027 |
| `GET /admin/customers/login-as-customer/{id}` | measure | measurement run 1 | used | ok | 1,200,027 |
| `GET /admin/customers/login-as-customer/{id}` | variance | variance run 1 | used | ok | 1,200,027 |
| `GET /admin/customers/login-as-customer/{id}` | variance | variance run 1 | used | ok | 1,200,027 |
| `GET /admin/customers/login-as-customer/{id}` | variance | variance run 1 | used | ok | 1,200,027 |
| `GET /admin/customers/search` | measure | measurement run 1 | used | ok | 1,680,039 |
| `GET /admin/customers/search` | variance | variance run 1 | used | ok | 1,680,039 |
| `GET /admin/customers/search` | variance | variance run 1 | used | ok | 1,680,039 |
| `GET /admin/customers/search` | variance | variance run 1 | used | ok | 1,680,039 |
| `GET /admin/customers/view/{id}` | measure | measurement run 1 | used | other:runner_failed | n/a |
| `GET /admin/customers/{id}/addresses` | measure | measurement run 1 | used | ok | 1,200,027 |
| `GET /admin/customers/{id}/addresses` | variance | variance run 1 | used | ok | 1,200,027 |
| `GET /admin/customers/{id}/addresses` | variance | variance run 1 | used | ok | 1,200,027 |
| `GET /admin/customers/{id}/addresses` | variance | variance run 1 | used | ok | 1,200,027 |
| `GET /admin/marketing/communications/campaigns` | measure | measurement run 1 | used | ok | 1,920,035 |
| `GET /admin/marketing/communications/campaigns` | variance | variance run 1 | used | ok | 1,920,035 |
| `GET /admin/marketing/communications/campaigns` | variance | variance run 1 | used | ok | 1,920,035 |
| `GET /admin/marketing/communications/campaigns` | variance | variance run 1 | used | ok | 1,920,035 |
| `GET /admin/marketing/communications/campaigns/edit/{id}` | measure | measurement run 1 | used | ok | 2,400,059 |
| `GET /admin/marketing/communications/campaigns/edit/{id}` | variance | variance run 1 | used | ok | 2,400,059 |
| `GET /admin/marketing/communications/campaigns/edit/{id}` | variance | variance run 1 | used | ok | 2,400,059 |
| `GET /admin/marketing/communications/campaigns/edit/{id}` | variance | variance run 1 | used | ok | 2,400,059 |
| `GET /admin/marketing/promotions/cart-rules/edit/{id}` | measure | measurement run 1 | used | 500 | n/a |
| `GET /admin/marketing/promotions/cart-rules/edit/{id}` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /admin/marketing/promotions/cart-rules/edit/{id}` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /admin/marketing/promotions/cart-rules/edit/{id}` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /admin/sales/rma/reasons/edit/{id}` | measure | measurement run 1 | used | ok | 1,200,029 |
| `GET /admin/sales/rma/reasons/edit/{id}` | variance | variance run 1 | used | ok | 1,200,029 |
| `GET /admin/sales/rma/reasons/edit/{id}` | variance | variance run 1 | used | ok | 1,200,029 |
| `GET /admin/sales/rma/reasons/edit/{id}` | variance | variance run 1 | used | ok | 1,200,029 |
| `GET /admin/settings/currencies` | measure | measurement run 1 | used | ok | 1,920,035 |
| `GET /admin/settings/currencies` | variance | variance run 1 | used | ok | 1,920,035 |
| `GET /admin/settings/currencies` | variance | variance run 1 | used | ok | 1,920,035 |
| `GET /admin/settings/currencies` | variance | variance run 1 | used | ok | 1,920,035 |
| `GET /admin/settings/inventory-sources/edit/{id}` | measure | measurement run 1 | used | ok | 2,400,042 |
| `GET /admin/settings/inventory-sources/edit/{id}` | variance | variance run 1 | used | ok | 2,400,042 |
| `GET /admin/settings/inventory-sources/edit/{id}` | variance | variance run 1 | used | ok | 2,400,042 |
| `GET /admin/settings/inventory-sources/edit/{id}` | variance | variance run 1 | used | ok | 2,400,042 |
| `GET /admin/settings/roles` | measure | measurement run 1 | used | ok | 1,920,035 |
| `GET /admin/settings/roles` | variance | variance run 1 | used | ok | 1,920,035 |
| `GET /admin/settings/roles` | variance | variance run 1 | used | ok | 1,920,035 |
| `GET /admin/settings/roles` | variance | variance run 1 | used | ok | 1,920,035 |
| `GET /api/categories` | measure | measurement run 1 | used | ok | 1,200,029 |
| `GET /api/categories` | variance | variance run 1 | used | ok | 960,049 |
| `GET /api/categories` | variance | variance run 1 | used | ok | 960,049 |
| `GET /api/categories` | variance | variance run 1 | used | ok | 960,049 |
| `GET /api/categories/tree` | measure | measurement run 1 | used | ok | 480,011 |
| `GET /api/categories/tree` | variance | variance run 1 | used | ok | 480,011 |
| `GET /api/categories/tree` | variance | variance run 1 | used | ok | 480,011 |
| `GET /api/categories/tree` | variance | variance run 1 | used | ok | 480,011 |
| `GET /api/customer/addresses` | measure | measurement run 1 | used | ok | 240,007 |
| `GET /api/customer/addresses` | variance | variance run 1 | used | ok | 240,007 |
| `GET /api/customer/addresses` | variance | variance run 1 | used | ok | 240,007 |
| `GET /api/customer/addresses` | variance | variance run 1 | used | ok | 240,007 |
| `GET /api/customer/wishlist` | measure | measurement run 1 | used | 500 | n/a |
| `GET /api/customer/wishlist` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /api/customer/wishlist` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /api/customer/wishlist` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /api/product/{id}/reviews` | measure | measurement run 1 | used | ok | 480,041 |
| `GET /api/product/{id}/reviews` | variance | variance run 1 | used | ok | 480,041 |
| `GET /api/product/{id}/reviews` | variance | variance run 1 | used | ok | 480,041 |
| `GET /api/product/{id}/reviews` | variance | variance run 1 | used | ok | 480,041 |
| `GET /api/products` | measure | measurement run 1 | used | ok | 14,361,602 |
| `GET /api/products` | variance | variance run 1 | used | ok | 14,361,602 |
| `GET /api/products` | variance | variance run 1 | used | ok | 14,361,602 |
| `GET /api/products` | variance | variance run 1 | used | ok | 14,361,602 |
| `GET /api/products/{id}/related` | measure | measurement run 1 | used | 500 | n/a |
| `GET /api/products/{id}/related` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /api/products/{id}/related` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /api/products/{id}/related` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /api/products/{id}/up-sell` | measure | measurement run 1 | used | 500 | n/a |
| `GET /api/products/{id}/up-sell` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /api/products/{id}/up-sell` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /api/products/{id}/up-sell` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `GET /customer/account` | measure | measurement run 1 | used | ok | 4,800,082 |
| `GET /customer/account` | variance | variance run 1 | used | ok | 4,800,082 |
| `GET /customer/account` | variance | variance run 1 | used | ok | 4,800,082 |
| `GET /customer/account` | variance | variance run 1 | used | ok | 4,800,082 |
| `GET /customer/account/addresses/create` | measure | measurement run 1 | used | ok | 6,480,103 |
| `GET /customer/account/addresses/create` | variance | variance run 1 | used | ok | 6,480,103 |
| `GET /customer/account/addresses/create` | variance | variance run 1 | used | ok | 6,480,103 |
| `GET /customer/account/addresses/create` | variance | variance run 1 | used | ok | 6,480,103 |
| `GET /customer/account/downloadable-products` | measure | measurement run 1 | used | ok | 4,800,082 |
| `GET /customer/account/downloadable-products` | variance | variance run 1 | used | ok | 4,800,082 |
| `GET /customer/account/downloadable-products` | variance | variance run 1 | used | ok | 4,800,082 |
| `GET /customer/account/downloadable-products` | variance | variance run 1 | used | ok | 4,800,082 |
| `GET /customer/account/profile/edit` | measure | measurement run 1 | used | ok | 4,800,083 |
| `GET /customer/account/profile/edit` | variance | variance run 1 | used | ok | 4,800,083 |
| `GET /customer/account/profile/edit` | variance | variance run 1 | used | ok | 4,800,083 |
| `GET /customer/account/profile/edit` | variance | variance run 1 | used | ok | 4,800,083 |
| `GET /customer/account/reviews` | measure | measurement run 1 | used | ok | 5,280,101 |
| `GET /customer/account/reviews` | variance | variance run 1 | used | ok | 5,280,101 |
| `GET /customer/account/reviews` | variance | variance run 1 | used | ok | 5,280,101 |
| `GET /customer/account/reviews` | variance | variance run 1 | used | ok | 5,280,101 |
| `GET /customer/account/rma` | measure | measurement run 1 | used | ok | 4,560,079 |
| `GET /customer/account/rma` | variance | variance run 1 | used | ok | 4,560,079 |
| `GET /customer/account/rma` | variance | variance run 1 | used | ok | 4,560,079 |
| `GET /customer/account/rma` | variance | variance run 1 | used | ok | 4,560,079 |
| `GET /customer/account/rma/get-order-items/{orderId}` | measure | measurement run 1 | used | ok | 9 |
| `GET /customer/account/rma/get-order-items/{orderId}` | variance | variance run 1 | used | ok | 9 |
| `GET /customer/account/rma/get-order-items/{orderId}` | variance | variance run 1 | used | ok | 9 |
| `GET /customer/account/rma/get-order-items/{orderId}` | variance | variance run 1 | used | ok | 9 |
| `GET /customer/account/wishlist` | measure | measurement run 1 | used | ok | 4,800,082 |
| `GET /customer/account/wishlist` | variance | variance run 1 | used | ok | 4,800,082 |
| `GET /customer/account/wishlist` | variance | variance run 1 | used | ok | 4,800,082 |
| `GET /customer/account/wishlist` | variance | variance run 1 | used | ok | 4,800,082 |
| `GET /sitemap.xml` | measure | measurement run 1 | used | ok | 480,006 |
| `GET /sitemap.xml` | variance | variance run 1 | used | ok | 480,006 |
| `GET /sitemap.xml` | variance | variance run 1 | used | ok | 480,006 |
| `GET /sitemap.xml` | variance | variance run 1 | used | ok | 480,006 |
| `POST /admin/marketing/search-seo/search-synonyms/create` | measure | measurement run 1 | used | ok | 1,200,026 |
| `POST /admin/marketing/search-seo/search-synonyms/create` | variance | variance run 1 | used | ok | 1,200,026 |
| `POST /admin/marketing/search-seo/search-synonyms/create` | variance | variance run 1 | used | ok | 1,200,026 |
| `POST /admin/marketing/search-seo/search-synonyms/create` | variance | variance run 1 | used | ok | 1,200,026 |
| `POST /admin/sales/eu-withdrawals/{id}/resend-confirmation` | measure | measurement run 1 | used | ok | 1,200,028 |
| `POST /admin/sales/eu-withdrawals/{id}/resend-confirmation` | variance | variance run 1 | used | ok | 1,200,028 |
| `POST /admin/sales/eu-withdrawals/{id}/resend-confirmation` | variance | variance run 1 | used | ok | 1,200,028 |
| `POST /admin/sales/eu-withdrawals/{id}/resend-confirmation` | variance | variance run 1 | used | ok | 1,200,028 |
| `POST /admin/sales/invoices/create/{order_id}` | measure | measurement run 1 | used | ok | 2,160,028 |
| `POST /admin/sales/invoices/create/{order_id}` | variance | variance run 1 | used | ok | 2,160,028 |
| `POST /admin/sales/invoices/create/{order_id}` | variance | variance run 1 | used | ok | 2,160,028 |
| `POST /admin/sales/invoices/create/{order_id}` | variance | variance run 1 | used | ok | 2,160,028 |
| `POST /admin/sales/rma/reasons/mass-update` | measure | measurement run 1 | used | ok | 1,200,027 |
| `POST /admin/sales/rma/reasons/mass-update` | variance | variance run 1 | used | ok | 1,200,027 |
| `POST /admin/sales/rma/reasons/mass-update` | variance | variance run 1 | used | ok | 1,200,027 |
| `POST /admin/sales/rma/reasons/mass-update` | variance | variance run 1 | used | ok | 1,200,027 |
| `POST /admin/sales/rma/rma-status/mass-delete` | measure | measurement run 1 | used | ok | 1,440,029 |
| `POST /admin/sales/rma/rma-status/mass-delete` | variance | variance run 1 | used | ok | 1,440,029 |
| `POST /admin/sales/rma/rma-status/mass-delete` | variance | variance run 1 | used | ok | 1,440,029 |
| `POST /admin/sales/rma/rma-status/mass-delete` | variance | variance run 1 | used | ok | 1,440,029 |
| `POST /customer/account/orders/cancel/{id}` | measure | measurement run 1 | used | ok | 960,006 |
| `POST /customer/account/orders/cancel/{id}` | variance | variance run 1 | used | ok | 960,006 |
| `POST /customer/account/orders/cancel/{id}` | variance | variance run 1 | used | ok | 960,006 |
| `POST /customer/account/orders/cancel/{id}` | variance | variance run 1 | used | ok | 960,006 |

## bagisto: failed cold tries by class

| Which tries | timeout | 422 | 500 | exception | other |
| --- | ---: | ---: | ---: | ---: | ---: |
| used (rule B drops them; a timeout on a marked route makes it not measured) | 18 | 0 | 6 | 0 | 1 |
| superseded by a re-run (not used) | 0 | 0 | 0 | 0 | 0 |

| Route | Marked | Kind | State | Class | Status | Detail |
| --- | --- | --- | --- | --- | ---: | --- |
| `DELETE /api/customer/wishlist/{id}` | yes | measure | used | 500 | 500 | None |
| `DELETE /api/customer/wishlist/{id}` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `DELETE /api/customer/wishlist/{id}` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `DELETE /api/customer/wishlist/{id}` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /admin/catalog/products/edit/{id}` | yes | measure | used | 500 | 500 | None |
| `GET /admin/catalog/products/edit/{id}` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /admin/catalog/products/edit/{id}` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /admin/catalog/products/edit/{id}` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /admin/customers/view/{id}` | yes | measure | used | other:runner_failed | None | exit 9: ayStore","session_driver":"array","mail":"Illuminate\\Support\\Testing\\Fakes\\MailFake","notification":"Illumin |
| `GET /admin/marketing/promotions/cart-rules/edit/{id}` | yes | measure | used | 500 | 500 | None |
| `GET /admin/marketing/promotions/cart-rules/edit/{id}` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /admin/marketing/promotions/cart-rules/edit/{id}` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /admin/marketing/promotions/cart-rules/edit/{id}` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /api/customer/wishlist` | yes | measure | used | 500 | 500 | None |
| `GET /api/customer/wishlist` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /api/customer/wishlist` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /api/customer/wishlist` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /api/products/{id}/related` | yes | measure | used | 500 | 500 | None |
| `GET /api/products/{id}/related` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /api/products/{id}/related` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /api/products/{id}/related` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /api/products/{id}/up-sell` | yes | measure | used | 500 | 500 | None |
| `GET /api/products/{id}/up-sell` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /api/products/{id}/up-sell` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |
| `GET /api/products/{id}/up-sell` | yes | variance | used | timeout (600 seconds or more) | 500 | the request ran longer than 600 seconds |

Marked routes not measured under rule B because of a timeout (600 seconds or more): `DELETE /api/customer/wishlist/{id}`, `GET /admin/catalog/products/edit/{id}`, `GET /admin/marketing/promotions/cart-rules/edit/{id}`, `GET /api/customer/wishlist`, `GET /api/products/{id}/related`, `GET /api/products/{id}/up-sell`

Timeouts recorded under another class name (counted as timeout by their detail): none

## bagisto: outside a denominator

| Route | Quantity | Category | Reason |
| --- | --- | --- | --- |
| `DELETE /admin/sales/rma/rules/delete/{id}` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `DELETE /api/checkout/cart` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `DELETE /api/customer/wishlist/{id}` | queries | no_estimate | estimate incomplete (partial) |
| `DELETE /api/customer/wishlist/{id}` | rows_examined | no_estimate | estimate incomplete (partial) |
| `DELETE /api/customer/wishlist/{id}` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured; scale 10000: not measured; scale 30000: not measured |
| `DELETE /customer/logout` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /admin/catalog/products/edit/{id}` | queries | no_estimate | estimate incomplete (partial) |
| `GET /admin/catalog/products/edit/{id}` | rows_examined | no_estimate | estimate incomplete (partial) |
| `GET /admin/catalog/products/edit/{id}` | peak_memory | no_estimate | peak_memory not_predicted: scale 10000: not measured; scale 30000: not measured |
| `GET /admin/customers/view/{id}` | queries | unmeasured | no cold value at the size; failed tries: other:runner_failed |
| `GET /admin/customers/view/{id}` | rows_examined | unmeasured | no cold value at the size; failed tries: other:runner_failed |
| `GET /admin/customers/view/{id}` | peak_memory | unmeasured | no cold value at the size; failed tries: other:runner_failed |
| `GET /admin/marketing/promotions/cart-rules/edit/{id}` | queries | no_estimate | estimate incomplete (partial) |
| `GET /admin/marketing/promotions/cart-rules/edit/{id}` | rows_examined | no_estimate | estimate incomplete (partial) |
| `GET /admin/marketing/promotions/cart-rules/edit/{id}` | peak_memory | no_estimate | peak_memory not_predicted: scale 30000: not measured |
| `GET /api/categories` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /api/categories/attributes` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /api/categories/attributes/{attribute_id}/options` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /api/categories/tree` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /api/core/countries` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /api/customer/addresses` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /api/customer/addresses/allowed-countries` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /api/customer/addresses/allowed-states` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /api/customer/wishlist` | queries | no_estimate | estimate incomplete (partial) |
| `GET /api/customer/wishlist` | rows_examined | no_estimate | estimate incomplete (partial) |
| `GET /api/customer/wishlist` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured; scale 10000: not measured; scale 30000: not measured |
| `GET /api/product/{id}/reviews` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /api/products` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /api/products/{id}/related` | queries | no_estimate | estimate incomplete (partial) |
| `GET /api/products/{id}/related` | rows_examined | no_estimate | estimate incomplete (partial) |
| `GET /api/products/{id}/related` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured; scale 10000: not measured; scale 30000: not measured |
| `GET /api/products/{id}/up-sell` | queries | no_estimate | estimate incomplete (partial) |
| `GET /api/products/{id}/up-sell` | rows_examined | no_estimate | estimate incomplete (partial) |
| `GET /api/products/{id}/up-sell` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured; scale 10000: not measured; scale 30000: not measured |
| `GET /customer/account` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /customer/account/addresses/create` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /customer/account/downloadable-products` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /customer/account/profile/edit` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /customer/account/reviews` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /customer/account/rma` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /customer/account/rma/get-order-items/{orderId}` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /customer/account/wishlist` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /search` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /sitemap.xml` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `GET /webmcp/product` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `PATCH /customer/account/addresses/edit/{id}` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `POST /api/checkout/onepage/addresses` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `POST /api/checkout/onepage/orders` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `POST /api/customer/wishlist` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `POST /customer/account/orders/cancel/{id}` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `POST /customer/account/profile/destroy` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `POST /customer/account/rma/cancel/{id}` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `POST /customer/account/rma/reopen/{id}` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |
| `PUT /api/checkout/cart` | peak_memory | no_estimate | peak_memory not_predicted: scale 0: not measured |

Failed tries of routes without an estimate row (outside every denominator, they do not affect the verdict):

| Route | Class | Status | Detail |
| --- | --- | ---: | --- |
| `DELETE /admin/customers/groups/delete/{id}` | other:client_error (recorded as client_error) | 400 | Default Group Can not be Deleted |
| `DELETE /admin/settings/locales/edit/{id}` | other:client_error (recorded as client_error) | 400 | At least one Locale is required. |
| `DELETE /api/compare-items` | other:unauthenticated (recorded as unauthenticated) | 401 | None |
| `DELETE /api/compare-items/all` | other:unauthenticated (recorded as unauthenticated) | 401 | None |
| `DELETE /customer/account/addresses/delete/{id}` | other:not_found (recorded as not_found) | 404 | None |
| `GET /admin/reporting/sales/view` | other:not_found (recorded as not_found) | 404 | None |
| `GET /admin/sales/invoices/print/{id}` | 500 (recorded as exception) | 500 | None |
| `GET /admin/sales/orders/view/{id}` | 500 (recorded as exception) | 500 | None |
| `GET /admin/sales/shipments/view/{id}` | 500 (recorded as exception) | 500 | None |
| `GET /admin/settings/exchange-rates/edit/{id}` | other:not_found (recorded as not_found) | 404 | None |
| `GET /api/product/{id}/reviews/{review_id}/translate` | other:forbidden (recorded as forbidden) | 403 | 403 Forbidden |
| `GET /appearance-preview` | other:forbidden (recorded as forbidden) | 403 | None |
| `GET /booking-slots/{id}` | 500 (recorded as exception) | 500 | None |
| `GET /customer/account/gdpr` | other:not_found (recorded as not_found) | 404 | None |
| `GET /customer/account/gdpr/pdf-view` | other:not_found (recorded as not_found) | 404 | None |
| `GET /customer/account/orders/view/{id}` | 500 (recorded as exception) | 500 | None |
| `GET /customer/account/orders/{orderId}/withdraw` | 500 (recorded as exception) | 500 | None |
| `GET /customer/account/rma/get-messages` | other:not_found (recorded as not_found) | 404 | None |
| `GET /customer/account/rma/images/{id}` | other:not_found (recorded as not_found) | 404 | None |
| `GET /customer/account/your-cookie-consent-preferences` | other:not_found (recorded as not_found) | 404 | None |
| `GET /customer/datagrid/look-up` | 422 (recorded as validation_422) | 422 | The datagrid id field is required. (and 2 more errors) / fields: datagrid_id, column, search |
| `GET /withdraw` | other:not_found (recorded as not_found) | 404 | None |
| `POST /admin/cms/create` | 422 (recorded as validation_422) | 422 | The url key field is required. (and 3 more errors) / fields: url_key, page_title, html_content, channels |
| `POST /admin/customers/addresses/default/{id}` | other:not_found (recorded as not_found) | 404 | None |
| `POST /admin/marketing/communications/events/create` | 422 (recorded as validation_422) | 422 | The name field is required. (and 2 more errors) / fields: name, description, date |
| `POST /admin/sales/cart/{id}/shipping-methods` | 500 (recorded as exception) | 500 | None |
| `POST /admin/sales/rma/custom-fields/update/{id}` | 422 (recorded as validation_422) | 422 | The label field is required. (and 3 more errors) / fields: label, code, position, type |
| `POST /admin/settings/exchange-rates/create` | 500 (recorded as exception) | 500 | None |
| `POST /api/checkout/cart/coupon` | 422 (recorded as validation_422) | 422 | The code field is required. / fields: code |
| `POST /api/checkout/cart/move-to-wishlist` | 422 (recorded as validation_422) | 422 | The ids field is required. (and 1 more error) / fields: ids, qty |
| `POST /api/checkout/onepage/payment-methods` | 422 (recorded as validation_422) | 422 | The payment field is required. / fields: payment |
| `POST /api/checkout/onepage/shipping-methods` | 422 (recorded as validation_422) | 422 | The shipping method field is required. / fields: shipping_method |
| `POST /api/compare-items` | other:unauthenticated (recorded as unauthenticated) | 401 | None |
| `POST /api/customer/addresses` | 422 (recorded as validation_422) | 422 | The address field is required. (and 2 more errors) / fields: address, postcode, phone |
| `POST /customer/account/addresses/create` | 422 (recorded as validation_422) | 422 | The address field is required. (and 2 more errors) / fields: address, postcode, phone |
| `POST /customer/account/gdpr` | other:not_found (recorded as not_found) | 404 | None |
| `POST /customer/account/gdpr/revoke/{id}` | other:not_found (recorded as not_found) | 404 | None |
| `POST /customer/account/orders/{orderId}/withdraw` | 500 (recorded as exception) | 500 | None |
| `POST /customer/account/profile/edit` | 422 (recorded as validation_422) | 422 | The first name field is required. (and 3 more errors) / fields: first_name, last_name, gender, phone |
| `POST /customer/account/rma/store` | 422 (recorded as validation_422) | 422 | The order id field is required. (and 5 more errors) / fields: order_id, order_item_id, rma_qty, resolution_type, rma_rea |
| `POST /paypal/standard/ipn` | other:external_call_blocked (recorded as external_call_blocked) | 200 | hosts: ipnpb.sandbox.paypal.com |
| `POST /search/upload` | 422 (recorded as validation_422) | 422 | The image field is required. / fields: image |
| `POST /withdraw/lookup` | other:not_found (recorded as not_found) | 404 | None |
| `POST /withdraw/{orderId}/store` | other:forbidden (recorded as forbidden) | 403 | None |
| `PUT /admin/sales/rma/reasons/update/{id}` | 422 (recorded as validation_422) | 422 | The title field is required. (and 3 more errors) / fields: title, status, position, resolution_type |
| `PUT /admin/settings/channels/edit/{id}` | 422 (recorded as validation_422) | 422 | The code field is required. (and 10 more errors) / fields: code, en.name, inventory_sources, root_category_id, locales,  |
| `PUT /admin/settings/currencies/edit` | other:not_found (recorded as not_found) | 404 | None |
| `PUT /admin/settings/users/edit` | 422 (recorded as validation_422) | 422 | The password confirmation field must match password. / fields: password_confirmation |
| `PUT /customer/account/addresses/edit/{id}` | 422 (recorded as validation_422) | 422 | The address field is required. (and 2 more errors) / fields: address, postcode, phone |

Measured but without an estimate row: `DELETE /admin/customers/groups/delete/{id}`, `DELETE /admin/settings/locales/edit/{id}`, `DELETE /api/compare-items`, `DELETE /api/compare-items/all`, `DELETE /customer/account/addresses/delete/{id}`, `GET /admin/reporting/sales/view`, `GET /admin/sales/invoices/print/{id}`, `GET /admin/sales/orders/view/{id}`, `GET /admin/sales/shipments/view/{id}`, `GET /admin/settings/exchange-rates/edit/{id}`, `GET /api/product/{id}/reviews/{review_id}/translate`, `GET /appearance-preview`, `GET /booking-slots/{id}`, `GET /customer/account/gdpr`, `GET /customer/account/gdpr/pdf-view`, `GET /customer/account/orders/view/{id}`, `GET /customer/account/orders/{orderId}/withdraw`, `GET /customer/account/rma/get-messages`, `GET /customer/account/rma/images/{id}`, `GET /customer/account/your-cookie-consent-preferences`, `GET /customer/datagrid/look-up`, `GET /withdraw`, `POST /admin/cms/create`, `POST /admin/customers/addresses/default/{id}`, `POST /admin/marketing/communications/events/create`, `POST /admin/sales/cart/{id}/shipping-methods`, `POST /admin/sales/rma/custom-fields/update/{id}`, `POST /admin/settings/exchange-rates/create`, `POST /api/checkout/cart/coupon`, `POST /api/checkout/cart/move-to-wishlist`, `POST /api/checkout/onepage/payment-methods`, `POST /api/checkout/onepage/shipping-methods`, `POST /api/compare-items`, `POST /api/customer/addresses`, `POST /customer/account/addresses/create`, `POST /customer/account/gdpr`, `POST /customer/account/gdpr/revoke/{id}`, `POST /customer/account/orders/{orderId}/withdraw`, `POST /customer/account/profile/edit`, `POST /customer/account/rma/store`, `POST /paypal/standard/ipn`, `POST /search/upload`, `POST /withdraw/lookup`, `POST /withdraw/{orderId}/store`, `PUT /admin/sales/rma/reasons/update/{id}`, `PUT /admin/settings/channels/edit/{id}`, `PUT /admin/settings/currencies/edit`, `PUT /admin/settings/users/edit`, `PUT /customer/account/addresses/edit/{id}`

