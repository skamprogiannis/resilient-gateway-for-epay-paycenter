# Changelog

Notable changes to Resilient Gateway for ePay Paycenter are recorded here.
Release artifacts and checksums belong in GitHub Releases.

## Unreleased

## 2.0.0

- Adopt the public project name and repository identity while preserving the
  WooCommerce gateway ID and stored data keys.
- Replace private rollout notes with public operations, protocol, security,
  and contribution guides.
- Remove unused presentation rules and exclude repository-only material from
  release archives.
- Remove third-party payment-brand artwork whose public redistribution rights
  were not documented.
- Consolidate merchant credential normalization and add strict PHP and
  JavaScript development checks.
- Require authenticated declines before changing order state, bound callback
  input, and accept forwarded callback IPs only from configured trusted proxies.
- Preserve verified bank approval when local settlement fails so recovery can
  retry it without another bank request.
- Recover after partially saved callbacks, record native transaction IDs for
  bank-confirmed manually processed orders, and preserve the original payment
  identity when retrying a second successful attempt.
- Register Checkout Blocks at its payment-registry event so the gateway is
  available regardless of plugin loading order.
- Require the actual handler response for a successful callback diagnostic.
- Remove unused fallbacks and historical implementation commentary.
- Document migration from the differently named private package: drain
  in-flight payments, deactivate the old package, activate 2.0.0, verify the
  preserved settings, and only then remove the inactive folder through hosting
  tools. WordPress Admin deletion would run the old shared-state uninstaller.

## 1.0.37.2

- Refresh the verified `FOLLOW_UP` channel in the settings card immediately
  after a successful read-only test.

## 1.0.37.1

- Add opt-in missing-response recovery through the ePay Transaction Web
  Service.
- Require a read-only channel verification before automatic recovery can be
  enabled.
- Poll unresolved attempts at bounded checkpoints through 48 hours and keep
  stock reserved for 240 minutes by default.
- Validate merchant identity, channel, MerchantReference, approval fields, and
  transaction identifiers before completing an order.
- Warn staff about late, detached, unresolved, locally unpersisted, or possibly
  duplicated payments.

## 1.0.37

- Improve Greek telephone routing for 3-D Secure fields and add the
  `epay_paycenter_3ds_fields` filter.
- Add administrator warnings for rejected Ticketing credentials and unresolved
  payment attempts.
- Keep initiated IRIS ResponseCode `09` orders on hold.
- Correct cancel-token matching across concurrent attempts.
- Make the gateway logging option effective without requiring `WP_DEBUG`.
- Distinguish the website address from the outbound IP required during merchant
  onboarding and add `epay_paycenter_outbound_ip`.

## 1.0.36.2

- Add a cutover adapter for callback URLs registered by the Papaki
  `WC_Piraeusbank_Gateway` plugin. The adapter yields to Papaki whenever that
  plugin is active.

## 1.0.36.1

- Store overlapping payment attempts independently so a later request cannot
  overwrite another attempt's MerchantReference and TranTicket.
- Match cancellation against every open attempt and clear all one-time secrets
  after verified settlement.

## 1.0.36

- Store the bank-required MD5 password digest instead of plaintext.
- Rate-limit diagnostic writes to the public callback log.
- Lengthen the random MerchantReference suffix.
- Trust `CF-Connecting-IP` for an optional callback allowlist only when the
  request is confirmed to have arrived through Cloudflare.

## 1.0.35

- Remove unsupported preauthorization; all transactions now use Sale.
- Verify signed decline callbacks with the ticket belonging to the matching
  attempt.
- Use the plugin version for Checkout Blocks asset cache-busting.

## 1.0.34

- Record Paycenter's `PanCardType` for eligible Google Pay responses.
- Authenticate signed non-success callbacks before changing order state.
- Record recharge attempts against already-paid orders without replacing the
  original settlement.

## 1.0.33

- Keep the TranTicket server-side. Earlier releases exposed this HMAC secret in
  the redirect form; merchants upgrading from those releases should reconcile
  historical paid orders with bank settlements.

## 1.0.32–1.0.22

- Add coordinated vulnerability reporting, WAF diagnostics, IRIS support,
  classic-checkout installment selection, Greek translations, WordPress 7.0
  compatibility, and WordPress.org packaging corrections.
- Correct the callback flow, customer decline notices, text domain, and plugin
  slug used by the upstream distribution.

## 1.0.21–1.0.0

- Establish the Paycenter Ticketing, redirect, callback, settings, logging,
  translation, HPOS, and Checkout Blocks integrations.

The unmodified upstream import and its checksum are documented in
[UPSTREAM.md](UPSTREAM.md).
