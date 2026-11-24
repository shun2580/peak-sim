# Runtime measurement run

profile: bagisto

Every verdict here was measured on this input, along the path that was taken, in the scope named beside it. A flat count on a path that never read the scaled tables, or that returned an empty response, is `no_info`, not O(1). Where the static analysis and the measurement disagree, both are shown as they are, and a difference is not fitted to either side.

## Conflicts

No conflicts: no route where the static analysis was determinate and the measurement said otherwise.

## Summary

Routes: 120. Tried: 112.

| outcome class | routes |
| --- | ---: |
| client_error | 2 |
| exception | 33 |
| external_call_blocked | 1 |
| forbidden | 5 |
| not_found | 23 |
| ok | 12 |
| runner_failed | 1 |
| unauthenticated | 3 |
| unfilled_param | 24 |
| validation_422 | 16 |

| verdict | routes |
| --- | ---: |
| measured_O1 | 0 |
| measured_ON | 0 |
| measured_ONM | 0 |
| inconclusive | 0 |
| no_info | 0 |
| not_measured | 120 |

| against the static analysis | routes |
| --- | ---: |
| agree | 0 |
| runtime_only | 0 |
| static_stronger | 0 |
| conflict | 0 |
| not_comparable | 120 |

## Routes

| route | method | outcome | verdict | scope | slope | static | against static | note |
| --- | --- | --- | --- | --- | ---: | --- | --- | --- |
| / | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /admin/appearance/themes/{code}/sections | POST | unfilled_param | not_measured | - | - | unknown | not_comparable | code: no_rule_to_fill |
| /admin/catalog/products/edit/{id} | GET | exception | not_measured | - | - | unknown | not_comparable | exception |
| /admin/catalog/products/search | GET | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |
| /admin/cms/create | POST | validation_422 | not_measured | - | - | unknown | not_comparable | The channels field is required. \| fields: channels |
| /admin/configuration/{slug?}/{slug2?} | GET | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |
| /admin/customers/addresses/default/{id} | POST | not_found | not_measured | - | - | unknown | not_comparable | not_found |
| /admin/customers/gdpr/edit/{id} | GET | not_found | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /admin/customers/groups/delete/{id} | DELETE | client_error | not_measured | - | - | unknown | not_comparable | Default Group Can not be Deleted |
| /admin/customers/login-as-customer/{id} | GET | not_found | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /admin/customers/search | GET | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |
| /admin/customers/view/{id} | GET | runner_failed | not_measured | - | - | unknown | not_comparable | exit 9: ayStore","session_driver":"array","mail":"Illuminate\\Support\\Testing\\Fakes\\MailFake","notification":"Illuminate\\Support\\Testing\\Fakes\\NotificationFake","queue":"Illuminate\\Support\\Te |
| /admin/customers/{id}/addresses | GET | not_found | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /admin/marketing/communications/campaigns | GET | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |
| /admin/marketing/communications/campaigns/edit/{id} | GET | not_found | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /admin/marketing/communications/events/create | POST | validation_422 | not_measured | - | - | unknown | not_comparable | The date field must be a valid date. (and 1 more error) \| fields: date |
| /admin/marketing/promotions/cart-rules/edit/{id} | GET | exception | not_measured | - | - | unknown | not_comparable | exception |
| /admin/marketing/search-seo/search-synonyms/create | POST | validation_422 | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): The name field is required. (and 1 more error) \| fields: name, terms |
| /admin/marketing/search-seo/sitemaps/edit/{id} | DELETE | not_found | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /admin/reporting/sales/view | GET | not_found | not_measured | - | - | unknown | not_comparable | not_found |
| /admin/sales/cart/{id}/shipping-methods | POST | exception | not_measured | - | - | unknown | not_comparable | exception |
| /admin/sales/eu-withdrawals/{id}/resend-confirmation | POST | not_found | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /admin/sales/invoices/create/{order_id} | POST | not_found | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /admin/sales/invoices/print/{id} | GET | exception | not_measured | - | - | unknown | not_comparable | exception |
| /admin/sales/orders/view/{id} | GET | exception | not_measured | - | - | unknown | not_comparable | exception |
| /admin/sales/rma/custom-fields/update/{id} | POST | validation_422 | not_measured | - | - | unknown | not_comparable | The type field is required. \| fields: type |
| /admin/sales/rma/reasons/edit/{id} | GET | not_found | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /admin/sales/rma/reasons/mass-update | POST | not_found | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /admin/sales/rma/reasons/update/{id} | PUT | validation_422 | not_measured | - | - | unknown | not_comparable | The resolution type field is required. \| fields: resolution_type |
| /admin/sales/rma/rma-status/mass-delete | POST | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |
| /admin/sales/rma/rules/delete/{id} | DELETE | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /admin/sales/shipments/view/{id} | GET | exception | not_measured | - | - | unknown | not_comparable | exception |
| /admin/settings/channels/edit/{id} | PUT | validation_422 | not_measured | - | - | unknown | not_comparable | The inventory sources field is required. (and 4 more errors) \| fields: inventory_sources, locales, default_locale_id, currencies, base_currency_id |
| /admin/settings/currencies | GET | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |
| /admin/settings/currencies/edit | PUT | not_found | not_measured | - | - | unknown | not_comparable | not_found |
| /admin/settings/exchange-rates/create | POST | exception | not_measured | - | - | unknown | not_comparable | exception |
| /admin/settings/exchange-rates/edit/{id} | GET | not_found | not_measured | - | - | unknown | not_comparable | not_found |
| /admin/settings/inventory-sources/edit/{id} | GET | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |
| /admin/settings/locales/edit/{id} | DELETE | client_error | not_measured | - | - | unknown | not_comparable | At least one Locale is required. |
| /admin/settings/roles | GET | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |
| /admin/settings/users/edit | PUT | validation_422 | not_measured | - | - | unknown | not_comparable | The password confirmation field must match password. \| fields: password_confirmation |
| /api/categories | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/categories/attributes | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/categories/attributes/{attribute_id}/options | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/categories/tree | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/checkout/cart | DELETE | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/checkout/cart | PUT | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/checkout/cart/coupon | POST | validation_422 | not_measured | - | - | unknown | not_comparable | Coupon code is invalid. |
| /api/checkout/cart/move-to-wishlist | POST | validation_422 | not_measured | - | - | unknown | not_comparable | The ids field is required. (and 1 more error) \| fields: ids, qty |
| /api/checkout/onepage/addresses | POST | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/checkout/onepage/orders | POST | exception | not_measured | targeted:product_attribute_values | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/checkout/onepage/payment-methods | POST | forbidden | not_measured | - | - | unknown | not_comparable | forbidden |
| /api/checkout/onepage/shipping-methods | POST | forbidden | not_measured | - | - | unknown | not_comparable | forbidden |
| /api/compare-items | DELETE | unauthenticated | not_measured | - | - | unknown | not_comparable | unauthenticated |
| /api/compare-items | POST | unauthenticated | not_measured | - | - | unknown | not_comparable | unauthenticated |
| /api/compare-items/all | DELETE | unauthenticated | not_measured | - | - | unknown | not_comparable | unauthenticated |
| /api/core/countries | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/customer/addresses | GET | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /api/customer/addresses | POST | validation_422 | not_measured | - | - | unknown | not_comparable | The address field is required. (and 1 more error) \| fields: address, postcode |
| /api/customer/addresses/allowed-countries | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/customer/addresses/allowed-states | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/customer/wishlist | GET | exception | not_measured | - | - | unknown | not_comparable | exception |
| /api/customer/wishlist | POST | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /api/customer/wishlist/{id} | DELETE | exception | not_measured | - | - | unknown | not_comparable | exception |
| /api/product/{id}/reviews | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/product/{id}/reviews/{review_id}/translate | GET | forbidden | not_measured | - | - | unknown | not_comparable | 403 Forbidden |
| /api/products | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/products/{id}/related | GET | exception | not_measured | - | - | unknown | not_comparable | exception |
| /api/products/{id}/up-sell | GET | exception | not_measured | - | - | unknown | not_comparable | exception |
| /appearance-preview | GET | forbidden | not_measured | - | - | unknown | not_comparable | forbidden |
| /booking-slots/{id} | GET | exception | not_measured | - | - | unknown | not_comparable | exception |
| /cache/{template}/{filename} | GET | unfilled_param | not_measured | - | - | unknown | not_comparable | template: no_rule_to_fill |
| /customer/account | GET | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/addresses/create | GET | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/addresses/create | POST | validation_422 | not_measured | - | - | unknown | not_comparable | The address field is required. (and 1 more error) \| fields: address, postcode |
| /customer/account/addresses/delete/{id} | DELETE | not_found | not_measured | - | - | unknown | not_comparable | not_found |
| /customer/account/addresses/edit/{id} | PATCH | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/addresses/edit/{id} | PUT | validation_422 | not_measured | - | - | unknown | not_comparable | The address field is required. (and 1 more error) \| fields: address, postcode |
| /customer/account/downloadable-products | GET | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/gdpr | GET | not_found | not_measured | - | - | unknown | not_comparable | not_found |
| /customer/account/gdpr | POST | not_found | not_measured | - | - | unknown | not_comparable | not_found |
| /customer/account/gdpr/pdf-view | GET | not_found | not_measured | - | - | unknown | not_comparable | not_found |
| /customer/account/gdpr/revoke/{id} | POST | not_found | not_measured | - | - | unknown | not_comparable | not_found |
| /customer/account/orders/cancel/{id} | POST | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/orders/view/{id} | GET | exception | not_measured | - | - | unknown | not_comparable | exception |
| /customer/account/orders/{orderId}/withdraw | GET | exception | not_measured | - | - | unknown | not_comparable | exception |
| /customer/account/orders/{orderId}/withdraw | POST | exception | not_measured | - | - | unknown | not_comparable | exception |
| /customer/account/profile/destroy | POST | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/profile/edit | GET | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/profile/edit | POST | validation_422 | not_measured | - | - | unknown | not_comparable | The gender field is required. \| fields: gender |
| /customer/account/reviews | GET | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/rma | GET | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/rma/cancel/{id} | POST | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/rma/get-messages | GET | not_found | not_measured | - | - | unknown | not_comparable | not_found |
| /customer/account/rma/get-order-items/{orderId} | GET | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/rma/images/{id} | GET | not_found | not_measured | - | - | unknown | not_comparable | not_found |
| /customer/account/rma/reopen/{id} | POST | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/rma/store | POST | validation_422 | not_measured | - | - | unknown | not_comparable | The resolution type field is required. \| fields: resolution_type |
| /customer/account/wishlist | GET | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/your-cookie-consent-preferences | GET | not_found | not_measured | - | - | unknown | not_comparable | not_found |
| /customer/datagrid/look-up | GET | validation_422 | not_measured | - | - | unknown | not_comparable | The datagrid id field is required. (and 2 more errors) \| fields: datagrid_id, column, search |
| /customer/logout | DELETE | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/resend/verification/{email} | GET | unfilled_param | not_measured | - | - | unknown | not_comparable | email: no_rule_to_fill |
| /customer/social-login/{provider} | GET | unfilled_param | not_measured | - | - | unknown | not_comparable | provider: no_rule_to_fill |
| /customer/social-login/{provider}/callback | GET | unfilled_param | not_measured | - | - | unknown | not_comparable | provider: no_rule_to_fill |
| /customer/verify-account/{token} | GET | unfilled_param | not_measured | - | - | unknown | not_comparable | token: no_rule_to_fill |
| /downloadable/download-sample/{type}/{id} | GET | unfilled_param | not_measured | - | - | unknown | not_comparable | type: no_rule_to_fill |
| /paypal/standard/ipn | POST | external_call_blocked | not_measured | - | - | unknown | not_comparable | hosts: ipnpb.sandbox.paypal.com |
| /payu/redirect | GET | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |
| /phonepe/redirect | GET | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |
| /razorpay/payment/redirect | GET | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |
| /search | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /search/upload | POST | validation_422 | not_measured | - | - | unknown | not_comparable | The image field is required. \| fields: image |
| /sitemap.xml | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /stripe/redirect | GET | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |
| /subscription/{token} | GET | unfilled_param | not_measured | - | - | unknown | not_comparable | token: no_rule_to_fill |
| /webmcp/product | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /withdraw | GET | not_found | not_measured | - | - | unknown | not_comparable | not_found |
| /withdraw/lookup | POST | not_found | not_measured | - | - | unknown | not_comparable | not_found |
| /withdraw/{orderId}/store | POST | forbidden | not_measured | - | - | unknown | not_comparable | forbidden |

