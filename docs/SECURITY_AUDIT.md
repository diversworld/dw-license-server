# Audit integrity

Migration `Version20261003090353` adds a singleton `audit_chain_head`, a unique nullable sequence, hash version and immutable actor snapshot. It does not rewrite any historical record or hash. Deploy the code and rebuild the production cache together with the normal reviewed migration procedure. Stop writers while applying DDL; MySQL/MariaDB DDL commits implicitly. The migration can resume after individual DDL statements. Downgrading is refused once version-2 entries exist, because removing their version would destroy verifiability.

Every append locks the singleton row with a database `FOR UPDATE` query, chooses its predecessor and sequence, inserts the entry and advances the head in the business transaction. Multiple application instances use the same database head. A rolled-back transaction rolls back both the audit entry and head. The isolated SQLite tests exercise behavior; separate-process MariaDB tests exercise the actual row locks. These changes target the project's MySQL/MariaDB installation; they do not establish support for another production database engine.

Version 2 hashes UTF-8 canonical JSON with recursively sorted mapping keys, preserved list order and floating-point representation. The payload includes version, sequence, entry UUID, stored timestamp (second precision), immutable actor UUID and login identifier, event and entity references, message, masked context, client IP, truncated user agent and predecessor hash. The database stores exactly that timestamp precision. Actor deletion can null the optional user relation; its identity snapshot remains part of the hash. IP addresses and login identifiers are personal data: restrict audit read/export rights and apply the installation's retention policy.

Sensitive field names are masked recursively; change records preserve their `old`/`new` structure. Authentication success, failure and logout produce explicit events. Failed authentication does not record submitted identifiers, passwords, exception messages or request bodies. Request URLs, authorization headers and cookies are not logged.

Version 1 uses the previous hash algorithm exactly. It cannot protect fields omitted by that historical algorithm, such as message or actor. Historical forks, invalid hashes and unsupported versions are reported rather than silently repaired. A database engine that normalized historical JSON object key order may already have destroyed the exact representation needed to reproduce a version-1 hash; the verifier reports the mismatch. Preserve an original export for investigation. Version 2 removes the object-order dependency.

## Verification and external checkpoints

```sh
php bin/console app:audit:verify --env=prod
php bin/console app:audit:verify --checkpoint --env=prod > audit-checkpoint.json
```

Exit 0 means all available entries and the chain head match; exit 1 identifies content, predecessor, sequence or head mismatches; exit 2 means verification could not run. Checkpoint export occurs only after successful verification and includes the entry count, latest sequence/hash and export time. Verification takes the same head lock to obtain a consistent view and temporarily blocks appenders; schedule large checks accordingly.

Store checkpoints regularly outside the license database, under separate access control, for example in immutable storage or a signed operational record. Compare the retained sequence/hash with the entry at that same sequence in a later verified history, and retain the associated historical audit export. Comparing only the newest hash is insufficient, because legitimate appends also change the newest hash. A plain database hash chain cannot prevent an administrator who controls the whole database from rewriting the complete history and recomputing every hash and head. External checkpoints expose a change to previously anchored history; they are essential for that threat model. A checkpoint alone does not authenticate who exported it and is not a substitute for externally protected or signed storage.
