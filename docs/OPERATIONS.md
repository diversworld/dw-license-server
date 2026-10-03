# Monitoring and recovery

GET /health/live confirms that the application kernel answers without requiring the database or signers. GET /health/ready runs SELECT 1 and validates that the active Ed25519 private/public pair is available; it returns 200 ready or 503 unavailable with no hostnames, key paths, database messages or secrets. It creates no licenses, customers or audit events. Restrict probe volume at the reverse proxy and alert on sustained readiness failures (for example three successive probes), 5xx rate, refresh failures, unexpected 429 rates and p95 API latency. Add independent external availability/TLS checks. Readiness does not certify a complete customer workflow or restore.

Responses carry X-Request-ID. Only syntactically valid UUIDs are accepted from the caller; others receive a generated UUID. Production Monolog records JSON. The targeted license.api.request event contains request_id, a fixed operation name, status and elapsed seconds; no request bodies, credentials, tenant/domain strings or headers are logged by this observer. Review reverse-proxy/APM configuration separately before enabling body logging.

GET /operations/metrics requires an authenticated global administrator. It exposes Prometheus request counters, fixed latency histogram buckets, rate-limit count and failed-refresh count. Labels are limited to five operation values and four HTTP status classes. Shared-cache updates use a Symfony lock. Configure a shared cache and lock store when scraping one aggregate across instances, or scrape each independent instance and sum them. Cache clearing/restarts can reset counters; Prometheus rate calculations must handle resets. No tenant, license key, token, arbitrary route path or domain label is used. The default filesystem cache/store is node-local. Metrics failure never changes a successful business response. Use an authenticated scraper/session, or expose this endpoint only via an authenticated internal proxy; it has no anonymous metrics token bypass.

## Recovery snapshots

Pause HTTP writes, workers and deployment/rotation on all instances, then run:

```sh
php bin/console app:operations:snapshot backup /secure/backups/license-2026-10-03 --maintenance-confirmed --env=prod
```

The acknowledgment is an operator requirement, not an application-enforced maintenance switch. The parent directory must exist outside the application tree. A MySQL/MariaDB client is required. The SQL dump uses a transaction and includes routines, events, triggers and binary UUIDs. Signing/rotation share a lock with snapshot creation. Nontransactional database engines are unsupported for consistent business snapshots. Backups include the complete license directory, keyring lifecycle/history, every private/public key, independent TOTP key when present, environment files and package configuration. Files are 0600, directories 0700. The archive contains credentials: encrypt it in the backup storage system, protect access separately, retain it off-host, test retention and keep a separate controlled escrow of the independent TOTP key. No built-in cloud transfer or archive encryption is configured. SHA-256 manifest checks detect accidental corruption; they do not authenticate a backup whose files and manifest an attacker can replace. Restore only an authenticated operator-selected backup.

Create a disposable matching-version MySQL/MariaDB database and a fresh application instance. Set DATABASE_URL explicitly to that empty database and configure unused signing/TOTP key paths, then run:

```sh
php bin/console app:operations:snapshot restore /secure/backups/license-2026-10-03 --env=prod
php bin/console doctrine:schema:validate --env=prod
php bin/console app:audit:verify --checkpoint=/secure/checkpoints/restored.json --env=prod
```

Restore refuses nonempty databases and existing key destinations. Configuration is copied to recovered-configuration for review; it never replaces the current DATABASE_URL or connects to a backup's old production database automatically. Apply reviewed configuration, restore uploads separately, verify keyring states/permissions and issue/validate representative online and offline tokens in the disposable environment. Verify authenticator decryption, assignments and queues before resuming traffic. A partial restore requires a new empty target. The same database family/version was tested; cross-family/version SQL compatibility needs a separate rehearsal. External checkpoints and signing-key trust anchors should be retained independently of the snapshot.

The automated recovery test creates two random MariaDB databases, backs up a customer/license/installation plus encrypted authenticator and rotated keyring, restores into the second database and checks data, old/new signatures, TOTP decryption, schema, audit chain, corruption rejection and nonempty-target refusal. It never uses production databases or production signing keys.
