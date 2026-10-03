# License platform hardening: review and upgrade

Branch: `feature/license-platform-hardening`. Baseline: `7c47668741c062da4ea49b26f197d1a081da4ea6`. Existing changes after the baseline and concurrent user commits are preserved. The installed versions are Symfony 8.1, PHP >=8.4, Doctrine ORM 3.7/DBAL 4.5 and EasyAdmin 5.6. Each of the thirteen requested sections was implemented and checked in order; see [work log](PLATFORM_HARDENING.md) for per-section evidence and commits.

## 1. Implemented changes

- Own-profile image content validation/re-encoding, 5 MB and dimension limits, random filenames and Apache/Nginx execution protection.
- Central roles including Super Admin; HTTP/persistence protection against unauthorized Super Admin changes.
- Explicit license transitions, separate administrative withdrawal of revocation, reasons/actors/history, expiry rules and transactional row locks.
- Canonical versioned audit hashes, serialized database chain head, masked fields, transaction rollback, legacy verification, CLI integrity verification and external checkpoints.
- Pinned CI Actions/dependencies/runtime matrix, disposable database installation/upgrade tests, fixed protocol vectors and unchanged real Contao validator source.
- Optional or role/action-required TOTP, controlled switch/recovery, recovery-code regeneration, CSRF/rate limits, session revocation and independently encrypted secrets.
- Prepared/active/verification-allowed/retired/revoked signing keys, lease-aware retirement, emergency revocation, reviewed activation/history and signed trust manifests.
- Explicit customer assignments, global/scoped staff permissions and cross-customer request/form/ORM/query/history guards.
- Liveness/readiness, sign/DB readiness checks, structured request logs, bounded metrics and reproducible database/key/configuration backup/restore.
- Configurable localized asynchronous expiry reminders, persistent duplicate suppression, retry/delivery history and suppression after renewal/revocation/archive.
- Separate customer portal with own license/installations/features/history, protected signed downloads and limited audited domain changes.
- Customer-scoped hashed API credentials, scopes/expiry/revocation/one-time secrets, persistent creation/renewal idempotency and signed versioned queued webhooks with safe targets.
- Declarative product features/quotas, license plans, installation/duration/update rights and frozen grants; migration preserves existing license fields and replaces the former hardcoded SLA condition.

README, operating guides, German/English/French/Spanish translations and exported OpenAPI are updated. No production migrations, deployments, key rotations, external webhook deliveries or real customer mail were executed.

## 2. Executed checks

DDEV PHP **8.4.24** and MariaDB **11.8**:

- Complete PHP suite: **155 tests / 1361 assertions**, successful, no skipped database or real-client cases. This includes independent concurrent transactions, full migration chain/new installations/interrupted upgrades, data preservation, actual HTTP requests, recovery into an empty second database, Doctrine queue processing with a Null mail transport and both prepared unchanged Contao client sources.
- Latest additional product/form/initializer/OpenAPI suite: **8 tests / 81 assertions**, successful, including three cases added after the complete suite began. Earlier plan/license/audit/real-client subset: 26 / 359. Legacy credential migration: 1 / 15. Product rights migration: 1 / 32. Counts overlap and must not be added as independent tests.
- Python deployment suite: **13 tests**, successful; simulated deployment/external targets.
- Composer strict validation and locked dependency audit: successful; no known vulnerability advisories found.
- Symfony container, **31 YAML files**, **22 Twig templates**, PHP syntax and `git diff --check`: successful. PHP syntax uses host PHP 8.5.4; functional execution uses DDEV PHP 8.4.24.

Database tests use randomly named temporary databases, assert their identity and delete only those databases. Functional tests otherwise use isolated in-memory SQLite. Webhooks use MockHttpClient with predefined resolutions; emails use fake/Null transports.

## 3. Checks not executed

- Remote GitHub Actions and its MySQL 8.4 / PHP 8.5 complete matrix: not executed from this workspace. The local host PHP 8.5 lacks GD/PDO-SQLite for the full functional suite; DDEV PHP 8.4 runs it.
- An entire Contao application/browser/production hosting environment: not exercised; integration invokes the unchanged production validator class from the pinned Git source.
- Publication of advanced client commit `fd01ad0d04554f7b2d010eef76c5a1858aef6736`: not verified. The local exact Git source is tested; publicly pinned baseline `75eed10a6bda5f627324386f8a580b19e40228a8` is separately tested. CI guarantees that baseline, and reports missing advanced source explicitly.
- Production restores/alerts, multi-host rollout/lock infrastructure, SMTP provider deliveries and real webhook recipients: intentionally excluded. Operator deployment/monitoring remains required.

## 4. Configuration and upgrade

