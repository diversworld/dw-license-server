# Diversworld Lizenzserver

Symfony ^7 / PHP >= 8.4. Die Verwaltung läuft unter `/admin` und ist auf `ROLE_ADMIN` beschränkt.

## Einrichtung

```bash
ddev start
ddev composer install
ddev exec php bin/console doctrine:migrations:migrate
ddev exec php bin/console app:license:keys
ddev exec php bin/console app:license:init
ddev exec php bin/console app:admin:create admin@example.org
```

Der letzte Befehl fragt das Passwort verdeckt ab und überschreibt keine vorhandenen Benutzer. Es gibt keine voreingestellten Zugangsdaten.

`config/license/private.key` enthält den Base64-Ed25519-Secret-Key. Beide Schlüsseldateien sind git-ignoriert. Den privaten Schlüssel sichern und nur für den PHP-Prozess lesbar bereitstellen. Die Schlüsselgenerierung ersetzt keine vorhandenen Schlüssel. Bei einem Serverumzug das gesamte Verzeichnis `config/license/` einschließlich `keyring.json` und `keys/` sicher übernehmen. `config/license/public.key` wird in den Contao-Installationen hinterlegt. Nicht `config/jwt/public.pem` verwenden: Dieser RSA-Schlüssel kann die Ed25519-Lizenzen nicht prüfen. Die Lexik-JWT-Schlüssel sind davon unabhängig und werden für dieses Lizenzprotokoll nicht verwendet.

Für mehrere Serverinstanzen benötigt `LOCK_DSN` einen gemeinsamen unterstützten Symfony-Lock-Store; zusätzlich serialisiert eine Datenbank-Zeilensperre konkurrierende Aktivierungen. Rate-Limiter und Sessions sollten dann ebenfalls einen gemeinsamen Store verwenden. Der Standard ist für einen einzelnen DDEV-/Serverprozess-Verbund eingerichtet.

## Lizenzvergabe

1. Als Administrator anmelden und einen aktiven Kunden anlegen.
2. Das Produkt `contao-issue-service-bundle` ist über `app:license:init` verfügbar.
3. Eine Lizenz mit Kunde, Produkt, Domainlimit, Status, Modus und Features anlegen. Für das Issue-Service-Bundle das Feature **sla** eintragen. Lizenzschlüssel werden zufällig erzeugt.
4. In der Lizenzliste **Installation zuweisen / Token** wählen und die Mandantenkennung sowie Domain ohne Protokoll, Port oder Pfad eintragen.
5. Das ausgestellte Token in Contao einfügen oder als `.lic`-Datei importieren.

Eine wiederholte Aktivierung derselben Domain und desselben Mandanten verbraucht keinen weiteren Platz. Gesperrte Installationen werden durch erneute Aktivierungsanfragen nicht automatisch aktiviert. Eine neue Domain verbraucht einen Platz. Für einen Umzug die alte Installation sperren und die neue Domain zuweisen. Mandantenkennung und Domain einer bestehenden Aktivierung sind nicht editierbar.

Die Tokenlaufzeit und zusätzliche Kulanzzeit werden je Produkt in Sekunden konfiguriert (Standard: 86400 und 2592000). Online-Tokens enthalten `refresh_after` und `grace_until`; `expires_at` ist spätestens das Ende der Kulanzzeit, auch bei langfristigen oder unbefristeten Lizenzen. Ein gesetztes Lizenzablaufdatum bleibt die frühere harte Grenze. 0 Sekunden Kulanz beendet die Nutzung direkt nach der Tokenlaufzeit. Änderungen der Produktpolitik wirken auf neu ausgestellte Tokens; bestehende Tokens behalten ihre signierten Fristen. Offline-Lizenzen benötigen ein explizites Ablaufdatum. Eine Sperre kann lokal bereits ausgegebene Tokens erst beim nächsten erfolgreichen Serverkontakt oder bei deren Ablauf unwirksam machen.

Die Domainbindung beruht auf vertrauenswürdiger Contao-Konfiguration und beweist keine DNS-Inhaberschaft. Wer PHP-Code und Konfiguration seiner Installation kontrolliert, kann lokale Lizenzprüfungen verändern.

## Contao Issue Service Bundle

Abgestimmt mit `/home/diversworld/sources/contao-issue-service-bundle/docs/SLA_LICENSE.md` und dessen `LicenseValidationService`. Der Validator im Client-Repository wurde für `kid`, öffentliche Schlüssellisten und `grace_until` angepasst. Dieses Client-Update muss vor der ersten Schlüsselrotation verteilt werden. Einzelne Base64-Prüfschlüssel und alte Tokens bleiben kompatibel.

