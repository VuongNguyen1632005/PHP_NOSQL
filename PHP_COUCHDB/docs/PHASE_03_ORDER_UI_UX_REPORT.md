# PHASE 03 – ORDER & DELIVERY UI/UX

## 1. Existing UI review

- Customer history and order detail already existed, including order status history and a delivery-tracking include. The delivery include showed both a milestone checklist and a second event list, but did not make failed-delivery events prominent.
- Admin order list showed order ID, customer, date, quantity and total, but omitted payment state, delivery state and tracking code.
- Admin detail showed products and totals, recipient, payment, status actions and delivery actions. It omitted unit price and delivery event history, used an English heading, and put related details into dense, hard-to-scan markup.
- Order snapshots do not contain a product image. Checkout captures product name, variant/size, quantity, unit price and line total. Order detail therefore cannot show a historically correct image without changing the document model or looking up mutable catalog data.
- Order views use Bootstrap and `public/assets/css/catalog.css`. No order-specific JavaScript was found; the shared layout loads `catalog.js` for other storefront behavior.

## 2. UX problems found

- Admin list did not expose payment/delivery state or tracking code at a glance.
- Detail views lacked unit price and clear separation among order, payment, shipping and delivery details.
- Delivery milestones and event history duplicated one another; failed delivery/retry was easy to miss.
- Legacy orders with no order history could render an empty timeline, and several missing fields appeared blank.
- Admin delivery heading was English; internal status codes could be used as fallbacks in unknown states.
- Order forms and tables needed clearer narrow-screen behavior and keyboard focus visibility.

## 3. Changes implemented

- Added Vietnamese payment and delivery labels and text-bearing status badges.
- Improved order list cards, detail sections, empty states, missing-value copy, form labels, table headers, alternative text and focus indicators.
- Rendered delivery history from stored events only. Failed events receive a warning symbol and their actual note; retries remain separate dated history entries. No timestamp or order event is synthesized.
- Kept the order and delivery timelines distinct. Customer views omit staff assignment and update controls.
- Preserved server-provided valid transition options, CSRF fields, existing form routes and ownership/auth guards.
- Did not add an estimated-date input: the existing update endpoint does not accept it. Existing estimated dates are displayed.
- Added HTTP smoke assertions for all five requested HTML pages, PHP warning output, missing-tracking text, staff-data privacy, tracking/status fields, and duplicate delivery forms.

## 4. Admin Order List

- Shows order ID, customer, date, quantity, total, payment status, delivery status and tracking code when present.
- Keeps the existing backend status filter and cursor pagination. No query/index changes were needed.
- Uses an explicit “Chưa có thông tin giao hàng” badge when older orders have no delivery tracking.

## 5. Admin Order Detail

- Separates order/payment summary, customer/recipient, shipping, update actions and delivery management.
- Product table has image placeholder, product, size, quantity, unit price and line total. The accessible placeholder explains that the order did not save an image.
- Shows subtotal, discount, shipping fee and grand total.
- Shows assigned staff, tracking details and delivery history. The staff identifier is admin-only.
- Status selects include only server-provided valid transitions; update forms retain CSRF tokens.

## 6. Customer Order History

- Cards show order ID, date, item count, total and payment status, plus delivery status when a tracking document exists.
- Existing ownership scope and cursor pagination remain unchanged.

## 7. Customer Order Detail

- Separates recipient, products/totals, payment and shipping, order history and delivery tracking.
- Shows item size, quantity and captured unit price. No staff ID or admin update form is rendered.
- Legacy orders without tracking show a plain no-tracking message; legacy shipping tracking fields remain readable when present.

## 8. Order Timeline

- Uses only `status_history[]`, with actual timestamps. Empty/legacy history gets an explanatory message instead of an empty list.
- Customer rendering omits source and staff attribution.

## 9. Delivery Timeline

- Uses only `delivery_tracking.history[]`, showing real date/time and note for each event.
- Failed delivery is labeled “Giao hàng thất bại” with a warning mark; a later retry appears as its own subsequent event.
- The current tracking code, delivery status, estimated date and actual delivered time are shown separately. No-tracking and empty-history states are explicit.

## 10. Responsive changes

