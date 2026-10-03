# Expiry reminders

Reminders are disabled by default. Configure REMINDERS_ENABLED=1, REMINDER_DAYS='[30,7,1]', a valid REMINDER_FROM and a working MAILER_DSN after the migration. Customers choose de/en/fr/es and additional responsible recipients in their form; their primary email is always included and duplicates are removed. Invalid addresses are rejected by the form and skipped defensively by the scheduler.

Run app:licenses:remind daily. Thresholds use UTC calendar days; a missed daily run does not retrospectively send every missed threshold. Each (license, exact expiry, threshold, recipient) has a persistent unique dispatch record. License-row locks serialize simultaneous schedules; dispatch to the Doctrine transport participates in the same database transaction as the record and audits. Workers consume async separately. Failed deliveries are retried three times with exponential delay and then retained on the failed transport for operator inspection. Use messenger:failed:show and explicitly reviewed retries. Delivery status, attempts and history appear in the read-only expiry reminder administration.

```sh
php bin/console app:licenses:remind --env=prod
php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M --env=prod
```

Apply Version20261003102654 before starting workers. The transport has auto_setup=0: schema creation goes through the migration. Production defaults to a persistent Doctrine transport. Tests replace it with in-memory transport or an isolated real MariaDB queue and use a simulated/Null mail transport. No production messages were sent.

A worker locks and rechecks the license and expiry immediately before delivery. Renewal cancels an old queued expiry and permits a new dispatch identifier. Revocation, pause or license/customer archival suppresses queued messages; unarchiving/reactivation does not resend an already completed identifier. Delivery history remains immutable through the admin UI and records exception classes only, not transport credentials/error bodies. Emails contain product, expiry and threshold, never license keys or signed tokens. The stable Message-ID helps correlate retries. The SMTP transport runs inside the worker; routing Symfony SendEmailMessage to another queue cannot cause premature success status.

Persistent identifiers prevent repeated scheduling and repeated handling after a committed success. Exactly-once external SMTP delivery is not achievable across the database and a remote mail server: an accepted email followed by connection loss or database failure may be retried. Use provider-side idempotency/deduplication of the stable Message-ID where available and review ambiguous failed deliveries before retrying. Sent means accepted by the configured transport, not confirmed delivery to the recipient's inbox. A Null transport also reports acceptance; production operators must configure a real transport deliberately.
