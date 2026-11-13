#!/usr/bin/env python3
"""Build a local review archive and byte manifest. Never publishes or tags."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import zipfile

root = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--version', required=True)
parser.add_argument('--output', type=Path, required=True)
parser.add_argument('--git-ref', help='Verify a Git distribution instead of Composer archive (e.g. HEAD)')
args = parser.parse_args()
if not re.fullmatch(r'\d+\.\d+\.\d+-rc\.\d+', args.version):
    parser.error('Review artifacts require an explicit x.y.z-rc.n version')
out = args.output.resolve()
if out == root or root in out.parents and not out.is_relative_to(root / 'releases'):
    parser.error('Use releases/ inside the checkout, or a directory outside it')
out.mkdir(parents=True, exist_ok=True)
name = f'telescope-monitor-{args.version}'
archive = out / (name + '.zip')
if archive.exists():
    parser.error('Output archive already exists; use a new empty review directory')
env = dict(os.environ, COMPOSER_ROOT_VERSION=args.version)
if args.git_ref:
    subprocess.run(['git', 'archive', '--format=zip', '--output=' + str(archive), args.git_ref], cwd=root, check=True)
else:
    subprocess.run(['composer', 'archive', '--format=zip', '--dir=' + str(out), '--file=' + name], cwd=root, env=env, check=True)
with zipfile.ZipFile(archive) as package:
    paths = package.namelist()
    forbidden = {'vendor', 'node_modules', 'tests', 'workbench', '.archive', '.git', '.github', '.idea', '.impeccable', '.playwright-mcp', 'releases'}
    for name_in_zip in paths:
        p = Path(name_in_zip)
        if p.is_absolute() or '..' in p.parts or set(p.parts) & forbidden or p.name.startswith('.env') or p.suffix in {'.sqlite', '.pem', '.key'}:
            raise SystemExit('Unsafe archive member: ' + name_in_zip)
    manifest = {}
    for name_in_zip in paths:
        if name_in_zip.endswith('/'):
            continue
        content = package.read(name_in_zip)
        if content != (root / name_in_zip).read_bytes():
            raise SystemExit('Source/archive byte mismatch: ' + name_in_zip)
        manifest[name_in_zip] = hashlib.sha256(content).hexdigest()
    for required in ['LICENSE', 'README.md', 'composer.json', 'dist/manifest.json', 'dist/licenses/figtree.txt', 'dist/licenses/heroicons.txt', 'dist/licenses/vue.txt', 'dist/licenses/vue-router.txt', 'docs/configuration-reference.md', 'docs/telescope-source-capture.md', 'docs/technical-architecture-v1.md', 'docs/operations-guide-v1.md', 'docs/webhook-contract-v1.md', 'docs/reproduce.md', 'docs/slack-notification-example.json', f'docs/release-notes-v{args.version}.md', f'docs/release-verification-v{args.version}.md']:
        if required not in manifest:
            raise SystemExit('Missing required artifact: ' + required)
    asset_manifest = json.loads(package.read('dist/manifest.json'))
    for asset in asset_manifest.values():
        for asset_path in [asset['file'], *asset.get('css', []), *asset.get('assets', [])]:
            if 'dist/' + asset_path not in manifest:
                raise SystemExit('Missing compiled asset: ' + asset_path)
(out / 'FILES.sha256').write_text(''.join(f'{digest}  {name_in_zip}\n' for name_in_zip, digest in sorted(manifest.items())))
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(out / 'SHA256SUMS').write_text(f'{digest}  {archive.name}\n')
(out / 'artifact.json').write_text(json.dumps({'version': args.version, 'package': 'telescope-monitor/telescope-monitor', 'disposition': 'Release candidate — stable qualification pending', 'archive': archive.name, 'sha256': digest, 'files': len(manifest), 'source_manifest_sha256': hashlib.sha256((out / 'FILES.sha256').read_bytes()).hexdigest(), 'publication_performed': False}, indent=2) + '\n')
metadata = json.loads((root / 'composer.json').read_text())
package_metadata = {key: value for key, value in metadata.items() if key in {'name', 'type', 'description', 'license', 'require', 'autoload', 'extra'}}
package_metadata.update(version=args.version, dist={'type': 'zip', 'url': archive.as_uri(), 'shasum': hashlib.sha1(archive.read_bytes()).hexdigest()})
(out / 'composer-repository.json').write_text(json.dumps({'type': 'package', 'package': package_metadata}, indent=2) + '\n')
print(json.dumps({'archive': str(archive), 'files': len(manifest), 'sha256': digest}))
