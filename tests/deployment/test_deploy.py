"""Tests execute the deployment CLI; PHP and Composer are controlled test doubles."""
import os
import ast
import shutil
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/deploy.py'


class DeploymentTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='license deployment ')
        self.root = Path(self.temp.name)
        self.source = self.root / 'source code'
        self.target = self.root / 'target application'
        for base in (self.source, self.target):
            for name in ('composer.json', 'bin/console', 'public/index.php'):
                path = base / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('old' if base == self.target else 'new')
        for name in ('composer.lock', 'symfony.lock', 'src/Service/LicenseManager.php', 'src/Command/InitializeProductsCommand.php', 'config/packages/security.yaml'):
            path = self.source / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('application')
        (self.source / 'public/.htaccess').write_text('FallbackResource /index.php\n')
        (self.source / '.env.local').write_text('development credentials')
        (self.target / '.env').write_text('production defaults')
        (self.target / '.env.local').write_text('production credentials')
        (self.target / 'config/license').mkdir(parents=True)
        for name in ('private.key', 'public.key'):
            (self.target / 'config/license' / name).write_text('keep-'+name)
        self.log = self.root / 'commands'
        self.runner = self.root / 'tool'
        self.runner.write_text('''#!/usr/bin/env python3
import os,sys
from pathlib import Path
with open(os.environ['DEPLOY_TEST_LOG'], 'a') as f: f.write(repr(sys.argv[1:])+'\\n')
if 'dump-env' in sys.argv: Path('.env.local.php').write_text('compiled production env')
if 'assets:install' in sys.argv:
    assets = Path('public/bundles/easyadmin')
    assets.mkdir(parents=True, exist_ok=True)
    (assets / 'app.css').write_text('body {}')
    assets.chmod(0o700)
    (assets / 'app.css').chmod(0o600)
if os.environ.get('DEPLOY_TEST_FAIL') and 'doctrine:migrations:migrate' in sys.argv: sys.exit(9)
''')
        self.runner.chmod(0o755)

    def tearDown(self):
        self.temp.cleanup()

    def execute(self, *options, target=None, fail=False):
        env = dict(os.environ, DEPLOY_TEST_LOG=str(self.log))
        if fail:
            env['DEPLOY_TEST_FAIL'] = '1'
        return subprocess.run([sys.executable, str(SCRIPT), str(self.source), str(target or self.target), '--php', str(self.runner), '--composer', str(self.runner), *options], capture_output=True, text=True, env=env)

    def test_full_deployment_preserves_secrets_and_orders_commands(self):
        result = self.execute()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual('new', (self.target / 'public/index.php').read_text())
        self.assertEqual('FallbackResource /index.php\n', (self.target / 'public/.htaccess').read_text())
        self.assertEqual('production credentials', (self.target / '.env.local').read_text())
        self.assertEqual('production defaults', (self.target / '.env').read_text())
        self.assertEqual('keep-private.key', (self.target / 'config/license/private.key').read_text())
        self.assertEqual(0o755, (self.target / 'public/bundles/easyadmin').stat().st_mode & 0o777)
        self.assertEqual(0o644, (self.target / 'public/bundles/easyadmin/app.css').stat().st_mode & 0o777)
        log = self.log.read_text()
        self.assertIn('--no-plugins', log)
        self.assertLess(log.index('dump-env'), log.index('doctrine:migrations:migrate'))
        self.assertLess(log.index('doctrine:migrations:migrate'), log.index('app:license:init'))
        backups = list(self.root.glob('*backup*/application.tar.gz'))
        self.assertEqual(1, len(backups))
        self.assertEqual(0o700, backups[0].parent.stat().st_mode & 0o777)

    def test_real_php_configuration_preserves_credentials(self):
        vendor = SCRIPT.parent.parent / 'vendor'
        if not shutil.which('php') or not (vendor / 'autoload.php').exists():
            self.skipTest('Local PHP and installed dependencies required')
        (self.target / 'vendor').symlink_to(vendor, target_is_directory=True)
        (self.target / '.env').write_text('APP_ENV=dev\nAPP_SECRET=testing-secret-at-least-16-characters\nDATABASE_URL="mysql://test:test@localhost/test"\n')
        (self.target / '.env.local').write_text('MAILER_DSN="null://null"\n')
        (self.target / '.env.prod.local').write_text('APP_DEBUG=1\nCUSTOM_SETTING=keep\n')
        tree = ast.parse(SCRIPT.read_text())
        code = next(node.value.value for node in ast.walk(tree) if isinstance(node, ast.Assign) and any(isinstance(t, ast.Name) and t.id == 'configure' for t in node.targets))
        result = subprocess.run(['php', '-r', code], cwd=self.target, env=dict(os.environ, APP_ENV='prod', APP_DEBUG='0'), capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
        text = (self.target / '.env.prod.local').read_text()
        self.assertIn('APP_DEBUG=0', text)
        self.assertIn('CUSTOM_SETTING=keep', text)
        self.assertIn("LOCK_DSN='flock'", text)
        self.assertNotIn('DATABASE_URL', text)
        self.assertEqual(0o600, (self.target / '.env.prod.local').stat().st_mode & 0o777)

    def test_missing_secret_is_generated_once_and_never_printed(self):
        vendor = SCRIPT.parent.parent / 'vendor'
        if not shutil.which('php') or not (vendor / 'autoload.php').exists():
            self.skipTest('Local PHP and installed dependencies required')
        (self.target / 'vendor').symlink_to(vendor, target_is_directory=True)
        (self.target / '.env').write_text('APP_SECRET=\nDATABASE_URL="mysql://test:test@localhost/test"\n')
        (self.target / '.env.local').write_text('')
        tree = ast.parse(SCRIPT.read_text())
        code = next(node.value.value for node in ast.walk(tree) if isinstance(node, ast.Assign) and any(isinstance(t, ast.Name) and t.id == 'configure' for t in node.targets))
        env = dict(os.environ, APP_ENV='prod', APP_DEBUG='0')
        env.pop('APP_SECRET', None)
        first = subprocess.run(['php', '-r', code], cwd=self.target, env=env, capture_output=True, text=True)
        self.assertEqual(0, first.returncode, first.stderr)
        line = next(line for line in (self.target / '.env.prod.local').read_text().splitlines() if line.startswith('APP_SECRET='))
        secret = line.split('=', 1)[1].strip('"')
        self.assertEqual(64, len(secret))
        self.assertNotIn(secret, first.stdout + first.stderr)
        second = subprocess.run(['php', '-r', code], cwd=self.target, env=env, capture_output=True, text=True)
        self.assertEqual(0, second.returncode, second.stderr)
        self.assertIn(line, (self.target / '.env.prod.local').read_text())
        self.assertNotIn('neu erzeugt', second.stdout)

    def test_nonexecutable_composer_phar_runs_with_selected_php(self):
        phar = self.root / 'composer with spaces.phar'
        phar.write_text('<?php // fake Composer PHAR')
        phar.chmod(0o600)
        result = self.execute('--composer', str(phar))
        self.assertEqual(0, result.returncode, result.stderr)
        commands = [ast.literal_eval(line) for line in self.log.read_text().splitlines()]
        for operation in ('install', 'dump-autoload', 'check-platform-reqs', 'dump-env'):
            call = next(call for call in commands if operation in call)
            self.assertEqual(str(phar), call[0])

    def test_missing_composer_explains_phar_option_without_mutation(self):
        result = self.execute('--composer', str(self.root / 'missing-composer'))
        self.assertNotEqual(0, result.returncode)
        self.assertIn('--composer', result.stderr)
        self.assertIn('composer.phar', result.stderr)
        self.assertFalse(list(self.root.glob('*backup*')))
        self.assertEqual('old', (self.target / 'public/index.php').read_text())

    def test_dry_run_changes_nothing(self):
        result = self.execute('--dry-run')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual('old', (self.target / 'public/index.php').read_text())
        self.assertFalse(self.log.exists())
        self.assertFalse(list(self.root.glob('*backup*')))

    def test_nested_target_is_rejected(self):
        result = self.execute(target=self.source / 'public')
        self.assertNotEqual(0, result.returncode)
        self.assertFalse(self.log.exists())

    def test_nonexistent_target_reports_resolved_path_without_creating_it(self):
        target = self.root / 'wrong' / 'license'
        result = self.execute(target=target)
        self.assertNotEqual(0, result.returncode)
        self.assertIn(str(target), result.stderr)
        self.assertIn('Zielordner existiert nicht', result.stderr)
        self.assertIn('Arbeitsverzeichnis:', result.stdout)
        self.assertFalse(target.exists())
        self.assertFalse(self.log.exists())

    def test_document_root_reports_project_parent(self):
        result = self.execute(target=self.target / 'public')
        self.assertNotEqual(0, result.returncode)
        self.assertIn('DocumentRoot', result.stderr)
        self.assertIn(str(self.target), result.stderr)
        self.assertFalse(self.log.exists())

    def test_missing_keys_require_explicit_choice(self):
        (self.target / 'config/license/private.key').unlink()
        result = self.execute()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('--keys-dir', result.stderr)
        self.assertEqual('old', (self.target / 'public/index.php').read_text())

    def test_symlink_cannot_redirect_application_writes(self):
        outside = self.root / 'outside'
        outside.mkdir()
        (self.target / 'src').symlink_to(outside, target_is_directory=True)
        result = self.execute()
        self.assertNotEqual(0, result.returncode)
        self.assertFalse(list(outside.iterdir()))

    def test_failure_stops_after_migration_and_reports_backup(self):
        result = self.execute(fail=True)
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn('app:license:init', self.log.read_text())
        self.assertIn('Dateisicherung', result.stderr)


if __name__ == '__main__':
    unittest.main()
