#!/usr/bin/env python3
"""Create and verify a consistent SQLite snapshot without modifying the source."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import sqlite3


def snapshot(source: Path, destination: Path) -> dict:
    source = source.expanduser().resolve(strict=True)
    destination = destination.expanduser().absolute()
    if source == destination.resolve():
        raise ValueError('Source and destination must differ')
    destination.parent.mkdir(parents=True, exist_ok=True)
    # Exclusive creation prevents overwriting either a previous backup or a live DB.
    fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    os.close(fd)
    try:
        with sqlite3.connect(source.as_uri() + '?mode=ro', uri=True, timeout=30) as src:
            with sqlite3.connect(destination) as dst:
                src.backup(dst)
                check = dst.execute('PRAGMA integrity_check').fetchall()
                if check != [('ok',)]:
                    raise ValueError('Snapshot integrity check failed')
                counts = {}
                for (table,) in dst.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"):
                    quoted = '"' + table.replace('"', '""') + '"'
                    counts[table] = dst.execute('SELECT COUNT(*) FROM ' + quoted).fetchone()[0]
        digest = hashlib.file_digest(destination.open('rb'), 'sha256').hexdigest()
        return {'snapshot': str(destination), 'sha256': digest, 'integrity_check': 'ok', 'table_counts': counts}
    except BaseException:
        destination.unlink(missing_ok=True)
        raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('source', type=Path)
    parser.add_argument('destination', type=Path)
    args = parser.parse_args()
    try:
        print(json.dumps(snapshot(args.source, args.destination), ensure_ascii=False, indent=2))
    except (OSError, ValueError, sqlite3.Error) as error:
        parser.exit(1, f'Snapshot failed: {error}\n')
