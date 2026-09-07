=== WebHosting4U Secure Card Gateway for ePay Paycenter (Piraeus Bank) ===
Contributors: webhosting4ugr
Tags: woocommerce, payment gateway, piraeus bank, greece, credit card
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.37
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent WooCommerce gateway by WebHosting4U for the ePay Paycenter (Piraeus Bank / Euronet Merchant Services) Redirection service.

== Description ==

= What sets this plugin apart =

* **HPOS-native from day one.** Built against WooCommerce's High-Performance Order Storage from the very first release. All order metadata uses the HPOS-aware `$order->update_meta_data()` / `get_meta()` API — never the legacy `update_post_meta()` / `get_post_meta()` calls that silently fail on HPOS-enabled stores (the WooCommerce default for new installs since 8.x). Your transaction IDs, support reference IDs, and approval codes are preserved whichever storage mode you run.

* **Cloudflare-aware.** Automatically detects when your store is served through Cloudflare (via the `CF-Ray` / `CF-Connecting-IP` request headers) and surfaces the current Cloudflare IPv4 CIDR ranges right in the gateway settings page, ready to copy. You hand them to Euronet Merchant Services so the bank's callbacks are not blocked at their firewall when they arrive via Cloudflare edge IPs. Live list fetched from cloudflare.com/ips-v4 and cached for 12 hours.

* **Built-in WAF / callback self-test.** A diagnostic button in the gateway settings sends a realistic, *declined-transaction*-shaped POST to your own callback URL via loopback and reports whether your host's web-application firewall (cPFence, ModSecurity / OWASP CRS, Imunify360, BitNinja, LiteSpeed WAF) silently blocks it before PHP runs. No real order is created or modified — the synthetic payload carries a `WAFTEST-` merchant reference that cannot match any order in the database. Catches the class of *"callbacks never arrive"* problems before they cost you a sale.

* **Modern admin UI.** Card-based layout, dashicons throughout, one-click Copy-to-clipboard for all callback URLs grouped in a single block, environment badge (Test / Live / Production) on the credentials section, collapsible Cloudflare details. Audited against the "Modern" admin theme introduced in WordPress 7.0.

* **Audited for WordPress 7.1 on release day.** Reviewed against the full breaking-changes list in the WordPress 7.0 Field Guide on 2026-05-20 and again in the WordPress 7.1 Field Guide on 2026-08-28. "Tested up to" has tracked the current WordPress release since the very first stable version. The plugin requires PHP 7.4 (the WordPress 7.1 minimum) and uses no API deprecated in 7.0 or 7.1.

* **Fully bilingual (EN + EL).** All 179 admin and customer-facing strings translated to Greek and shipped as both classic `.mo` and WordPress 6.5+ performant `.l10n.php` payloads. The wp.org page itself ships with an English `readme.txt` that opens with a Greek summary, plus a parallel full Greek `readme-el.txt` companion file inside the plugin folder for Greek-speaking merchants.

----------

**Ελληνικά:**

Ανεξάρτητο πρόσθετο πύλης πληρωμής WooCommerce από τη WebHosting4U για
την υπηρεσία *ePay Paycenter Redirection* της Τράπεζας Πειραιώς /
Euronet Merchant Services. Υλοποιεί πλήρως την επίσημη προδιαγραφή
*Redirection v3.1*:

* Έκδοση μοναδικού εισιτηρίου (TranTicket) μέσω SOAP Ticketing Web
  Service με κωδικοποίηση UTF-8.
* Αυτόματη ανακατεύθυνση POST στην ασφαλή σελίδα πληρωμής της τράπεζας
  (`pay.aspx`) — τα δεδομένα κάρτας δεν περνούν ποτέ από τον διακομιστή
  του καταστήματος.
* Πλήρης επαλήθευση HashKey HMAC-SHA256 σε κάθε επιτυχημένη απάντηση
  πριν χαρακτηριστεί η παραγγελία ως εξοφλημένη.
* Συναλλαγές Sale (άμεση εκκαθάριση στην επόμενη παρτίδα).
* **Υποστήριξη IRIS Payments** (άμεσες πληρωμές μέσω ΔΙΑΣ). Όταν η
  Euronet Merchant Services ενεργοποιήσει το IRIS στη σύμβασή σας, η
  σελίδα πληρωμής της τράπεζας εμφανίζει στον πελάτη και τις δύο
  επιλογές (κάρτα ή IRIS). Το πρόσθετο αναγνωρίζει τις απαντήσεις IRIS,
  αποθηκεύει το κανάλι πληρωμής στην παραγγελία, και εμφανίζει
  μηνύματα προσαρμοσμένα στα IRIS σενάρια (ακύρωση από την εφαρμογή
  τράπεζας, λήξη QR 5 λεπτών, σφάλμα υπηρεσίας IRIS κ.λπ.).
* **Πληρωμές Google Pay™** (Redirection v3.1) — γίνονται δεκτές
  αυτόματα, χωρίς καμία ρύθμιση. Η σελίδα της τράπεζας εμφανίζει το
  Google Pay σε συμβατές συσκευές για συναλλαγές αγοράς και προέγκρισης.
  Επειδή το Google Pay επιστρέφει την τυπική απάντηση κάρτας,
  επαληθεύεται με το ίδιο HashKey HMAC-SHA256· το πρόσθετο καταγράφει
  επιπλέον το πεδίο `PanCardType` (FPAN πραγματικός αριθμός κάρτας /
  DPAN token συσκευής) στην παραγγελία.
* Συμπλήρωση πεδίων 3-D Secure από τη διεύθυνση χρέωσης / αποστολής
  του WooCommerce.
* Συμβατότητα με HPOS (Custom Order Tables) και WooCommerce Blocks
  checkout.

**Προϋπόθεση:** πρέπει να έχετε υπογεγραμμένο συμβόλαιο αποδοχής με την
Euronet Merchant Services / Τράπεζα Πειραιώς και να διαθέτετε τα
διαπιστευτήρια `AcquirerId`, `MerchantId`, `PosId`, `Username`,
`Password`. Το πρόσθετο δεν παρέχει δικούς του δοκιμαστικούς
λογαριασμούς.

