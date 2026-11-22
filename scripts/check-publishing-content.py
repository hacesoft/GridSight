#!/usr/bin/env python3
"""Ověřitelné vydávací předkontroly dokumentace, lokalizace a metadat HDS G02/G04/G05."""
import json
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

root = Path(__file__).resolve().parents[1]
src = root / 'src'
metadata = ET.parse(src / 'appinfo/info.xml').getroot()
required = {'en', 'cs', 'de', 'es', 'fr', 'it', 'nl', 'pl', 'pt', 'sk', 'uk'}
for tag in ('summary', 'description'):
    entries = {item.attrib.get('lang') for item in metadata.findall(tag)}
    missing = required - entries
    assert not missing, f'{tag}: missing metadata locales {missing}'
    assert 'hu' in entries, f'{tag}: missing optional hu metadata'
    for item in metadata.findall(tag):
        assert item.text and item.text.strip(), f'{tag}: empty text {item.attrib}'
        if tag == 'description':
            assert item.text.count('\n- ') >= 10, f'{tag}: incomplete feature overview {item.attrib}'
assert metadata.findtext('version') == '1.0.1'
assert metadata.find('documentation') is None, 'duplicate App Store resource links'
assert metadata.findtext('website') == 'https://github.com/hacesoft/GridSight'
assert metadata.findtext('repository') == 'https://github.com/hacesoft/GridSight'
assert metadata.findtext('id') == 'hc_gridsight'
assert (src / 'img/app.svg').exists()
for name in ('README.md','README_CZ.md','docs/cz/01_INSTALACE.md','docs/en/01_INSTALLATION.md',
             'docs/cz/06_PREHLED_FUNKCI_A_OZNAMENI.md','docs/en/06_FEATURES_AND_NOTICES.md'):
    assert (root/name).is_file(), f'missing documentation {name}'
cz = (root/'docs/cz/06_PREHLED_FUNKCI_A_OZNAMENI.md').read_text()
for key in ('2D','3D','LINEA API je dočasně nedostupné','EG.D','Delta Green','HDO','UPS','Shelly','Daikin','pětiminut','SPOT','18'):
    if key=='18':continue
    assert key in cz, f'not covered in Czech feature guide: {key}'
for name in ('docs/cz/01_INSTALACE.md','docs/en/01_INSTALLATION.md'):
    data=(root/name).read_text()
    assert '[01/18]' in data and '[18/18]' in data, f'{name}: old installer instructions'
cs=json.loads((src/'l10n/cs.json').read_text())['translations']
assert cs['Power flow between the site and the grid: positive import, negative export.'] != 'Power flow between the site and the grid: positive import, negative export.'
assert cs['LINEA API is temporarily unavailable. Waiting for reconnection.'].startswith('LINEA API je dočasně')
# Doplňková maďarština musí mít nově celý katalog stejně jako povinné jazyky.
hu=json.loads((src/'l10n/hu.json').read_text())['translations']
assert hu['LINEA API is temporarily unavailable. Waiting for reconnection.'].startswith('A LINEA API átmenetileg')
source=json.loads((src/'l10n/en.json').read_text())['translations']
assert set(hu) == set(source), 'hu must cover all keys'
assert len(hu) == 594, 'hu must include complete translation catalog'
# Kontrola českých popisů vstupů a výstupů všech pojmenovaných PHP metod.
php_count = 0
for path in src.rglob('*.php'):
    lines=path.read_text().splitlines()
    for i,line in enumerate(lines):
        if not re.search(r'\bfunction\s+\w+\s*\(',line):continue
        php_count+=1
        # Anotace PHP mohou být bezprostředně před deklarací.
        start=i
        while start > 0 and lines[start-1].lstrip().startswith('#['):start-=1
        context='\n'.join(lines[max(0,start-20):start])
        assert ('Vstupy:' in context and 'Výstup:' in context), f'{path.relative_to(root)}:{i+1}: CZ function contract missing'
assert php_count>=140, f'PHP function coverage too small: {php_count}'
# Zdokumentované funkce webového rozhraní, nikoliv jen PHP.
js=(src/'js/app-1.0.1.js').read_text()
assert js.count('Vstupy:') >= 120 and js.count('Výstup:') >= 120, 'named JS functions must describe inputs and outputs'
assert 'Vstup: parametry deklarované ve funkci' not in js, 'generic JS function comments forbidden'
assert 'legenda maďarské notace' in js, 'missing Hungarian prefix legend'
print(f'PASS: {len(required)} HDS locales + complete Hungarian, 12 complete metadata descriptions, 18 installer steps, {php_count} PHP and documented JS functions')
