# A verdict (judge_a.py)


Rows 30000. The comparison tool is `extrapolation-data-2/compare_test2.py` (SHA-256 `95da6a9b77bdc5c43a1455c9c1ac9b4f11cb0e966aa1cfeac4e42220e382bb8b`, checked before the run). B decides, A shown (docs/simulator-spec.md 7.1).

## bagisto

**Verdict (rule B): UNDECIDABLE.** queries: 46 of 57 routes with an estimate could not be measured, more than 20%; rows_examined: 46 of 57 routes with an estimate could not be measured, more than 20%

Rule A, shown and not deciding: undecidable. queries: 46 of 57 routes with an estimate could not be measured, more than 20%; rows_examined: 46 of 57 routes with an estimate could not be measured, more than 20%

| Condition 1a (D153; per application, D215) | Inside | Denominator | Needed | Short |
| --- | --- | --- | --- | --- |
| Goal, unmarked routes, queries (±2%) | 5 | 5 | n/a | n/a (undecidable) |
| Goal, unmarked routes, rows examined (±10%) | 4 | 5 | n/a | n/a (undecidable) |
| Lowest standard, all routes, queries (±5%) | 11 | 11 | n/a | n/a (undecidable) |
| Lowest standard, all routes, rows examined (±20%) | 10 | 11 | n/a | n/a (undecidable) |

The test is undecidable here: no number short is given (D153).

Condition 1a for this application is undecidable.

Condition 3: **1 routes outside the goal** (0 marked, 1 unmarked; marks are the marks of test A). Not measured, with an estimate: 46; no estimate: 6 (counted apart, not misses).

| Route | Marked | Quantity outside the goal and its relative error |
| --- | --- | --- |
| `GET /admin/customers/search` | no | rows examined 30.00% |

Not measured, with an estimate: `DELETE /admin/marketing/search-seo/sitemaps/edit/{id}`, `DELETE /api/checkout/cart`, `DELETE /customer/logout`, `GET /`, `GET /admin/catalog/products/search`, `GET /admin/configuration/{slug?}/{slug2?}`, `GET /admin/customers/gdpr/edit/{id}`, `GET /admin/customers/login-as-customer/{id}`, `GET /admin/customers/{id}/addresses`, `GET /admin/marketing/communications/campaigns`, `GET /admin/marketing/communications/campaigns/edit/{id}`, `GET /admin/sales/rma/reasons/edit/{id}`, `GET /admin/settings/currencies`, `GET /admin/settings/inventory-sources/edit/{id}`, `GET /admin/settings/roles`, `GET /api/categories`, `GET /api/categories/attributes`, `GET /api/categories/attributes/{attribute_id}/options`, `GET /api/categories/tree`, `GET /api/core/countries`, `GET /api/customer/addresses`, `GET /api/customer/addresses/allowed-countries`, `GET /api/customer/addresses/allowed-states`, `GET /api/product/{id}/reviews`, `GET /customer/account/addresses/create`, `GET /customer/account/downloadable-products`, `GET /customer/account/profile/edit`, `GET /customer/account/reviews`, `GET /customer/account/rma`, `GET /customer/account/rma/get-order-items/{orderId}`, `GET /payu/redirect`, `GET /phonepe/redirect`, `GET /razorpay/payment/redirect`, `GET /sitemap.xml`, `GET /stripe/redirect`, `GET /webmcp/product`, `PATCH /customer/account/addresses/edit/{id}`, `POST /admin/marketing/search-seo/search-synonyms/create`, `POST /admin/sales/eu-withdrawals/{id}/resend-confirmation`, `POST /admin/sales/rma/reasons/mass-update`, `POST /admin/sales/rma/rma-status/mass-delete`, `POST /api/checkout/onepage/addresses`, `POST /api/checkout/onepage/orders`, `POST /api/customer/wishlist`, `POST /customer/account/rma/cancel/{id}`, `POST /customer/account/rma/reopen/{id}`.

## Both applications together (shown beside the verdicts; not used for them, D71 and D215)

undecidable. queries: 46 of 57 routes with an estimate could not be measured, more than 20%; rows_examined: 46 of 57 routes with an estimate could not be measured, more than 20%

