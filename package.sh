#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Build from module.xml so new shared helpers and views cannot be omitted.
python3 - "$SCRIPT_DIR" <<'PY'
from pathlib import Path
import os
import re
import sys
import tarfile
import tempfile
import xml.etree.ElementTree as ET

project = Path(sys.argv[1]).resolve()
manifest = ET.parse(project / 'module.xml').getroot()
name = manifest.findtext('rawname', '').strip()
version = re.sub(r'[^A-Za-z0-9._-]+', '_', manifest.findtext('version', '').strip())
if not re.fullmatch(r'[A-Za-z0-9_-]+', name) or not version:
    raise SystemExit('Invalid module name or version')
files = [Path('module.xml')]
for entry in manifest.findall('./files/file'):
    relative = Path((entry.text or '').strip())
    if relative.is_absolute() or '..' in relative.parts or not relative.parts:
        raise SystemExit('Invalid manifest file path')
    source = project / relative
    if not source.is_file() or not source.resolve().is_relative_to(project):
        raise SystemExit(f'Missing or unsafe manifest file: {relative}')
    files.append(relative)
sounds = project / 'announcements'
if not sounds.is_dir():
    raise SystemExit('Missing announcements directory')
for sound in sounds.rglob('*'):
    if sound.is_symlink():
        raise SystemExit('Announcement symlinks are not supported')
output = project / 'dist'
output.mkdir(exist_ok=True)
package = output / f'{name}-{version}.tgz'
fd, staged = tempfile.mkstemp(prefix='.package-', dir=output)
os.close(fd)
try:
    with tarfile.open(staged, 'w:gz') as archive:
        for relative in dict.fromkeys(files):
            archive.add(project / relative, arcname=f'{name}/{relative.as_posix()}')
        archive.add(sounds, arcname=f'{name}/announcements')
    os.chmod(staged, 0o644)
    os.replace(staged, package)
finally:
    if os.path.exists(staged):
        os.unlink(staged)
print(f'Packaged: {package}')
PY
