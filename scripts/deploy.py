#!/usr/bin/env python3
"""Install the license application into an existing Symfony project (Python 3.9+)."""
import argparse
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile
from datetime import datetime, timezone


MANAGED = ('bin', 'config', 'migrations', 'src', 'templates', 'translations', 'public',
           'composer.json', 'composer.lock', 'symfony.lock')


def fail(message):
    raise RuntimeError(message)


def files_to_copy(source):
    for name in MANAGED:
        root = source / name
        if not root.exists():
            continue
        for path in ([root] if root.is_file() else sorted(root.rglob('*'))):
            relative = path.relative_to(source)
            if relative.parts[:2] in [('config', 'license'), ('config', 'jwt'), ('config', 'secrets'), ('public', 'bundles')]:
                continue
            if path.is_symlink():
                fail(f'Symlink in Anwendungsdateien nicht unterstützt: {relative}')
            if path.is_file():
                yield path, relative


def validate_destination(target, relative):
    path = target
    for part in relative.parts:
        path = path / part
        if path.is_symlink():
            fail(f'Ziel enthält einen Symlink im Schreibpfad: {path}')


def run(command, target, env):
    print('+ ' + ('PHP-Konfiguration prüfen/ergänzen' if '-r' in command else ' '.join(map(str, command))), flush=True)
    subprocess.run(list(map(str, command)), cwd=target, env=env, check=True)


