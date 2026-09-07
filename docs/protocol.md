# Payment protocol and invariants

This document describes the behavior implemented by the plugin. It is not a
substitute for the current merchant documentation supplied by Euronet Merchant
Services.

## Reference documents

The imported gateway and subsequent changes refer to:

- ePay Paycenter Redirection Manual versions 2.9 and 3.1; and
- ePay Transaction Web Service Manual version 2.4, `FOLLOW_UP` request.

Version 3.1 introduced the `PanCardType` response used to identify eligible
Google Pay transactions. Existing response and scenario handling retains
citations to the earlier 2.9 sections against which it was implemented. The
bank manuals are not redistributed by this project; confirm current onboarding
requirements with Euronet Merchant Services.

## Payment sequence

1. WooCommerce creates or loads the order and the gateway generates a
   MerchantReference containing the numeric order ID and a random suffix.
2. The server calls `IssueNewTicket` with merchant credentials, amount,
   currency, one or more installments, and permitted 3-D Secure fields.
3. The returned TranTicket is stored server-side and associated with that exact
   attempt. It is never included in the browser form.
4. The browser posts merchant identifiers, language, MerchantReference, and
   ParamBackLink to Paycenter's hosted payment page.
5. Paycenter posts the transaction response to the WooCommerce WC-API callback.
6. The handler resolves the order and matching open attempt, authenticates the
   result, performs an idempotent status transition, records operational fields,
   and removes one-time secrets after verified settlement.

Every ticket issuance is a distinct attempt. A reload, second tab, or retry
must not replace another attempt's MerchantReference, TranTicket, or cancel
token.

## Transaction type and installments

`RequestType` is always `02` (Sale). The gateway does not implement the
separate settle operation required to complete a preauthorization. It also does
not expose a Paycenter refund operation.

An installment value of `1` represents a single payment. Classic checkout can
show a selector governed by the configured flat maximum, minimum order amount,
or amount tiers. The server clamps submitted values again before Ticketing.
Checkout Blocks does not expose that selector and therefore uses `1`.

## Callback routes

The canonical route is:

```text
/?wc-api=epay_paycenter
```

WooCommerce can also resolve its path form. Merchants should register the exact
URLs generated on the current gateway settings page.

The compatibility adapter also listens for the historical Papaki route:

```text
/wc-api/WC_Piraeusbank_Gateway
```

It yields when the Papaki plugin is active. Legacy success and failure query
values do not decide order state; the response payload does. A cancellation is
accepted only with the matching attempt's order ID and constant-time-checked
token.

## Authentication and order state

The callback URL is public. Reaching it is never evidence that a request came
from the bank.

For a successful response, the handler requires an exact open
MerchantReference, the matching TranTicket, and a valid HMAC-SHA256 HashKey
over the documented response fields before calling WooCommerce
`payment_complete()`.

Every callback that changes an order requires a valid HashKey, including a
decline. Paycenter can omit HashKey on documented declines; those responses
leave the order unchanged because a MerchantReference identifies an attempt
but does not authenticate its result. Use verified `FOLLOW_UP` recovery or
the AdminTool to resolve the payment. An optional IP allowlist is defense in
depth and does not replace signature verification.

Successful callbacks are idempotent. A response for an already-paid order does
not replace the recorded settlement. A documented recharge response can be
recorded for audit without changing the paid order.

An invalid signature or missing matching TranTicket leaves order status and
metadata unchanged. Authenticated declines become failed and return the
customer to an order-aware retry page.

## IRIS and Google Pay

IRIS is identified from the HMAC-verified response using the Paycenter
CardType/PaymentMethod fields. IRIS ResponseCode `09` means initiated but not
final: the order becomes on hold, the customer is told not to pay again, and
the response is not treated as a decline. Other IRIS outcomes follow their
provider response code.

Google Pay follows the normal card success path. A non-empty `PanCardType` of
`FPAN` or `DPAN` is stored as provider-returned operational metadata and used
to annotate the order. The plugin never receives the underlying PAN value.

## Missing-response recovery

`FOLLOW_UP` uses a server-to-server request with AcquirerID `GR014` and the
merchant credentials already configured for Ticketing. Before activation, an
administrator must query a known successful order in read-only mode. The plugin
tries `eCommerce` and `3DSecure` and stores the channel that returns the valid
transaction. Credential or environment changes invalidate that verification.

A recovered result is accepted as paid only when all of these conditions hold:

- response request type is `FOLLOW_UP`;
- MerchantID, PosID, username, ChannelType, and MerchantReference echo the
  request;
- `ResultCode` is `0`;
- `StatusFlag` is `Success`;
- ResponseCode is one of `00`, `08`, `10`, or `16`; and
- support reference, numeric transaction ID, and parseable transaction time
  are present.

`Failure` is a declined result. `Pending` and ResultCode `1010` remain
inconclusive. Transport, XML, identity, or incomplete-success errors do not
complete the order.

Automatic checks use bounded checkpoints through 48 hours. One worker holds a
per-order lock, limits each batch and run time, and persists bank approval as
`paid_unsettled` before completing the WooCommerce order. If that local
transition fails, a later run retries the stored approval without asking the
bank to approve it again. A manually selected Processing status does not prove
that this gateway previously settled the payment. A late success does
not silently restore stock; it produces a fulfilment warning. Multiple approved
references are preserved and flagged rather than collapsed into one result.

## Stored data and retention

The attempt table associates each order with its MerchantReference,
TranTicket-derived authentication material, merchant identifiers, amount,
currency, installment count, lifecycle status, and timestamps. Recovery adds
query state, retry timestamps, response/result codes, support and transaction
IDs, transaction time, payment method, and IRIS status/ID. Raw SOAP, PAN, CVV,
card expiry, and masked-card fields are not retained by the recovery client.

Resolved attempt rows are pruned after 90 days by default. Unresolved rows
remain for manual reconciliation. Uninstall removes plugin settings and the
attempt table but intentionally leaves historical WooCommerce order metadata.

## Extension points

All values returned by these filters are validated or bounded before use:

- `epay_paycenter_icon`: replace the checkout icon URL.
- `epay_paycenter_allowed_callback_ips`: provide additional callback source IPs
  or CIDRs as defense in depth.
- `epay_paycenter_trusted_proxy_ips`: identify proxies allowed to supply the
  original callback address through `CF-Connecting-IP`.
- `epay_paycenter_outbound_ip`: display a hosting-provider-confirmed IPv4 or
  IPv6 address in the onboarding panel.
- `epay_paycenter_3ds_fields`: supply only `BillAddrLine3`, `ShipAddrLine3`,
  `HomePhone`, `MobilePhone`, or `WorkPhone` from data genuinely collected by
  the store. The filter receives the `WC_Order` as its second argument.
- `epay_paycenter_reconcile_batch_size`: orders considered per worker run,
  constrained to 1–100.
- `epay_paycenter_reconcile_time_budget`: worker time budget in seconds,
  constrained to 10–240.
- `epay_paycenter_reconcile_retention_days`: retention for resolved attempt
  rows, with a minimum of one day.

Do not use filters to bypass callback authentication, invent fraud-scoring
data, or send fields the customer did not provide.