In der **Contao-Anwendung** unter `config/services.yaml`:

```yaml
parameters:
    contao_issue_service.license_public_key: 'INHALT_VON_PUBLIC.KEY'
    contao_issue_service.license_tenant: 'kunde-123'
    contao_issue_service.license_domain: 'support.example.org'
    contao_issue_service.license_endpoint: 'https://license.ddev.site/api/v1/licenses/validate'
```

Mandantenkennung und Domain müssen zur ausgestellten Lizenz passen. Im produktiven Betrieb eine erreichbare HTTPS-Adresse des Lizenzservers verwenden. Bei lokalen Verbindungen zwischen DDEV-Projekten müssen DNS/Routing und das Vertrauen in die DDEV-CA auch im Client-Container eingerichtet sein; die TLS-Prüfung nicht abschalten.

```bash
# In der Contao-Installation:
ddev exec php vendor/bin/contao-console issue:license:validate --offline-file=/pfad/kunde.lic
ddev exec php vendor/bin/contao-console issue:license:validate --online
```

`--offline-file` importiert auch ein initiales Online-Token. Das Bundle unterstützt aktuell den Import signierter Tokens und deren Erneuerung; es besitzt noch keinen Eingabedialog für den rohen Lizenzschlüssel.

## Signierschlüssel rotieren

Rotation ersetzt niemals den bisherigen Schlüssel. Neue Tokens tragen eine signierte Schlüsselkennung `kid`; Tokens ohne Kennung werden weiterhin mit dem ursprünglichen Schlüssel geprüft.

```bash
php bin/console app:license:rotate prepare
php bin/console app:license:rotate public-keys
# Zuerst den aktualisierten Client und die ausgegebene öffentliche JSON-Liste verteilen.
php bin/console app:license:rotate activate SCHLUESSELKENNUNG
```

Die vollständige öffentliche JSON-Liste als String in `contao_issue_service.license_public_key` konfigurieren. `public.key` bleibt der ursprüngliche Prüfschlüssel; nach Rotation die Liste aus `public-keys` verwenden. Bei nicht aktualisierten Clients die Aktivierung verschieben. Die Signierdateien und `keyring.json` bleiben privat und git-ignoriert. Auch eine Rückkehr zu einer früheren Schlüsselkennung ist über `activate` möglich; alte öffentliche Schlüssel bleiben erhalten.

## Zwei-Faktor-Anmeldung und Rollen

Bei der ersten Anmeldung wird die freiwillige Einrichtung angeboten: QR-Code mit der Authenticator-App scannen (oder den Schlüssel manuell eingeben), dann Passwort sowie aktuellen TOTP-Code bestätigen. „Für jetzt überspringen“ gilt für die aktuelle Sitzung; „2FA ablehnen“ speichert die Entscheidung dauerhaft und unterdrückt weitere Aufforderungen. Über das Benutzermenü lässt sich die Einrichtung jederzeit nachholen. Ohne eingerichtete 2FA bleiben die jeweiligen Rollenrechte verfügbar. Es gelten TOTP/SHA1, sechs Ziffern und 30 Sekunden. Danach werden zehn einmalige Wiederherstellungscodes genau einmal angezeigt; in der Datenbank liegen nur deren SHA-256-Hashes. Bei weiteren Anmeldungen folgt auf das Passwort die TOTP-Abfrage. Authenticator-Geheimnisse und Wiederherstellungscodes werden im AuditLog geschwärzt. Bei Benutzern mit aktivierter 2FA müssen bestehende Sitzungen ohne abgeschlossene Zwei-Faktor-Anmeldung erneut authentifiziert werden. Ein bereits aktivierter Authenticator lässt sich über die Optionen zum Überspringen oder Ablehnen nicht umgehen.

| Rolle | Berechtigungen |
| --- | --- |
| Administration | Alle Verwaltungsaktionen einschließlich Rollen, Produkteinstellungen und Widerruf |
| Support | Lesen, Installationen verwalten, Tokens ausstellen, pausieren und reaktivieren |
| Vertrieb | Lesen, Kunden und Lizenzen anlegen/bearbeiten, verlängern und Tokens ausstellen |
| Nur Lesen | Dashboard, Lizenzlisten und Historie lesen |

Jeder Benutzer kann sein eigenes Profil pflegen. Rollen werden in der Benutzerverwaltung ausgewählt. Neue Benutzer werden mit verdeckter Passwortabfrage angelegt:

