# Platform hardening work log

Baseline: `7c47668741c062da4ea49b26f197d1a081da4ea6`. Work branch: `feature/license-platform-hardening`.
Runtime: PHP 8.4+, Symfony 8.1, Doctrine ORM 3.7 / DBAL 4.5. Production migrations, deployments and real deliveries are excluded.

The requirements are implemented and checked in this order. Each completed section records its evidence; pending sections are not claimed as implemented.

1. Profile image content validation, own-profile enforcement and webserver protection — completed: 8 HTTP tests / 40 assertions; container lint passed.
2. Central role validation and HTTP-tested Super Admin protection — completed: 6 HTTP tests / 29 assertions, including forged direct POSTs and persisted roles.
3. Explicit withdrawal of revocation and centrally defined license transitions — completed: HTTP/service role/state/expiry tests plus a real two-process MariaDB row-lock test (1 test / 11 assertions).
4. Concurrent, versioned audit chain; verifier and external checkpoints — completed: 21 audit tests / 192 assertions; complete MariaDB migration suite 15 tests / 205 assertions, including three independent append workers and four interrupted audit-DDL stages; container lint passed.
5. CI, isolated installation/upgrade checks and pinned real Contao client integration — completed: local full suite 98 tests / 877 assertions; final real-client/vector suite 8 tests / 80 assertions; Python deployment suite 13 tests; strict Composer validation and audit passed; YAML 30 files, Twig 15 templates and PHP 111 files passed. The remote workflow, full PHP 8.5 suite and MySQL matrix are not claimed as executed.
6. Role/action 2FA policy, recovery, session invalidation and encryption — completed: full suite 119 tests / 987 assertions, including actual HTTP recovery, optional/required policy, CSRF/rate limits, independent-key protection, legacy upgrade/conversion and separate database connections.
7. Signing-key lifecycle, emergency revocation, rollout and history — completed: 22 service/HTTP/real-client tests / 230 assertions; prepared keys remain untrusted, stale activation and invalid private keys are rejected, lease bounds block early retirement, signed manifests and emergency revocation are exercised.
8. Customer assignments, global/scoped permissions and object-level isolation — completed: 4 HTTP tests / 24 assertions for both customers, forged associations and API tenant misuse; full suite 127 cases / 812 assertions with 16 initially skipped database cases then separately executed successfully (16 / 229); container lint passed.
9. Health, bounded metrics, request IDs, backup and recovery test — completed: operations/policy/isolation suite 21 tests / 114 assertions; real two-database MariaDB recovery 1 test / 29 assertions, including customer/license/installation, old/new signing keys, TOTP decryption, schema and audit verification. Container lint passed.
10. Persistent, asynchronous expiry reminders and simulated deliveries — completed: reminder/audit suite 9 tests / 188 assertions; isolated real Doctrine queue and Null transport 1 / 20; fresh/repeated installation 1 / 18; full suite 136 cases / 874 assertions with 18 explicit database skips. Container lint and all 17 Twig templates passed.
11. Customer portal, protected license downloads and domain-change history — completed: final portal HTTP suite 5 tests / 30 assertions; shared portal/2FA/role suite 23 / 123; isolated fresh/repeated installation 1 / 18; container and all 20 Twig templates passed.
12. Scoped hashed API credentials, idempotency and signed safe webhooks — pending.
13. Product entitlements, plans, declarative rules and preservation of existing rights — pending.

## Profile images

Upload inputs must be JPEG, PNG or WebP with detected MIME matching actual contents, at most 5,000,000 bytes, 16–4096 pixels per dimension and at most 16 million pixels. GD decoding detects corrupt images. Storage re-encodes the image, discarding appended content and metadata, and generates a random 128-bit filename with the detected format's extension. PHP requires `ext-gd`. Configure `upload_max_filesize=5M` and `post_max_size=8M` or larger; the application enforces its own smaller byte limit.

Apache must allow the upload directory's `.htaccess` (or place the equivalent rules in the vhost). Disable execution and directory indexes. Existing JPEG, PNG and WebP filenames remain readable; unsupported existing formats should be converted deliberately rather than renamed blindly.

