# Contributing

Contributions are welcome when they preserve the gateway's payment and
compatibility invariants.

## Development environment

Use a local or disposable WordPress installation with WooCommerce. Never point
a development copy at live Paycenter credentials or allow it to submit real
payments. Use synthetic provider responses and dedicated test credentials when
the provider has issued them for that purpose.

Do not add production databases, uploads, backups, logs, screenshots, customer
messages, merchant forms, bank statements, or AdminTool exports to the
repository. Bank manuals may be cited by title and version but must not be
redistributed without permission.

## Compatibility invariants

- Keep the WooCommerce gateway ID `epay_paycenter` and its settings key stable.
- Preserve established order-meta and attempt-table keys unless a tested
  migration is included.
- Authenticate a bank result before changing an order to paid.
- Keep callback handling idempotent and bind each callback to its exact
  MerchantReference and attempt.
- Never expose a TranTicket, password digest, or HashKey to the browser or logs.
  Cancel tokens belong only in the per-attempt backlink and server-side state;
  keep them out of logs.
- Treat an initiated IRIS payment as unresolved, not as permission to invite a
  second payment.
- Keep automatic `FOLLOW_UP` recovery opt-in and require channel verification
  after credential changes.
- Preserve the legacy callback alias and yield it to the Papaki plugin while
  that plugin is active.

Read [docs/protocol.md](docs/protocol.md) before changing the payment state
machine.

## Code and documentation

- Follow WordPress PHP and escaping conventions and support the versions listed
  in `readme.txt`.
- Sanitize untrusted input at the boundary and escape output at render time.
- Keep security and payment-invariant comments; remove comments that only
  narrate implementation history.
- Add or update tests for every behavior change, including invalid and replayed
  callbacks.
- Keep customer-facing and administrator strings translatable and update the
  Greek catalog when English source strings change.
- Describe behavior factually. Do not promise provider features that the plugin
  does not implement.

## Local checks

Install development dependencies, then run the static checks:

```bash
composer install
npm ci
composer test
npm test
```

The self-contained qualification harness creates a fresh WordPress and
WooCommerce site with synthetic orders. It intercepts Paycenter requests and
does not require merchant credentials or database backups. With Docker Compose
available, run:

```bash
npx playwright install --with-deps
bash scripts/prepare-tests.sh
npm run test:integration
npm run test:matrix
docker compose -f compose.test.yml down
```

The integration suite exercises callbacks, concurrent attempts, recovery,
settings, and migrations in Chromium. The matrix checks classic and Blocks
checkout across seven browser configurations. Add `-v` to the shutdown command
only when you want to reset the synthetic database.

Run syntax and whitespace checks before submitting:

```bash
find . -name '*.php' -not -path './dist/*' -not -path './vendor/*' -print0 \
  | xargs -0 -n1 php -l
node --check assets/js/blocks.js
node --check assets/js/epay-paycenter-admin.js
node --check assets/js/epay-paycenter-redirect.js
git diff --check
```

Exercise successful, declined, invalid-signature, cancelled, replayed,
concurrent-attempt, pending-IRIS, and missing-callback paths in a credential-free
test boundary. Verify classic and Blocks checkout separately; Blocks currently
uses `Installments=1` rather than exposing the classic installment selector.

## Translations

Use WP-CLI's `i18n make-pot` command with the
`resilient-gateway-for-epay-paycenter` slug and domain. Restrict extraction to
the entrypoint, `includes`, `templates`, and `assets/js` so fixtures do not add
test-only messages. Merge the POT into the Greek PO with `msgmerge`, translate
new messages, and remove obsolete entries with `msgattrib --no-obsolete`.
Validate and compile the catalog with `msgfmt --check`, then run
`wp i18n make-php languages/resilient-gateway-for-epay-paycenter-el.po languages`
to update WordPress's PHP translation file. Commit the POT, PO, MO, and PHP
catalog together.

## Pull requests

Keep each change focused. Explain the payment invariant affected, the tests
run, and any operator action required. Use synthetic identifiers in examples.
For security-sensitive work, follow [SECURITY.md](SECURITY.md) instead of
opening a public pull request before coordinated disclosure.

Release tags and archives are created by maintainers only. The packaging script
requires a clean commit tagged with the version declared by the plugin.
