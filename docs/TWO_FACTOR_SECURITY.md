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

## Login failures after an update

The deployment script excludes `config/security/` from application-file copying. It preserves the target's authenticator encryption key and its permissions and never installs a development authenticator key. An earlier deployment-script version could overwrite this file. If that happened, restore the original production key from the deployment's file backup, retaining mode 0600 and access for the PHP process. Creating a new key cannot decrypt existing authenticators. For a configured key path outside this directory, preserve that original file as well.

An invalid-code message alone does not establish a key problem: encrypted-secret loading normally fails explicitly when the key is missing, replaced or has inappropriate permissions. The key initializer reports the specific failure and refuses to replace a missing/mismatched key when encrypted records exist. Run it only against the intended production configuration; on a new installation without encrypted records it initializes a key.

For an invalid-code message, compare `date -u` on the server with a phone using automatic date and time. TOTP uses Unix time, a 30-second period and zero configured leeway; changing the displayed timezone does not fix clock drift. Correct time synchronization, then try a freshly generated code for the correct account. If an authenticator was replaced, use the current app entry or the password plus an unused recovery code through the recovery form. For multiple application instances, check time synchronization, the database and the encryption key on each instance. Do not send authenticator secrets, encryption keys or recovery codes in diagnostic reports.

If unused recovery codes are also rejected, clock drift alone cannot explain the problem. From the actual production project, using the same PHP version and website user as PHP-FPM, run:

```sh
php bin/console app:security:2fa:diagnose account@example.org --env=prod --no-debug
```

This read-only command reports the account's presence, active state, authenticator storage/readability, number of stored recovery codes and registration of the recovery-code listener. It prints no secrets or hashes, consumes no codes, and creates no keys. A zero code count means that the stored codes are exhausted or absent; a positive count does not prove that the user's saved codes match the current set. A missing listener points to configuration, dependencies or a stale container. An absent account points to the email/database configuration. Encrypted-secret errors identify a key/access problem. CLI and PHP-FPM must use the same release and configuration.

For an update by Git, install the locked production dependencies with `composer install --no-dev --optimize-autoloader` and rebuild the production container with `php bin/console cache:clear --env=prod --no-debug` as part of the reviewed deployment process. Reload PHP-FPM if OPcache does not check file timestamps. Do not reset an account's factors or regenerate its encryption key merely to suppress an invalid-code message.

## User operations

The account's two-factor menu opens `/security/2fa/manage` once enrolled. Authenticator replacement requires the current password, a current app code or unused recovery code, and a valid code from the replacement authenticator. Recovery-code regeneration requires the current password and an existing factor. All previous recovery codes become invalid; new codes are shown once and stored only as hashes.

`/security/2fa/recover` is available after password authentication, including during an unfinished 2FA challenge. It requires the password again and an unused recovery code. Recovery clears the lost authenticator and old codes, ends sessions and returns to login. Required roles must enroll again before accessing administration. The application does not provide an unauthenticated fallback without a recovery code; installations need a separately reviewed operator identity-verification process for that situation.

Forms use CSRF protection and a ten-per-minute limiter. Sensitive operations refresh and lock the user row before validating credentials, so concurrent recovery-code operations see current state. Successful changes create explicit secret-free security audit events. Plaintext authenticator/credential parameters are marked sensitive in exception traces.

Authenticator changes, recovery-code regeneration, recovery, password/role/activity changes and explicit session revocation increment the persisted security version. Symfony user refresh rejects tokens carrying an older version; operation responses also log out the current session. Existing enrolled sessions without a completed challenge continue to require reauthentication. Share session and rate-limit stores across instances. Direct database modifications outside the application do not automatically increment the security version and require explicit operator session revocation.
