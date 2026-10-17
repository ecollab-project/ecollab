#!/usr/bin/env python3
"""Rebuild the first-paint palette CSS from the shared JSON catalog."""
import json
from pathlib import Path
import sys
root = Path(__file__).resolve().parent.parent
palettes = json.loads((root / 'assets/data/color-palettes.json').read_text())
lines = ['/* Named palettes from the supplied reference. UI shades preserve readable contrast. */']
for palette in sorted(palettes, key=lambda p: p['id'] != 'forest-mint'):
    for mode in ['dark', 'light']:
        selector = 'html:root' if palette['id'] == 'forest-mint' else 'html:root[data-color-palette="' + palette['id'] + '"]'
        if mode == 'light':
            selector += '[data-theme="light"]'
        tokens = ''.join('--ec-' + key + ':' + value + ';' for key, value in palette[mode].items())
        lines.append(selector + '{' + tokens + '--ec-accent-soft:color-mix(in srgb,var(--ec-accent) 14%,transparent);--ec-focus:var(--ec-accent);--ec-button-text:#fff;color-scheme:' + mode + ';}')
text = '\n'.join(lines) + '\n'
target = root / 'assets/css/color-palettes.css'
if '--check' in sys.argv:
    if target.read_text() != text:
        raise SystemExit('Palette CSS differs from the catalog; run python3 scripts/build-color-palettes.py')
else:
    target.write_text(text)
