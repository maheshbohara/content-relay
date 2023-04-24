#!/usr/bin/env python3
"""E2E: build mutated copies of a Vietnam bundle for scenarios 3, 6, 7 and 8.

Usage: make-variants.py <work-dir>
Reads <work-dir>/bundle-v2 (made with `wp content-relay bundle 4356 --dir=<work-dir>/bundle-v2` on the sender)
and writes v2-s3 (EN heading changed), v2-s6 (EN parent path missing), v2-s7 (term 227 slug changed) and
v2-s8 (hero file bytes changed; own files/ copy). Nothing on either site is touched.
"""
import hashlib
import json
import os
import shutil
import sys

work = sys.argv[1]
base = os.path.join(work, 'bundle-v2')
original = json.load(open(os.path.join(base, 'manifest.json')))


def variant(name, mutate, own_files=False):
    target = os.path.join(work, name)
    shutil.rmtree(target, ignore_errors=True)
    os.makedirs(target)
    if own_files:
        shutil.copytree(os.path.join(base, 'files'), os.path.join(target, 'files'))
    else:
        os.symlink(os.path.join(base, 'files'), os.path.join(target, 'files'))
    manifest = json.loads(json.dumps(original))
    print(name, '->', mutate(manifest, target))
    json.dump(manifest, open(os.path.join(target, 'manifest.json'), 'w'), ensure_ascii=False)


def s3(m, _):
    o = m['objects']['post:4356']
    o['content'] = o['content'].replace('"heading":"Vietnam"', '"heading":"Vietnam relay test"', 1)
    return 'EN heading changed'


def s6(m, _):
    m['objects']['post:2873']['path'] = 'export-navigator-missing'
    return 'EN parent path points nowhere'


def s7(m, _):
    t = m['objects']['term:227']
    t['slug'] = 'vietnam-relay-test'
    t['name'] = 'Vietnam relay test'
    return 'term 227 slug changed'


def s8(m, target):
    old = m['objects']['post:4350']['file']['sha1']
    data = open(os.path.join(target, 'files', old), 'rb').read() + b'content-relay-s8'
    new = hashlib.sha1(data).hexdigest()
    open(os.path.join(target, 'files', new), 'wb').write(data)
    for key in ('post:4350', 'post:4351'):
        m['objects'][key]['file']['sha1'] = new
        m['objects'][key]['file']['bytes'] = len(data)
    return f'hero file {old[:8]} -> {new[:8]}'


variant('v2-s3', s3)
variant('v2-s6', s6)
variant('v2-s7', s7)
variant('v2-s8', s8, own_files=True)
