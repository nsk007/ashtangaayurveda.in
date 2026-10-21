# Ayurveda Store Core

Store-specific onboarding and notification policy for Ashtanga Ayurveda. The plugin is intentionally inactive until installed and activated by a site administrator. It does not modify WooCommerce, theme, product, order, or customer data when loaded.

## Current phase

Phases 2-3 provide the provider-neutral core and a passwordless OTP onboarding path using Fast2SMS's documented server API. The current code also includes inactive Razorpay/Shiprocket package installs, a conditional Razorpay gateway-ID adapter, and Phase 6 SMS/email transports with asynchronous transactional SMS hooks. The custom plugin remains inactive and has not changed live store settings. Fast2SMS credentials are read only from server-side constants; provider selectors and DLT template IDs are non-secret settings.

## Architecture boundaries

- OTP adapters own delivery and verification with a provider-issued challenge. The onboarding flow owns rate limits, verified-identifier lookup, secure customer creation, and WooCommerce session handling. OTP values are never stored.
- SMS and email adapters handle business-specific notifications only. WooCommerce remains responsible for its native order emails; email delivery is configured through the WordPress mail transport.
- Payment adapters identify a registered WooCommerce gateway only. The official gateway plugin owns payment UI, credentials, callbacks, webhooks, refunds, and order payment state.
- Shipping adapters may check product-page PIN serviceability. A registered WooCommerce shipping method owns checkout rates and recalculation; shipment creation and tracking stay with the shipping plugin.
- Provider adapters register on `ashtanga_store_core_register_providers` and must implement the contract matching their capability. Selection of an absent adapter returns a `WP_Error`; there is no silent fallback.

## Proposed implementation phases

1. Inspect the current store and establish compatibility constraints.
2. Add the modular plugin foundation, contracts, provider registry, settings, and documentation.
3. Implement mandatory customer accounts and passwordless OTP onboarding while preserving the cart and Checkout Block.
4. Install the official Razorpay WooCommerce gateway and its thin adapter. Sandbox activation and checkout remain pending test keys and WooCommerce 11.2 compatibility verification.
5. Keep Shiprocket inactive until its rate-removal, Classic-vs-Block COD recalculation, product PIN behavior, package-data gaps, and compatibility concerns are resolved.
6. Add Fast2SMS OTP and DLT SMS adapters plus a WordPress mail transport; WooCommerce continues to own native order emails. Configure approved templates and SMTP externally.
7. Add idempotency, redacted logging, privacy handling, and failure paths. SMS delivery is async and deduplicated; automatic retries are disabled where provider acceptance is ambiguous.
8. Run sandbox/browser/store tests and publish a launch checklist. The custom plugin remains inactive until Fast2SMS credentials are configured, to avoid blocking current checkout with no verifier.

## Configuration and launch prerequisites

- The npm `studio` shim points to a missing `wp-studio` module. The bundled Studio launcher works; use the Studio app's `studio.bat` for runtime checks.
- Razorpay's official 4.8.8 plugin is installed locally but intentionally inactive. Source declares Checkout Block and HPOS support and verifies webhook signatures; however, the plugin metadata is tested through WooCommerce 10.6.2, below this site's 11.2.0. Its gateway defaults enabled, so do not activate until test keys are ready and `woocommerce_razorpay_settings[enabled]` is explicitly set to `no`. Then activate, verify COD remains available, run sandbox tests, and enable Razorpay only after compatibility is proven. No payment adapter processes payments; the registered adapter merely identifies WooCommerce's `razorpay` gateway ID.
- Shiprocket 2.0.9 is installed locally but intentionally inactive. It registers a standard `WC_Shipping_Method`, but does not declare Checkout Block compatibility. Its COD/prepaid rate refresh is hooked to Classic Checkout's `woocommerce_checkout_update_order_review`; do not assume that refresh occurs in Store API checkout.
- Shiprocket's `woocommerce_package_rates` filter removes all other shipping rates whenever a Shiprocket rate exists. Activating it as-is would remove the store's existing free-shipping method above ₹499. Do not activate until the free-shipping business rule is preserved and explicitly tested.
- Shiprocket's native product PIN widget calls its provider endpoint directly from inline JavaScript, always requests COD, accepts non-six-digit input, sends only the current product weight, displays an EDD but not a shipping charge, and uses `.html()` for provider EDD content. The method's package formatter also divides an undefined `$weight` variable when the WooCommerce weight unit is grams. These are third-party source findings; do not edit that plugin. A server-side supported adapter/UI or a verified upstream fix is required before relying on the product PIN flow.
- Shiprocket requires a configured pickup address, serviceable account, and verified product weights/dimensions. All 23 existing products currently have no weight or dimensions. Do not invent package values, fallback charges, or delivery estimates.
- Fast2SMS requires an API key, a configured Smart OTP ID, and approved Indian DLT sender ID/templates for SMS. Define `ASHTANGA_FAST2SMS_API_KEY`, `ASHTANGA_FAST2SMS_OTP_ID`, and `ASHTANGA_FAST2SMS_SENDER_ID` in the server environment / `wp-config.php`; never commit their values. The custom OTP adapter uses documented `/dev/otp/send`, `/dev/otp/resend`, and `/dev/otp/verify`; the notification adapter uses `/dev/bulkV2` with DLT templates. The official Fast2SMS plugin advertises OTP/order notifications, but its documented Checkout OTP is Classic Checkout only and its WooCommerce test ceiling is 9.9, so this implementation uses the verified API directly. The API still requires sandbox/mock testing before production.
- Configure the email provider outside this plugin. Set a consistent sender identity and publish SPF, DKIM, and DMARC records for the sending domain.
- Age is not collected unless the business documents a legal, product-safety, or operational requirement and an appropriate retention policy.

