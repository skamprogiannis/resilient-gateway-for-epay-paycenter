=== Resilient Gateway for ePay Paycenter ===
Contributors: skamprogiannis, webhosting4ugr
Tags: woocommerce, payment gateway, epay, iris, greece
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept WooCommerce payments through ePay Paycenter, with authenticated callbacks and optional recovery when a response is missing.

== Description ==

Resilient Gateway for ePay Paycenter connects WooCommerce to the hosted ePay
Paycenter Redirection service operated by Piraeus Bank and Euronet Merchant
Services.

The plugin:

* issues a single-use transaction ticket and redirects the customer to the
  hosted Paycenter payment page;
* verifies the HMAC-SHA256 HashKey before completing an order from a successful
  callback;
* keeps concurrent payment attempts separate and handles repeated callbacks
  idempotently;
* recognises card, IRIS, and eligible Google Pay responses returned by
  Paycenter;
* places an initiated but unconfirmed IRIS payment on hold instead of inviting
  the customer to pay twice;
* optionally queries the ePay `FOLLOW_UP` Web Service when the normal callback
  is missing;
* supports HPOS, classic checkout, and WooCommerce Checkout Blocks; and
* provides callback diagnostics and compatibility with callback URLs from the
  Papaki `WC_Piraeusbank_Gateway` plugin while that plugin is inactive.

All transactions use Sale. The plugin does not implement preauthorization
completion or Paycenter refunds. Installment selection is available on classic
checkout when enabled by the merchant agreement; Checkout Blocks uses one
payment (`Installments=1`).

**Requirements:** You need an ePay Paycenter merchant agreement and the
environment-specific credentials issued by Euronet Merchant Services. This
plugin does not provide a Paycenter account or shared test credentials.

= Independent project and trademarks =

This is independent GPL-2.0-or-later software maintained by Stephanos Kamprogiannis and
based on the WebHosting4U plugin. It is not affiliated with, endorsed by, or
sponsored by Piraeus Bank S.A., Euronet Merchant Services, WebHosting4U,
WooCommerce, or Automattic Inc.

“ePay”, “Paycenter”, “Piraeus Bank”, “Google Pay”, and “WooCommerce” are
trademarks of their respective owners and are used only to identify compatible
services. Upstream attribution and modification history are preserved in the
bundled notices. No third-party payment-brand artwork is bundled.

== External services ==

The plugin communicates with the following services. Review the applicable
merchant agreement and privacy terms before enabling the gateway.

= 1. ePay Paycenter Ticketing Web Service =

* Endpoint: `https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx`
* Purpose: obtain the single-use `TranTicket` required to start and authenticate
  a payment attempt.
* Data sent: AcquirerId, MerchantId, PosId, username, the bank-required MD5
  password digest, MerchantReference, amount, numeric currency code, Sale
  request type, installment count, and 3-D Secure fields derived from the
  WooCommerce order. Those 3-D Secure fields can include the customer's name,
  email, billing and shipping addresses, and phone number.
* When: when WooCommerce prepares the order-pay redirect.

The password digest and TranTicket are reusable or one-time authentication
secrets and must be protected like credentials. They are not sent to the
customer's browser or written to the plugin log.

= 2. ePay Paycenter hosted payment page =

* Endpoint: `https://paycenter.piraeusbank.gr/redirection/pay.aspx`
* Purpose: collect payment details and complete the bank-hosted payment flow.
* Data sent by the redirect form: merchant identifiers, language,
  MerchantReference, and a per-attempt backlink. Payment-card credentials
  (PAN, CVV, and expiry) are entered on the hosted page and are not processed
  by this plugin.
* When: after Ticketing returns a valid TranTicket.

Paycenter returns the result to the merchant callback URL. The canonical
endpoint is `https://example.com/?wc-api=epay_paycenter`; use the exact values
shown on the gateway settings page for the Notification, Success, Failure, and
Backlink fields supplied during onboarding.

= 3. ePay Transaction Web Service (FOLLOW_UP) =

* Endpoint: `https://paycenter.piraeusbank.gr/services/paymentgateway.asmx`
* Purpose: query the final result for an issued MerchantReference when the
  normal callback was not received.
* Data sent: MerchantId, PosId, username, MD5 password digest, AcquirerID
  `GR014`, the verified ChannelType, and MerchantReference.
* When: during an administrator's read-only channel test and, only after
  separate opt-in, for unresolved attempts for up to 48 hours.

The plugin accepts a recovered success only when the response echoes the
expected merchant, POS, user, channel, and MerchantReference and contains a
complete approved result.