Η πλήρης ελληνική μετάφραση της σελίδας του προσθέτου στο WordPress.org
θα είναι διαθέσιμη μέσω του [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/secure-card-gateway-for-epay-paycenter-piraeus-bank/)
μόλις εγκριθεί από την κοινότητα. Δείτε επίσης το συνοδευτικό
`readme-el.txt` για την ολοκληρωμένη ελληνική τεκμηρίωση.

----------

**English:**

This plugin integrates WooCommerce with the ePay Paycenter Redirection
service operated by Piraeus Bank / Euronet Merchant Services. It implements
the official Redirection v3.1 specification end to end:

* SOAP Ticketing Web Service (`IssueNewTicket`) with UTF-8 payload.
* Auto-submitted HTML form POST redirection to the Paycenter secure
  payment page (`pay.aspx`) so card data never touches your server.
* Full HMAC-SHA256 HashKey verification for every successful callback
  before marking an order as paid.
* Sale transactions, settled in the next batch with no further merchant
  action required.
* **IRIS payments** (Greek instant payment / DIAS) accepted transparently
  when enabled by Euronet Merchant Services on the merchant agreement.
  The bank's hosted page presents card and IRIS as the two payment
  options; the plugin recognises the IRIS-specific response payload
  (`CardType=15`, `PaymentMethod=IRIS`), surfaces IRIS-tailored decline
  messages for the IRIS-only ResponseCodes (05 user-cancelled-in-bank-app,
  06 service error, 09 pending, 68 5-minute QR timeout, 70 IRIS service
  error) and records the channel on the order so card vs IRIS settlements
  are distinguishable in your reports.
* **Google Pay™** (Redirection v3.1) accepted automatically — no
  configuration needed. The bank's hosted page shows Google Pay on
  eligible devices/browsers for purchase and pre-authorization
  transactions (with or without installments). Because Google Pay
  returns the standard card success payload, it is verified against the
  same HMAC-SHA256 HashKey as any card; the plugin additionally records
  the `PanCardType` (FPAN real card number / DPAN device token) on the
  order and notes "Paid via Google Pay" so wallet settlements are
  distinguishable in your reports.
* 3-D Secure auxiliary fields populated from the WooCommerce billing /
  shipping address.
* HPOS (Custom Order Tables) and WooCommerce Blocks checkout support.

**You must have signed an acquiring contract with Euronet Merchant
Services / Piraeus Bank and obtained `AcquirerId`, `MerchantId`, `PosId`,
`Username` and `Password` credentials before using this plugin.** The
plugin does not provide test or sandbox accounts on its own; please
contact Euronet Merchant Services to request one.

= Affiliation and trademark notice =

This plugin is independent software published by **WebHosting4U** and is
**not affiliated with, endorsed by, sponsored by, or otherwise officially
connected to** Piraeus Bank S.A., Euronet Merchant Services, or
Automattic Inc. The third-party names "ePay", "Paycenter", "Piraeus Bank"
and "WooCommerce" are trademarks of their respective owners and are
used here in good faith, after the unaffiliation marker "for", solely
to describe the third-party service this plugin integrates with, in
line with the WordPress.org Detailed Plugin Guidelines on third-party
trademarks. The bundled accepted card brands image
(`assets/img/wp-cards.png`) is included with the rights-holder's
authorization for the merchant distribution scope of this plugin.

= User tracking and consent =

This plugin does **not** load any analytics, telemetry, advertising,
fingerprinting, profiling or behavioural tracking code, neither on the
storefront nor in the WordPress admin. It does not set cookies on
visitor browsers, does not contact any first-party or third-party
analytics endpoint, and does not collect aggregated or individual
usage statistics from the merchant's installation. The only outbound
network traffic the plugin generates is the strictly transactional
traffic documented in the *External services* section below, which
is required to complete a payment the merchant has explicitly
configured the plugin to perform. No user-tracking consent prompt is
therefore required by this plugin (Plugin Review Team Guidelines 7
and 9).

== External services ==

This plugin reaches out to three external services. Two are operated
by Euronet Merchant Services on behalf of Piraeus Bank S.A. for the
"ePay Paycenter" payment redirection product (mandatory for the
plugin's core function). The third is a publicly available Cloudflare
endpoint used only in the admin panel to help store owners configure
firewall rules for payment callbacks.

= 1. ePay Paycenter Ticketing Web Service =

* What it is: a SOAP / ASMX endpoint published by Euronet Merchant
  Services that issues a single-use `TranTicket` for each card
  payment attempt. The ticket is kept server-side only (never exposed
  to the browser) and used solely to verify the HMAC-SHA256 HashKey on
  the bank's callback; the customer's browser is redirected to the
  secure payment page by a form carrying only non-secret fields, so
  cardholder data never touches the merchant server.
* Endpoint: `https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx`
* What is sent: the merchant credentials provided by Euronet Merchant
  Services (AcquirerId, MerchantId, PosId, Username and an MD5 hash
  of the Password), the order's MerchantReference (numeric WooCommerce
  order id with a short random suffix), the transaction amount and
  ISO 4217 numeric currency code, the request type (always Sale),
  and the 3-D Secure auxiliary fields populated
  from the WooCommerce order: billing email, cardholder name,
  billing address (city / lines / post code / state / ISO 3166
  numeric country code), shipping address when present, and the
  customer's mobile phone number formatted as `CC-Number`. No
  cardholder data, no PAN, no CVV, no expiry, and no analytics
  identifier is ever transmitted; cardholder data is collected
  exclusively on the bank's secure payment page.
* When it is sent: once per successful checkout submission, at the
  moment WooCommerce hands control to the gateway's
  *Pay for order* page, immediately before the customer is
  auto-redirected to the bank.

= 2. ePay Paycenter Redirection page =

* What it is: the bank-hosted secure payment page where the customer
  enters card details and completes the 3-D Secure challenge. The
  plugin renders an auto-submitted HTML form whose `action` attribute
  is the URL below.
* Endpoint: `https://paycenter.piraeusbank.gr/redirection/pay.aspx`
* What is sent: the merchant identifiers (AcquirerId, MerchantId,
  PosId, User), the language code, the MerchantReference issued
  during ticketing, and a per-order ParamBackLink so the bank's
  Cancel button returns the customer to the correct WordPress
  endpoint. The TranTicket itself is read by the customer's
  browser from the hidden form field; the merchant server is not
  the originator of the redirect POST.
