# Operations guide

This guide covers merchant setup, missing-response recovery, monitoring, and
safe migration. It does not replace the onboarding instructions or merchant
agreement supplied by Euronet Merchant Services.

## Initial setup

1. Obtain separate Test and Live credentials from Euronet Merchant Services.
2. Install the plugin and open **WooCommerce → Settings → Payments → ePay
   Paycenter**.
3. Select the intended environment and enter AcquirerId, MerchantId, PosId,
   Username, and Password exactly as issued.
4. Register the technical values displayed in **Bank integration data**. For a
   new installation, callbacks use `/?wc-api=epay_paycenter`. Copy the values
   from the current site instead of typing them from this example.
5. Confirm the server's outbound IP with the hosting provider. The website's
   inbound address can differ behind NAT, a proxy, or a load balancer.
6. Run **Test callback URL** and complete the provider's required test cases
   before enabling the Live environment.

The callback diagnostic sends a synthetic declined-shaped POST from WordPress
to its own public callback URL. It can reveal DNS, loopback, TLS, and common
host-WAF problems. It does not prove that Paycenter can reach the site from the
provider network.

If the site is behind Cloudflare, review the current Cloudflare ranges shown by
the plugin with the hosting provider and Euronet Merchant Services. Callback IP
allowlisting is optional defense in depth; cryptographic and per-attempt checks
remain the authorization boundary.

## Enabling missing-response recovery

Automatic recovery is off by default.

1. In the ePay AdminTool, choose an existing transaction that is final and
   successful.
2. In **Missing-response recovery**, enter the corresponding WooCommerce order
   ID and select **Verify follow-up channel**.
3. The read-only test checks `eCommerce` and `3DSecure` using AcquirerID
   `GR014`. It reads the bank result and does not modify the order.
4. Confirm that the card shows a verified channel, response code, and
   transaction time.
5. Enable automatic recovery and save the gateway settings.

Verification alone does not enable order changes. Changing the environment or
merchant credentials invalidates the verification; repeat the test before
re-enabling recovery.

## Recovery behavior

The worker checks unresolved attempts approximately 5, 15, and 30 minutes and
1, 2, 4, 8, 24, and 48 hours after ticket creation. WordPress cron depends on
site traffic, so these are eligibility checkpoints, not guaranteed execution
times.

A result can complete an order only when it has:

- the expected merchant, POS, username, ChannelType, and MerchantReference;
- `ResultCode=0` and `StatusFlag=Success`;
- an approved ResponseCode accepted by the client; and
- complete support ID, transaction ID, and transaction timestamp fields.

ResultCode `1010` remains inconclusive and is retried. Network, parsing, or
identity errors never mark an order paid.

FOLLOW_UP `Failure/09` also stays retryable unless the response explicitly
identifies a card and contains no IRIS fields. A missing payment method is not
proof of a card decline. Upgrading to 2.0.1 resumes ambiguous attempts that an
older parser closed. It does not itself change an order's status. Attempts
already older than 48 hours get one further check and then a review case if
the bank result remains inconclusive.

While recovery is active, stock for this gateway is reserved for 240 minutes by
default. The merchant setting accepts 60 to 1440 minutes. Recovery continues
until 48 hours after ticket creation, not 48 hours after stock release.
If a payment is recovered after an
order became cancelled or failed, verify stock and fulfilment before dispatch.

## Alerts requiring review

Routine successful recovery does not create a global warning. The plugin asks
for human review when it detects:

- an approved payment for a cancelled, failed, trashed, deleted, or otherwise
  detached order;
- a bank-approved result that WooCommerce could not persist as paid;
- more than one successful MerchantReference for one order; or
- an attempt that remained unresolved after the bounded checks.

Use the order notes and MerchantReference to compare WooCommerce with the ePay
AdminTool. Never infer settlement from the customer's browser screen. Do not
fulfil a warning case until amount, currency, final status, transaction ID, and
reference agree.

Reviews appear only on classic/HPOS order lists, order details, and this
gateway's settings page. Historical attempts first checked after the recovery
window are collapsed separately. An old date never hides a confirmed payment
discrepancy.

