# Two-factor policy, recovery and encrypted storage

Two-factor enrollment remains voluntary by default. Enforce it through server-side configuration, for example in the installation's ignored environment file:

```dotenv
TWO_FACTOR_REQUIRED_ROLES='["ROLE_ADMIN","ROLE_SUPER_ADMIN"]'
TWO_FACTOR_REQUIRED_ACTIONS='["LICENSE_REVOKE","LICENSE_WITHDRAW_REVOCATION","USER_MANAGE"]'
```

Role rules use the actual role hierarchy, so requiring a lower role also covers roles that inherit it. Permission rules apply at the voter/service boundary; hiding buttons is supplementary. Unknown role or permission names fail configuration validation. A required role cannot bypass enrollment by sending a valid-CSRF skip/decline request. A voluntarily declined user can still enroll later, but an action requiring verified 2FA stays forbidden until its challenge has been completed.

## Independent encryption key

Migration `Version20261003093351` adds `user.security_version` and expands authenticator storage. It preserves legacy plaintext without inventing a production encryption key or silently rewriting old audit history. Before operating enrollment/management with the new code, initialize an independent key and explicitly convert legacy records:

```sh
php bin/console app:security:totp-key:init --env=prod
php bin/console app:security:encrypt-totp --env=prod
```

These are deployment instructions, not commands executed on production during development. The migration has been exercised against disposable databases. Apply the reviewed migration through the normal installation process, and pause writers while schema changes are applied.

`TOTP_ENCRYPTION_KEY_FILE` defaults to `config/security/totp.key`, separate from the database, `APP_SECRET` and license signing keys. Store it outside publicly served directories; default directory/file modes are 0700/0600 and Git ignores it. `TOTP_KEY_AUTO_CREATE=0` is the production default. Only isolated tests enable automatic key creation. The initializer never overwrites a key and refuses to generate a replacement if encrypted accounts exist but their original key is missing or wrong. Restore the original key in that case.

Sodium authenticated encryption uses a fresh random nonce for each stored secret. Doctrine decrypts into an unmapped runtime field and encrypts before database writes. Legacy plaintext can be read during the controlled transition; the conversion command seals it without changing credentials or revoking sessions and can be repeated safely. Ordinary updates seal any remaining legacy secret as well. Conversion is not complete until every legacy secret has been processed; preserve the prior backup until recovery has been checked.

Session serialization excludes both stored and decrypted authenticator secrets. Pending setup/replacement secrets are encrypted in the session, bound to user ID and security version, and expire after ten minutes. QR codes and recovery codes appear only in authenticated responses with `Cache-Control: no-store`.

Back up this key together with the database and required configuration, under separately controlled access. Every application instance must receive the same key before it accesses encrypted accounts. Key initialization uses the configured Symfony lock store; multiple instances need a common store and coordinated distribution. Losing the key can make encrypted authenticators unusable. A compromised database alone does not reveal the encryption key, but compromise of both the application host/key and database defeats this protection. Rotating this encryption key is a separate controlled re-encryption operation, not license signing-key rotation.

## User operations

The account's two-factor menu opens `/security/2fa/manage` once enrolled. Authenticator replacement requires the current password, a current app code or unused recovery code, and a valid code from the replacement authenticator. Recovery-code regeneration requires the current password and an existing factor. All previous recovery codes become invalid; new codes are shown once and stored only as hashes.

`/security/2fa/recover` is available after password authentication, including during an unfinished 2FA challenge. It requires the password again and an unused recovery code. Recovery clears the lost authenticator and old codes, ends sessions and returns to login. Required roles must enroll again before accessing administration. The application does not provide an unauthenticated fallback without a recovery code; installations need a separately reviewed operator identity-verification process for that situation.

Forms use CSRF protection and a ten-per-minute limiter. Sensitive operations refresh and lock the user row before validating credentials, so concurrent recovery-code operations see current state. Successful changes create explicit secret-free security audit events. Plaintext authenticator/credential parameters are marked sensitive in exception traces.

Authenticator changes, recovery-code regeneration, recovery, password/role/activity changes and explicit session revocation increment the persisted security version. Symfony user refresh rejects tokens carrying an older version; operation responses also log out the current session. Existing enrolled sessions without a completed challenge continue to require reauthentication. Share session and rate-limit stores across instances. Direct database modifications outside the application do not automatically increment the security version and require explicit operator session revocation.
