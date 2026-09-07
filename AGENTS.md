# Resilient Gateway for ePay Paycenter

## Repository Policy

- `upstream` and tags named `upstream-*` contain immutable WordPress.org imports.
- `main` contains the maintained public fork.
- Preserve GPL-2.0-or-later licence, upstream copyright, attribution, trademark notices, and dated modification notices.
- Keep the plugin slug distinct while preserving gateway ID `epay_paycenter` and its WooCommerce settings key.
- Never commit merchant credentials, TranTickets, callback HashKeys, production logs, order exports, or customer data.
- Do not add an in-plugin GitHub token or an updater that can collide with the WordPress.org package.

## Change Policy

- Keep downstream payment behavior changes minimal and documented in `readme.txt`.
- Store concurrent payment attempts independently; never restore a shared serialized read-modify-write attempt map.
- Authenticate callbacks before changing order state and keep paid-order handling idempotent.
- Remove one-time ticket and cancel secrets after successful verification.
- Keep authorship and dated modification history in `NOTICE.md`, `UPSTREAM.md`,
  and `CHANGELOG.md`; source comments should explain current invariants.

## Qualification

Install PHP and JavaScript development dependencies and run the static checks:

```bash
composer install
npm ci
composer test
npm test
```

The repository includes a disposable Docker qualification site with synthetic
orders and a fake Paycenter transport. It requires Docker Compose, Node.js, and
Playwright browsers:

```bash
npx playwright install --with-deps
bash scripts/prepare-tests.sh
npm run test:integration
npm run test:matrix
docker compose -f compose.test.yml down
```

Add `-v` to the shutdown command only when resetting the synthetic database.
The harness mounts this source read-only and intercepts Paycenter traffic.
Never use production credentials, backups, or real payments in it.

Build a release only from a clean, qualified commit:

```bash
scripts/build-release.sh
```
