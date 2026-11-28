#!/usr/bin/env python3
"""Verify OMCP build manifests and install a checksum-pinned CI asset ZIP."""
import argparse
import base64
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import shutil
import stat
import tempfile
import zipfile

MAX_ARCHIVE = 64 * 1024 * 1024
MAX_FILE = 64 * 1024 * 1024
MAX_TOTAL = 256 * 1024 * 1024
MAX_ENTRIES = 5000


def relative_path(value):
    if not isinstance(value, str) or len(value) > 512 or '\\' in value:
        raise ValueError('Invalid asset path')
    parts = value.split('/')
    if not parts or any(not re.fullmatch(r'[A-Za-z0-9_.-]+', p) or p in ('.', '..') for p in parts):
        raise ValueError('Unsafe asset path')
    return Path(*parts)


def safe_path(root, name):
    path = root
    for part in relative_path(name).parts:
        path = path / part
        if path.is_symlink():
            raise ValueError('Symlink in asset path')
    return path


def digest(stream, algorithm):
    value = hashlib.new(algorithm)
    for block in iter(lambda: stream.read(1024 * 1024), b''):
        value.update(block)
    return value.digest()


def verify(root, fix_fonts=False):
    root = Path(root).absolute()
    if root.is_symlink() or not root.is_dir():
        raise ValueError('Asset root must be a directory')
    manifest = safe_path(root, 'manifest.json')
    if not manifest.is_file() or manifest.stat().st_size > 1024 * 1024:
        raise ValueError('Manifest is missing or too large')
    data = json.loads(manifest.read_text(encoding='utf-8'))
    if not isinstance(data, dict) or 'main.js' not in data or len(data) > MAX_ENTRIES:
        raise ValueError('Invalid asset manifest')
    fixed = 0
    for name, item in data.items():
        if not isinstance(item, dict) or not isinstance(item.get('src'), str) or not item['src'].startswith('/assets/'):
            raise ValueError('Invalid manifest entry: ' + name)
        src = item['src'][len('/assets/'):]
        path = safe_path(root, src)
        if not path.is_file() or path.stat().st_size > MAX_FILE:
            raise ValueError('Missing or oversized asset: ' + src)
        with path.open('rb') as stream:
            actual = 'sha384-' + base64.b64encode(digest(stream, 'sha384')).decode('ascii')
        expected = item.get('integrity')
        if not isinstance(expected, str):
            raise ValueError('Invalid integrity field: ' + name)
        if not hmac.compare_digest(expected, actual):
            # Webpack asset/resource font aliases can carry a pre-output digest.
            # Never repair JavaScript or other asset integrity mismatches.
            if not fix_fonts or expected != '' or path.suffix.lower() not in ('.woff', '.woff2'):
                raise ValueError('Integrity mismatch: ' + name)
            item['integrity'] = actual
            fixed += 1
    if fixed:
        with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8', dir=root, delete=False) as temp:
            json.dump(data, temp, indent=2)
            temp.write('\n')
            path = Path(temp.name)
        try:
            os.replace(path, manifest)
        finally:
            path.unlink(missing_ok=True)
    print(f'Verified {len(data)} manifest entries; normalized {fixed} font entries.')


def install(archive, dest, expected, fix_fonts=False):
    if not re.fullmatch(r'[a-f0-9]{64}', expected):
        raise ValueError('Expected archive SHA-256 is required')
    archive = Path(archive)
    dest = Path(dest).absolute()
    if not archive.is_file() or archive.stat().st_size > MAX_ARCHIVE:
        raise ValueError('Invalid asset archive')
    if dest.is_symlink() or not dest.is_dir():
        raise ValueError('Destination must be an existing non-symlink asset directory')
    with archive.open('rb') as stream:
        if not hmac.compare_digest(digest(stream, 'sha256').hex(), expected):
            raise ValueError('Asset archive SHA-256 mismatch')
        stream.seek(0)
        with zipfile.ZipFile(stream) as zipped, tempfile.TemporaryDirectory(prefix='.omcp-assets-', dir=dest.parent) as tmp:
            stage = Path(tmp)
            entries = zipped.infolist()
            if len(entries) > MAX_ENTRIES or sum(e.file_size for e in entries) > MAX_TOTAL:
                raise ValueError('Asset archive exceeds limits')
            seen = set()
            for entry in entries:
                name = entry.filename.rstrip('/') if entry.is_dir() else entry.filename
                target = safe_path(stage, name)
                if name in seen or entry.file_size > MAX_FILE:
                    raise ValueError('Duplicate or oversized archive entry')
                seen.add(name)
                kind = stat.S_IFMT(entry.external_attr >> 16)
                if entry.is_dir():
                    if kind not in (0, stat.S_IFDIR):
                        raise ValueError('Invalid archive directory')
                    target.mkdir(parents=True, exist_ok=True)
                else:
                    if kind not in (0, stat.S_IFREG):
                        raise ValueError('Only regular asset files may be extracted')
                    target.parent.mkdir(parents=True, exist_ok=True)
                    with zipped.open(entry) as source, target.open('xb') as output:
                        shutil.copyfileobj(source, output)
            verify(stage, fix_fonts)
            verify(stage)
            files = sorted(path for path in stage.rglob('*') if path.is_file())
            # Validate every destination before writing any asset.
            targets = [(source, safe_path(dest, source.relative_to(stage).as_posix())) for source in files]
            for _, target in targets:
                if target.exists() and not target.is_file():
                    raise ValueError('Destination asset is not a regular file')
                for parent in target.parents:
                    if parent == dest:
                        break
                    if parent.exists() and not parent.is_dir():
                        raise ValueError('Destination parent is not a directory')
            for source, target in targets:
                target.parent.mkdir(parents=True, exist_ok=True)
                with tempfile.NamedTemporaryFile(dir=target.parent, delete=False) as output:
                    temporary = Path(output.name)
                    with source.open('rb') as contents:
                        shutil.copyfileobj(contents, output)
                try:
                    os.replace(temporary, target)
                finally:
                    temporary.unlink(missing_ok=True)
    verify(dest)


def main():
    parser = argparse.ArgumentParser()
    commands = parser.add_subparsers(dest='action', required=True)
    check = commands.add_parser('verify')
    check.add_argument('root')
    check.add_argument('--fix-font-integrity', action='store_true')
    extract = commands.add_parser('install')
    extract.add_argument('archive')
    extract.add_argument('dest')
    extract.add_argument('--sha256', required=True)
    extract.add_argument('--fix-font-integrity', action='store_true')
    args = parser.parse_args()
    try:
        if args.action == 'verify':
            verify(args.root, args.fix_font_integrity)
        else:
            install(args.archive, args.dest, args.sha256, args.fix_font_integrity)
    except (OSError, ValueError, zipfile.BadZipFile) as error:
        parser.exit(1, f'Asset verification failed: {error}\n')


if __name__ == '__main__':
    main()
