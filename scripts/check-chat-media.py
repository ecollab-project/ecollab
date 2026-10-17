"""Offline integrity and allowlist checks; no network or application data access."""
import hashlib
import json
import pathlib
import re
import xml.etree.ElementTree as ET

root=pathlib.Path(__file__).resolve().parents[1]
media=root/'assets/media'
catalog=json.loads((media/'catalog.json').read_text())
provenance=json.loads((media/'provenance.json').read_text())
paths=set()
for entry in catalog['emojis']+catalog['stickers']:
    if 'image' not in entry:continue
    path=entry['image']
    assert re.fullmatch(r'(?:emoji/fluent/[a-f0-9-]+\.svg|stickers/noto/[a-f0-9-]+\.webp)',path),path
    paths.add(path)
    raw=(media/path).read_bytes()
    assert hashlib.sha256(raw).hexdigest()==provenance['files'][path]['sha256'],path
    if path.endswith('.svg'):
        svg=ET.fromstring(raw)
        assert svg.tag=='{http://www.w3.org/2000/svg}svg'
        for element in svg.iter():
            assert element.tag.split('}')[-1] not in ('script','foreignObject','image','use')
            assert all(not key.lower().startswith('on') and 'href' not in key.lower() for key in element.attrib)
    else:
        assert raw[:4]==b'RIFF' and raw[8:12]==b'WEBP' and b'ANIM' in raw,path
assert paths==set(provenance['files'])
normalize=lambda s:s.replace('\ufe0f','')
fluent={normalize(e['emoji']) for e in catalog['emojis'] if 'image' in e}
for sticker in catalog['stickers']:
    assert re.fullmatch('[a-f0-9-]{2,80}',sticker['id'])
    assert normalize(sticker['emoji']) in fluent,'Missing reduced-motion static image: '+sticker['id']
assert len({s['id'] for s in catalog['stickers']})==len(catalog['stickers'])
assert len(catalog['emojis'])>1500 and len(catalog['stickers'])>=20
print('PASS:',len(catalog['emojis']),'searchable emoji;',len(paths)-len(catalog['stickers']),'Fluent SVGs;',len(catalog['stickers']),'animated stickers; hashes, SVG safety and static fallbacks verified')