1. Review staging compatibility and take an independently verified backup of the database, **entire** signing keyring/private keys and required application configuration/security encryption key. Stop writers and workers during deployment/migrations. Keep old backups under controlled access; migration data transformations cannot reconstruct plaintext secrets.
2. Install locked dependencies with the intended PHP version (`composer install --no-dev --optimize-autoloader`). Configure private writable uploads/key locations, Apache/Nginx restrictions and a shared lock/session/rate-limit/cache store for multiple instances.
3. Restore an existing independent security key, or initialize it with `php bin/console app:security:totp-key:init --env=prod` when no encrypted data exists. Never replace a lost key: restore the original. `TOTP_ENCRYPTION_KEY_FILE` protects TOTP **and webhook** secrets and must be backed up separately from the database. `TOTP_KEY_AUTO_CREATE=0` stays the production default.
4. Run `php bin/console doctrine:migrations:migrate --env=prod --no-interaction`, then `doctrine:schema:validate`, cache rebuild and `app:audit:verify`. Follow [2FA conversion](TWO_FACTOR_SECURITY.md) for explicit legacy-secret encryption; the migration does not silently rewrite historical secrets/audit records.
5. Review `TWO_FACTOR_REQUIRED_ROLES` and `TWO_FACTOR_REQUIRED_ACTIONS` JSON settings, staff global/customer assignments and portal users (`ROLE_CUSTOMER`, non-global, assigned customers). New API credentials need reviewed scopes and expiry; legacy credential migration grants no scopes.
6. Doctrine Messenger uses `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`; migration creates queues. Start supervised `messenger:consume async`, inspect/retry failures and schedule `app:licenses:remind`. Set a real reviewed mail transport, `REMINDER_FROM`, recipients/locales, `REMINDER_DAYS` before enabling `REMINDERS_ENABLED=1`. Default notifications stay off. Configure portal limits (`PORTAL_DOMAIN_CHANGE_LIMIT`, `PORTAL_DOMAIN_CHANGE_WINDOW_DAYS`).
7. Configure product rules/plans before issuing new feature-bearing licenses. Existing licenses already receive preserved snapshots. Distribute trusted verification keys and the reviewed advanced client before activating a new signing key; follow the fingerprint/distribution checks in [key operations](SIGNING_KEYS.md).
8. Configure alarms/readiness probes, protected metrics, regular private/off-host snapshots and actual restore rehearsals. Review the recovered configuration before activation; restore requires an empty target database and unused key/config destinations.

New reviewed migrations: `Version20261003090353`, `Version20261003093351`, `Version20261003101128`, `Version20261003102654`, `Version20261003103656`, `Version20261003105033`, `Version20261003110323`. Run the entire applicable chain; never mark a failed version applied or use schema:update to bypass it. Existing compatibility guards retain the previously corrected interrupted-table/column behavior. The product migration's backfill also remains safe to inspect with --dry-run.

## 5. Limits and compatibility

- A database administrator can recompute an entire database hash chain; externally retained checkpoints provide independent evidence. Keyring file history also depends on trusted host access.
- Previously issued offline tokens cannot learn remote revocations/domain changes while disconnected. Online tokens retain their signed hard limits until contact/expiry. Old single-key clients require trusted key distribution/client updates before rotation; legacy clients have their original fixed grace behavior.
- Automatic trust changes require authenticated signed manifests or reviewed out-of-band distribution, not an unsigned public list. Shared keyring/locking and coordinated distribution are mandatory across replicas.
- Numeric quotas and update rights are signed/persisted; clients must enforce application usage/update access. Existing clients may ignore added claims. There is no new package download API or automatic cross-license usage meter.
- New grants follow current product rules; existing snapshots survive plan/product changes. Explicit renewal does not silently extend update access. Reviewed malformed legacy feature definitions may need operator correction before editing.
- Webhooks/SMTP are at least once. Webhook receivers must commit unique event IDs with side effects; timestamp validation alone does not prevent replay inside its window. Direct arbitrary license CRUD edits have audit records but do not emit explicit business webhooks. Endpoint URLs permit HTTPS public DNS/443 and paths only, without query strings/redirects/proxies.
- Metrics counters reset when their cache is cleared. Database snapshots assume operator-confirmed maintenance and compatible vendor/version; cloud storage, external encryption, production alert routing and infrastructure are operational work.
- Advanced-client release publication and the remote MySQL/PHP matrix remain unverified. See [client integration](CLIENT_INTEGRATION.md) before rollout.

## 6. PR description

**Title:** Harden customer-scoped license administration, audit integrity and integration lifecycle

This change closes server-side upload, Super Admin, customer-scope and license-state authorization gaps. It serializes concurrent license/audit writes, preserves legacy audit verification and licensing claims, and adds optional or policy-required encrypted TOTP with session revocation. Signing keys receive reviewed lifecycle transitions, expiry-aware retirement, emergency revocation and coordinated trust distribution.

Customer-scoped administration now includes a separate portal, protected license downloads/domain changes, hashed scoped API credentials, idempotent create/renew endpoints and a persistent signed webhook outbox. Product rules and plans declare features, quotas, duration, installation and update rights; issued licenses keep snapshots so later plan edits do not change existing grants. Readiness/metrics, recoverable database/key/config snapshots and persistent localized asynchronous expiry reminders complete the operating workflow.

Validation: local complete PHP suite 155 tests/1361 assertions plus final product/form/OpenAPI subset 8/81; Python deployment suite 13; strict Composer validation/audit, container/YAML/Twig/PHP checks and disposable MariaDB installation/upgrade/concurrency/recovery all pass. Real pinned unchanged Contao validator sources and protocol vectors are exercised. Remote Actions/MySQL/PHP 8.5 matrix, full Contao hosting/browser rollout and production delivery/restore remain unexecuted.

Deployment requires the reviewed migration chain, preserved independent security encryption key, supervised Messenger workers and staged client/key distribution. Reminders default off. Legacy API credentials gain no scopes; irreversible hashes and newly issued plan rights require a reviewed backup restore for rollback. No production migration, deployment or actual recipient contact occurred.
