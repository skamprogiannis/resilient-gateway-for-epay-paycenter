# Resilient Gateway for ePay Paycenter

A WooCommerce payment gateway for the ePay Paycenter Redirection service
operated by Piraeus Bank and Euronet Merchant Services. It redirects payment
card, IRIS, and eligible Google Pay transactions to the hosted Paycenter page,
authenticates callbacks, and can recover a final bank result when the normal
callback is missing.

This independent GPL-2.0-or-later project is based on the WebHosting4U plugin.
It is not affiliated with or endorsed by Piraeus Bank, Euronet Merchant
Services, WebHosting4U, WooCommerce, or Automattic. See [NOTICE.md](NOTICE.md)
and [UPSTREAM.md](UPSTREAM.md) for attribution and provenance.

## Requirements

- WordPress 6.3 or later
- PHP 7.4 or later
- WooCommerce 7.0 or later
- An ePay Paycenter merchant agreement and credentials issued by Euronet
  Merchant Services

The plugin does not provide a Paycenter account or shared test credentials.

## Features

- Sale transactions through Paycenter's hosted payment page
- HMAC-SHA256 verification before a successful callback can complete an order
- Separate records for concurrent payment attempts and idempotent settlement
- IRIS response handling, including a safe on-hold state for an initiated
  payment whose final result is not yet known
- Optional `FOLLOW_UP` recovery for missing callbacks
- HPOS, classic checkout, and Checkout Blocks support
- Classic-checkout installment selection with optional amount tiers; Blocks
  checkout uses a one-time payment (`Installments=1`)
- A callback reachability diagnostic and optional callback IP allowlist
- Compatibility with callback URLs previously registered for the Papaki
  `WC_Piraeusbank_Gateway` plugin while that plugin is inactive

The gateway intentionally supports Sale only. It does not implement
preauthorization completion or refunds through Paycenter.

## Installation

1. Download a release archive from the
   [releases page](https://github.com/skamprogiannis/resilient-gateway-for-epay-paycenter/releases).
   Choose `resilient-gateway-for-epay-paycenter-VERSION.zip` under **Assets**,
   not GitHub's automatic **Source code** archives.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, select the
   archive, and activate it.
3. Open **WooCommerce → Settings → Payments → ePay Paycenter**.
4. Enter the test or live credentials supplied for that environment.
5. Give Euronet Merchant Services the integration values shown on the settings
   page. For a new installation, the callback endpoint is
   `https://example.com/?wc-api=epay_paycenter`.
6. Run the callback diagnostic and complete the test cases required by your
   Paycenter onboarding documentation before enabling live payments.

Missing-response recovery is disabled by default and requires a separate,
read-only channel verification. Follow [the operations guide](docs/operations.md)
before enabling it.

## Compatibility

The public WooCommerce gateway ID remains `epay_paycenter`. Existing settings,
order metadata, and attempt-table data continue to use the established keys so
that changing the package name does not create a second payment method.

Version 2.0.0 uses a new public package name and folder. When replacing an
earlier private build, first let in-flight payments return, deactivate the old
package, then install and activate this one. Do not run both packages at once.
The existing gateway settings and payment records remain available through the
stable gateway ID; verify the environment, credentials, callback, and recovery
status before removing the inactive package's folder through the hosting file
manager or shell. Do not use WordPress Admin's **Delete** action: the old
uninstaller removes the settings and attempt table shared with this version.

For a migration from the Papaki gateway, read the cutover procedure in
[docs/operations.md](docs/operations.md). Do not switch gateways while a
customer payment is in progress.

## Development

This repository contains only source and documentation suitable for public
development. Do not use production credentials, transaction exports, database
backups, or customer data in tests or issues.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the local qualification workflow and
[docs/protocol.md](docs/protocol.md) for the payment and recovery invariants.
The [code quality review](docs/code-quality-review.md) records the refactoring
decisions and compatibility code deliberately retained in 2.0.0.
Install the development dependencies and run the static checks with:

```bash
composer install
npm ci
composer test
npm test
```

Release archives are built from an exact clean tag with:

```bash
scripts/build-release.sh
```

## Support and security

Use [GitHub issues](https://github.com/skamprogiannis/resilient-gateway-for-epay-paycenter/issues)
for reproducible non-sensitive bugs. Ask Euronet Merchant Services about
merchant activation, credentials, settlement, and AdminTool records.

Do not disclose vulnerabilities or transaction data in a public issue. Follow
[SECURITY.md](SECURITY.md) for private security reporting.

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
