"""Extract unchanged validator bytes from a pinned real Git commit into ignored test storage."""
import hashlib
import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
profiles = json.loads((ROOT / 'tests/Integration/contao-client.json').read_text())
if len(sys.argv) != 3 or sys.argv[1] not in profiles:
    raise SystemExit('Usage: prepare_contao_client.py public|advanced /path/to/client-git-repository')
name, repository = sys.argv[1:]
profile = profiles[name]
source = subprocess.check_output(['git', '-C', repository, 'show', profile['commit'] + ':' + profile['path']])
if hashlib.sha256(source).hexdigest() != profile['sha256']:
    raise SystemExit('Pinned client source digest mismatch; no fixture substituted.')
destination = ROOT / 'var/contao-integration' / (name + '.php')
destination.parent.mkdir(parents=True, exist_ok=True)
destination.write_bytes(source)
print('Prepared unchanged ' + name + ' client at ' + profile['commit'])
