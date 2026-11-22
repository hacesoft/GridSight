#!/usr/bin/env python3
"""HDS 1.2.1: kontrola konkrétních českých komentářů a souladu typové notace.

Vstup: zdrojový strom GridSight vedle této kontrolní utility.
Výstup: nenulový návratový kód pro chybějící dokumentaci/nesoulad vlastností;
nezávisí na Composeru ani na běžícím Nextcloudu.
"""
from pathlib import Path
import re
root=Path(__file__).resolve().parents[2]
src=root/'src'
php_doc=re.compile(r'^[ \t]*/\*\*(?:(?!/\*\*).)*?\*/\s*(?:#\[[^\n]*\]\s*)*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+([A-Za-z_]\w*)\s*\(',re.M|re.S)
js_files=list((src/'js').glob('*.js'))
assert len(js_files)==2, 'expected two application JavaScript files'
php_count=0
for path in src.rglob('*.php'):
    content=path.read_text()
    all_methods=re.findall(r'\bfunction\s+([A-Za-z_]\w*)\s*\(', content)
    docs=list(php_doc.finditer(content))
    assert len(all_methods)==len(docs),(path,'not all PHP methods documented',len(all_methods),len(docs))
    for m in docs:
        comment=content[m.start():m.end()]
        assert 'Vstupy:' in comment and 'Výstup:' in comment,(path,m.group(1),'missing Czech contract')
        assert '@return ' in comment,(path,m.group(1),'missing documented return')
    php_count+=len(docs)
    # PHP dynamické vlastnosti bývají při pouhém lint bezchybný syntakticky, ale škodí běhu.
    props=set(re.findall(r'\b(?:public|protected|private)\s+(?:readonly\s+)?(?:\??[\\\w|]+\s+)?\$([A-Za-z_]\w*)',content))
    accesses=set(re.findall(r'\$this->([A-Za-z_]\w*)\b(?!\s*\()',content))
    assert not (accesses-props),(path,'undeclared PHP property',sorted(accesses-props))
    if re.search(r'\$[snbova][A-Z]',content):
        assert 'typová legenda proměnných' in content[:1500],(path,'missing notation header')
        assert 'Historie: 2026-11-22' in content[:1500],(path,'missing latest notation history')
    assert not any(s in content for s in ('hodnota parametru','strukturovaná pole dat nebo výsledků operace',
        'Provede anonymní callback uvnitř volající operace','Zpracuje hodnotu pole při transformaci')),(path,'placeholder contract')
assert php_count==145,php_count

app=(src/'js/app-1.0.1.js').read_text()
dash=(src/'js/dashboard-1.0.1.js').read_text()
for path in js_files:
    content=path.read_text()
    head=content[:1700]
    assert 'legenda maďarské notace' in head and 'Historie: 2026-11-22' in head,path
    for prefix in ('s –','n –','b –','a –','o –','v –','m –','fn –'):
        assert prefix in head,(path,'missing prefix',prefix)
    assert content.count('Vstupy:') >= (120 if path.name.startswith('app-') else 4),path
    assert content.count('Výstup:') >= (120 if path.name.startswith('app-') else 4),path
    for generic in ('položka či průběžná hodnota dané operace',
                    'výsledek lokálního výpočtu nebo změna stavu popsaná výrazem',
                    'string|number|object', 'provedena operace uvedená v popisu bez návratové hodnoty'):
        assert generic not in content, (path, 'generic function contract', generic)
    assert 'parametry deklarované ve funkci' not in content,path
# Konkrétní případy předchozí regresní chyby a chybného použití předpon.
for prohibited in ('$sStatusCode', '$sPricesValid', '$nReports', '$nRow'):
    assert not re.search(re.escape(prohibited)+r'(?![A-Za-z0-9_])', (src/'lib/Service/EnergyAnalysisService.php').read_text()+(src/'lib/Service/LineaApiService.php').read_text()),prohibited
for snippet in ('function renderStatus(vStatus)', 'function renderClimate(vDevice,vStatus)',
                'vSummary=oData?.summary', 'const sStateClass=vDevice.error'):
    assert snippet in app,snippet
for snippet in ('const fnNumber=', 'const vStatus=vJ.data'):
    assert snippet in dash,snippet
assert '\\OC::$server' in (src/'bin/collector-daemon.php').read_text()
assert '\\OC::$vServer' not in (src/'bin/collector-daemon.php').read_text()
assert (root/'docs/cz/07_KODOVACI_STANDARD.md').exists()
print(f'PASS: {php_count} PHP metod, úplné JS hlavičky/kontrakty, typové předpony a deklarace PHP vlastností')