* When it is sent: once per checkout, immediately after the
  Ticketing call above succeeds.
* Inbound counterpart: Paycenter posts a signed transaction
  response (HMAC-SHA256 HashKey) back to the plugin's WC-API
  callback URL on the merchant site
  (`https://<merchant-site>/?wc-api=epay_paycenter`). This is the
  same service - the merchant site is the Notification / Success /
  Failure / Backlink target the merchant configures in the
  Euronet portal. No data leaves the merchant server on this
  inbound leg; the plugin only reads, verifies, and acts on the
  response.

= Service operator and legal links =

Both endpoints above are operated by Euronet Merchant Services
(epay) for Piraeus Bank S.A.. Before activating the gateway,
merchants must review and agree to the operator's terms and
privacy policy:

* Service homepage: <https://epayworldwide.com/> (Euronet Merchant
  Services / epay corporate site)
* Greek market homepage: <https://www.epaygreece.gr/>
* Terms of Service: <https://www.epaygreece.gr/oroi-xrisis/>
* Privacy Policy: <https://www.epaygreece.gr/politiki-aporritou/>
* Piraeus Bank corporate site: <https://www.piraeusbank.gr/>
* Piraeus Bank Privacy Policy: <https://www.piraeusbank.gr/en/idiwtes/protection-of-personal-data>

If any of the above URLs change after publication, please consult
the live operator websites for the current version of the relevant
document. The plugin's behaviour is not affected by such updates
because the operator's terms apply to the merchant's relationship
with Euronet Merchant Services / Piraeus Bank, not to the plugin
itself. Merchants remain responsible for keeping their own
privacy policy and terms aligned with the data flows documented
above (notably the transmission of billing / shipping address
fields and customer email / phone to the bank for 3-D Secure
authentication).

= 3. Cloudflare IPv4 list =

* What it is: a publicly available plain-text file published by
  Cloudflare, Inc. that lists the current IPv4 CIDR ranges used by
  Cloudflare's edge network. The plugin fetches this file once every
  12 hours (or 15 minutes on failure) via `wp_safe_remote_get()` and
  caches the result in a WordPress transient.
* Endpoint: `https://www.cloudflare.com/ips-v4/`
* What is sent: a standard HTTP GET request with no personal data,
  no order information, no credentials, and no cookies. The only
  identifying information in the request is the plugin's `User-Agent`
  string (`secure-card-gateway-for-epay-paycenter-piraeus-bank/VERSION`).
* Why: when the plugin's admin settings page detects that the
  WordPress site is served through Cloudflare (via the CF-Ray /
  CF-Connecting-IP / CDN-Loop request headers), it displays the
  current Cloudflare IPv4 ranges so the store owner can copy them
  into an email to Euronet Merchant Services to whitelist them for
  payment callbacks. Without this list, callbacks routed through
  Cloudflare edge IPs may be rejected by the bank's firewall.
* When: only when an administrator views the gateway's WooCommerce
  settings page and Cloudflare is detected on the incoming request.
  It is never triggered on the storefront or by guest/customer visits.
* Cloudflare service homepage: <https://www.cloudflare.com/>
* Cloudflare Privacy Policy: <https://www.cloudflare.com/privacypolicy/>
* Cloudflare Terms of Service: <https://www.cloudflare.com/terms/>

No data is sent to any third party other than the three endpoints
listed above.

== Installation ==

1. Upload the plugin ZIP through **Plugins → Add New → Upload Plugin**,
   or extract it into `wp-content/plugins/wh4u-secure-card-gateway-for-epay-paycenter-piraeus-bank/`.
2. Activate the plugin.
3. Go to **WooCommerce → Settings → Payments** and enable
   *ePay Paycenter (Piraeus Bank)*.
4. Enter your AcquirerId, MerchantId, PosId, Username and Password
   exactly as provided by Euronet Merchant Services.
5. Set the environment (Test / Live), language, and transaction type.
6. Provide Euronet Merchant Services with the following URLs for your
   merchant record:
     * Referrer URL: your shop checkout page.
     * Success URL:  `https://your-site.tld/wc-api/epay_paycenter/`
     * Failure URL:  `https://your-site.tld/wc-api/epay_paycenter/`
     * Backlink URL: `https://your-site.tld/wc-api/epay_paycenter/`
     * IP address:   the outbound IP of your web server.
     * Response method: **POST** (recommended).
7. Execute the mandatory test cases documented in Section 7 of the
   Redirection v2.9 manual before requesting live credentials.

== Frequently Asked Questions ==

= Which cards are supported? =

Visa, Mastercard, Maestro, and (subject to agreement with Euronet
Merchant Services) Diners / Discover and American Express.

= Does the plugin support IRIS payments? =