## OTP policy defaults

The administrator settings page bounds and stores OTP policy values: five-minute expiry, 30-second resend cooldown, three sends per phone per ten minutes, ten sends per IP per ten minutes, 300 sends per site per hour, five verification attempts per challenge, and 30 verification attempts per IP per ten minutes. The Fast2SMS API receives the configured expiry rounded up to minutes; the local challenge expires at the configured second boundary. Rate-limit identifiers are HMAC-hashed, and resend cooldown is enforced server-side.

## Notification delivery

- WooCommerce continues to own payment/order/refund emails. The `wordpress-mail` adapter calls `wp_mail()` only for business-specific templates provided through `ashtanga_store_core_email_notification`; SMTP or a transactional mail plugin can own the transport.
- Transactional SMS is opt-in per event by leaving its DLT template ID blank by default. Configure numeric approved template IDs for processing, completed, or COD-on-hold notices under the plugin's Transactional SMS settings. The default variable order is order number then customer first name; the approved DLT template must match, or a site adapter must filter `ashtanga_store_core_sms_variables`.
- Order-status SMS runs asynchronously through Action Scheduler, with WP-Cron as fallback. Order meta prevents duplicate queueing/sending for each event. Failures log only order ID, event, and error code. Fast2SMS `/dev/bulkV2` does not expose an idempotency key, so automatic retries are disabled to avoid duplicate customer texts after an ambiguous timeout. Manual retry tooling remains a launch follow-up.
- DLT principal entity, approved sender ID, content templates, variable order, and account balance must be configured by the merchant. Promotional messages are not generated by these hooks.

## Customer account behavior

- Guest checkout is forced off at runtime without rewriting the existing WooCommerce option. WooCommerce's Store API registration-required check rejects anonymous Checkout Block requests; Classic Checkout also adds a server-side validation error.
- Unauthenticated shoppers are sent to the My Account page with the cart/session preserved. OTP verification creates a random-login WooCommerce customer account or logs in an existing verified account.
- An existing legacy billing phone is bound only when it matches exactly one user with a paid order carrying that phone. Ambiguous/unproven matches and staff accounts are rejected; the recovery path is customer care.
- New users receive an opaque username, an undisclosed random password, and a non-routable placeholder WordPress email until a verified account email workflow is added. The entered email is saved as WooCommerce billing email and marked `ashtanga_email_verified=no`; it is not used for authentication or password recovery.
- Customers must complete first/last name, email, and an Indian delivery address with a six-digit PIN before checkout. Billing and shipping details are stored in WordPress user meta used by WooCommerce. Age is not requested.

## Repeatable onboarding test plan

Use a mocked HTTP transport or a dedicated Fast2SMS test account and test recipient only; the implementation session did not send any SMS.

1. With API constants missing, request an OTP and verify the public response is generic, no OTP is sent, and the admin sees the configuration warning.
2. With a mocked successful send, request a code, check that the challenge transient contains only a phone hash, expiry, and purpose, then verify a wrong code and an expired challenge.
3. Verify resend cooldown, per-phone/IP/site send caps, per-challenge/IP verification caps, and provider timeout/rejection behavior.
4. Verify a correct code creates one customer with a random username, marks the email unverified, sets WordPress and WooCommerce sessions, and preserves the cart through profile completion.
5. Repeat the same verified phone concurrently; confirm one account is bound. Verify ambiguous legacy matches and staff accounts are rejected.
6. Post directly to the Store API checkout route without authentication and expect WooCommerce's `403` guest-checkout rejection. Also test classic `?wc-ajax=checkout` and direct checkout URL access.
7. Complete profile details, confirm billing/shipping fields and the unverified email marker, then verify the checkout block prepopulates the saved address.

Live OTP, Razorpay payment, Shiprocket rates, email delivery, and order notification tests remain pending; no production SMS or payment was triggered.

## Data and privacy

The plugin stores provider IDs and bounded security settings only in the WordPress options API. Provider secrets, OTP values, payment credentials, and full provider payloads must not be stored or logged here. Customer phone, email, and delivery data remain in WooCommerce customer/order records for account access and fulfillment. The plugin registers WordPress personal-data export/erasure callbacks for its phone-verification hash and onboarding flags; WooCommerce remains responsible for its order/address retention and erasure rules. Retention and legally required order retention must be reviewed before launch.

No regulatory compliance is guaranteed by this plugin. Merchant account approval, DLT registration, email DNS authentication, consent, and operational retention are the store owner's responsibility.