```bash
php bin/console app:admin:create support@example.org Vorname Nachname --role=support
```

Zulässige Rollenoptionen: `admin`, `support`, `sales`, `viewer`. Änderungen und sensible Aktionen werden zusätzlich serverseitig durch Voter geprüft.

## Lizenzaktionen und Historie

In der Lizenzliste stehen Verlängern, Pausieren, Widerrufen, Reaktivieren und Lizenzhistorie bereit. Jede Änderung benötigt eine Begründung mit 3 bis 1000 Zeichen. Die Historie speichert Bearbeiter, Zeitpunkt, Begründung sowie Status und Ablaufdatum vor und nach der Aktion. Sie ist über die Anwendung unveränderlich; auch AuditLogs dürfen weder manuell angelegt, bearbeitet noch gelöscht werden.

Verlängern verlangt ein zukünftiges Ablaufdatum nach dem bisherigen Datum und lässt den Status unverändert. Pausieren ist für aktive Lizenzen möglich. Widerrufen verlangt Administrationsrechte. Abgelaufene Lizenzen müssen vor einer Reaktivierung verlängert werden. Status und das spätere Ablaufdatum werden über diese Vorgänge geändert; ein initiales Ablaufdatum kann beim Anlegen gesetzt werden. Bereits ausgegebene Online-Tokens bleiben bis zum nächsten Serverkontakt oder ihrer signierten harten Grenze verwendbar. Offline-Tokens bleiben bis zu ihrem Ablaufdatum verwendbar und lassen sich ohne Serverkontakt nicht vorzeitig sperren.

## JSON-API

HTTPS verwenden. Alle Anfragen sind `POST` mit `Content-Type: application/json`. Tokens und Lizenzschlüssel sind Zugangsdaten und gehören nicht in URLs oder Logs. Die API ist auf 60 Anfragen pro Minute je Client-IP, Methode und Pfad begrenzt.

### Aktivieren: `/api/v1/licenses/activate`

```json
{
  "licenseKey": "64_STELLIGER_LIZENZSCHLUESSEL",
  "product": "contao-issue-service-bundle",
  "tenant": "kunde-123",
  "domain": "support.example.org"
}
```

Die erste Ausstellung ist alternativ vollständig über die Verwaltung möglich. Der Lizenzschlüssel berechtigt zur Belegung freier Installationsplätze dieser Lizenz und ist entsprechend vertraulich zu behandeln.

### Erneuern: `/api/v1/licenses/validate`

```json
{"token":"SIGNIERTES_TOKEN","tenant":"kunde-123","domain":"support.example.org"}
```

Erfolg: HTTP 200 mit `{"token":"NEUES_SIGNIERTES_TOKEN"}`. Format: `base64url(JSON).base64url(Ed25519-Signatur)`; signiert wird der erste kodierte Teil. Kein JWT.

- 401: ungültige Signatur oder Tokenstruktur.
- 403: unbekannte/gesperrte Lizenz, inaktiver Kunde/Produkt, falsche Bindung oder gesperrte Installation.
- 410: Lizenz abgelaufen.
- 409: Domainlimit bei Aktivierung erreicht.
- 422: ungültige Eingaben oder Offline-Lizenz ohne Ablaufdatum.
- 429: Anfragelimit erreicht; später erneut versuchen.
- 503: konkurrierende Aktivierung; später erneut versuchen.

Ein abgelaufenes Online-Token darf erneuert werden, wenn seine Signatur und Bindung stimmen und die Lizenz serverseitig weiterhin gültig ist. Tokens aus dem bisherigen externen Signierwerkzeug ohne `license_id`, `activation_id` und `product` müssen einmalig durch eine serverseitig ausgestellte Lizenz ersetzt werden.

## Tests

Die mitgelieferte `.env.test` verwendet DDEVs lokale Standardzugangsdaten `root/root`, damit Doctrine die separate Testdatenbank anlegen kann. Diese Zugangsdaten gelten ausschließlich für DDEV.

Die HTTP-Tests verwenden ausschließlich die separate Doctrine-Testdatenbank (`db_test`); die Produktions-/Entwicklungsdatenbank wird nicht geleert.

```bash
ddev exec php bin/console doctrine:database:create --env=test --if-not-exists
ddev exec php bin/console doctrine:migrations:migrate --env=test --no-interaction
ddev exec php bin/phpunit
ddev exec php bin/console lint:container
ddev exec php bin/console lint:twig templates/
ddev exec php bin/console doctrine:schema:validate
```