## Rows per route (cold attempt)

Two different numbers, not to be mixed up. **Returned rows** are the rows the statements gave back to the app. **Examined rows** are the rows the database looked at to answer them (the growth of the session's `Handler_read_*` counters, with the cost of reading the counters taken off); a statement that scans a table to return one row examined the whole table. `scale: value`, `-` where it could not be measured.

| route | method | returned rows | examined rows |
| --- | --- | --- | --- |
| / | GET | 240000: 1 | 240000: 240007 |
| /admin/appearance/themes/{code}/sections | POST | - | - |
| /admin/catalog/products/edit/{id} | GET | - | - |
| /admin/catalog/products/search | GET | 0: 4, 240000: 4 | 0: 92, 240000: 1200026 |
| /admin/cms/create | POST | - | - |
| /admin/configuration/{slug?}/{slug2?} | GET | 0: 4, 240000: 4 | 0: 137, 240000: 1920035 |
| /admin/customers/addresses/default/{id} | POST | - | - |
| /admin/customers/gdpr/edit/{id} | GET | 240000: 5 | 240000: 1200027 |
| /admin/customers/groups/delete/{id} | DELETE | - | - |
| /admin/customers/login-as-customer/{id} | GET | 240000: 5 | 240000: 1200027 |
| /admin/customers/search | GET | 0: 5, 240000: 18 | 0: 95, 240000: 1680039 |
| /admin/customers/view/{id} | GET | - | - |
| /admin/customers/{id}/addresses | GET | 240000: 5 | 240000: 1200027 |
| /admin/marketing/communications/campaigns | GET | 0: 4, 240000: 4 | 0: 137, 240000: 1920035 |
| /admin/marketing/communications/campaigns/edit/{id} | GET | 240000: 240012 | 240000: 2400059 |
| /admin/marketing/communications/events/create | POST | - | - |
| /admin/marketing/promotions/cart-rules/edit/{id} | GET | - | - |
| /admin/marketing/search-seo/search-synonyms/create | POST | 0: 4, 240000: 4 | 0: 92, 240000: 1200026 |
| /admin/marketing/search-seo/sitemaps/edit/{id} | DELETE | 240000: 6 | 240000: 1200029 |
| /admin/reporting/sales/view | GET | - | - |
| /admin/sales/cart/{id}/shipping-methods | POST | - | - |
| /admin/sales/eu-withdrawals/{id}/resend-confirmation | POST | 240000: 5 | 240000: 1200028 |
| /admin/sales/invoices/create/{order_id} | POST | 240000: 240005 | 240000: 2160028 |
| /admin/sales/invoices/print/{id} | GET | - | - |
| /admin/sales/orders/view/{id} | GET | - | - |
| /admin/sales/rma/custom-fields/update/{id} | POST | - | - |
| /admin/sales/rma/reasons/edit/{id} | GET | 240000: 6 | 240000: 1200029 |
| /admin/sales/rma/reasons/mass-update | POST | 240000: 5 | 240000: 1200027 |
| /admin/sales/rma/reasons/update/{id} | PUT | - | - |
| /admin/sales/rma/rma-status/mass-delete | POST | 0: 4, 240000: 4 | 0: 95, 240000: 1440029 |
| /admin/sales/rma/rules/delete/{id} | DELETE | 240000: 5 | 240000: 1200028 |
| /admin/sales/shipments/view/{id} | GET | - | - |
| /admin/settings/channels/edit/{id} | PUT | - | - |
| /admin/settings/currencies | GET | 0: 4, 240000: 4 | 0: 137, 240000: 1920035 |
| /admin/settings/currencies/edit | PUT | - | - |
| /admin/settings/exchange-rates/create | POST | - | - |
| /admin/settings/exchange-rates/edit/{id} | GET | - | - |
| /admin/settings/inventory-sources/edit/{id} | GET | 0: 5, 240000: 480005 | 0: 144, 240000: 2400042 |
| /admin/settings/locales/edit/{id} | DELETE | - | - |
| /admin/settings/roles | GET | 0: 4, 240000: 4 | 0: 137, 240000: 1920035 |
| /admin/settings/users/edit | PUT | - | - |
| /api/categories | GET | 240000: 33 | 240000: 1200029 |
| /api/categories/attributes | GET | 240000: 33110 | 240000: 33149 |
| /api/categories/attributes/{attribute_id}/options | GET | 240000: 36 | 240000: 496615 |
| /api/categories/tree | GET | 240000: 480002 | 240000: 480011 |
| /api/checkout/cart | DELETE | 240000: 2 | 240000: 5 |
| /api/checkout/cart | PUT | 240000: 1 | 240000: 4 |
| /api/checkout/cart/coupon | POST | - | - |
| /api/checkout/cart/move-to-wishlist | POST | - | - |
| /api/checkout/onepage/addresses | POST | 240000: 1 | 240000: 240007 |
| /api/checkout/onepage/orders | POST | 240000: 1 | 240000: 4 |
| /api/checkout/onepage/payment-methods | POST | - | - |
| /api/checkout/onepage/shipping-methods | POST | - | - |
| /api/compare-items | DELETE | - | - |
| /api/compare-items | POST | - | - |
| /api/compare-items/all | DELETE | - | - |
| /api/core/countries | GET | 240000: 240001 | 240000: 240007 |
| /api/customer/addresses | GET | 240000: 80001 | 240000: 240007 |
| /api/customer/addresses | POST | - | - |
| /api/customer/addresses/allowed-countries | GET | 240000: 240001 | 240000: 480010 |
| /api/customer/addresses/allowed-states | GET | 240000: 240001 | 240000: 480010 |
| /api/customer/wishlist | GET | - | - |
| /api/customer/wishlist | POST | 240000: 4 | 240000: 10 |
| /api/customer/wishlist/{id} | DELETE | - | - |
| /api/product/{id}/reviews | GET | 240000: 27 | 240000: 480041 |
| /api/product/{id}/reviews/{review_id}/translate | GET | - | - |
| /api/products | GET | 240000: 215 | 240000: 14361602 |
| /api/products/{id}/related | GET | - | - |
| /api/products/{id}/up-sell | GET | - | - |
| /appearance-preview | GET | - | - |
| /booking-slots/{id} | GET | - | - |
| /cache/{template}/{filename} | GET | - | - |
| /customer/account | GET | 240000: 10 | 240000: 4800082 |
| /customer/account/addresses/create | GET | 240000: 480010 | 240000: 6480103 |
| /customer/account/addresses/create | POST | - | - |
| /customer/account/addresses/delete/{id} | DELETE | - | - |
| /customer/account/addresses/edit/{id} | PATCH | 240000: 1 | 240000: 80006 |
| /customer/account/addresses/edit/{id} | PUT | - | - |
| /customer/account/downloadable-products | GET | 240000: 10 | 240000: 4800082 |
| /customer/account/gdpr | GET | - | - |
| /customer/account/gdpr | POST | - | - |
| /customer/account/gdpr/pdf-view | GET | - | - |
| /customer/account/gdpr/revoke/{id} | POST | - | - |
| /customer/account/orders/cancel/{id} | POST | 240000: 240002 | 240000: 960006 |
| /customer/account/orders/view/{id} | GET | - | - |
| /customer/account/orders/{orderId}/withdraw | GET | - | - |
| /customer/account/orders/{orderId}/withdraw | POST | - | - |
| /customer/account/profile/destroy | POST | 240000: 2 | 240000: 5 |
| /customer/account/profile/edit | GET | 240000: 11 | 240000: 4800083 |
| /customer/account/profile/edit | POST | - | - |
| /customer/account/reviews | GET | 240000: 22 | 240000: 5280101 |
| /customer/account/rma | GET | 240000: 10 | 240000: 4560079 |
| /customer/account/rma/cancel/{id} | POST | 240000: 3 | 240000: 7 |
| /customer/account/rma/get-messages | GET | - | - |
| /customer/account/rma/get-order-items/{orderId} | GET | 240000: 2 | 240000: 9 |
| /customer/account/rma/images/{id} | GET | - | - |
| /customer/account/rma/reopen/{id} | POST | 240000: 5 | 240000: 8 |
| /customer/account/rma/store | POST | - | - |
| /customer/account/wishlist | GET | 240000: 10 | 240000: 4800082 |
| /customer/account/your-cookie-consent-preferences | GET | - | - |
| /customer/datagrid/look-up | GET | - | - |
| /customer/logout | DELETE | 240000: 1 | 240000: 5 |
| /customer/resend/verification/{email} | GET | - | - |
| /customer/social-login/{provider} | GET | - | - |
| /customer/social-login/{provider}/callback | GET | - | - |
| /customer/verify-account/{token} | GET | - | - |
| /downloadable/download-sample/{type}/{id} | GET | - | - |
| /paypal/standard/ipn | POST | - | - |
| /payu/redirect | GET | 0: 0, 240000: 0 | 0: 27, 240000: 480006 |
| /phonepe/redirect | GET | 0: 0, 240000: 0 | 0: 39, 240000: 720009 |
| /razorpay/payment/redirect | GET | 0: 0, 240000: 0 | 0: 48, 240000: 720009 |
| /search | GET | 240000: 1 | 240000: 240008 |
| /search/upload | POST | - | - |
| /sitemap.xml | GET | 240000: 720001 | 240000: 480006 |
| /stripe/redirect | GET | 0: 0, 240000: 0 | 0: 39, 240000: 720009 |
| /subscription/{token} | GET | - | - |
| /webmcp/product | GET | 240000: 1 | 240000: 4 |
| /withdraw | GET | - | - |
| /withdraw/lookup | POST | - | - |
| /withdraw/{orderId}/store | POST | - | - |