def publish_asset_permissions(target):
    # Static files are served by Apache/nginx, often under another user than PHP.
    # Keep secret files outside public/ under the restrictive deployment umask.
    public = target / 'public'
    validate_destination(target, Path('public/bundles'))
    public.chmod(0o755)
    bundles = public / 'bundles'
    if bundles.is_dir():
        paths = [bundles, *bundles.rglob('*')]
        for path in paths:
            validate_destination(target, path.relative_to(target))
        for path in paths:
            path.chmod(0o755 if path.is_dir() else 0o644)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('source', type=Path, help='Git-Projektverzeichnis des Lizenzservers')
    parser.add_argument('target', type=Path, help='Symfony-Projektverzeichnis, NICHT public/ oder DocumentRoot')
    parser.add_argument('--keys-dir', type=Path, help='Verzeichnis mit bestehender private.key und public.key')
    parser.add_argument('--generate-keys', action='store_true', help='Nur für eine neue Installation ohne vorhandene Lizenzen')
    parser.add_argument('--admin-email', help='Administrator interaktiv anlegen (Passwortabfrage)')
    parser.add_argument('--dry-run', action='store_true', help='Nur Pfade und Kopierplan prüfen; keine Änderungen')
    parser.add_argument('--php', default='php', help='PHP-CLI, mindestens Version 8.4')
    parser.add_argument('--composer', default='composer', help='Composer-Executable oder Pfad zu composer.phar (wird mit --php ausgeführt)')
    args = parser.parse_args()
    source, target = args.source.resolve(), args.target.resolve()
    print(f'Arbeitsverzeichnis: {Path.cwd()}\nQuelle: {source}\nZiel: {target}', flush=True)
    if source == target or source in target.parents or target in source.parents:
        fail('Quelle und Ziel müssen getrennte, nicht ineinander liegende Verzeichnisse sein.')
    for label, base in (('Quellordner', source), ('Zielordner', target)):
        if not base.is_dir():
            fail(f'{label} existiert nicht als Verzeichnis: {base}. Relative Pfade gelten ab dem oben angezeigten Arbeitsverzeichnis. Bitte einen absoluten Pfad verwenden.')
        missing = [name for name in ('composer.json', 'bin/console', 'public/index.php') if not (base / name).is_file()]
        if missing:
            hint = ''
            if (base.parent / 'composer.json').is_file() and (base.parent / 'bin/console').is_file():
                hint = f' Vermutlich wurde der DocumentRoot angegeben. Das Projektverzeichnis liegt unter: {base.parent}.'
            elif label == 'Zielordner' and all((Path.cwd() / name).is_file() for name in ('composer.json', 'bin/console', 'public/index.php')):
                hint = ' Das aktuelle Arbeitsverzeichnis ist ein Symfony-Projekt. Wenn dies das gewünschte Ziel ist, als Ziel einen Punkt (.) angeben.'
            fail(f'{label} ist kein vollständiges Symfony-Projekt: {base}. Fehlend: {", ".join(missing)}.{hint} Es wurden keine Dateien verändert.')
    for name in ('composer.lock', 'src/Service/LicenseManager.php', 'src/Command/InitializeProductsCommand.php'):
        if not (source / name).is_file():
            fail(f'Lizenzserver-Quelldatei fehlt: {name}')
    if args.keys_dir and args.generate_keys:
        fail('--keys-dir und --generate-keys sind nicht kombinierbar.')
    plan = list(files_to_copy(source))
    for _, relative in plan:
        validate_destination(target, relative)
    for name in ('.env.prod.local', '.env.local.php', 'vendor', 'var', 'config/license/private.key', 'config/license/public.key'):
        validate_destination(target, Path(name))
    key_target = target / 'config/license'
    key_source = args.keys_dir.resolve() if args.keys_dir else key_target
    has_private = (key_target / 'private.key').exists()
    if args.generate_keys and (has_private or (key_target / 'public.key').exists()):
        fail('Eine Schlüsseldatei existiert bereits. --generate-keys entfernen.')
    if not args.generate_keys:
        for name in ('private.key', 'public.key'):
            if not (key_source / name).is_file():
                fail(f'{key_source / name} fehlt. --keys-dir angeben oder für einen Neustart --generate-keys wählen.')
            existing = key_target / name
            if existing.exists() and existing.read_bytes() != (key_source / name).read_bytes():
                fail('Vorhandene Lizenzschlüssel dürfen nicht überschrieben werden.')
    print(f'Quelle: {source}\nZiel: {target}\nDocumentRoot muss sein: {target / "public"}')
    print(f'{len(plan)} Anwendungsdateien; lokale Env-Dateien, JWT-Schlüssel und Secrets bleiben erhalten.')
    if args.dry_run:
        for _, relative in plan:
            print(f'  {relative}')
        print('Plan: Backup → Dateien → Composer → Produktionskonfiguration → Schlüssel → Migrationen → Produkt → Cache/Assets → Prüfung')
        return
    php_executable = shutil.which(args.php)
    if not php_executable:
        fail(f'PHP nicht gefunden: {args.php}. --php /vollstaendiger/pfad/php angeben.')
    args.php = str(Path(php_executable).absolute())
    composer_executable = shutil.which(args.composer)
    composer_path = Path(composer_executable or args.composer).absolute()
    if not composer_path.is_file():
        fail(f'Composer nicht gefunden: {args.composer}. Mit --composer /vollstaendiger/pfad/composer.phar eine vorhandene Composer-2-Datei angeben. Eine PHAR-Datei benötigt keine Ausführungsrechte; sie wird mit --php gestartet.')
    with composer_path.open('rb') as handle:
        header = handle.read(1024)
    if composer_path.suffix.lower() == '.phar' or b'<?php' in header:
        composer_command = [args.php, str(composer_path)]
    elif os.access(composer_path, os.X_OK):
        composer_command = [str(composer_path)]
    else:
        fail(f'Composer-Datei ist weder ein PHP-/PHAR-Skript noch ausführbar: {composer_path}')
    os.umask(0o077)
    env = dict(os.environ, APP_ENV='prod', APP_DEBUG='0')
    run([args.php, '-r', 'exit(PHP_VERSION_ID >= 80400 && extension_loaded("pdo_mysql") && extension_loaded("sodium") ? 0 : 1);'], target, env)
    if not any((target / name).is_file() for name in ('.env', '.env.local', '.env.prod', '.env.prod.local')):
        fail('Ziel benötigt eine Env-Konfiguration mit DATABASE_URL und APP_SECRET.')
    # The deployment is in-place. Keep a private full filesystem backup outside the document root.
    backup = Path(tempfile.mkdtemp(prefix=f'{target.name}-backup-{datetime.now(timezone.utc):%Y%m%dT%H%M%SZ}-', dir=target.parent))
    print(f'Dateisicherung: {backup / "application.tar.gz"}', flush=True)
    with tarfile.open(backup / 'application.tar.gz', 'w:gz', dereference=False) as archive:
        archive.add(target, arcname=target.name)
    print('Die Datenbank wird NICHT gesichert. Vor dem Deployment eine Datenbanksicherung bereitstellen.', flush=True)
    try:
        for path, relative in plan:
            destination = target / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, destination)
        # Suppress Flex recipe changes and auto-scripts until target configuration is validated.
        run([*composer_command, 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction', '--no-scripts', '--no-plugins'], target, env)
        run([*composer_command, 'dump-autoload', '--no-dev', '--optimize', '--no-scripts', '--no-interaction'], target, env)
        run([*composer_command, 'check-platform-reqs', '--no-dev'], target, env)
        # Load actual text env files, ignoring any stale compiled .env.local.php.
        configure = r'''
require 'vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->loadEnv('.env', 'APP_ENV', 'prod');
$get = static fn ($name) => $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
$secret = $get('APP_SECRET');
$persistSecret = false;
if (false === $secret || null === $secret || '' === $secret) {
    // Preserve a secret previously deployed only through the compiled env file.
    $compiled = is_file('.env.local.php') ? require '.env.local.php' : [];
    $secret = is_array($compiled) ? ($compiled['APP_SECRET'] ?? '') : '';
    if ('' === $secret || null === $secret || false === $secret) {
        $secret = bin2hex(random_bytes(32));
        echo "APP_SECRET neu erzeugt; Speicherung in .env.prod.local (Wert wird nicht ausgegeben).\n";
    }
    $persistSecret = true;
}
if (!is_string($secret) || strlen($secret) < 16) { throw new RuntimeException('Vorhandener APP_SECRET ist zu kurz. Bitte im Ziel einen sicheren Wert mit mindestens 16 Zeichen konfigurieren; vorhandene Werte werden nicht automatisch ersetzt.'); }
$url = $get('DATABASE_URL');
if (!$url || !in_array(parse_url($url, PHP_URL_SCHEME), ['mysql', 'mariadb'], true)) { throw new RuntimeException('DATABASE_URL im Ziel muss die MariaDB/MySQL-Produktionsdatenbank angeben.'); }
$path = '.env.prod.local';
$text = is_file($path) ? file_get_contents($path) : '';
if ($persistSecret) {
    $text = preg_replace('/^(?:export\s+)?APP_SECRET\s*=.*$/m', '', $text);
    $text .= "\nAPP_SECRET=".str_replace('$', '\\$', json_encode($secret, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))."\n";
}
foreach (['APP_ENV' => 'prod', 'APP_DEBUG' => '0'] as $name => $value) {
    $text = preg_replace('/^(?:export\s+)?'.preg_quote($name, '/').'\s*=.*$/m', '', $text);
    $text .= "\n$name=$value\n";
}
foreach (['LOCK_DSN' => 'flock', 'MAILER_DSN' => 'null://null', 'DEFAULT_URI' => 'https://license.diversworld.eu', 'JWT_SECRET_KEY' => '%kernel.project_dir%/config/jwt/private.pem', 'JWT_PUBLIC_KEY' => '%kernel.project_dir%/config/jwt/public.pem', 'JWT_PASSPHRASE' => ''] as $name => $value) {
    if (false === $get($name) || null === $get($name)) { $text .= "\n$name='$value'\n"; }
}
file_put_contents($path, $text);
chmod($path, 0600);
'''
        run([args.php, '-r', configure], target, env)
        run([*composer_command, 'dump-env', 'prod'], target, env)
        os.chmod(target / '.env.local.php', 0o600)
        key_target.mkdir(parents=True, exist_ok=True)
        if args.keys_dir:
            for name in ('private.key', 'public.key'):
                if not (key_target / name).exists():
                    with (key_target / name).open('xb') as handle:
                        handle.write((key_source / name).read_bytes())
                    os.chmod(key_target / name, 0o600)
        if args.generate_keys:
            run([args.php, 'bin/console', 'app:license:keys', '--env=prod', '--no-interaction'], target, env)
        run([args.php, '-r', r'''
$secret = base64_decode(trim(file_get_contents('config/license/private.key')), true);
$public = base64_decode(trim(file_get_contents('config/license/public.key')), true);
if (false === $secret || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES || false === $public || !hash_equals(sodium_crypto_sign_publickey_from_secretkey($secret), $public)) { throw new RuntimeException('Ungültiges oder nicht zusammengehöriges Ed25519-Schlüsselpaar.'); }
'''], target, env)
        for command in (
            ['cache:clear'], ['lint:container'],
            ['doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'],
            ['app:license:init'], ['assets:install', 'public'], ['doctrine:schema:validate'],
        ):
            run([args.php, 'bin/console', *command, '--env=prod'], target, env)
            if command[0] == 'assets:install':
                publish_asset_permissions(target)
        if args.admin_email:
            run([args.php, 'bin/console', 'app:admin:create', args.admin_email, '--env=prod'], target, env)
    except Exception:
        print(f'Deployment abgebrochen. Dateisicherung: {backup}. Bereits ausgeführte Datenbankmigrationen werden nicht automatisch zurückgesetzt.', file=sys.stderr)
        raise
    print(f'Fertig. DocumentRoot: {target / "public"}. HTTPS und PHP-FPM-Konfiguration im Hostingpanel prüfen.')
    print('Als PHP-/Website-Benutzer ausführen; PHP-FPM benötigt Leserechte auf Env/Schlüssel und Schreibrechte auf var/.')
    print('Kein Administrator angelegt? Im Ziel: php bin/console app:admin:create EMAIL --env=prod')


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, subprocess.CalledProcessError) as error:
        print(f'FEHLER: {"Unterprozess fehlgeschlagen; siehe Ausgabe oben." if isinstance(error, subprocess.CalledProcessError) else error}', file=sys.stderr)
        sys.exit(1)
