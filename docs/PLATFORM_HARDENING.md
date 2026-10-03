# Platform hardening work log

Baseline: `7c47668741c062da4ea49b26f197d1a081da4ea6`. Work branch: `feature/license-platform-hardening`.
Runtime: PHP 8.4+, Symfony 8.1, Doctrine ORM 3.7 / DBAL 4.5. Production migrations, deployments and real deliveries are excluded.

The requirements are implemented and checked in this order. Each completed section records its evidence; pending sections are not claimed as implemented.

1. Profile image content validation, own-profile enforcement and webserver protection — completed: 8 HTTP tests / 40 assertions; container lint passed.
2. Central role validation and HTTP-tested Super Admin protection — pending.
3. Explicit withdrawal of revocation and centrally defined license transitions — pending.
4. Concurrent, versioned audit chain; verifier and external checkpoints — pending.
5. CI, isolated installation/upgrade checks and pinned real Contao client integration — pending.
6. Role/action 2FA policy, recovery, session invalidation and encryption — pending.
7. Signing-key lifecycle, emergency revocation, rollout and history — pending.
8. Customer assignments, global/scoped permissions and object-level isolation — pending.
9. Health, bounded metrics, request IDs, backup and recovery test — pending.
10. Persistent, asynchronous expiry reminders and simulated deliveries — pending.
11. Customer portal, protected license downloads and domain-change history — pending.
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