Die Tests decken Ausstellung, wiederholte Aktivierung, Limits, Sperren, Ablauf, Manipulation, Mandantenbindung, Offline-Modus, Wiederherstellung abgelaufener Online-Tokens sowie Login und Verwaltungsseiten ab. Ein Test prüft ausgegebene Tokens gegen eine Kopie des tatsächlichen Contao-Validators unter `tests/Fixtures`.

Die Migrationstests führen die Installationskommandos mit `APP_ENV=prod` auf jeweils neu angelegten, zufällig benannten Datenbanken aus. Sie prüfen die vollständige Neuinstallation einschließlich Schemaabgleich und Produktinitialisierung, wiederholte Ausführung, vorhandene Benutzer sowie die Übernahme alter Audit-Einträge, Rückmigration und Konflikte. Die temporären Datenbanken werden anschließend entfernt; bestehende Datenbanken werden nicht geleert. Ohne `MIGRATION_TEST_DATABASE_URL` werden diese Tests übersprungen. Der angegebene Datenbankbenutzer benötigt Rechte zum Anlegen und Entfernen der Testdatenbanken.

```bash
ddev exec env MIGRATION_TEST_DATABASE_URL=mysql://root:root@db:3306/db php vendor/bin/phpunit tests/Functional/MigrationInstallationTest.php
```

