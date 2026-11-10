"""Builds languages/coinspay-for-woocommerce-ru_RU.po from the .pot and ru_RU.py.

    python bin/i18n/build-po.py

Then make the binary files with WP-CLI: "wp i18n make-mo" for WordPress before 6.5
and "wp i18n make-php" for 6.5 and later. Fails on a string of the .pot without
a translation and on a translation no longer used.
"""
import io
import os
import re
import sys

sys.path.insert(0, os.path.dirname(__file__))
from ru_RU import RU  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
POT = os.path.join(ROOT, 'languages', 'coinspay-for-woocommerce.pot')
PO = os.path.join(ROOT, 'languages', 'coinspay-for-woocommerce-ru_RU.po')

QUOTED = re.compile(r'"((?:[^"\\]|\\.)*)"')


def unquote(lines):
    return ''.join(bytes(m, 'utf-8').decode('unicode_escape').encode('latin-1').decode('utf-8') for line in lines for m in QUOTED.findall(line))


def quote(text):
    return '"' + text.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n') + '"'


blocks = io.open(POT, encoding='utf-8').read().strip().split('\n\n')
header, entries = blocks[0], blocks[1:]

out = [
    '# Russian translation of CoinsPay for WooCommerce.',
    '# This file is distributed under the same license as the plugin: GPL-2.0-or-later.',
    'msgid ""',
    'msgstr ""',
    '"Project-Id-Version: CoinsPay for WooCommerce\\n"',
    '"Language: ru_RU\\n"',
    '"MIME-Version: 1.0\\n"',
    '"Content-Type: text/plain; charset=UTF-8\\n"',
    '"Content-Transfer-Encoding: 8bit\\n"',
    '"Plural-Forms: nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);\\n"',
    '"X-Domain: coinspay-for-woocommerce\\n"',
    '',
]
used = set()
missing = []
for block in entries:
    lines = block.split('\n')
    comments = [line for line in lines if line.startswith('#')]
    start = next(i for i, line in enumerate(lines) if line.startswith('msgid '))
    end = next(i for i, line in enumerate(lines) if line.startswith('msgstr '))
    msgid = unquote(lines[start:end])
    if msgid not in RU:
        missing.append(msgid)
        continue
    used.add(msgid)
    out.extend(comments)
    out.append('msgid ' + quote(msgid))
    out.append('msgstr ' + quote(RU[msgid]))
    out.append('')

unused = sorted(set(RU) - used)
if missing or unused:
    for text in missing:
        print('no translation:', text)
    for text in unused:
        print('not in the .pot:', text)
    sys.exit(1)

io.open(PO, 'w', encoding='utf-8', newline='\n').write('\n'.join(out))
print('%d strings -> %s' % (len(used), PO))