Yes. When IRIS is enabled on your merchant agreement by Euronet Merchant
Services, customers can choose between card and IRIS directly on the
bank's hosted payment page — the plugin does not need a separate setting
to "turn IRIS on" because the choice is made server-side at the bank,
not in your checkout. The plugin recognises IRIS responses (CardType=15
or PaymentMethod=IRIS in the bank's HMAC-verified callback), shows
IRIS-tailored messages for the IRIS-only decline scenarios (user
cancelled in their banking app, 5-minute QR timeout, IRIS service error,
etc.) and records the payment channel on the order so card and IRIS
transactions are distinguishable in your reports.

Per Piraeus Bank policy, **IRIS payments do not support installments**
(the full amount is charged) and **refunds are not supported for IRIS
transactions** (ResponseCode 9167). These restrictions are enforced by
the bank, not by the plugin.

= Does the plugin store any card data? =

No. Cardholder data is entered exclusively on the Paycenter secure
payment page and never transits your server. The plugin stores only
non-sensitive metadata such as approval code, response code and
`SupportReferenceID` for reconciliation.

= What happens if the HashKey cannot be verified? =

The order is set to *On hold* and a notice is logged. Verification is
mandatory before an order is marked paid; a mismatching HashKey is
treated as a potentially forged callback.

= Is this plugin GDPR-compatible? =

The plugin transmits only the minimum data required for the transaction
(order total, currency, merchant reference, and 3-D Secure auxiliary
fields such as billing email and address). No analytics or telemetry is
collected.

= My host's WAF (cPFence, ModSecurity, Imunify360, BitNinja, LiteSpeed) is blocking the bank callback. What do I do? =

Shared-hosting firewalls sometimes flag Paycenter's decline-callback
payload because it contains patterns (dash-only `TransactionDateTime`,
empty `HashKey` on declined transactions, Greek `ResponseDescription`
text) that overlap with default attack signatures. Symptoms: callbacks
for failed transactions never arrive and orders get stuck in *pending
payment*.

The plugin's settings page includes a **Test callback URL** diagnostic
that POSTs a realistic declined-transaction payload to your own callback
URL via loopback and reports whether a host WAF intercepts it. Run it
once before going live. If a WAF is intercepting, ask your hosting
provider to whitelist the URL `/?wc-api=epay_paycenter` (callback-URL
scope only — never disable rules server-wide). CDN-level WAFs
(Cloudflare, Sucuri, Akamai) must be configured separately at the CDN —
the in-admin diagnostic only exercises the origin server's WAF.

= My customer sees only "-1" after the bank return. What happened? =

The literal `-1` comes from WooCommerce core's `WC_API` handler. For
this plugin it usually means one of three things:

1. The callback URL in the Euronet portal does not match the plugin's
   URL. Use exactly `https://<your-site>/?wc-api=epay_paycenter` (no
   trailing slash, no extra path). Copy it from the gateway settings
   page where the plugin displays the canonical form.
2. A host WAF intercepted the callback before it reached PHP (see the
   WAF FAQ above).
3. The plugin handled the callback but a downstream redirect target
   suppressed the notice. Since 1.0.14 decline messages survive
   cross-origin redirect cookie stripping, so this is rare on current
   versions.

Check **WooCommerce → Status → Logs** for `epay-paycenter-*` entries
around the transaction timestamp. The `Callback envelope` INFO line is
written on every callback that reaches PHP, so its presence or absence
distinguishes WAF-level blocks from plugin-level rejects.

= Where do I report security bugs found in this plugin? =

Please report security bugs found in the source code of the Secure Card
Gateway for ePay Paycenter (Piraeus Bank) plugin through the [Patchstack
Vulnerability Disclosure Program](https://patchstack.com/database/vdp/cfd86dd3-29dd-411e-b5d7-731c76b719f0).
The Patchstack team will assist you with verification, CVE assignment, and
notify the developers of this plugin.

== Changelog ==

= 1.0.37 =
Adds daily reconciliation for payments that never reported back and improves the 3-D Secure data sent to the card issuer, corrects the order status for pending IRIS payments, plus two fixes and a settings-screen clarification. No configuration change required.
* Improvement (3-D Secure): the customer's phone number is now sent in the field that matches the kind of line it is. The Ticketing service accepts MobilePhone, HomePhone and WorkPhone as separate 3-D Secure inputs and the card issuer's risk engine reads them as separate facts, but WooCommerce collects a single phone with no indication of its type, and every number was previously sent as a mobile. Greek numbers are now routed correctly (69x to MobilePhone, 2xx to HomePhone), which the national numbering plan makes unambiguous. Numbers from other countries are unchanged, because no equivalent guarantee exists there and a wrong guess would be worse than the current behaviour. Better 3-D Secure data means more payments approved without a challenge screen.
* New filter for stores that collect more than WooCommerce does: `epay_paycenter_3ds_fields` supplies `BillAddrLine3`, `ShipAddrLine3`, `HomePhone`, `MobilePhone` and `WorkPhone` for the 3-D Secure request. WooCommerce core has two address lines and one billing phone, so these fields have no standard source and the plugin will not invent values for data a fraud engine scores. A store whose checkout genuinely collects a third address line or a second phone number can now supply them. Everything the filter returns is re-validated against the specification's character set and length limits before it is sent, and only those five field names are accepted, so the filter cannot alter the amount, the credentials or the transaction type.
* New: a prominent admin warning when the bank rejects the store's credentials. ResultCode 100 (Authentication Error) means the Username or Password for the Ticketing Web Service is wrong, and because that is the one call carrying the credentials, it fails every single checkout: no customer can pay by card until it is corrected. Until now it appeared only as an individual customer decline and an order note, so a store could lose a day of card revenue with nothing on any admin screen explaining why. A warning now appears on every admin page, records when the failures began, quotes the bank's response, and links straight to the credentials. It is not dismissible, because dismissing it would hide a complete outage, and it removes itself automatically the moment a payment is successfully started.
* New: a daily reconciliation check for payments that were started but never confirmed. If a customer is sent to the bank and the response never comes back, for example because a firewall or a stale callback URL in the Euronet portal swallows it, the order previously sat in "pending" forever with nothing to indicate anything had gone wrong. Such orders are now flagged after a grace period, given an order note listing their MerchantReference, and summarised in a dismissible admin notice. The check deliberately never changes an order's status and never marks anything paid: without the bank's follow-up Web Service, whose specification Euronet Merchant Services supplies on request, the plugin genuinely cannot distinguish an abandoned checkout from a completed payment whose response was lost, and guessing either way would be worse than reporting it. The affected orders are listed so they can be verified in the epay eCommerce AdminTool. The grace period (2 hours), retention (90 days) and batch size are filterable.
* Housekeeping: the internal ticket log finally has a lifecycle. Rows were written for every payment attempt and never read or removed, so the table grew indefinitely. Reconciliation now resolves each row to settled, closed or unresolved, and prunes resolved rows after 90 days. Unresolved rows are kept, because they are the audit trail for a payment nobody has accounted for yet.
* Fix (important, IRIS only): an IRIS payment that the bank reports as started but not yet completed (ResponseCode 09) is now placed ON HOLD instead of being marked failed. Per the Redirection Manual this code means the transfer was initiated and may still settle through DIAS, so marking it failed produced orders that were paid but recorded as failures, with nothing to reconcile the two. The customer is now told the payment is pending and asked NOT to pay again, and is sent to the order-received page rather than the pay-again page: an IRIS transfer still in flight can otherwise be paid a second time, and the bank does not accept refunds on IRIS transactions. Genuine IRIS declines (customer cancelled in the bank app, QR code timed out) are unaffected and still fail as before, as are all card declines, including a card response code 09, which does not carry the same meaning.
* Fix: pressing "Cancel" on the bank's payment page now reliably cancels the order even when the payment page was opened from an earlier attempt. Every reload of the pay-for-order page issues a fresh cancel token, and only the most recent one was accepted, so a customer who reached the bank page, went Back and Forward, or used a second browser tab, could press Cancel and have nothing happen: the order stayed pending and they were returned to the generic checkout page with no explanation. The token is now matched against every still-open payment attempt on the order, using the same timing-safe comparison already applied to the bank's callbacks. Forged, empty and expired tokens are still rejected.
* Fix: the "Logging" setting now enables debug logging on its own. Previously the debug-level lines also required the site-wide WP_DEBUG constant, which is off on virtually every production site, so ticking the box produced an empty log. The lines that were being suppressed are the ones needed to diagnose a callback that never arrives ("callback received before ticket issuance", "endpoint hit without payload", "callback from IP not in allowlist"), which is precisely the situation in which a merchant enables logging. Credentials, transaction tickets and HashKeys remain redacted as before.
* Improvement (merchant feedback): the "Bank integration data" panel now separates the two server addresses it was conflating. The row a merchant is meant to hand the bank is the server's OUTBOUND IP, but the panel showed the address WordPress is served on, labelled simply "IP address", with a one-click Copy button beside it. On a host that serves and egresses from different addresses (reverse proxy, load balancer, NAT, or simply more than one IP) that is the wrong value, and registering it with ePay makes every ticket request fail with nothing in the interface to explain why. The panel now shows "Outbound IP (the value ePay needs)" first and "Website IP (not the value for ePay)" below it, and the Copy button appears only next to a real outbound value. Because only a request to an outside host can reveal the outbound address, and this plugin makes no such call, the field explains how to obtain it (run curl -4 https://api.ipify.org over SSH, or ask your host) instead of displaying a confident wrong answer.
* New filter: `epay_paycenter_outbound_ip` lets a hosting provider, agency or site developer publish the server's known outbound IP so it appears in the panel ready to copy. Returned values are validated as real IPv4 / IPv6 addresses and ignored otherwise. Thanks to the merchant who reported the IP mismatch in detail.
* Translations: the Greek catalogue is complete again. Two settings strings introduced in 1.0.36 (the password field description and its placeholder) had shipped untranslated and appeared in English on Greek installations; both are now translated, along with every string added in this release. The translation template shipped with the plugin was also regenerated from the current source, having been left at the 1.0.19 string set, so contributors on translate.wordpress.org now see the strings the plugin actually uses.
* Compatibility: tested against WordPress 7.1 and reviewed against its Field Guide. No API used by this plugin changed, was deprecated, or was removed. The PHP 7.4 minimum is unchanged and matches the WordPress 7.1 requirement.

= 1.0.36 =
Hardening release from an internal security review. No payment-flow change; no merchant action required.
* Hardening: the Ticketing Web Service password is now stored as the MD5 digest that the specification requires be transmitted, instead of in plain text. The plain password is never written to the database. Existing installations are converted automatically the next time the gateway settings are saved, and continue to work until then. The password field now always displays empty — leave it blank to keep the saved credential, or type a new password to replace it.
* Hardening: the callback endpoint is public and unauthenticated, so the diagnostic lines it writes are now drawn from an hourly budget. Previously a request flood could append unlimited attacker-supplied text to the WooCommerce log and fill the disk. Normal trade is far below the limit and is unaffected; when the budget is reached a single line records that suppression is active. Requests carrying no MerchantReference at all no longer produce a log line.
* Hardening: the per-order MerchantReference secret was lengthened from 6 to 12 characters (roughly 31 to 61 bits). This value is the only secret protecting the documented decline callback path, on which the bank sends no HashKey.
* Hardening: the optional `epay_paycenter_allowed_callback_ips` filter now only trusts the CF-Connecting-IP header when the request genuinely arrived through Cloudflare. On a site that is not behind Cloudflare that header is caller-supplied, so the allowlist could previously be bypassed with a spoofed header. Order state was never at risk — it remains gated on the HashKey and the MerchantReference secret.

= 1.0.35 =
* Removed (important): the "Transaction type" and "Preauthorization expiry (days)" settings. All transactions are now sent as Sale (RequestType 02), which settles in the next batch with no further merchant action. Preauthorization only *commits* an amount — the Redirection Manual requires it to be completed separately (RequestType "SETTLE" via a Web Service whose specification Euronet Merchant Services supplies on request, or manually in the epay eCommerce AdminTool). That completion step was never implemented, so a store configured for Preauthorization marked orders as paid, reduced stock and shipped against funds that were only reserved and would silently expire. Removing the option resolves this. Stores previously set to Preauthorization now capture funds immediately, which is what their order statuses already indicated. If you deliberately relied on Preauthorization with manual completion in the AdminTool, please get in touch before updating.
* Fix: the HMAC-SHA256 HashKey on a signed non-success callback is now verified against the transaction ticket belonging to that specific payment attempt. Two internal calls passed the ticket to functions that did not declare a parameter to receive it, so verification silently fell back to the most recent ticket stored on the order. On an order with several payment attempts (each reload of the pay-for-order page issues a fresh ticket) this could verify against the wrong key and, because verification fails closed, leave the order without its decline status or message. Orders with a single attempt were unaffected.
* Fix: the WooCommerce Blocks checkout script is now versioned with the current plugin version instead of a value pinned at 1.0.24, so browsers no longer serve a cached copy of the payment method script after an update.
* Docs: readme feature lists and the External Services disclosure updated to state that the request type is always Sale.

= 1.0.34 =
* Compatibility: aligned with Redirection Manual v3.1 (Google Pay™ support). Google Pay needs no merchant configuration — the bank auto-offers it on eligible devices/browsers for purchase and pre-authorization transactions and returns the standard card success payload, which the plugin already verifies against the unchanged HMAC-SHA256 HashKey. The plugin now also captures the new `PanCardType` response field (FPAN = real card number, DPAN = device token, returned only for Google Pay), storing it as `_epay_pan_card_type` order meta and noting "Paid via Google Pay (FPAN/DPAN)" on the order when applicable so operators can tell wallet settlements apart. No change to the ticketing request, redirect form, or HashKey verification.
* Security (hardening): failure / decline callbacks are now authenticated before the order is touched. When Paycenter signs a non-success response (non-empty HashKey) the plugin verifies the HMAC-SHA256 HashKey and fails closed on any mismatch, leaving the order unchanged. When the bank sends the response with an empty HashKey (its documented behaviour for declined transactions — confirmed in Redirection Manual v3.1 §5) the plugin continues to rely on the per-order MerchantReference secret (order id + CSPRNG suffix) already required to reach the handler. Previously the HashKey was only checked on the success path; a forged failure callback that supplied a bad HashKey is now rejected instead of flipping a pending order to "failed". The same check guards the recharge-attempt annotation on already-paid orders.
* Compliance: recharge attempts on an already-paid order (Redirection Manual v3.1 §7 Test Case 3, ResultCode 1048 — e.g. the customer presses Back and the cached payment form re-submits after the transaction was approved) are no longer silently discarded by the duplicate-callback guard. The attempt is now recorded as an order note with the storage set the manual mandates (SupportReferenceID, MerchantReference, ResultCode, ResultDescription) under dedicated `_epay_recharge_attempt_*` meta keys, and the customer sees "This order has already been paid. Your new payment attempt was not processed and no additional charge was made." on the order-received page instead of no message. Order status and the approved transaction's audit meta remain untouched.

= 1.0.33 =
* Security: the Paycenter transaction ticket (`TranTicket`) is no longer emitted in the browser redirect form. Per Redirection Manual v2.9 §4 the ticket must never be visible to the user, and the Annex 1 sample form omits it. Because the ticket is the HMAC-SHA256 secret key used to authenticate the bank's success/failure callback, exposing it in the hidden form allowed a shopper to compute a valid HashKey and forge a paid-status callback for their own order. The ticket is now kept server-side only (in `_epay_tran_ticket` order meta) and used solely for callback verification; the redirect form now carries exactly the fields listed in the manual (AcquirerId, MerchantId, PosId, User, LanguageCode, MerchantReference, ParamBackLink). Stores should update immediately and review recent Paycenter orders for any marked paid without a matching bank settlement.

= 1.0.32 =
* Docs: added a Patchstack Vulnerability Disclosure Program (VDP) security-contact entry to the FAQ, giving security researchers a coordinated channel to report vulnerabilities (verification, CVE assignment, developer notification). No code, database, or payment-flow change.

= 1.0.31 =
* Docs: readme Description trimmed to fit the wp.org 2,500-word limit (the 1.0.30 release was truncated on the public plugin page). The standalone `== WAF compatibility ==` section was rolled into two FAQ entries that cover the same operator scenarios (WAF blocking callbacks, "-1" troubleshooting) without per-vendor configuration snippets — those move to support docs. No code change; the in-admin "Test callback URL" diagnostic, callback envelope logging and URL normalisation features previously documented in that section all remain.

= 1.0.30 =
* Feature: **IRIS payments support** per Redirection Manual v2.9. When Euronet Merchant Services enables IRIS on your merchant account, the bank's hosted page lets customers pay by IRIS instead of card; the plugin now recognises the IRIS response payload (`CardType=15` / `PaymentMethod=IRIS`), stores the payment channel on the order, and surfaces IRIS-tailored messages for the IRIS-only ResponseCodes 05 (user cancelled in their bank app), 06 (service error), 09 (initiated but not confirmed), 68 (5-minute QR-code timeout) and 70 (IRIS service unexpected error). HMAC-SHA256 HashKey verification applies to IRIS callbacks identically (the empty AuthStatus / PackageNo / TraceID fields are concatenated as empty strings per the manual). No payment-flow or callback-handler change for existing card transactions.
* Feature: **customer-selectable installments on the classic WooCommerce checkout**. The gateway now renders an "Installments (interest-free)" dropdown when installments are enabled and the cart total qualifies; the picked value is captured at order creation, persisted as `_epay_installments` order meta, and sent as the `Installments` parameter in the Ticketing request to Piraeus Bank.
* Feature: **tiered max-installments by amount** via a new admin setting "Tiered max installments by amount (interest-free)". Format `amount:max,amount:max,...` (e.g. `50:3, 100:6, 200:12`). Overrides the flat "Maximum installments" when set. Per Piraeus Bank policy all installments via this gateway are interest-free for the customer; the merchant absorbs the bank commission.
* Security: the customer-side dropdown is treated as untrusted. `process_payment()` always re-clamps the picked value against the merchant's tier policy evaluated for the live order total, and the SOAP ticket request re-clamps a second time as defence-in-depth. Tier-string parsing uses a strict regex; malformed segments are silently dropped instead of throwing.
* Internal: the existing `installments` checkbox + `max_installments` + `min_amount_for_installments` settings, previously cosmetic (the Ticketing request was hard-coded to `Installments=0`), are now wired up. `_epay_installments` order meta carries the value through the bank round-trip. Success order note now branches by payment channel — IRIS rows omit the empty PackageNo / TraceID columns that have no analogue in the DIAS instant-payment flow.
* Docs: readme Description, FAQ and Greek `readme-el.txt` companion updated with IRIS coverage; new section "What sets this plugin apart" surfaces the technical differentiators (HPOS-native, Cloudflare detection, WAF self-test, WP 7.0 audit, bilingual readme).
* Known limitation: the installments customer picker ships for classic WC checkout only. WooCommerce Blocks Checkout customers default to one-time payment (`Installments=1`, the bank's canonical "no installments" per Redirection Manual v2.9 §4); the Blocks picker is scheduled for a follow-up release.

= 1.0.28 =
* Compatibility: tested with WordPress 7.0 (released 2026-05-20). Verified that the gateway works on the new Modern admin theme, the iframed post editor changes do not affect WooCommerce Blocks checkout integration, and PHP 7.4+ requirement already meets the new core minimum.
* Docs: screenshot captions in readme corrected to match the actual images (bilingual EN/EL).

= 1.0.27 =
* UX: settings screen reordered — the "Merchant credentials" section now appears above the "Bank integration data" section, matching the natural onboarding flow (enter the credentials from Euronet first, then send the technical data back).
* UX: "Bank integration data" card and its Cloudflare detection sub-block are now collapsible (closed by default) to keep the settings screen compact after onboarding.
* UX: the five callback URLs (Website, Referrer, Success, Failure, Backlink) are consolidated into a single grey block with one "Copy" button, replacing the per-URL row layout.
* i18n: Greek translation added for the new "Copy callback URLs to clipboard" aria-label.
* Internal: aligned EPAY_PAYCENTER_VERSION constant with the plugin header (was lagging at 1.0.25, used by asset cache-busting and outbound User-Agent strings).

= 1.0.26 =
* Compliance: invalid Plugin URI header removed per Plugin Review Team feedback. The URI is an optional header (https://developer.wordpress.org/plugins/plugin-basics/header-requirements/) and the previously declared page was not public. Author URI (https://webhosting4u.gr/) is unchanged and reachable.

= 1.0.22 =
* Fix: plugin folder and main file renamed to secure-card-gateway-for-epay-paycenter-piraeus-bank to match WP.org slug; resolves Plugin Check TextDomainMismatch on all i18n calls.
* Fix: UTF-8 BOM properly stripped from all PHP files (previous fix re-encoded U+FEFF as a BOM on write; correct method skips the 3 BOM bytes before decoding).

= 1.0.21 =
* Fix (critical): TranTicket was missing from the redirect form POST to pay.aspx — every payment attempt would be rejected by the bank. Added to form_fields in output_receipt_page().
* Fix: apply_filters( epay_paycenter_icon ) pre-escaped the default URL violating escape-late; escaping now happens only at output.
* Fix: printf() with HTML link replaced with echo wp_kses_post( sprintf() ).
* Fix: is_cloudflare_proxied() now uses isset() + is_scalar() guards on CF_RAY / CF_CONNECTING_IP.
* Fix: phpcs:ignore added to dbDelta() SQL interpolation in create_tables().
* Cleanup: removed orphaned docblock for deleted collect_attempt_stats().

= 1.0.20 =
* Compliance: text domain corrected from wh4u-* to secure-card-gateway-for-epay-paycenter-piraeus-bank to match WP.org slug. All 179 i18n strings updated; language files renamed.
* Compliance: echo generate_settings_html() kept as phpcs:ignore with explanatory comment; wp_kses_post() wrap broke WooCommerce form attributes.
* Security: enqueue_admin_settings_assets() now checks current_user_can( manage_woocommerce ) before reading  args.
* External services: Cloudflare IPv4 fetch documented in readme with terms / privacy links.

= 1.0.19 =
* Compliance: plugin renamed to WebHosting4U Secure Card Gateway for ePay Paycenter (Piraeus Bank); slug wh4u-* per Guideline 17. External services section fully documented with terms / privacy links. Deprecated libxml_disable_entity_loader() removed.

= 1.0.18 =
* UI: checkout icon changed from piraeus.svg to responsive wp-cards.png. Block checkout now shows card brands. Front-end CSS enqueued on checkout.

= 1.0.17 =
* Localisation: Greek translation backfilled with all strings from 1.0.10-1.0.16. POT regenerated.

= 1.0.16 =
* Fix: decline notice not appearing after bank redirect. Hook priority collision with WooCommerce core resolved (priority 5 vs core 10).

= 1.0.15 =
* Compliance: failure handler aligned row-by-row with Redirection Manual v2.9 section 5 scenario table. Fixes misleading ResultCode 1048 message; widens 50x matching to 500-599.

= 1.0.14 =
* Fix: decline message lost after bank redirect due to SameSite=Lax session-cookie stripping. Notices now queued as order-scoped transients (session-independent delivery).

= 1.0.13 =
* Fix: -1 body when Success/Failure URL is doubled in the Euronet portal. Malformed wc-api values normalised at parse_request.

= 1.0.12 =
* Declined-transaction handling per Redirection Manual v2.9 section 5. Failure redirect now targets pay-for-order URL. Fixes -1 body via plugins_loaded binding.

= 1.0.11 =
* Admin diagnostics: Test callback URL button detects host WAF interception before live payments.

= 1.0.10 =
* Logging: Callback envelope INFO line written on every callback reach for WAF/plugin-reject disambiguation.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.37 =
Adds a prominent admin warning when the bank rejects your credentials, a condition that silently blocks every card payment, and a daily check that flags payments which were started but never confirmed, so an order whose bank response was lost no longer sits in pending unnoticed; it reports them for you to verify and never changes an order's status by itself. Also corrects a case where an IRIS payment reported as started but not yet completed was marked failed rather than on hold, which could leave a paid order recorded as a failure; affected customers are now asked not to pay again. Also: the "Bank integration data" panel no longer offers the website's inbound IP as the value to register with ePay: the address the bank needs is the server's outbound IP, which on many hosts is different, and registering the wrong one makes every transaction fail without a visible cause. Also fixes the "Cancel" button on the bank payment page failing silently when the page came from an earlier payment attempt, and makes the "Logging" setting work without also needing WP_DEBUG. Tested against WordPress 7.1. No payment-flow change and no configuration required.

= 1.0.36 =
Hardening release from an internal security review. The bank password is now stored only as the MD5 digest the specification transmits, callback logging is rate-limited so the public endpoint cannot be used to fill your disk, the per-order callback secret was lengthened, and the optional IP allowlist no longer trusts a spoofable proxy header. No payment-flow change and no configuration required; the stored password is converted automatically the next time you save the gateway settings.

= 1.0.35 =
Important correctness release. The Preauthorization transaction type has been removed: completing a preauthorization requires a separate bank Web Service that was never implemented, so stores using it marked orders paid against funds that were only reserved and would expire uncollected. All transactions are now Sale, settled normally. Also fixes HashKey verification of signed decline callbacks on orders with more than one payment attempt, and a stale cache version on the Blocks checkout script. Contact the plugin author before updating if you deliberately used Preauthorization with manual completion in the AdminTool.

= 1.0.34 =
Aligns with Redirection Manual v3.1: Google Pay is accepted automatically (no configuration) on eligible devices and recorded on the order via the new PanCardType field. Also hardens failure-callback authentication (HashKey verified when the bank signs the response) and records recharge attempts on already-paid orders with a clear customer message. No payment-flow, database, or configuration change.

= 1.0.33 =
Security release. Fixes a payment-bypass flaw where the transaction ticket (the HMAC key that authenticates the bank callback) was exposed in the browser redirect form, letting a shopper forge a paid-order callback. Update immediately; no configuration changes required.

= 1.0.32 =
Docs-only release: adds a security-contact FAQ entry pointing to the Patchstack Vulnerability Disclosure Program. No code, database, or payment-flow change.

= 1.0.31 =
Docs-only release. Readme Description trimmed to fit the wp.org 2,500-word limit (the 1.0.30 plugin page was truncated). No code or behaviour change — the in-admin WAF diagnostic, IRIS support, installments picker and tiered installments from 1.0.30 are all preserved.

= 1.0.30 =
Adds IRIS payments support (auto-detected when Euronet enables IRIS on your account) and a customer-selectable installments dropdown on classic WC checkout with tiered max-installments by order amount. Existing installments config preserved. Blocks checkout customers default to one-time payment.

= 1.0.28 =
Compatibility with WordPress 7.0 (Modern admin theme, iframed editor, PHP 7.4 minimum). No payment-flow, database, or callback-handler change.

= 1.0.27 =
Settings screen reorganized: merchant credentials now sit above bank integration data, the bank-data card is collapsed by default, and the five callback URLs share one grey "Copy" block. No payment-flow, database, or callback-handler change.

= 1.0.20 =
Text domain corrected to match plugin slug. Cloudflare IP fetch documented in External services. echo wrapped with wp_kses_post(). No payment-flow or database change.

= 1.0.19 =
Plugin renamed to "WebHosting4U Secure Card Gateway for ePay Paycenter (Piraeus Bank)" with slug wh4u-* per Plugin Review Team feedback. External services fully documented with terms / privacy links. Deprecated libxml_disable_entity_loader() removed. No payment-flow change.

= 1.0.18 =
Checkout icon changed from piraeus.svg to a responsive wp-cards.png image (400 px desktop, scales on mobile). Block checkout now shows card brands. Front-end CSS enqueued on checkout. Drop-in upgrade.

= 1.0.17 =
Greek translation backfilled with all strings from 1.0.10-1.0.16: scenario labels, decline notices, admin notes, WAF self-test UI. POT regenerated. No PHP/database/payment-flow change.

= 1.0.16 =
Fixes decline notice not appearing after bank redirect. Hook priority collision with WooCommerce core resolved (priority 5 vs core 10). Works on every theme. No database or payment-flow change.

= 1.0.15 =
Aligns with Redirection Manual v2.9 section 5 scenario table. Fixes misleading ResultCode 1048 message, widens 50x matching to 500-599, adds AdminTool hint on 1045. Drop-in upgrade.

= 1.0.14 =
Fixes decline message not appearing after bank redirect due to SameSite=Lax stripping the session cookie. Notices now queued as order-scoped transients. No database or payment-flow change.

= 1.0.13 =
Fixes "-1" body when Success/Failure URL is doubled in the Euronet portal. Malformed wc-api values normalised at parse_request. Please also correct the URL in the portal.

= 1.0.12 =
Declined-transaction handling per Redirection Manual v2.9 section 5. Issuer decline shown to customer, failures redirect to pay-for-order URL. Fixes "-1" body via plugins_loaded binding.

= 1.0.11 =
Adds "Test callback URL" button on the settings screen. Detects host WAF interception (cPFence, ModSec, Imunify360, BitNinja, LiteSpeed). No database or payment-flow change.

= 1.0.10 =
Adds a "Callback envelope" forensic log line at every callback reach so
host-WAF-blocked transactions can be distinguished from plugin-rejected
ones, and refines the customer-facing wording per Paycenter ResultCode
and ResponseCode. Safe drop-in upgrade, no database change.

= 1.0.9 =
Greek translation rewritten in plain, merchant-friendly Greek. Banking
jargon replaced with everyday terms; field labels shortened so the
WooCommerce settings layout is not stretched. Safe drop-in upgrade.

= 1.0.8 =
Plugin Check compliance: adds the standard `defined( 'ABSPATH' ) || exit;`
guard at the top of the performant-translations `.l10n.php` file. No
runtime or database change, safe drop-in upgrade.

= 1.0.7 =
Plugin Review compliance: the bank redirect auto-submit helper is now
loaded via wp_enqueue_script() from a standalone asset file instead of
an inline `<script>` block in the receipt template. Safe drop-in
upgrade, no database or payment-flow change.

= 1.0.6 =
Adds a complete Greek (el) translation, regenerates the POT catalog from
the live source, and ships WP 6.5+ performant-translations `.l10n.php`
files next to every `.mo`. Safe drop-in upgrade, no database change.

= 1.0.5 =
Plugin Check compliance: sanitizes $_SERVER input on the settings
screen, switches statistics queries to $wpdb->prepare() with the %i
identifier placeholder, and trims the 1.0.2 upgrade notice under the
300-char limit. No functional change.

= 1.0.4 =
Adds Cloudflare auto-detection and a help notice on the gateway
settings page instructing store owners to email Euronet Merchant
Services to whitelist Cloudflare's IPv4 ranges. Safe drop-in upgrade.

= 1.0.3 =
Stops the WC-API callback endpoint from spamming the WooCommerce error
log with "Callback missing MerchantReference" entries when hit by bots,
scanners or direct browser visits. No functional or security change.

= 1.0.2 =
Redesigned settings screen with status overview and an auto-generated
Bank integration data block listing the exact Website, Referrer,
Success, Failure and Backlink URLs, server IP and response method
asked for by Euronet Merchant Services. Safe drop-in upgrade.

= 1.0.1 =
Compliance, security and packaging fixes. Recommended for all users;
no database migration required.

= 1.0.0 =
Initial release.

== Screenshots ==

1. ePay Paycenter gateway entry in **WooCommerce → Settings → Payments** with editable title and description shown to the customer. / Καταχώρηση πύλης ePay Paycenter στο **WooCommerce → Ρυθμίσεις → Πληρωμές** με επεξεργάσιμο τίτλο και περιγραφή.
2. Gateway settings page: **Merchant credentials** (AcquirerId, MerchantId, PosId, Username, Password) above **Bank integration data** with the Success / Failure / Backlink URLs grouped into a single grey block with a one-click Copy button. / Σελίδα ρυθμίσεων πύλης: **Στοιχεία πρόσβασης εμπόρου** πάνω από τα **Στοιχεία σύνδεσης με την τράπεζα**, με τα Success / Failure / Backlink URLs σε ενιαίο γκρι πεδίο και κουμπί αντιγραφής με ένα κλικ.
