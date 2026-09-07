# Code quality review for 2.0.0

Eight review tracks examined the maintained 1.0.37.2 source, its call sites,
WordPress hooks, WooCommerce contracts, bank protocol references, and payment
qualification tests. The priorities were authenticated state changes,
recoverable settlement, and a public package independent of any deployment.

## 1. Duplication and module boundaries

Credential normalization was repeated in the gateway and follow-up client.
Open-ticket metadata and order notices also lived in the gateway even though
callback and recovery code needed them. These responsibilities are now small
shared modules: `Credentials`, `Open_Tickets`, `Order_Notices`, and
`Credential_Notice`. The admin script shares request and button-state helpers.

Keep callback HMAC authentication separate from follow-up response validation:
they use different trust mechanisms. Do not merge them merely because both
eventually complete an order. A broad database-repository rewrite offers less
certain benefit than the focused extractions and is deferred.

## 2. Shared type definitions

Generic array annotations hid differences between bank results, settings,
callback fields, and payment attempts. PHPStan array shapes now describe those
contracts beside the class that owns them; consumers import shared aliases.
Browser globals have a dedicated declaration file shared by the three scripts.

Keep domain-specific types with their owning module. A central class containing
every possible type would add an unrelated dependency to otherwise independent
modules.

## 3. Unused code

Reference searches included dynamic WordPress hooks and filters, templates,
tests, and release packaging. Unused gateway state, presentation rules, and a
stale qualification-summary script were removable. Optional payment-brand
artwork was removed because redistribution rights were not documented; a
validated icon URL filter remains available.

Knip's JavaScript package model cannot establish reachability for this
primarily PHP plugin. WordPress entry points must be traced explicitly before
deletion. Public extension hooks and compatibility helpers are not dead code
simply because the repository has no direct caller.

## 4. Dependency cycles

The gateway, bootstrap, callback handler, and recovery worker previously
referenced each other. Moving shared state and notice behavior into leaf
modules removes the reason for those back-references. A PHP token-based class
dependency check rejects strongly connected components; browser scripts remain
independent WordPress entry points.

Madge would only describe JavaScript module imports here. It would miss the
PHP cycle that affected payment code, so it is not the release gate.

## 5. Weak types

Strict JavaScript checking and PHPStan level 8 expose untyped collections,
nullable platform results, and order/refund unions. Narrow DOM elements before
using element-specific properties and validate settings at the WordPress
option boundary. Do not substitute a refund object for a payable order.

Untrusted XML, request input, database results, filters, and platform APIs still
need boundary validation. A `mixed` type at such a boundary is accurate;
unchecked propagation into payment logic is the problem. No broad baseline or
suppression should be used to make the type check pass.

## 6. Error handling

Unauthenticated declines could change orders, invalid success callbacks could
put orders on hold, and failed local settlement could discard useful recovery
state. Callbacks now require valid authentication before mutation. Recovery
persists a verified approval as `paid_unsettled` before applying it locally,
then retries that approval if WooCommerce cannot complete the order.

Keep catches around network/XML operations, local settlement, and optional
logging: these failures have defined outcomes. Remove silent success fallbacks.
A callback diagnostic must recognize the actual callback response, and privacy
checks must distinguish a clean scan from a failed scan.

## 7. Legacy behavior

The unused mixed-case action registration and redundant loading guards were
removable. Credential storage now has an ordered, repeatable migration.

The Papaki callback alias, existing gateway/settings/meta/table identifiers,
older open-ticket readers, and browser copy fallback remain intentional
compatibility surfaces. Removing them would break installed merchants without
improving the active payment flow. The alias yields while Papaki is active.
Sale-only processing also remains explicit; no unused preauthorization flow
is presented as supported.

## 8. Documentation and repository hygiene

Deployment-specific names, translations, release notes, and examples made the
package unsuitable for reuse. Public branding and a consistent text domain
replace them. Legal provenance remains in `NOTICE.md` and `UPSTREAM.md`.
Operations and protocol guides describe current behavior; comments should
explain invariants or protocol constraints rather than narrate past edits.

The public repository includes synthetic qualification fixtures. Production
databases, logs, credentials, screenshots, and bank manuals stay outside it.
Release packaging uses an explicit runtime allowlist. Migration instructions
warn against WordPress Admin deletion of the old package because its uninstall
routine removes state shared with the renamed package.

## Verification

The release gates are PHP syntax checks, WordPress coding standards, PHPStan
level 8, strict JavaScript checking, dependency-cycle checks, privacy scans,
and synthetic payment integration tests. The browser matrix covers classic
and Blocks checkout. Tests must include invalid and replayed callbacks,
concurrent attempts, pending IRIS, missing responses, and local settlement
failure. These checks establish plugin behavior; provider-side settlement
still requires merchant onboarding and reconciliation procedures.
