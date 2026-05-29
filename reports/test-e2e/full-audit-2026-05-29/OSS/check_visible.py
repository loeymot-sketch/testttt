#!/usr/bin/env python3
"""check_visible.py <order_id> <target_status>
Reads OSS API JSON from stdin, prints 'hit' if (id, status) matches, else 'miss'.
"""
import sys, json

if len(sys.argv) < 3:
    print("usage: check_visible.py <id> <status>", file=sys.stderr)
    sys.exit(2)

oid = int(sys.argv[1])
tgt = int(sys.argv[2])

try:
    d = json.load(sys.stdin)
    data = d.get('data', []) if isinstance(d, dict) else d
    for o in data:
        if int(o.get('id', -1)) == oid and int(o.get('status', -1)) == tgt:
            print('hit')
            sys.exit(0)
    print('miss')
except Exception as e:
    print(f'parse_err:{e}', file=sys.stderr)
    print('miss')
