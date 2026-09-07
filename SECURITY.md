# Security policy

## Supported releases

Security fixes are made against the latest published release. Reproduce an
issue on that release before reporting it when doing so is safe.

## Private reporting

Report vulnerabilities through
[GitHub private vulnerability reporting](https://github.com/skamprogiannis/resilient-gateway-for-epay-paycenter/security/advisories/new).
Do not open a public issue for a suspected vulnerability.

Include:

- the affected version and WordPress/WooCommerce versions;
- the preconditions and minimal reproduction steps;
- the expected and observed security boundary;
- impact and suggested remediation, if known; and
- synthetic requests or fixtures that reproduce the issue.

Do not include production credentials, password digests, TranTickets,
HashKeys, customer data, MerchantReferences, transaction IDs, database dumps,
or unredacted logs. If sensitive evidence is essential, first submit a private
report describing what is available and wait for handling instructions.

Merchant-account compromise, settlement disputes, AdminTool access, and
service-side incidents must also be reported to Euronet Merchant Services or
the appropriate bank contact. This project's reporting channel cannot inspect
or change provider systems.

## Disclosure

Please allow time to reproduce the issue, coordinate with upstream or the
payment provider when necessary, prepare a fix, and notify affected users.
Avoid testing against transactions or systems that you do not own or have
permission to assess.
