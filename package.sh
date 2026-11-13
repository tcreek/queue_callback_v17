#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
python3 - "$SCRIPT_DIR" <<'PY'
from pathlib import Path
import gzip
import hashlib
import io
import os
import re
import sys
import tarfile
import tempfile
import xml.etree.ElementTree as ET

project = Path(sys.argv[1]).resolve()
manifest = ET.parse(project / 'module.xml').getroot()
name = manifest.findtext('rawname', '').strip()
version = manifest.findtext('version', '').strip()
if not re.fullmatch(r'[A-Za-z0-9_-]+', name) or not re.fullmatch(r'[A-Za-z0-9._-]+', version):
    raise SystemExit('Invalid module name or version')
files = ['module.xml'] + [(entry.text or '').strip() for entry in manifest.findall('./files/file')]
if len(files) != len(set(files)):
    raise SystemExit('Duplicate manifest entries')
for relative in files:
    path = Path(relative)
    source = project / path
    if path.is_absolute() or '..' in path.parts or not path.parts or source.is_symlink():
        raise SystemExit(f'Unsafe manifest path: {relative}')
    if not source.is_file() or not source.resolve().is_relative_to(project):
        raise SystemExit(f'Missing or unsafe manifest file: {relative}')
for pattern in ['*.php', 'views/*.php', 'agi-bin/*.agi', 'announcements/*']:
    for source in project.glob(pattern):
        if source.is_file() and str(source.relative_to(project)) not in files:
            raise SystemExit(f'Runtime file omitted from manifest: {source.relative_to(project)}')
output = project / 'dist'
output.mkdir(exist_ok=True)
package = output / f'{name}-{version}.tgz'
fd, staged = tempfile.mkstemp(prefix='.package-', dir=output)
os.close(fd)
try:
    with open(staged, 'wb') as stream:
        with gzip.GzipFile(filename='', fileobj=stream, mode='wb', mtime=0) as gz:
            with tarfile.open(fileobj=gz, mode='w') as archive:
                for relative in sorted(files):
                    data = (project / relative).read_bytes()
                    member = tarfile.TarInfo(f'{name}/{relative}')
                    member.size = len(data)
                    member.mode = 0o755 if relative.startswith('agi-bin/') else 0o644
                    member.uid = member.gid = 0
                    member.uname = member.gname = 'root'
                    member.mtime = 0
                    archive.addfile(member, io.BytesIO(data))
    with tarfile.open(staged, 'r:gz') as archive:
        if len(archive.getmembers()) != len(files):
            raise SystemExit('Incorrect package file count')
        for member in archive.getmembers():
            relative = member.name.removeprefix(f'{name}/')
            if not member.isfile() or relative not in files or archive.extractfile(member).read() != (project / relative).read_bytes():
                raise SystemExit('Package content verification failed')
    os.chmod(staged, 0o644)
    os.replace(staged, package)
finally:
    if os.path.exists(staged):
        os.unlink(staged)
checksum = hashlib.sha256(package.read_bytes()).hexdigest()
(package.with_name(package.name + '.sha256')).write_text(f'{checksum}  {package.name}\n')
print(f'Packaged and verified: {package} ({len(files)} files)')
print(f'SHA256: {checksum}')
PY
