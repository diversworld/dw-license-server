# Pinned real Contao client tests

`tests/Integration/contao-client.json` pins original Git commits and the SHA-256 digest of the production `LicenseValidationService` source. `scripts/prepare_contao_client.py` extracts the exact Git object into ignored test storage; it never edits that class. Tests invoke it in separate PHP processes so the two original classes can be tested without namespace substitutions or fixture adapters. The tests obtain current tokens through real server HTTP requests and also verify reproducible Ed25519 vectors with a public deterministic test seed.

## Published baseline

The publicly obtainable baseline is `diversworld/contao-issue-service-bundle` commit `75eed10a6bda5f627324386f8a580b19e40228a8`. GitHub Actions checks out that exact commit and requires its integration tests to run. Missing source fails CI rather than substituting the locally maintained validator fixture.

This client accepts one base64 public key. It accepts legacy tokens without `kid` and new tokens signed by that same key. It cannot consume a JSON keyring. Its online grace calculation is fixed at 30 days after `refresh_after`; a shorter server window is enforced by the signed `expires_at`, but this client cannot use a longer configured window. These limitations are tested explicitly.

## Required client update for keyrings and longer grace

The local real client commit `fd01ad0d04554f7b2d010eef76c5a1858aef6736` adds keyrings, legacy signature lookup and explicit `grace_until`. That exact unmodified Git source is used for the advanced local integration tests: both old/new keys, legacy tokens, regular rotation, longer grace and exact expiry boundaries. No published release containing that update was verified. Before deploying these capabilities, publish/review that client update and require that commit or a later compatible release on each participating installation. Distribute trusted verification keys before activating the new signing key. Existing clients must retain their single-key configuration until updated.

The current CI guarantees the published baseline integration. Advanced real-client tests are explicitly skipped if their pinned source is unavailable; fixed protocol tests still run. A successful baseline CI run does not demonstrate advanced client rollout. The original full Contao application, browser and hosting environment are not exercised by these production-validator integration tests.

## Local reproduction

```sh
git clone --no-checkout https://github.com/diversworld/contao-issue-service-bundle.git var/contao-client
python3 scripts/prepare_contao_client.py public var/contao-client
python3 scripts/prepare_contao_client.py advanced /path/to/client-repository-containing-fd01ad0
ddev exec --raw -- env REQUIRE_REAL_CONTAO_CLIENT=1 php bin/phpunit tests/Integration/RealContaoClientTest.php
```

`tests/Fixtures/license_protocol_vectors.json` contains fixed legacy, `kid` and offline test tokens. Regenerate the same bytes with `php tests/Support/generate_protocol_vectors.php`; its public 32-byte seed of `0x01` must never be used as a real signing key.

The workflow uses locked Symfony 8.1 dependencies and PHP 8.4/8.5 with MariaDB 11.8/MySQL 8.4. It runs Composer validation/audit, container/YAML/Twig/PHP checks, schema validation, isolated installation/upgrade/concurrency tests and Python deployment tests. Each migration test creates its own random database and verifies `SELECT DATABASE()` before doing work. Production credentials and notification delivery are not used.