= 4. Cloudflare IPv4 list =

* Endpoint: `https://www.cloudflare.com/ips-v4/`
* Purpose: show the current Cloudflare IPv4 ranges to an administrator who is
  configuring callback firewall rules.
* Data sent: a normal HTTPS GET and its network metadata, including the server's
  source IP and the plugin User-Agent. No order, customer, or merchant
  credential is included.
* When: only on the gateway settings screen when Cloudflare is detected and the
  cached list has expired. The result is cached for 12 hours.

Third-party terms and privacy information:

* Euronet Merchant Services terms: https://www.epaygreece.gr/oroi-xrisis/
* Euronet Merchant Services privacy: https://www.epaygreece.gr/politiki-aporritou/
* Piraeus Bank privacy: https://www.piraeusbank.gr/en/idiwtes/protection-of-personal-data
* Cloudflare privacy: https://www.cloudflare.com/privacypolicy/
* Cloudflare terms: https://www.cloudflare.com/terms/

== Data and privacy ==

The plugin contains no analytics, advertising, telemetry, or behavioural
tracking code and does not set its own browser cookies. Transactional traffic
and storage are still part of the merchant's payment processing and privacy
responsibilities.

WooCommerce orders store operational payment fields such as MerchantReference,
result and response codes, support and transaction IDs, approval data, payment
method, and timestamps. Open attempts temporarily retain a TranTicket digest or
secret material required to authenticate the response; one-time ticket and
cancel secrets are removed after verified settlement.

When missing-response recovery is enabled, the attempt table also stores its
query state, retry times, and selected non-card result fields. Resolved attempt
rows are pruned after 90 days by default; unresolved rows remain for manual
review. Historical order metadata is retained with the WooCommerce order, even
if the plugin is uninstalled.

WooCommerce logs can include transaction identifiers, order IDs, result codes,
and callback request metadata such as request method and field names. Passwords,
TranTickets, HashKeys, and likely card numbers are redacted. Control log
retention through WooCommerce and enable detailed logging only while needed.

Merchants should document this processing in their privacy and retention
policies and restrict access to orders, logs, backups, and AdminTool records.

== Installation ==

1. Upload the release archive through **Plugins → Add New Plugin → Upload
   Plugin**, then activate it.
2. Open **WooCommerce → Settings → Payments → ePay Paycenter**.
3. Enter the credentials for the selected Test or Live environment.
4. Register the integration values displayed by the plugin with Euronet
   Merchant Services. Confirm the site's current URL and outbound server IP
   with the hosting provider.
5. Run **Test callback URL**. The test checks loopback reachability and common
   host-WAF interception; it does not prove that Paycenter itself can deliver a
   callback.
6. Complete the test cases required by the Paycenter onboarding documentation
   before enabling live payments.
7. If missing-response recovery is required, verify the ChannelType with a
   known successful ePay order. The test is read-only. Enable automatic
   recovery in a later settings save only after verification succeeds.

When migrating from Papaki, deactivate the Papaki plugin before relying on its
legacy callback URL. Do not switch plugins while a payment is in progress; the
response must return to the plugin that issued its ticket.

Version 2.0.0 also uses a new public package folder. To replace an earlier
private build, let in-flight payments return, deactivate the old package, then
install and activate this release. Do not activate both. The stable
`epay_paycenter` gateway ID preserves existing settings and payment records;
verify the environment, credentials, callback, and recovery status before
removing its folder through the hosting file manager or shell. Do not use
WordPress Admin's Delete action: the old uninstaller removes settings and
attempt records shared with this version.

== Frequently Asked Questions ==

= Does the merchant server process card details? =

The plugin sends order, customer-contact, and 3-D Secure address fields to the
Ticketing service. PAN, CVV, and card expiry are entered on Paycenter's hosted
page and are not processed by this plugin.

= What happens when Paycenter does not send a callback? =

Without automatic recovery, the plugin cannot infer whether the customer
abandoned payment or the response was lost. Staff must verify the
MerchantReference in the ePay AdminTool.

After an administrator verifies and enables `FOLLOW_UP`, the plugin performs
bounded checks for up to 48 hours. Only an exact approved bank result calls
WooCommerce's normal `payment_complete()` path. Late, detached, unresolved,
locally unpersisted, or possibly duplicated payments produce a review case.
The order-screen panel separates confirmed payment discrepancies, technical
check problems, unconfirmed attempts, and Historical checks. It also shows
queued attempts and the last recovery run. An empty review queue is not proof
that all payments were checked. Mark reviewed records acknowledgement without
changing payment or order data.

= How long is stock reserved? =

