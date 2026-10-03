# Customer-scoped integrations

After migration `Version20261003105033`, an authorized administrator uses a customer's **API credentials** action to create a credential with an expiry (maximum one year) and explicit `licenses:create` and/or `licenses:renew` scopes. The secret is displayed once; storage contains only its SHA-256 digest. Revoking the credential takes effect on subsequent requests. Existing plaintext credentials are hashed during upgrade, retain their owner/expiry/activity, and receive **no management scopes** automatically. Issue a replacement credential with reviewed scopes. A downgrade cannot reconstruct plaintext and requires a reviewed backup restore. Duplicate active legacy digests are denied rather than selecting an ambiguous owner.

Swagger UI and OpenAPI at the existing documentation routes include the versioned management endpoints and bearer scheme. Public activation/refresh endpoints retain their existing client contract. All management requests require JSON, `Authorization: Bearer <secret>` and a fresh `Idempotency-Key` (1–128 ASCII letters/digits/`_.:-`). Reuse a key only for exactly the same operation and body. Identical repeats return the stored response; different content yields 409. Idempotency records persist with the credential; never remove them while an integration may retry. Creating and renewing are customer-scoped even if an arbitrary license UUID is supplied. Renewal extends expiry and preserves suspension/revocation. Reasons and API actor identifiers appear in audit history. Creation optionally accepts a `plan` UUID instead of explicit expiry/rights; see [Product entitlements](PRODUCT_ENTITLEMENTS.md).

```sh
curl --request POST https://license.example.org/api/v1/management/licenses \
  --header "Authorization: Bearer $API_CREDENTIAL" \
  --header 'Content-Type: application/json' --header 'Idempotency-Key: order-1042-create' \
  --data '{"product":"example-product","expiresAt":"2027-10-03T12:00:00+00:00","mode":"online","features":[],"maxDomains":1,"reason":"Order 1042"}'
curl --request POST https://license.example.org/api/v1/management/licenses/UUID/renew \
  --header "Authorization: Bearer $API_CREDENTIAL" \
  --header 'Content-Type: application/json' --header 'Idempotency-Key: order-1042-renew' \
  --data '{"expiresAt":"2028-10-03T12:00:00+00:00","reason":"Renewal order 1042"}'
```

A successful create response contains `licenseId`, `licenseKey`, `expiresAt`; renewal contains `licenseId`, `expiresAt`, `status`. Preserve the license secret securely. Responses use `Cache-Control: no-store`. Validation errors are 422; authentication/authorization failures 401/403; foreign IDs can return 404; missing/invalid idempotency keys return 400. Existing API rate limits apply (429). Credentials never authorize global administration.

# Webhooks

A customer's **Webhooks** action registers an HTTPS URL and explicit events. A random HMAC secret is displayed once and encrypted at rest with the independently stored `TOTP_ENCRYPTION_KEY_FILE` key. This key now protects both TOTP and webhook secrets: restore it with the database; initialization refuses replacement if encrypted records exist. Registration/disable actions require customer ownership, the `API_CREDENTIAL_MANAGE` permission, configured action 2FA, CSRF and a rate limit. Never put the webhook secret in a URL.

Creation through the management API emits `license.created`. Explicit renewal/state actions and portal domain changes emit the respective listed events (`license.renewed`, `license.pause`, `license.revoke`, `license.reactivate`, `license.withdraw_revocation`, `license.domain_change`). Arbitrary direct CRUD edits do not emit business webhooks; their audit entries remain available. Each endpoint/event pair has a persistent unique delivery identifier. Outbox and business changes commit together using the Doctrine Messenger transport; run the same `async` worker documented for expiry reminders. Failed attempts are stored and Messenger retries before its failure queue. Operators can inspect delivery history from the customer's Webhooks action and retry failed Messenger messages after fixing the cause. Disabled endpoints and archived/inactive customers are cancelled by the worker.

Delivery headers: `X-Webhook-Id`, `X-Webhook-Timestamp` (Unix seconds), `X-Webhook-Signature`. The UTF-8 JSON body has `version: 1`, a stable UUID `id`, event `type`, `occurredAt` and customer/license data. No license secret or token is sent. HMAC-SHA256 signs the exact bytes `timestamp + '.' + eventId + '.' + rawBody`, prefixed `v1=`. Each retry preserves event ID but uses a fresh timestamp. Check the timestamp within ±300 seconds and use constant-time signature comparison; require matching header/body IDs and version 1. `App\Security\WebhookSignature::verify()` is a reusable PHP verifier.

Replay-safe receiver example:

```php
$valid = WebhookSignature::verify($rawBody, $timestamp, $eventId, $signature, $secret, time());
if (!$valid) { return response(401); }
// In ONE receiver database transaction:
// INSERT eventId into a table with a UNIQUE constraint.
// On duplicate: acknowledge 2xx without repeating any side effects.
// Otherwise apply business changes and commit eventId with them.
// Only return 2xx after that commit.
```

Timestamp checking alone does not prevent replay within its window. Delivery is at least once; the receiver's durable event-ID transaction is required. Retry handling cannot atomically commit across the remote recipient and this database. Never acknowledge a failed receiver transaction. Limit raw bodies to 64 KiB before verification. Treat secrets as rotatable credentials; create a replacement endpoint, coordinate receiver deployment and disable the old one.

URLs allow HTTPS/443, public DNS hostnames and paths only: no credentials, query strings, fragments, IP literals, localhost/private/reserved IP ranges, proxies or redirects. Symfony's `NoPrivateNetworkHttpClient` resolves and pins addresses before connecting and checks the final peer, including IPv6 and DNS rebinding. TLS verification stays enabled; each request has bounded connection/duration limits. Network-level egress restrictions remain recommended. Tests use mocked HTTP transports and resolved fake targets; no real recipients are contacted.