After checking a case, select **Mark reviewed**. This records the reviewer and
UTC time without changing the order or deleting bank evidence. Reviewed cases
remain available in gateway settings. Changing an order to Processing alone
does not establish that its bank checks were resolved.

## Cancellation returns

An authenticated bank cancellation returns to checkout, not the cancelled
order's payment link. The notice appears once and advises customers to contact
the shop before paying again if their bank shows a charge. The current cart is
left untouched. If browser cookies were lost, the plugin does not reconstruct
another session's cart or expose its customer details.

## IRIS pending responses

IRIS ResponseCode `09` is intermediate. The plugin places the order on hold and
directs the customer away from the pay-again page to reduce duplicate-payment
risk. Verify the final bank state before fulfilment or cancellation. If
automatic recovery is disabled, staff must perform this verification in the
AdminTool.

This plugin does not implement an IRIS refund operation. Follow the process
specified by the payment provider for any required correction.

## Logs and privacy

WooCommerce logs use source `epay-paycenter`. The callback envelope records
the request method and the names and count of present callback fields. Later
verified paths can record order and transaction identifiers and result codes.
Credential keys are matched case-insensitively; password digests, TranTickets,
HashKeys, and likely card numbers are redacted.

Enable detailed logging only while diagnosing a problem. Export only the
minimum redacted lines needed for support, apply the site's retention policy,
and never post transaction data in a public issue.

Follow-up results include reference, channel, result/response code, state,
transaction ID/time, payment method, and IRIS status. These operational records,
order notes, and the attempt table cover routine payment monitoring. A separate
checkout investigation logger can be deactivated after exporting its evidence;
retain it inactive for future browser, JavaScript, or delivery-widget problems.
Existing detailed Ticketing logs may contain customer contact/address fields;
keep them private and apply a retention policy.

## Legacy Papaki cutover

The compatibility adapter listens on the historical
`WC_Piraeusbank_Gateway` WC-API route and forwards it to this plugin's normal
handler only while the Papaki plugin is inactive. Success or failure is derived
from the Paycenter response, not from the `peiraeus` query value. Cancellation
also requires the order ID and per-attempt token generated in this plugin's
backlink.

For a safe switch:

1. Stop initiating new payments during a quiet period.
2. Allow in-flight payments to return to the plugin that issued their ticket.
3. Deactivate Papaki.
4. Activate and configure this gateway.
5. Test the registered callback URL before reopening checkout.

Do not keep both gateways active. To roll back, drain in-flight attempts again,
deactivate this plugin, and reactivate the previous gateway. The compatibility
route is intended to avoid an immediate bank-side URL change; it cannot verify
a callback whose ticket was issued by a different active plugin.

## Migration from an earlier private package

Version 2.0.0 has a different public package name and folder. Its WooCommerce
gateway ID, settings key, order metadata, and attempt table remain unchanged,
so existing configuration and payment history are reused.

1. Pause new ePay checkouts during a quiet period.
2. Let every in-flight payment return to the package that issued its ticket.
3. Record the active environment and whether missing-response recovery is
   enabled, without exporting secret values.
4. Deactivate the earlier private package.
5. Install and activate Resilient Gateway for ePay Paycenter 2.0.0.
6. Confirm that the existing gateway settings are present and correct.
7. Run the callback diagnostic and confirm the saved `FOLLOW_UP` verification.
8. Complete a controlled test transaction before reopening checkout.
9. Keep the inactive package for rollback until the new package has been
   verified, then remove its folder through the hosting file manager or shell.

Do not use **Delete** in WordPress Admin for the previous package. Its uninstall
routine removes the settings and attempt table shared with this version.

Never activate both packages together. Both register the same gateway and
callback identifiers, and an in-flight response depends on the one-time secret
held by the package that issued it.

## Update checklist

- Read the upgrade notice and `CHANGELOG.md`.
- Back up WordPress and the database through the site's normal process.
- Record the installed version and current gateway settings without exporting
  secret values.
- Install the tagged release archive from the project repository and verify
  its published checksum.
- Confirm the gateway remains enabled and uses the intended environment.
- Run the callback diagnostic.
- If credentials or recovery behavior changed, verify the `FOLLOW_UP` channel
  again.
- Monitor WooCommerce logs and order notes during the first test transaction.