- Added scoped small-screen rules for stacked filters, compact cards, single-column detail facts, wrapping badges and horizontally scrollable product tables.
- Verified populated customer and admin pages at desktop (1280px browser viewport) and mobile (390px browser viewport). Desktop document width was 1265px; at 390px the document/client width was 375px, with no horizontal page overflow.
- Browser testing reproduced an authenticated mobile header that stacked too tall. The compact mobile header rule now keeps the brand, primary catalog navigation, the relevant orders link and sign-out control on one row; the verified header was about 67px tall and remained within the viewport.
- At 390px the product table scrolls within its own wrapper (660px table inside a 298px wrapper) without expanding the page. Order and delivery facts, timelines, filters and delivery forms remained readable and usable.

## 11. Accessibility changes

- Added explicit labels, semantic table headers/caption, section headings, `aria-label` on timelines/pagination, accessible image-placeholder text and decorative-icon hiding.
- Status is always written as text as well as color. Keyboard focus has a visible outline.
- Responsive product table retains headers and can scroll horizontally.

## 12. Files modified

- `templates/admin/orders/index.php`
- `templates/admin/orders/detail.php`
- `templates/orders/index.php`
- `templates/orders/detail.php`
- `templates/orders/delivery_timeline.php`
- `public/assets/css/catalog.css`
- `scripts/http_smoke_checkout.php`
- `scripts/smoke_checkout.php`
- `docs/PHASE_03_ORDER_UI_UX_REPORT.md`

The temporary browser fixture helper and all five test account/order documents were removed after verification.

## 13. Tests

- `php -l` passed for all modified PHP templates and smoke scripts in the app container.
- `composer checkout:smoke` — PASS, including tracked, failed/retried and delivered customer rendering, ownership privacy, and legacy compatibility.
- `composer checkout:http-smoke` — PASS, including admin list/detail, customer history/detail, guest history/detail, warning checks, CSRF form rendering, and the no-tracking state.
- `composer catalog:admin-smoke` — PASS.
- `composer auth:jwt-smoke` — PASS.
- `composer couchdb:diagnostics` — PASS (read-only diagnostics).
- After the Phase 03B browser-only UI fix: `php -l scripts/http_smoke_checkout.php`, `php -l scripts/smoke_checkout.php`, `composer checkout:smoke` and `composer checkout:http-smoke` — PASS. The HTTP smoke also confirms that an invalid delivery CSRF submission shows the user-facing error banner and does not update the order.

## 14. Manual browser checks

Real-browser verification used temporary customer and manager fixtures in the `_test` database. All fixtures were removed after the checks. The invalid-CSRF error feedback is additionally verified by the HTTP smoke test.

```text
CUSTOMER
[x] Order history desktop
[x] Order detail desktop
[x] Order detail 390px
[x] Order timeline
[x] Delivery timeline
[x] Old order without tracking
[x] Failed delivery
[x] Delivered

STAFF / MANAGER
[x] Admin orders
[x] Admin order detail
[x] Admin order detail 390px
[x] Order transition
[x] Delivery transition
[x] Failed delivery
[x] Retry delivery
[x] Delivered
[x] Success/error messages (success in browser; invalid-CSRF error banner in HTTP smoke)

- Desktop checks used a 1280px viewport; mobile checks used 390px. At mobile width there was no document-level horizontal overflow, although the product table intentionally scrolls inside its wrapper.
- Customer history and detail showed product name, size, quantity and captured unit price. Customer views did not expose staff assignment or admin controls.
- Staff verified filtering, order transitions through shipping, auto-created tracking information, delivery failure, retry, and delivery completion in the real admin form. Success feedback appeared after updates.
```

## 15. Bugs found during UI verification

- Reproduced: the shared authenticated header stacked into a tall column at 390px, consuming a large part of the first screen. Fixed with a scoped compact mobile header rule in `public/assets/css/catalog.css`; verified the order/admin navigation and sign-out remain visible without horizontal overflow.
- No backend or business-logic bug was found. Delivery/order transitions, tracking, histories and ownership rendered as expected.

## 16. Remaining issues

- Product images are not stored in order snapshots; this phase shows an accessible placeholder rather than querying a product whose image may have changed since purchase.
- The UI uses existing English enum values only as backend form values; displayed labels are Vietnamese.
- Snapshot images can be considered as a later P3 improvement if the product requires historically accurate images; no schema change was made for this phase.

## 17. Ready for Phase 4?

**YES — all Phase 03B customer/admin browser checklist items passed, including populated 390px details, order/delivery transitions and error feedback. The responsive header issue was fixed and the required checkout smoke tests passed afterward.**
