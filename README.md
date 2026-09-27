# Diversworld Lizenzserver

Symfony 8.1 / PHP >= 8.4, Doctrine ORM und MariaDB unter DDEV. Die Verwaltung läuft unter `/admin` und ist auf `ROLE_ADMIN` beschränkt. Konsolenbefehle immer innerhalb von DDEV ausführen.

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

`config/license/private.key` enthält den Base64-Ed25519-Secret-Key. Beide Schlüsseldateien sind git-ignoriert. Den privaten Schlüssel sichern und nur für den PHP-Prozess lesbar bereitstellen. Die Schlüsselgenerierung ersetzt keine vorhandenen Schlüssel. Bei einem Serverumzug denselben Schlüssel sicher übernehmen; ein neuer Schlüssel macht bestehende Tokens ungültig. `config/license/public.key` wird in den Contao-Installationen hinterlegt. Nicht `config/jwt/public.pem` verwenden: Dieser RSA-Schlüssel kann die Ed25519-Lizenzen nicht prüfen. Die Lexik-JWT-Schlüssel sind davon unabhängig und werden für dieses Lizenzprotokoll nicht verwendet.

Für mehrere Serverinstanzen benötigt `LOCK_DSN` einen gemeinsamen unterstützten Symfony-Lock-Store; zusätzlich serialisiert eine Datenbank-Zeilensperre konkurrierende Aktivierungen. Rate-Limiter und Sessions sollten dann ebenfalls einen gemeinsamen Store verwenden. Der Standard ist für einen einzelnen DDEV-/Serverprozess-Verbund eingerichtet.

## Lizenzvergabe

1. Als Administrator anmelden und einen aktiven Kunden anlegen.
2. Das Produkt `contao-issue-service-bundle` ist über `app:license:init` verfügbar.
3. Eine Lizenz mit Kunde, Produkt, Domainlimit, Status, Modus und Features anlegen. Für das Issue-Service-Bundle das Feature **sla** eintragen. Lizenzschlüssel werden zufällig erzeugt.
4. In der Lizenzliste **Installation zuweisen / Token** wählen und die Mandantenkennung sowie Domain ohne Protokoll, Port oder Pfad eintragen.
5. Das ausgestellte Token in Contao einfügen oder als `.lic`-Datei importieren.

Eine wiederholte Aktivierung derselben Domain und desselben Mandanten verbraucht keinen weiteren Platz. Gesperrte Installationen werden durch erneute Aktivierungsanfragen nicht automatisch aktiviert. Eine neue Domain verbraucht einen Platz. Für einen Umzug die alte Installation sperren und die neue Domain zuweisen. Mandantenkennung und Domain einer bestehenden Aktivierung sind nicht editierbar.

Online-Tokens sollen nach 24 Stunden erneuert werden. Das vorhandene Bundle gewährt anschließend maximal 30 Tage Kulanz; ein gesetztes Lizenzablaufdatum bleibt die harte Grenze. Unbefristete Online-Lizenzen erhalten jeweils ein Token für 31 Tage. Offline-Lizenzen benötigen ein explizites Ablaufdatum. Eine Sperre kann lokal bereits ausgegebene Tokens erst beim nächsten erfolgreichen Serverkontakt oder bei deren Ablauf unwirksam machen.

Die Domainbindung beruht auf vertrauenswürdiger Contao-Konfiguration und beweist keine DNS-Inhaberschaft. Wer PHP-Code und Konfiguration seiner Installation kontrolliert, kann lokale Lizenzprüfungen verändern.

## Contao Issue Service Bundle

Abgestimmt mit `/home/diversworld/sources/contao-issue-service-bundle/docs/SLA_LICENSE.md` und dessen `LicenseValidationService`. Das Client-Repository wird von diesem Projekt nicht verändert.

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