Die Bereinigungsmigration übernimmt Einträge aus der früheren Tabelle `license_audit_log` nach `audit_log`, bevor sie die alte Tabelle entfernt. Bei gleichen IDs mit unterschiedlichen Inhalten bricht sie ab, damit keine Protokolle verloren gehen. Wiederherstellungscodes werden für vorhandene Benutzer mit einem leeren JSON-Array initialisiert; ein literaler JSON-Standardwert wird vermieden, weil [MySQL dafür einen Ausdruck verlangt](https://dev.mysql.com/doc/refman/8.4/en/data-type-defaults.html).

## Installation in eine vorhandene Symfony-Umgebung

Das Skript `scripts/deploy.py` läuft **auf dem Webserver**. Voraussetzung: Python 3.9+, PHP >= 8.4 mit PDO-MySQL und Sodium, Composer 2 sowie eine konfigurierte, erreichbare MySQL-/MariaDB-Datenbank. Es benötigt zwei getrennte Projektordner. Der Zielordner ist das Symfony-Projektverzeichnis **oberhalb von `public/`**, nicht der DocumentRoot.

Zuerst den Kopierplan ohne Änderungen prüfen:

```bash
python3 /pfad/git-license/scripts/deploy.py \
    /pfad/git-license /pfad/symfony-ziel --dry-run \
    --keys-dir /sicherer/pfad/bisherige-lizenzschluessel
```

Bestehende Lizenzen übernehmen:

```bash
python3 /pfad/git-license/scripts/deploy.py \
    /pfad/git-license /pfad/symfony-ziel \
    --keys-dir /sicherer/pfad/bisherige-lizenzschluessel
```

Das Schlüsselverzeichnis muss die zusammengehörigen Dateien `private.key` und `public.key` aus `config/license/` enthalten. Bei bereits erfolgter Rotation auch `keyring.json` und alle Dateien unter `keys/` übernehmen. Bereits vorhandene Zielschlüssel werden niemals durch andere Schlüssel ersetzt. Wenn die Dateien schon im Ziel unter `config/license/` liegen, entfällt `--keys-dir`. Bestehende Lizenzdaten müssen vorab separat in die Zieldatenbank importiert werden; das Skript importiert keine Datenbanken.

Für eine **neue Installation ohne bisherige Lizenzen**:

```bash
python3 /pfad/git-license/scripts/deploy.py \
    /pfad/git-license /pfad/symfony-ziel \
    --generate-keys --admin-email admin@example.org
```

Das Administratorpasswort wird interaktiv verdeckt abgefragt. Bestehende Administratoren werden nicht überschrieben; bei importierten Benutzern `--admin-email` weglassen. PHP und Composer können bei Bedarf über `--php /pfad/php` und `--composer /pfad/composer` gewählt werden. Composer muss dieselbe geeignete PHP-Version verwenden.

Vorbereitung des Ziels:

- In dessen Env-Dateien `DATABASE_URL` für Produktion konfigurieren. Ein fehlender oder leerer `APP_SECRET` wird zufällig erzeugt und in `.env.prod.local` gespeichert. Ein vorhandener Wert bleibt erhalten; ein Wert mit weniger als 16 Zeichen führt zu einer verständlichen Fehlermeldung. Die `.env` und `.env.local` aus der Quelle werden nicht übernommen.
- Vorab eine **Datenbanksicherung** erstellen. Das Skript erstellt nur eine private vollständige **Dateisicherung** neben dem Zielprojekt; ausreichend freien Speicher einplanen.
- Als Website-/PHP-Benutzer ausführen. Env- und Schlüsseldateien erhalten restriktive Rechte. PHP-FPM muss diese Dateien lesen und `var/` beschreiben können.
- Während des Deployments die Website im Hostingpanel in Wartung nehmen. Die Installation erfolgt im bestehenden Ordner und ist nicht atomar.

Ablauf: Pfade/Schlüssel prüfen → Zielverzeichnis sichern → Anwendungsdateien kopieren → Composer ohne Entwicklungspakete installieren → Produktions-Env ergänzen und kompilieren → Schlüssel bereitstellen/prüfen → Cache und Container prüfen → Migrationen anwenden → erstes Modul registrieren → Assets installieren → Schema prüfen.

Erhalten bleiben lokale Env-Dateien (ergänzt wird `.env.prod.local`, neu kompiliert wird `.env.local.php`), JWT-Schlüssel, Secrets, Daten und zusätzliche Dateien im Ziel. Gleichnamige Anwendungsdateien und die Composer-Dateien werden ersetzt. Eine bestehende fremde Symfony-Anwendung sollte deshalb nicht als Ziel verwendet werden; vorgesehen ist die bereitgestellte Symfony-Basis oder eine ältere Version dieses Lizenzservers. Symlinks in den verwalteten Schreibpfaden werden abgewiesen.

Bei Fehlern stoppt das Skript und nennt den Sicherungspfad. Es setzt bereits ausgeführte Migrationen nicht automatisch zurück. Eine Wiederherstellung muss Dateien und Datenbank gemeinsam berücksichtigen. Alte Sicherungen enthalten Zugangsdaten und sollten geschützt aufbewahrt bzw. nach erfolgreicher Abnahme gelöscht werden.

Im Hostingpanel anschließend DocumentRoot auf **`ZIEL/public`** setzen und HTTPS sowie Symfony-Rewrite-Regeln konfigurieren. Das Skript verändert keine Webserver-, DNS- oder Zertifikatseinstellungen. Bei aktivem OPcache ohne Zeitstempelprüfung PHP-FPM nach dem Deployment über das Hostingpanel neu laden.

Skripttests (ohne Produktivzugriff):

```bash
python3 -m unittest discover -s tests/deployment -v
```

Die Tests führen das Skript in temporären Verzeichnissen aus; Composer/PHP-Deploymentbefehle werden dabei simuliert. Ein zusätzlicher Test prüft die Env-Verarbeitung mit echtem PHP und dem installierten Symfony Dotenv.

### Hosting meldet „Programm nicht gefunden: composer“

Wenn Composer nicht im SSH-PATH liegt, unterstützt `--composer` auch den vollständigen Pfad einer `composer.phar`. Diese Datei wird mit dem über `--php` gewählten PHP gestartet und benötigt keine Ausführungsrechte. Composer 2 kann über die [offizielle Downloadseite](https://getcomposer.org/download/) bezogen und außerhalb von `public/` hochgeladen werden.

Beispiel aus dem Symfony-Zielverzeichnis (PHP-Pfad zuvor auf dem Hosting prüfen):

```bash
/opt/plesk/python/3/bin/python "$QUELLE/scripts/deploy.py" \
    "$QUELLE" "$(pwd -P)" \
    --keys-dir "$QUELLE/config/license" \
    --php /opt/plesk/php/8.4/bin/php \
    --composer "$QUELLE/composer.phar"
```

Bei Verwendung einer anderen PHP-Version muss der PHP-Pfad angepasst werden (mindestens 8.4). Es werden weder Docker noch DDEV benötigt.

### Apache: 404 bei /login oder /admin

Die Datei `public/.htaccess` wird über das Symfony-Apache-Pack bereitgestellt und vom Deployment mitkopiert. Bei manuellen Uploads müssen auch versteckte Dateien übertragen werden. DocumentRoot muss auf `ZIEL/public` zeigen. Apache muss Rewrite-Regeln aus `.htaccess` zulassen; bei reinem Nginx-Betrieb sind entsprechende Regeln im Hostingpanel nötig. Symfony-Routen lassen sich mit `php bin/console debug:router --env=prod` kontrollieren.
