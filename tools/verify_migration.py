#!/usr/bin/env python3
"""Compare all original fields of the four legacy tables without printing values."""
import argparse
import json
from pathlib import Path
import sqlite3

TABLES = ('rustdesk_users', 'rustdesk_peers', 'rustdesk_tags', 'rustdesk_token')

def verify(before: Path, after: Path):
    result = {}
    with sqlite3.connect(before.resolve(strict=True).as_uri() + '?mode=ro', uri=True) as source:
        with sqlite3.connect(after.resolve(strict=True).as_uri() + '?mode=ro', uri=True) as target:
            for db in (source, target):
                if db.execute('PRAGMA integrity_check').fetchall() != [('ok',)]:
                    raise ValueError('Database integrity check failed')
            for table in TABLES:
                columns = [row[1] for row in source.execute(f'PRAGMA table_info({table})')]
                if not columns:
                    raise ValueError(f'Missing legacy table: {table}')
                fields = ','.join('"' + c.replace('"', '""') + '"' for c in columns)
                query = f'SELECT {fields} FROM {table} ORDER BY rowid'
                a, b = source.execute(query).fetchall(), target.execute(query).fetchall()
                if a != b:
                    raise ValueError(f'Original fields differ: {table}; compare before performing logins or management writes')
                result[table] = {'rows': len(a), 'original_fields_preserved': True}
    return result

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('before', type=Path)
    parser.add_argument('after', type=Path)
    args = parser.parse_args()
    try:
        print(json.dumps(verify(args.before, args.after), indent=2))
    except (OSError, ValueError, sqlite3.Error) as error:
        parser.exit(1, f'Verification failed: {error}\n')
