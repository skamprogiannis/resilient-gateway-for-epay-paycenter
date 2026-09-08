# Notices and attribution

Resilient Gateway for ePay Paycenter is GPL-2.0-or-later software maintained by
Stephanos Kamprogiannis (skamprogiannis).

## Upstream work

This project is derived from **WebHosting4U Secure Card Gateway for ePay
Paycenter (Piraeus Bank)**, published under GPL-2.0-or-later. The imported
version, source archive, checksum, and immutable import tag are recorded in the
[upstream provenance record](https://github.com/skamprogiannis/resilient-gateway-for-epay-paycenter/blob/main/UPSTREAM.md).
Copyright and authorship belong to their respective contributors.

The downstream history includes modifications originally developed for a
WastelessDesign deployment. This notice preserves that attribution; the public
repository starts with an upstream import and a sanitized maintained version.
The project is not operated by or limited to that store.

Source modifications add callback hardening, concurrent-attempt handling,
legacy Papaki callback compatibility, missing-response recovery, diagnostics,
and public-project packaging. See the
[changelog](https://github.com/skamprogiannis/resilient-gateway-for-epay-paycenter/blob/main/CHANGELOG.md)
for a concise history.

On 2026-09-07 the fork contributors corrected ambiguous FOLLOW_UP handling,
secured single-delivery cancellation notices, and added scoped staff reviews.
The staff review update separates payment discrepancies from unconfirmed
attempts and adds credential-bound recovery-run status and Greek labels.

On 2026-09-08 the fork contributors preserved valid classic-checkout installment
selections across payment-section refreshes and added a deterministic regression.
The same date's recovery update adds a bounded, configurable recheck window
and combines verification and configuration in one settings card.

## Trademarks and services

“ePay”, “Paycenter”, and “Piraeus Bank” are trademarks of Piraeus Bank S.A.
and/or Euronet Merchant Services. “Google Pay” is a trademark of Google LLC.
“WooCommerce” is a trademark of Automattic Inc. Other names and marks belong to
their respective owners.

These names are used only to identify services with which the software is
compatible. This project is not affiliated with, endorsed by, sponsored by, or
otherwise officially connected to Piraeus Bank, Euronet Merchant Services,
Google, WebHosting4U, WooCommerce, or Automattic.

No third-party payment-brand artwork is bundled in maintained release ZIPs.
This avoids implying an asset licence or endorsement beyond the descriptive
trademark use above.

## License

The complete GPL version 2 text is in [LICENSE.txt](LICENSE.txt). Distribution
and modification must preserve applicable copyright, license, attribution, and
trademark notices.