Nginx needs an explicit location that cannot fall through to a PHP handler, for example:

```nginx
location ^~ /uploads/profile/ {
    if ($uri !~* "\.(jpg|jpeg|png|webp)$") { return 403; }
    try_files $uri =404;
    add_header X-Content-Type-Options nosniff always;
}
```

Verify the deployed webserver rules independently; PHP tests cannot prove a remote Apache/Nginx configuration. Preserve existing upload files during upgrades. The profile controller accepts only the authenticated user's identifier; direct foreign object IDs must return 403.

## Administrative roles

`RoleCatalog` is the single definition of assignable roles, allowed persisted roles and the role hierarchy. Entity validation includes `ROLE_SUPER_ADMIN`. Normal administrators cannot modify Super-Admin targets or assign that role through crafted requests. Both the pre-form voter and the persistence guard enforce this boundary. No data migration is needed for this correction.

## License transitions

Support can reactivate only suspended licenses. Revoked licenses require the separate `withdraw_revocation` action and `LICENSE_WITHDRAW_REVOCATION` capability, inherited by Admin and Super Admin. Every action validates its reason, stores the actor and before/after status and expiry, and checks the current state after obtaining a database row lock. Expired licenses must first be extended. Renewing a suspended or revoked license preserves its status; extension alone cannot undo a suspension or revocation. No schema change is required: the existing action column accommodates the additional action.

## Audit integrity

See [Audit integrity](SECURITY_AUDIT.md) for hash versions, transactional sequencing, immutable actor snapshots, legacy limitations and external checkpoints. No production migration has been run. The generated and reviewed audit migration is `Version20261003090353`.

## CI and client integration

See [Pinned real Contao client tests](CLIENT_INTEGRATION.md). CI pins original client source and Actions commits. MariaDB 11.8 / PHP 8.4.24 was exercised locally. Host PHP 8.5.4 is available for the Python Dotenv test but lacks GD and PDO-SQLite, so its complete functional suite was not run. Local MySQL and the remote Actions execution were not exercised. The advanced real client Git commit was tested locally; a published release containing it was not verified.

## Two-factor security

See [Two-factor security](TWO_FACTOR_SECURITY.md). Default enrollment remains voluntary; required rules are configured per role/permission. Migration `Version20261003093351` preserves legacy secrets and adds revocable sessions. Production requires a separately initialized/restored TOTP encryption key and explicit legacy conversion. Production commands were not run. A concurrent workspace commit (`5ad7d07`) captured the initial implementation; follow-up changes finish protected pending enrollment and verification.

## Signing keys

See [Signing-key operations](SIGNING_KEYS.md). Operator-only lifecycle commands require actor, reason and current fingerprint; activation requires explicit distribution acknowledgment. Production instances must share their lock store and writable keyring. Disconnected clients require trusted out-of-band revocation updates. No key files in the real installation were changed.

## Customer access

See [Customer authorization](CUSTOMER_ACCESS.md). Migration `Version20261003101128` preserves existing global access and supports interrupted DDL. A concurrent user commit (`215b508`) captured the implementation; reverse-customer tests and completed verification follow separately.

## Operations

See [Monitoring and recovery](OPERATIONS.md). Snapshots require acknowledged maintenance and private off-application storage; restore targets must be empty. Tests used temporary databases and fake application key/config trees only. Metrics use a bounded shared-cache representation without domain/credential labels. Production alarms, off-host encryption/storage and remote probes were not configured or executed.

## Expiry reminders

See [Expiry reminders](EXPIRY_REMINDERS.md). Delivery defaults off; enable only after migration and reviewed mail configuration. Migration `Version20261003102654` creates persistent dispatch records and Messenger queues with partial-DDL guards. Provider-level exactly-once delivery remains an SMTP limitation; stable identifiers suppress ordinary duplicate scheduling/handling.

## Customer portal

See [Customer portal](CUSTOMER_PORTAL.md). `ROLE_CUSTOMER` inherits no administrative viewing role and requires explicit non-global assignments. Migration `Version20261003103656` preserves previous action histories and adds domain-change details. Requests for invalid/foreign downloads and domain changes are rejected; existing offline files still need replacement after an approved domain change.