When automatic recovery is active, stock is reserved for 240 minutes by
default. The setting accepts 60 to 1440 minutes. Bank checks continue for up to
48 hours, so a payment recovered after cancellation requires a stock and
fulfilment review.

= Why is an IRIS order on hold? =

IRIS ResponseCode `09` means the transfer was initiated but is not final. The
plugin places the order on hold and tells the customer not to pay again. Do not
fulfil or cancel solely from that intermediate response; verify the final bank
status. Paycenter refunds for IRIS are not implemented by this plugin.

= Do installments work in Checkout Blocks? =

Checkout Blocks currently uses a one-time payment (`Installments=1`). The
configurable installment selector is available only in classic checkout.

= Can I keep callback URLs registered for the Papaki plugin? =

Yes, while the Papaki plugin is inactive. The compatibility adapter accepts the
historical `WC_Piraeusbank_Gateway` route and forwards it to the same verified
handler. A bare cancel query is not sufficient; cancellation also requires the
per-attempt order ID and token generated by this plugin.

= How do I report a bug? =

Use https://github.com/skamprogiannis/resilient-gateway-for-epay-paycenter/issues
for reproducible reports that contain no credentials, customer data, or live
transaction identifiers. Contact Euronet Merchant Services for merchant-account,
settlement, or AdminTool questions.

= How do I report a security vulnerability? =

Do not open a public issue. Use GitHub private vulnerability reporting:
https://github.com/skamprogiannis/resilient-gateway-for-epay-paycenter/security/advisories/new

== Changelog ==

= 2.1.0 =

* Preserve valid installment selections across classic-checkout refreshes and explain unavailable choices.
* Configure bank rechecks from 1–168 hours (default: 48), affecting new and ongoing attempts without reopening expired or reviewed cases. Existing limited technical-error retries remain.
* Combine verification and recovery settings in one translated card. Stock reservation remains independent.
* Display the author as Stephanos Kamprogiannis.

= 2.0.2 =
* Separate payment discrepancies, technical problems, and unconfirmed attempts; retain Historical checks and review acknowledgements.
* Show queued attempts and recovery-run status without treating an empty review queue as proof of payment verification.
* Report delayed, unscheduled, or unreadable checks, with responsive English/Greek controls on order and gateway screens.
* Preserve payment decisions, retry timings, stock handling, credentials, and callback URLs.

= 2.0.1 =
* Keep ambiguous FOLLOW_UP Failure/09 responses retryable and resume checks closed by older versions.
* Return cancelled payments to checkout with one notice, without changing the customer's cart.
* Limit staff reviews to orders and gateway settings; separate historical checks and retain acknowledgements.
* Log concise per-reference bank outcomes without raw responses or customer contact fields.

= 2.0.0 =

* Adopt the public project identity and package folder while preserving the
  `epay_paycenter` gateway ID, settings, order metadata, and attempt table.
* Add public operations, protocol, security, contribution, provenance, and
  changelog documentation.
* Consolidate credential normalization, strengthen development-time type and
  static-analysis checks, and remove unused or stale code and presentation
  rules.
* Require authenticated callbacks before modifying an order and preserve bank
  approvals across failures to complete the WooCommerce order locally.
* Register Checkout Blocks at its payment-registry event and preserve the
  canonical transaction when retrying a second approved attempt.

= 1.0.37.2 =

* Refresh the verified `FOLLOW_UP` channel in the settings card immediately
  after the read-only test succeeds.

= 1.0.37.1 =

* Add opt-in missing-response recovery using the ePay `FOLLOW_UP` Web Service.
* Validate merchant identity, channel, MerchantReference, approval fields, and
  transaction identifiers before completing an order.
* Add bounded retry, stock-reservation, late-payment, local-settlement, and
  possible-duplicate-payment handling.

= 1.0.37 =

* Improve 3-D Secure phone-field mapping and add the
  `epay_paycenter_3ds_fields` extension point.
* Add credential-failure and unresolved-attempt administrator warnings.
* Keep initiated IRIS ResponseCode `09` orders on hold.
* Correct concurrent cancel-token matching, gateway logging, and outbound-IP
  guidance.

The complete history is maintained in `CHANGELOG.md` in the source repository.

== Upgrade Notice ==

= 2.0.0 =

This release uses a new public package folder. Drain in-flight payments,
deactivate the earlier private package, install this release, and confirm the
preserved settings. Keep the inactive package for rollback, then remove its
folder through hosting tools. Never use WordPress Admin's Delete action for
the previous package: its uninstaller removes shared settings and payment
attempts. Never activate both packages together.
